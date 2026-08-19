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
            // case 'checkout.session.completed':
            //     $this->handleCheckoutCompleted(
            //         $event->data->object
            //     );

            //     break;

            case 'customer.subscription.created':
                $this->handleSubscriptionCreated(
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

            // case 'invoice.paid':
            //     $this->handleInvoicePaid(
            //         $event->data->object
            //     );
            //     break;

            // case 'invoice.payment_failed':
            //     $this->handleInvoicePaymentFailed(
            //         $event->data->object
            //     );
            //     break;
        }

        return response()->json([
            'received' => true,
        ]);
    }

    private function handleSubscriptionCreated($stripeSubscription)
    {
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

    private function handleInvoicePaid($invoice)
    {
        $stripe = new StripeClient(
            config('services.stripe.secret')
        );

        if (!$invoice->subscription) {
            return;
        }

        $subscription = Subscription::where(
            'stripe_subscription_id',
            $invoice->subscription
        )->first();

        if (!$subscription) {
            Log::warning('Local subscription not found', [
                'stripe_subscription_id' => $invoice->subscription,
                'stripe_invoice_id' => $invoice->id,
            ]);

            return;
        }

        $stripeSubscription = $stripe->subscriptions->retrieve(
            $invoice->subscription,
            []
        );

        // Update subscription
        $subscription->update([
            'status' => $stripeSubscription->status,

            'current_period_start' => Carbon::createFromTimestamp(
                $stripeSubscription->current_period_start
            ),

            'current_period_end' => Carbon::createFromTimestamp(
                $stripeSubscription->current_period_end
            ),

            'cancel_at_period_end' =>
                $stripeSubscription->cancel_at_period_end,
        ]);

        // Create/update local invoice
        Invoice::updateOrCreate(
            [
                'stripe_invoice_id' => $invoice->id,
            ],
            [
                'user_id' => $subscription->user_id,
                'subscription_id' => $subscription->id,
                'stripe_customer_id' => $invoice->customer,
                'status' => $invoice->status,
                'amount' => $invoice->amount_paid,
                'currency' => $invoice->currency,
            ]
        );
    }

    private function handleInvoicePaymentFailed($invoice)
    {
        if (!$invoice->subscription) {
            return;
        }

        $subscription = Subscription::where(
            'stripe_subscription_id',
            $invoice->subscription
        )->first();

        if (!$subscription) {
            return;
        }

        $subscription->update([
            'status' => 'past_due',
        ]);

        Invoice::updateOrCreate(
            [
                'stripe_invoice_id' => $invoice->id,
            ],
            [
                'user_id' => $subscription->user_id,
                'subscription_id' => $subscription->id,
                'stripe_customer_id' => $invoice->customer,
                'status' => $invoice->status,
                'amount' => $invoice->amount_due,
                'currency' => $invoice->currency,
            ]
        );
    }

    private function timestampToDate($timestamp)
    {
        return $timestamp
            ? now()->createFromTimestamp($timestamp)
            : null;
    }
}