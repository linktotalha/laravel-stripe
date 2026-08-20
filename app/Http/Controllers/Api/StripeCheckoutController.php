<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\User;
use App\Services\StripeSyncService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class StripeCheckoutController extends Controller
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly StripeSyncService $sync,
    ) {}

    /**
     * Start a subscription checkout.
     *
     * price_id is the STRIPE price id ("price_..."), the same identifier used by
     * changePlan below.
     */
    public function create(Request $request)
    {
        $validated = $request->validate([
            'price_id' => [
                'required',
                'string',
                Rule::exists('prices', 'stripe_price_id')->where(
                    fn ($query) => $query->where('active', true)
                ),
            ],
        ]);

        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | One subscription per user
        |--------------------------------------------------------------------------
        |
        | Without this a double click creates two live subscriptions, and every
        | later lookup then has to guess which one is "the" subscription.
        */

        $existing = $this->sync->activeSubscriptionFor($user);

        if ($existing) {
            return response()->json([
                'message' => 'User already has an active subscription. Use change-plan instead.',
                'subscription' => $this->presentSubscription($existing),
            ], 409);
        }

        $price = Price::where('stripe_price_id', $validated['price_id'])->firstOrFail();

        try {
            $customerId = $this->ensureStripeCustomer($user);

            $session = $this->stripe->checkout->sessions->create([
                'customer' => $customerId,

                'mode' => 'subscription',

                'line_items' => [
                    [
                        'price' => $price->stripe_price_id,
                        'quantity' => 1,
                    ],
                ],

                // Stripe redirects the BROWSER here, so these must point at the
                // frontend, not at token-protected API routes.
                'success_url' => rtrim(config('services.stripe.success_url'), '/')
                    . '?session_id={CHECKOUT_SESSION_ID}',

                'cancel_url' => config('services.stripe.cancel_url'),

                'metadata' => [
                    'user_id' => (string) $user->id,
                    'price_id' => (string) $price->id,
                ],

                // Copied onto the subscription so the webhook can resolve the
                // owner even before the customer id lookup is available.
                'subscription_data' => [
                    'metadata' => [
                        'user_id' => (string) $user->id,
                        'price_id' => (string) $price->id,
                    ],
                ],
            ]);
        } catch (ApiErrorException $e) {
            return $this->stripeFailure('Unable to start checkout.', $e, [
                'user_id' => $user->id,
                'stripe_price_id' => $price->stripe_price_id,
            ]);
        }

        return response()->json([
            'message' => 'Checkout session created',
            'checkout_session_id' => $session->id,
            'checkout_url' => $session->url,
        ]);
    }

    /**
     * Confirm a completed checkout.
     *
     * The frontend success page calls this with the session_id from the URL.
     * The subscription itself is persisted by the webhook; this only reports
     * state back, so it stays correct even if the user closes the tab.
     */
    public function success(Request $request)
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string'],
        ]);

        $user = $request->user();

        try {
            $session = $this->stripe->checkout->sessions->retrieve(
                $validated['session_id'],
                ['expand' => ['subscription.items']]
            );
        } catch (ApiErrorException $e) {
            return $this->stripeFailure('Unable to retrieve checkout session.', $e, [
                'user_id' => $user->id,
                'session_id' => $validated['session_id'],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Authorization
        |--------------------------------------------------------------------------
        |
        | Session ids are guessable enough to be worth checking: only the
        | customer the session belongs to may read it.
        */

        $sessionCustomerId = is_string($session->customer)
            ? $session->customer
            : ($session->customer->id ?? null);

        if (! $user->stripe_customer_id || $sessionCustomerId !== $user->stripe_customer_id) {
            return response()->json([
                'message' => 'This checkout session does not belong to the current user.',
            ], 403);
        }

        if ($session->mode !== 'subscription') {
            return response()->json([
                'message' => 'This is not a subscription checkout session.',
            ], 422);
        }

        $stripeSubscription = $session->subscription;

        // Payment may still be processing; the webhook will finish the job.
        if (! $stripeSubscription) {
            return response()->json([
                'message' => 'Checkout completed but the subscription is not ready yet.',
                'checkout_session' => [
                    'id' => $session->id,
                    'status' => $session->status,
                    'payment_status' => $session->payment_status,
                ],
                'subscription' => null,
            ], 202);
        }

        // Sync here too so the response is correct even if the webhook is
        // still in flight. Both paths are idempotent.
        $local = $this->sync->syncSubscription($stripeSubscription, $session->id);

        return response()->json([
            'message' => 'Subscription checkout completed successfully.',
            'checkout_session' => [
                'id' => $session->id,
                'status' => $session->status,
                'payment_status' => $session->payment_status,
            ],
            'subscription' => $local ? $this->presentSubscription($local) : null,
        ]);
    }

    /**
     * Show the caller's current subscription.
     */
    public function show(Request $request)
    {
        $subscription = $this->sync->activeSubscriptionFor($request->user());

        if (! $subscription) {
            return response()->json([
                'message' => 'User does not have an active subscription.',
                'subscription' => null,
            ], 404);
        }

        return response()->json([
            'subscription' => $this->presentSubscription($subscription),
        ]);
    }

    /**
     * Upgrade or downgrade the current subscription.
     *
     * price_id is the STRIPE price id ("price_..."), matching create() above.
     */
    public function changePlan(Request $request)
    {
        $validated = $request->validate([
            'price_id' => [
                'required',
                'string',
                Rule::exists('prices', 'stripe_price_id')->where(
                    fn ($query) => $query->where('active', true)
                ),
            ],
        ]);

        $user = $request->user();

        $newPrice = Price::where('stripe_price_id', $validated['price_id'])->firstOrFail();

        $localSubscription = $this->sync->activeSubscriptionFor($user);

        if (! $localSubscription) {
            return response()->json([
                'message' => 'User does not have an active subscription.',
            ], 404);
        }

        $oldPriceId = $localSubscription->price_id;

        if ((int) $oldPriceId === (int) $newPrice->id) {
            return response()->json([
                'message' => 'User is already subscribed to this plan.',
            ], 422);
        }

        try {
            $stripeSubscription = $this->stripe->subscriptions->retrieve(
                $localSubscription->stripe_subscription_id
            );

            $subscriptionItem = $stripeSubscription->items->data[0] ?? null;

            if (! $subscriptionItem) {
                return response()->json([
                    'message' => 'Stripe subscription item not found.',
                ], 422);
            }

            $updatedSubscription = $this->stripe->subscriptions->update(
                $stripeSubscription->id,
                [
                    'items' => [
                        [
                            'id' => $subscriptionItem->id,
                            'price' => $newPrice->stripe_price_id,
                        ],
                    ],

                    // Bill the difference on the next invoice.
                    'proration_behavior' => 'create_prorations',

                    'metadata' => [
                        'user_id' => (string) $localSubscription->user_id,
                        'price_id' => (string) $newPrice->id,
                    ],
                ]
            );
        } catch (ApiErrorException $e) {
            return $this->stripeFailure('Unable to change subscription plan.', $e, [
                'user_id' => $user->id,
                'stripe_subscription_id' => $localSubscription->stripe_subscription_id,
            ]);
        }

        $localSubscription = $this->sync->syncSubscription($updatedSubscription)
            ?? $localSubscription->refresh();

        return response()->json([
            'message' => 'Subscription plan changed successfully.',
            'subscription' => $this->presentSubscription($localSubscription) + [
                'old_price_id' => $oldPriceId,
            ],
        ]);
    }

    /**
     * Cancel at the end of the current billing period.
     *
     * The status stays "active" until Stripe actually ends it and sends
     * customer.subscription.deleted.
     */
    public function cancelSubscription(Request $request)
    {
        $user = $request->user();

        $localSubscription = $this->sync->activeSubscriptionFor($user);

        if (! $localSubscription) {
            return response()->json([
                'message' => 'User does not have an active subscription.',
            ], 404);
        }

        if ($localSubscription->cancel_at_period_end) {
            return response()->json([
                'message' => 'Subscription is already scheduled for cancellation.',
                'subscription' => $this->presentSubscription($localSubscription),
            ], 422);
        }

        try {
            $updatedSubscription = $this->stripe->subscriptions->update(
                $localSubscription->stripe_subscription_id,
                ['cancel_at_period_end' => true]
            );
        } catch (ApiErrorException $e) {
            return $this->stripeFailure('Unable to cancel subscription.', $e, [
                'user_id' => $user->id,
                'stripe_subscription_id' => $localSubscription->stripe_subscription_id,
            ]);
        }

        $localSubscription = $this->sync->syncSubscription($updatedSubscription)
            ?? $localSubscription->refresh();

        return response()->json([
            'message' => 'Subscription will be canceled at the end of the current billing period.',
            'subscription' => $this->presentSubscription($localSubscription),
        ]);
    }

    /**
     * Undo a scheduled cancellation while the period is still running.
     */
    public function resumeSubscription(Request $request)
    {
        $user = $request->user();

        $localSubscription = $this->sync->activeSubscriptionFor($user);

        if (! $localSubscription) {
            return response()->json([
                'message' => 'User does not have an active subscription.',
            ], 404);
        }

        if (! $localSubscription->cancel_at_period_end) {
            return response()->json([
                'message' => 'Subscription is not scheduled for cancellation.',
                'subscription' => $this->presentSubscription($localSubscription),
            ], 422);
        }

        try {
            $updatedSubscription = $this->stripe->subscriptions->update(
                $localSubscription->stripe_subscription_id,
                ['cancel_at_period_end' => false]
            );
        } catch (ApiErrorException $e) {
            return $this->stripeFailure('Unable to resume subscription.', $e, [
                'user_id' => $user->id,
                'stripe_subscription_id' => $localSubscription->stripe_subscription_id,
            ]);
        }

        $localSubscription = $this->sync->syncSubscription($updatedSubscription)
            ?? $localSubscription->refresh();

        return response()->json([
            'message' => 'Subscription resumed.',
            'subscription' => $this->presentSubscription($localSubscription),
        ]);
    }

    /**
     * Create the Stripe customer on first use and remember it.
     */
    private function ensureStripeCustomer(User $user): string
    {
        if ($user->stripe_customer_id) {
            return $user->stripe_customer_id;
        }

        $customer = $this->stripe->customers->create([
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => [
                'user_id' => (string) $user->id,
            ],
        ]);

        $user->update(['stripe_customer_id' => $customer->id]);

        return $customer->id;
    }

    private function presentSubscription(Subscription $subscription): array
    {
        $subscription->loadMissing('price');

        return [
            'id' => $subscription->id,
            'stripe_subscription_id' => $subscription->stripe_subscription_id,
            'price_id' => $subscription->price_id,
            'stripe_price_id' => $subscription->price?->stripe_price_id,
            'status' => $subscription->status,
            'current_period_start' => $subscription->current_period_start,
            'current_period_end' => $subscription->current_period_end,
            'trial_start' => $subscription->trial_start,
            'trial_end' => $subscription->trial_end,
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'canceled_at' => $subscription->canceled_at,
            'ended_at' => $subscription->ended_at,
        ];
    }

    /**
     * Log the Stripe error, return a generic message.
     *
     * Stripe messages can name internal ids and account configuration, so they
     * do not belong in an API response.
     */
    private function stripeFailure(string $message, ApiErrorException $e, array $context)
    {
        Log::error($message, $context + ['error' => $e->getMessage()]);

        return response()->json(['message' => $message], 422);
    }
}
