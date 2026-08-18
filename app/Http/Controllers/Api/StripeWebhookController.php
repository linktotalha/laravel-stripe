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

    // private function handleCheckoutCompleted($session)
    // {
    //     if ($session->mode !== 'subscription') {
    //         return;
    //     }

    //     $stripeSubscriptionId = $session->subscription;

    //     if (!$stripeSubscriptionId) {
    //         Log::warning('Checkout completed without subscription', [
    //             'session_id' => $session->id,
    //         ]);

    //         return;
    //     }

    //     $userId = $session->metadata->user_id ?? null;
    //     $priceId = $session->metadata->price_id ?? null;

    //     if (!$userId || !$priceId) {
    //         Log::error('Checkout metadata missing', [
    //             'session_id' => $session->id,
    //         ]);

    //         return;
    //     }

    //     $user = User::find($userId);
    //     $price = Price::find($priceId);

    //     if (!$user || !$price) {
    //         Log::error('User or price not found', [
    //             'user_id' => $userId,
    //             'price_id' => $priceId,
    //         ]);

    //         return;
    //     }

    //     Subscription::updateOrCreate(
    //         [
    //             'stripe_subscription_id' => $stripeSubscriptionId,
    //         ],
    //         [
    //             'user_id' => $user->id,
    //             'price_id' => $price->id,
    //             'stripe_checkout_session_id' => $session->id,
    //             'status' => 'active',
    //         ]
    //     );
    // }

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

    // private function handleInvoicePaid($invoice)
    // {
    //     $stripeSubscriptionId =
    //         $invoice->parent?->subscription_details?->subscription;

    //     if (!$stripeSubscriptionId) {
    //         Log::warning('Invoice does not contain subscription ID', [
    //             'invoice_id' => $invoice->id,
    //         ]);

    //         return;
    //     }

    //     $subscription = Subscription::where(
    //         'stripe_subscription_id',
    //         $stripeSubscriptionId
    //     )->first();

    //     if (!$subscription) {
    //         Log::warning('Subscription not found yet. Invoice will be retried.', [
    //             'invoice_id' => $invoice->id,
    //             'stripe_subscription_id' => $stripeSubscriptionId,
    //         ]);

    //         // Important: don't save the invoice here.
    //         // Throwing causes the webhook/job to fail and can be retried.
    //         throw new \RuntimeException(
    //             "Subscription {$stripeSubscriptionId} not found"
    //         );
    //     }

    //     Invoice::updateOrCreate(
    //         [
    //             'stripe_invoice_id' => $invoice->id,
    //         ],
    //         [
    //             'user_id' => $subscription->user_id,
    //             'subscription_id' => $subscription->id,

    //             'stripe_customer_id' => $invoice->customer,

    //             'status' => $invoice->status,

    //             'amount_due' => $invoice->amount_due ?? 0,
    //             'amount_paid' => $invoice->amount_paid ?? 0,
    //             'amount_remaining' => $invoice->amount_remaining ?? 0,

    //             'currency' => $invoice->currency,

    //             'invoice_created_at' =>
    //                 $this->timestampToDate($invoice->created ?? null),

    //             'due_date' =>
    //                 $this->timestampToDate($invoice->due_date ?? null),

    //             'paid_at' =>
    //                 $this->timestampToDate(
    //                     $invoice->status_transitions?->paid_at
    //                 ),

    //             'hosted_invoice_url' =>
    //                 $invoice->hosted_invoice_url,

    //             'invoice_pdf' =>
    //                 $invoice->invoice_pdf,

    //             'metadata' =>
    //                 $invoice->toArray(),
    //         ]
    //     );

    //     Log::info('Invoice saved successfully', [
    //         'invoice_id' => $invoice->id,
    //         'subscription_id' => $subscription->id,
    //         'stripe_subscription_id' => $stripeSubscriptionId,
    //     ]);
    // }

    private function timestampToDate($timestamp)
    {
        return $timestamp
            ? now()->createFromTimestamp($timestamp)
            : null;
    }
}