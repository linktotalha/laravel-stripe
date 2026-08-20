<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StripeEvent;
use App\Models\Subscription;
use App\Services\StripeSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Invoice as StripeInvoice;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly StripeSyncService $sync,
    ) {}

    public function handle(Request $request)
    {
        $payload = $request->getContent();

        $signature = $request->header('Stripe-Signature');

        try {
            $event = Webhook::constructEvent(
                $payload,
                $signature,
                config('services.stripe.webhook_secret')
            );
        } catch (\UnexpectedValueException $e) {
            Log::error('Invalid Stripe webhook payload');

            return response()->json(['message' => 'Invalid payload'], 400);
        } catch (SignatureVerificationException $e) {
            Log::error('Invalid Stripe webhook signature');

            return response()->json(['message' => 'Invalid signature'], 400);
        }

        // Never log the full event: it carries customer PII.
        Log::info('Stripe webhook received', [
            'id' => $event->id,
            'type' => $event->type,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Idempotency
        |--------------------------------------------------------------------------
        |
        | Stripe retries until it sees a 2xx, so the same event id can arrive
        | more than once. The unique index on stripe_event_id makes the insert
        | the lock: if it is already there and done, acknowledge and stop.
        */

        $record = StripeEvent::firstOrCreate(
            ['stripe_event_id' => $event->id],
            [
                'type' => $event->type,
                'event_created_at' => Carbon::createFromTimestampUTC($event->created),
            ]
        );

        if ($record->processed_at) {
            Log::info('Stripe webhook already processed, skipping', [
                'id' => $event->id,
            ]);

            return response()->json(['received' => true, 'duplicate' => true]);
        }

        /*
        |--------------------------------------------------------------------------
        | Dispatch
        |--------------------------------------------------------------------------
        |
        | A handler failure is recorded and returned as a 500 so Stripe retries.
        | Anything unexpected is acknowledged rather than retried forever.
        */

        try {
            $this->dispatchEvent($event);

            $record->update(['processed_at' => now(), 'error' => null]);
        } catch (\Throwable $e) {
            Log::error('Stripe webhook handler failed', [
                'id' => $event->id,
                'type' => $event->type,
                'error' => $e->getMessage(),
            ]);

            $record->update(['error' => $e->getMessage()]);

            // 500 tells Stripe to retry with backoff.
            return response()->json(['message' => 'Handler failed'], 500);
        }

        return response()->json(['received' => true]);
    }

    private function dispatchEvent(Event $event): void
    {
        $object = $event->data->object;

        match ($event->type) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($object),

            // created / updated / deleted all carry a full subscription object,
            // so one sync path covers activation, plan changes made in the
            // Stripe dashboard or Customer Portal, dunning, and cancellation.
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->handleSubscriptionChanged($object),

            'invoice.paid' => $this->handleInvoicePaid($object),

            'invoice.payment_failed' => $this->handleInvoicePaymentFailed($object),

            default => Log::info('Unhandled Stripe event type', [
                'type' => $event->type,
            ]),
        };
    }

    /**
     * Record the subscription as soon as checkout completes, and stamp the
     * session id so the success endpoint can find it.
     */
    private function handleCheckoutCompleted(StripeCheckoutSession $session): void
    {
        if ($session->mode !== 'subscription' || ! $session->subscription) {
            return;
        }

        $subscriptionId = is_string($session->subscription)
            ? $session->subscription
            : $session->subscription->id;

        $stripeSubscription = $this->stripe->subscriptions->retrieve($subscriptionId);

        $this->sync->syncSubscription($stripeSubscription, $session->id);
    }

    private function handleSubscriptionChanged(StripeSubscription $stripeSubscription): void
    {
        $this->sync->syncSubscription($stripeSubscription);
    }

    private function handleInvoicePaid(StripeInvoice $invoice): void
    {
        $subscription = $this->syncSubscriptionForInvoice($invoice);

        if (! $subscription) {
            return;
        }

        $this->sync->syncInvoice($invoice, $subscription);
    }

    private function handleInvoicePaymentFailed(StripeInvoice $invoice): void
    {
        // The real status comes from Stripe: a failed FIRST invoice leaves the
        // subscription "incomplete", and exhausted retries give "canceled" or
        // "unpaid" -- hardcoding "past_due" would be wrong in all three cases.
        $subscription = $this->syncSubscriptionForInvoice($invoice);

        if (! $subscription) {
            return;
        }

        $this->sync->syncInvoice($invoice, $subscription);
    }

    /**
     * Re-sync the subscription an invoice belongs to and return the local row.
     */
    private function syncSubscriptionForInvoice(StripeInvoice $invoice): ?Subscription
    {
        $subscriptionId = $this->sync->subscriptionIdFromInvoice($invoice);

        if (! $subscriptionId) {
            // One-off invoice, not tied to a subscription.
            return null;
        }

        $stripeSubscription = $this->stripe->subscriptions->retrieve($subscriptionId);

        $subscription = $this->sync->syncSubscription($stripeSubscription);

        if (! $subscription) {
            Log::warning('Local subscription not found for invoice', [
                'stripe_subscription_id' => $subscriptionId,
                'stripe_invoice_id' => $invoice->id,
            ]);
        }

        return $subscription;
    }
}
