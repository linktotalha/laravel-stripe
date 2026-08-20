<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Stripe\Invoice as StripeInvoice;
use Stripe\Subscription as StripeSubscription;

/**
 * Maps Stripe objects onto local records.
 *
 * All Stripe -> database writes go through here so the webhook and the
 * synchronous endpoints can never drift apart.
 *
 * NOTE ON API VERSION: stripe-php v21 pins API version 2026-07-29.dahlia.
 * Two fields moved in 2025-04-30.basil and are read accordingly below:
 *
 *   Subscription::$current_period_{start,end}  ->  SubscriptionItem
 *   Invoice::$subscription                     ->  Invoice::$parent
 *                                                  ->subscription_details
 *                                                  ->subscription
 */
class StripeSyncService
{
    /**
     * Statuses a subscription can never move back out of.
     *
     * Stripe delivers webhooks out of order, so a late "active" event must not
     * resurrect a subscription that has already ended.
     */
    private const TERMINAL_STATUSES = [
        'canceled',
        'incomplete_expired',
    ];

    /**
     * Statuses that count as the user currently holding the plan.
     */
    public const ACTIVE_STATUSES = [
        'active',
        'trialing',
        'past_due',
        'unpaid',
    ];

    /**
     * Create or update the local subscription from a Stripe subscription.
     */
    public function syncSubscription(
        StripeSubscription $stripeSubscription,
        ?string $checkoutSessionId = null
    ): ?Subscription {
        $item = $stripeSubscription->items->data[0] ?? null;

        if (! $item) {
            Log::error('Stripe subscription has no items', [
                'stripe_subscription_id' => $stripeSubscription->id,
            ]);

            return null;
        }

        $user = $this->resolveUser($stripeSubscription);

        if (! $user) {
            Log::error('Cannot resolve user for Stripe subscription', [
                'stripe_subscription_id' => $stripeSubscription->id,
                'stripe_customer_id' => $this->idOf($stripeSubscription->customer),
            ]);

            return null;
        }

        $price = $this->resolvePrice($stripeSubscription, $item);

        if (! $price) {
            Log::error('Cannot resolve local price for Stripe subscription', [
                'stripe_subscription_id' => $stripeSubscription->id,
                'stripe_price_id' => $this->idOf($item->price ?? null),
            ]);

            return null;
        }

        $existing = Subscription::where(
            'stripe_subscription_id',
            $stripeSubscription->id
        )->first();

        // Out-of-order webhook: never reopen a subscription that already ended.
        if (
            $existing
            && in_array($existing->status, self::TERMINAL_STATUSES, true)
            && ! in_array($stripeSubscription->status, self::TERMINAL_STATUSES, true)
        ) {
            Log::info('Ignoring stale Stripe subscription event', [
                'stripe_subscription_id' => $stripeSubscription->id,
                'local_status' => $existing->status,
                'event_status' => $stripeSubscription->status,
            ]);

            return $existing;
        }

        $attributes = [
            'user_id' => $user->id,
            'price_id' => $price->id,
            'status' => $stripeSubscription->status,

            // Period lives on the subscription ITEM since API 2025-04-30.basil.
            'current_period_start' => $this->toDate($item->current_period_start ?? null),
            'current_period_end' => $this->toDate($item->current_period_end ?? null),

            'trial_start' => $this->toDate($stripeSubscription->trial_start ?? null),
            'trial_end' => $this->toDate($stripeSubscription->trial_end ?? null),

            'cancel_at_period_end' => (bool) ($stripeSubscription->cancel_at_period_end ?? false),
            'canceled_at' => $this->toDate($stripeSubscription->canceled_at ?? null),
            'ended_at' => $this->toDate($stripeSubscription->ended_at ?? null),

            'metadata' => $stripeSubscription->toArray(),
        ];

        // Only ever set the checkout session id; never null out a known one.
        if ($checkoutSessionId) {
            $attributes['stripe_checkout_session_id'] = $checkoutSessionId;
        }

        $subscription = Subscription::updateOrCreate(
            ['stripe_subscription_id' => $stripeSubscription->id],
            $attributes
        );

        Log::info('Subscription synced from Stripe', [
            'subscription_id' => $subscription->id,
            'stripe_subscription_id' => $stripeSubscription->id,
            'status' => $subscription->status,
        ]);

        return $subscription;
    }

    /**
     * Create or update the local invoice row.
     */
    public function syncInvoice(
        StripeInvoice $stripeInvoice,
        Subscription $subscription
    ): Invoice {
        return Invoice::updateOrCreate(
            ['stripe_invoice_id' => $stripeInvoice->id],
            [
                'user_id' => $subscription->user_id,
                'subscription_id' => $subscription->id,
                'stripe_customer_id' => $this->idOf($stripeInvoice->customer),

                'status' => $stripeInvoice->status,

                // Column names must match the schema exactly: a stray key such
                // as "amount" is silently dropped by mass assignment.
                'amount_due' => $stripeInvoice->amount_due ?? 0,
                'amount_paid' => $stripeInvoice->amount_paid ?? 0,
                'amount_remaining' => $stripeInvoice->amount_remaining ?? 0,

                'currency' => $stripeInvoice->currency,

                'invoice_created_at' => $this->toDate($stripeInvoice->created ?? null),
                'due_date' => $this->toDate($stripeInvoice->due_date ?? null),
                'paid_at' => $this->toDate(
                    $stripeInvoice->status_transitions->paid_at ?? null
                ),

                'hosted_invoice_url' => $stripeInvoice->hosted_invoice_url,
                'invoice_pdf' => $stripeInvoice->invoice_pdf,

                'metadata' => $stripeInvoice->toArray(),
            ]
        );
    }

    /**
     * Pull the subscription id off an invoice.
     *
     * Invoice::$subscription was removed in API 2025-04-30.basil. Reading it
     * returns null rather than throwing, which silently disables any handler
     * that guards on it.
     */
    public function subscriptionIdFromInvoice(StripeInvoice $stripeInvoice): ?string
    {
        $details = $stripeInvoice->parent->subscription_details ?? null;

        return $this->idOf($details->subscription ?? null);
    }

    /**
     * The subscription the user currently holds, if any.
     */
    public function activeSubscriptionFor(User $user): ?Subscription
    {
        return Subscription::where('user_id', $user->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->latest('id')
            ->first();
    }

    /**
     * Resolve the owning user, preferring the Stripe customer id over metadata
     * so subscriptions created outside our checkout flow still land.
     */
    private function resolveUser(StripeSubscription $stripeSubscription): ?User
    {
        $customerId = $this->idOf($stripeSubscription->customer);

        if ($customerId) {
            $user = User::where('stripe_customer_id', $customerId)->first();

            if ($user) {
                return $user;
            }
        }

        $userId = $stripeSubscription->metadata->user_id ?? null;

        return $userId ? User::find($userId) : null;
    }

    /**
     * Resolve the local price from the subscription item, falling back to the
     * metadata we stamp at checkout.
     */
    private function resolvePrice(
        StripeSubscription $stripeSubscription,
        $item
    ): ?Price {
        $stripePriceId = $this->idOf($item->price ?? null);

        if ($stripePriceId) {
            $price = Price::where('stripe_price_id', $stripePriceId)->first();

            if ($price) {
                return $price;
            }
        }

        $priceId = $stripeSubscription->metadata->price_id ?? null;

        return $priceId ? Price::find($priceId) : null;
    }

    /**
     * Accept either an id string or an expanded Stripe object.
     */
    private function idOf($value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        return isset($value->id) ? (string) $value->id : null;
    }

    private function toDate($timestamp): ?Carbon
    {
        return $timestamp ? Carbon::createFromTimestampUTC($timestamp) : null;
    }
}
