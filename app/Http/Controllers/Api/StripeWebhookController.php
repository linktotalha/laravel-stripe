<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Price;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Stripe\StripeClient;
use Carbon\Carbon;

class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->getContent();

        $signature = $request->header('Stripe-Signature');

        $webhookSecret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent(
                $payload,
                $signature,
                $webhookSecret
            );
        } catch (\UnexpectedValueException $e) {
            Log::error('Invalid Stripe webhook payload');

            return response()->json([
                'message' => 'Invalid payload',
            ], 400);
        } catch (SignatureVerificationException $e) {
            Log::error('Invalid Stripe webhook signature');

            return response()->json([
                'message' => 'Invalid signature',
            ], 400);
        }

        Log::info('Stripe webhook received', [
            'type' => $event->type,
            'id' => $event->id,
            'event' => $event,
            'data' => $event->data->object
        ]);

        switch ($event->type) {
            case 'checkout.session.completed':
                $this->handleCheckoutCompleted(
                    $event->data->object
                );

                break;

            case 'customer.subscription.created':
                $this->handleSubscriptionCreated(
                    $event->data->object
                );

                break;
            
             case 'customer.subscription.updated':
                $this->handleSubscriptionUpdated(
                    $event->data->object
                );
                break;

            case 'invoice.payment_failed':
                $this->handleInvoicePaymentFailed(
                    $event->data->object
                );

                break;

            case 'invoice.paid':
                $this->handleInvoicePaid(
                    $event->data->object
                );

                break;
        }

        return response()->json([
            'received' => true,
        ]);
    }

    private function handleCheckoutCompleted($session)
    {

    Log::warning(
                'Subscription ID',
                [
                    'session' => $session,
                ]
            );
        if ($session->mode !== 'subscription') {
            return;
        }

        if (!$session->subscription) {
            return;
        }

        $subscription = Subscription::where(
            'stripe_subscription_id',
            $session->subscription
        )->first();

        Log::warning(
                'Subscription ID',
                [
                    'checkout_session_id' => $session->id,
                    'stripe_subscription_id' =>
                        $session->subscription,
                ]
            );

        if (!$subscription) {
            Log::warning(
                'Subscription not found for checkout session',
                [
                    'checkout_session_id' => $session->id,
                    'stripe_subscription_id' =>
                        $session->subscription,
                ]
            );

            return;
        }

        $subscription->update([
            'stripe_checkout_session_id' => $session->id,
        ]);

        Log::info('Checkout session linked', [
            'checkout_session_id' => $session->id,
            'stripe_subscription_id' =>
                $session->subscription,
        ]);
    }

    private function handleSubscriptionCreated($stripeSubscription)
    {
        $stripe = new StripeClient(
            config('services.stripe.secret')
        );
        $userId = $stripeSubscription->metadata->user_id ?? null;
        $priceId = $stripeSubscription->metadata->price_id ?? null;

        if (!$userId || !$priceId) {
            Log::error('Subscription metadata missing', [
                'subscription_id' => $stripeSubscription->id,
            ]);

            return;
        }

        $user = User::find($userId);
        $price = Price::find($priceId);

        if (!$user || !$price) {
            return;
        }

        $subscriptionItem = $stripeSubscription->items->data[0] ?? null;

        if (!$subscriptionItem) {
            Log::error('Subscription item not found', [
                'subscription_id' => $stripeSubscription->id,
            ]);

            return;
        }

        $checkoutSessionId = null;

        $sessions = $stripe->checkout->sessions->all([
            'subscription' => $stripeSubscription->id,
            'limit' => 1,
        ]);

        if (!empty($sessions->data)) {
            $checkoutSessionId = $sessions->data[0]->id;
        }

        Log::Info(
            'Sub Id Exists', ['sub id' => $checkoutSessionId]
        );

        $currentPeriodStart = $subscriptionItem->current_period_start ?? null;
        $currentPeriodEnd = $subscriptionItem->current_period_end ?? null;

        Subscription::updateOrCreate(
            [
                'stripe_subscription_id' =>
                    $stripeSubscription->id,
            ],
            [
                'user_id' => $user->id,
                'price_id' => $price->id,
                'stripe_checkout_session_id' => $checkoutSessionId,

                'status' =>
                    $stripeSubscription->status,

                'current_period_start' =>
                    $this->timestampToDate(
                        $currentPeriodStart
                    ),

                'current_period_end' =>
                    $this->timestampToDate(
                        $currentPeriodEnd
                    ),

                'trial_start' =>
                    $this->timestampToDate(
                        $stripeSubscription->trial_start ?? null
                    ),

                'trial_end' =>
                    $this->timestampToDate(
                        $stripeSubscription->trial_end ?? null
                    ),

                'cancel_at_period_end' =>
                    (bool) ($stripeSubscription->cancel_at_period_end ?? false),

                'canceled_at' =>
                    $this->timestampToDate(
                        $stripeSubscription->canceled_at ?? null
                    ),

                'ended_at' =>
                    $this->timestampToDate(
                        $stripeSubscription->ended_at ?? null
                    ),

                'metadata' =>
                    $stripeSubscription->toArray(),
            ]
        );
    }

    private function handleSubscriptionUpdated($stripeSubscription)
    {
        $subscription = Subscription::where(
            'stripe_subscription_id',
            $stripeSubscription->id
        )->first();

        if (!$subscription) {
            Log::warning('Local subscription not found on update', [
                'stripe_subscription_id' => $stripeSubscription->id,
            ]);

            return;
        }

        $subscriptionItem =
            $stripeSubscription->items->data[0] ?? null;

        if (!$subscriptionItem) {
            Log::error('Subscription item not found on update', [
                'subscription_id' => $stripeSubscription->id,
            ]);

            return;
        }

        $subscription->update([
            'status' => $stripeSubscription->status,

            'current_period_start' =>
                $this->timestampToDate(
                    $subscriptionItem->current_period_start ?? null
                ),

            'current_period_end' =>
                $this->timestampToDate(
                    $subscriptionItem->current_period_end ?? null
                ),

            'cancel_at_period_end' =>
                (bool) $stripeSubscription->cancel_at_period_end,

            'canceled_at' =>
                $this->timestampToDate(
                    $stripeSubscription->canceled_at ?? null
                ),

            'ended_at' =>
                $this->timestampToDate(
                    $stripeSubscription->ended_at ?? null
                ),

            'metadata' =>
                $stripeSubscription->toArray(),
        ]);

        Log::info('Subscription updated successfully', [
            'stripe_subscription_id' => $stripeSubscription->id,
            'status' => $stripeSubscription->status,
            'cancel_at_period_end' =>
                $stripeSubscription->cancel_at_period_end,
        ]);
    }

    private function getInvoiceSubscriptionId($invoice): ?string
    {
        if (
            isset($invoice->parent)
            && $invoice->parent->type === 'subscription_details'
            && !empty($invoice->parent->subscription_details->subscription)
        ) {
            return $invoice->parent->subscription_details->subscription;
        }

        // Fallback for pre-basil API versions
        return $invoice->subscription ?? null;
    }

    private function handleInvoicePaid($invoice)
    {
        $stripe = new StripeClient(config('services.stripe.secret'));

        $subscriptionId = $this->getInvoiceSubscriptionId($invoice);

        if (!$subscriptionId) {
            Log::info('Invoice not tied to a subscription, skipping', [
                'stripe_invoice_id' => $invoice->id,
            ]);

            return;
        }

        $subscription = Subscription::where(
            'stripe_subscription_id', $subscriptionId
        )->first();

        if (!$subscription) {
            $stripeSubscription = $stripe->subscriptions->retrieve($subscriptionId, []);
            $subscription = $this->upsertSubscriptionFromStripe($stripeSubscription);

            if (!$subscription) {
                Log::warning('Could not self-heal subscription for invoice', [
                    'stripe_subscription_id' => $subscriptionId,
                    'stripe_invoice_id' => $invoice->id,
                ]);
                return;
            }
        }

        $stripeSubscription = $stripe->subscriptions->retrieve($subscriptionId, []);
        $item = $stripeSubscription->items->data[0] ?? null;

        $subscription->update([
            'status' => $stripeSubscription->status,
            'current_period_start' => $this->timestampToDate($item->current_period_start ?? null),
            'current_period_end' => $this->timestampToDate($item->current_period_end ?? null),
            'cancel_at_period_end' => $stripeSubscription->cancel_at_period_end,
        ]);

        $this->upsertInvoiceFromStripe($invoice, $subscription);
    }

    private function upsertInvoiceFromStripe($invoice, Subscription $subscription, string $status = null)
    {
        return Invoice::updateOrCreate(
            ['stripe_invoice_id' => $invoice->id],
            [
                'user_id' => $subscription->user_id,
                'subscription_id' => $subscription->id,
                'stripe_customer_id' => $invoice->customer,
                'status' => $status ?? $invoice->status,

                'amount_due' => $invoice->amount_due ?? 0,
                'amount_paid' => $invoice->amount_paid ?? 0,
                'amount_remaining' => $invoice->amount_remaining ?? 0,

                'currency' => $invoice->currency,

                'invoice_created_at' => $this->timestampToDate($invoice->created ?? null),
                'due_date' => $this->timestampToDate($invoice->due_date ?? null),
                'paid_at' => $invoice->status === 'paid'
                    ? $this->timestampToDate($invoice->status_transitions->paid_at ?? $invoice->created ?? null)
                    : null,

                'hosted_invoice_url' => $invoice->hosted_invoice_url ?? null,
                'invoice_pdf' => $invoice->invoice_pdf ?? null,

                'metadata' => $invoice->toArray(),
            ]
        );
    }

    private function upsertSubscriptionFromStripe($stripeSubscription)
    {
        $userId = $stripeSubscription->metadata->user_id ?? null;
        $priceId = $stripeSubscription->metadata->price_id ?? null;

        if (!$userId || !$priceId) {
            Log::error('Subscription metadata missing', [
                'subscription_id' => $stripeSubscription->id,
            ]);
            return null;
        }

        $user = User::find($userId);
        $price = Price::find($priceId);

        if (!$user || !$price) {
            return null;
        }

        $subscriptionItem = $stripeSubscription->items->data[0] ?? null;

        if (!$subscriptionItem) {
            return null;
        }

        return Subscription::updateOrCreate(
            ['stripe_subscription_id' => $stripeSubscription->id],
            [
                'user_id' => $user->id,
                'price_id' => $price->id,
                'status' => $stripeSubscription->status,
                'current_period_start' => $this->timestampToDate($subscriptionItem->current_period_start ?? null),
                'current_period_end' => $this->timestampToDate($subscriptionItem->current_period_end ?? null),
                'trial_start' => $this->timestampToDate($stripeSubscription->trial_start ?? null),
                'trial_end' => $this->timestampToDate($stripeSubscription->trial_end ?? null),
                'cancel_at_period_end' => (bool) ($stripeSubscription->cancel_at_period_end ?? false),
                'canceled_at' => $this->timestampToDate($stripeSubscription->canceled_at ?? null),
                'ended_at' => $this->timestampToDate($stripeSubscription->ended_at ?? null),
                'metadata' => $stripeSubscription->toArray(),
            ]
        );
    }

    private function handleInvoicePaymentFailed($invoice)
    {
        $subscriptionId = $this->getInvoiceSubscriptionId($invoice);

        Log::warning('Stripe invoice.payment_failed received', [
            'invoice_id' => $invoice->id,
            'subscription_id' => $subscriptionId,
            'customer_id' => $invoice->customer,
        ]);

        if (!$subscriptionId) {
            Log::warning('Failed invoice has no subscription', [
                'invoice_id' => $invoice->id,
            ]);

            return;
        }

        $subscription = Subscription::where(
            'stripe_subscription_id',
            $subscriptionId
        )->first();

        if (!$subscription) {
            Log::warning('Local subscription not found', [
                'stripe_subscription_id' => $subscriptionId,
                'stripe_invoice_id' => $invoice->id,
            ]);

            return;
        }

        $stripe = new StripeClient(config('services.stripe.secret'));

        $stripeSubscription = $stripe->subscriptions->retrieve($subscriptionId, []);

        $subscription->update([
            'status' => $stripeSubscription->status,
            'cancel_at_period_end' => (bool) ($stripeSubscription->cancel_at_period_end ?? false),
            'canceled_at' => $this->timestampToDate($stripeSubscription->canceled_at ?? null),
            'ended_at' => $this->timestampToDate($stripeSubscription->ended_at ?? null),
            'metadata' => $stripeSubscription->toArray(),
        ]);

        $this->upsertInvoiceFromStripe($invoice, $subscription, 'open');

        // Fetch PaymentIntent failure details, if available
        if ($invoice->payment_intent ?? null) {
            $paymentIntent = $stripe->paymentIntents->retrieve($invoice->payment_intent, []);

            Log::warning('Stripe payment failed', [
                'invoice_id' => $invoice->id,
                'subscription_id' => $subscriptionId,
                'payment_intent_id' => $paymentIntent->id,
                'payment_status' => $paymentIntent->status,
                'error_code' => $paymentIntent->last_payment_error->code ?? null,
                'decline_code' => $paymentIntent->last_payment_error->decline_code ?? null,
                'error_message' => $paymentIntent->last_payment_error->message ?? null,
            ]);
        }
    }

    private function timestampToDate($timestamp)
    {
        return $timestamp
            ? now()->createFromTimestamp($timestamp)
            : null;
    }
}