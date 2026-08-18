<?php

namespace App\Http\Controllers\Api;

use App\Models\Price;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Stripe\StripeClient;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

class StripeCheckoutController extends Controller
{
    public function create(Request $request)
    {
        $validated = $request->validate([
            'price_id' => ['required', 'string'],
        ]);

        $user = $request->user();

        $stripe = new StripeClient(
            config('services.stripe.secret')
        );

        /*
        |--------------------------------------------------------------------------
        | Make sure customer exists
        |--------------------------------------------------------------------------
        */

        // $existingSubscription = Subscription::where('user_id', $user->id)
        //     ->whereIn('status', [
        //         'active',
        //         'trialing',
        //         'past_due',
        //         'unpaid',
        //     ])
        //     ->first();

        // if ($existingSubscription) {
        //     return response()->json([
        //         'message' => 'User already has an active subscription.',
        //         'subscription' => $existingSubscription,
        //     ], 409);
        // }

        if (!$user->stripe_customer_id) {

            $customer = $stripe->customers->create([
                'email' => $user->email,

                'name' => $user->name,

                'metadata' => [
                    'user_id' =>
                        (string) $user->id,
                ],
            ]);

            $user->update([
                'stripe_customer_id' =>
                    $customer->id,
            ]);
        }

        $price = Price::where(
            'stripe_price_id',
            $validated['price_id']
        )->firstOrFail();


        /*
        |--------------------------------------------------------------------------
        | Create Checkout Session
        |--------------------------------------------------------------------------
        */

        $session = $stripe->checkout->sessions->create([

            'customer' =>
                $user->stripe_customer_id,

            'mode' => 'subscription',

            'line_items' => [
                [
                    'price' =>
                        $validated['price_id'],

                    'quantity' => 1,
                ],
            ],

            'success_url' =>
                config('app.url')
                . '/subscription/success'
                . '?session_id={CHECKOUT_SESSION_ID}',

            'cancel_url' =>
                config('app.url')
                . '/subscription/cancel',

            'metadata' => [
                'user_id' =>
                    (string) $user->id,
                'price_id' => (string) $price->id
            ],
            'subscription_data' => [
                'metadata' => [
                    'user_id' => (string) $user->id,
                    'price_id' => (string) $price->id
                ],
            ],
        ]);

        return response()->json([
            'message' =>
                'Checkout session created',

            'checkout_session_id' =>
                $session->id,

            'checkout_url' =>
                $session->url,
        ]);
    }

    // public function success(Request $request)
    // {
    //     $request->validate([
    //         'session_id' => ['required', 'string'],
    //     ]);

    //     $stripe = new StripeClient(
    //         config('services.stripe.secret')
    //     );

    //     try {

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Retrieve Checkout Session
    //         |--------------------------------------------------------------------------
    //         */

    //         $session = $stripe->checkout->sessions->retrieve(
    //             $request->session_id,
    //             [
    //                 'expand' => [
    //                     'subscription',
    //                     'customer',
    //                     'line_items.data.price.product',
    //                 ],
    //             ]
    //         );

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Make sure this is a subscription checkout
    //         |--------------------------------------------------------------------------
    //         */

    //         if ($session->mode !== 'subscription') {

    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'This is not a subscription checkout session.',
    //             ], 422);
    //         }

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Get Subscription
    //         |--------------------------------------------------------------------------
    //         */

    //         $stripeSubscription = $session->subscription;

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Find Local Subscription
    //         |--------------------------------------------------------------------------
    //         */

    //         $localSubscription = Subscription::where(
    //             'stripe_subscription_id',
    //             $stripeSubscription->id
    //         )->first();

    //         return response()->json([
    //             'success' => true,

    //             'message' => 'Subscription checkout completed successfully.',

    //             'data' => [

    //                 'checkout_session' => [
    //                     'id' => $session->id,

    //                     'status' => $session->status,

    //                     'payment_status' =>
    //                         $session->payment_status,
    //                 ],

    //                 'customer' => [
    //                     'id' => $session->customer->id,

    //                     'email' =>
    //                         $session->customer->email,
    //                 ],

    //                 'subscription' => [

    //                     'stripe_subscription_id' =>
    //                         $stripeSubscription->id,

    //                     'status' =>
    //                         $stripeSubscription->status,

    //                     'current_period_start' =>
    //                         $this->timestampToDate(
    //                             $stripeSubscription->current_period_start
    //                         ),

    //                     'current_period_end' =>
    //                         $this->timestampToDate(
    //                             $stripeSubscription->current_period_end
    //                         ),

    //                     'cancel_at_period_end' =>
    //                         $stripeSubscription->cancel_at_period_end,
    //                 ],

    //                 'local_subscription' =>
    //                     $localSubscription,
    //             ],
    //         ]);

    //     } catch (\Throwable $e) {

    //         Log::error(
    //             'Stripe success page error',
    //             [
    //                 'session_id' =>
    //                     $request->session_id,

    //                 'message' =>
    //                     $e->getMessage(),
    //             ]
    //         );

    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Unable to retrieve checkout session.',
    //         ], 500);
    //     }
    // }

    public function changePlan(Request $request)
    {
        $request->validate([
            'price_id' => ['required', 'exists:prices,id'],
        ]);

        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Get new price
        |--------------------------------------------------------------------------
        */

        $newPrice = Price::where('id', $request->price_id)
            ->where('active', true)
            ->firstOrFail();

        /*
        |--------------------------------------------------------------------------
        | Get current local subscription
        |--------------------------------------------------------------------------
        */

        $localSubscription = Subscription::where('user_id', $user->id)
            ->whereIn('status', [
                'active',
                'trialing',
                'past_due',
            ])
            ->first();

        if (!$localSubscription) {
            return response()->json([
                'message' => 'User does not have an active subscription.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Old price
        |--------------------------------------------------------------------------
        */

        $oldPriceId = $localSubscription->price_id;

        if ($oldPriceId == $newPrice->id) {
            return response()->json([
                'message' => 'User is already subscribed to this plan.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Stripe client
        |--------------------------------------------------------------------------
        */

        $stripe = new StripeClient(
            config('services.stripe.secret')
        );

        try {

            /*
            |--------------------------------------------------------------------------
            | Get Stripe subscription
            |--------------------------------------------------------------------------
            */

            $stripeSubscription = $stripe->subscriptions->retrieve(
                $localSubscription->stripe_subscription_id,
                []
            );

            /*
            |--------------------------------------------------------------------------
            | Get current subscription item
            |--------------------------------------------------------------------------
            */

            $subscriptionItem =
                $stripeSubscription->items->data[0] ?? null;

            if (!$subscriptionItem) {
                return response()->json([
                    'message' => 'Stripe subscription item not found.',
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Change price
            |--------------------------------------------------------------------------
            */

            $updatedSubscription = $stripe->subscriptions->update(
                $stripeSubscription->id,
                [
                    'items' => [
                        [
                            'id' => $subscriptionItem->id,
                            'price' => $newPrice->stripe_price_id,
                        ],
                    ],

                    /*
                    |--------------------------------------------------------------------------
                    | Upgrade / downgrade proration
                    |--------------------------------------------------------------------------
                    */

                    'proration_behavior' => 'create_prorations',

                    /*
                    |--------------------------------------------------------------------------
                    | Stripe subscription metadata
                    |--------------------------------------------------------------------------
                    */

                    'metadata' => [
                        'user_id' =>
                            (string) $localSubscription->user_id,

                        'local_subscription_id' =>
                            (string) $localSubscription->id,

                        'price_id' =>
                            (string) $newPrice->id,

                        'stripe_price_id' =>
                            (string) $newPrice->stripe_price_id,
                    ],
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Get UPDATED subscription item
            |--------------------------------------------------------------------------
            */

            $updatedItem =
                $updatedSubscription->items->data[0] ?? null;

            if (!$updatedItem) {
                return response()->json([
                    'message' => 'Updated Stripe subscription item not found.',
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Stripe period values
            |--------------------------------------------------------------------------
            |
            | According to your Stripe response:
            |
            | current_period_start
            | current_period_end
            |
            | are inside the subscription item.
            |
            */

            $periodStart = !empty($updatedItem->current_period_start)
                ? date(
                    'Y-m-d H:i:s',
                    $updatedItem->current_period_start
                )
                : null;

            $periodEnd = !empty($updatedItem->current_period_end)
                ? date(
                    'Y-m-d H:i:s',
                    $updatedItem->current_period_end
                )
                : null;

            /*
            |--------------------------------------------------------------------------
            | Update local database
            |--------------------------------------------------------------------------
            */

            DB::transaction(function () use (
                $localSubscription,
                $newPrice,
                $updatedSubscription,
                $periodStart,
                $periodEnd
            ) {

                $localSubscription->price_id = $newPrice->id;

                $localSubscription->status =
                    $updatedSubscription->status;

                $localSubscription->current_period_start =
                    $periodStart;

                $localSubscription->current_period_end =
                    $periodEnd;

                $localSubscription->trial_start =
                    !empty($updatedSubscription->trial_start)
                        ? date(
                            'Y-m-d H:i:s',
                            $updatedSubscription->trial_start
                        )
                        : null;

                $localSubscription->trial_end =
                    !empty($updatedSubscription->trial_end)
                        ? date(
                            'Y-m-d H:i:s',
                            $updatedSubscription->trial_end
                        )
                        : null;

                $localSubscription->cancel_at_period_end =
                    (bool) $updatedSubscription->cancel_at_period_end;

                $localSubscription->canceled_at =
                    !empty($updatedSubscription->canceled_at)
                        ? date(
                            'Y-m-d H:i:s',
                            $updatedSubscription->canceled_at
                        )
                        : null;

                $localSubscription->ended_at =
                    !empty($updatedSubscription->ended_at)
                        ? date(
                            'Y-m-d H:i:s',
                            $updatedSubscription->ended_at
                        )
                        : null;

                /*
                |--------------------------------------------------------------------------
                | Store Stripe snapshot
                |--------------------------------------------------------------------------
                */

                $localSubscription->metadata =
                    $updatedSubscription->toArray();

                /*
                |--------------------------------------------------------------------------
                | IMPORTANT: explicitly save
                |--------------------------------------------------------------------------
                */

                $localSubscription->save();
            });

            /*
            |--------------------------------------------------------------------------
            | Reload from database
            |--------------------------------------------------------------------------
            */

            $localSubscription->refresh();

            /*
            |--------------------------------------------------------------------------
            | Log actual DB values
            |--------------------------------------------------------------------------
            */

            Log::info('Local subscription saved after plan change', [
                'subscription_id' =>
                    $localSubscription->id,

                'price_id' =>
                    $localSubscription->price_id,

                'status' =>
                    $localSubscription->status,

                'current_period_start' =>
                    $localSubscription->current_period_start,

                'current_period_end' =>
                    $localSubscription->current_period_end,

                'cancel_at_period_end' =>
                    $localSubscription->cancel_at_period_end,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'message' =>
                    'Subscription plan changed successfully.',

                'subscription' => [
                    'id' =>
                        $localSubscription->id,

                    'old_price_id' =>
                        $oldPriceId,

                    'new_price_id' =>
                        $localSubscription->price_id,

                    'stripe_subscription_id' =>
                        $updatedSubscription->id,

                    'stripe_price_id' =>
                        $updatedItem->price->id,

                    'status' =>
                        $localSubscription->status,

                    'current_period_start' =>
                        $localSubscription->current_period_start,

                    'current_period_end' =>
                        $localSubscription->current_period_end,

                    'cancel_at_period_end' =>
                        $localSubscription->cancel_at_period_end,

                    'canceled_at' =>
                        $localSubscription->canceled_at,

                    'ended_at' =>
                        $localSubscription->ended_at,
                ],
            ]);

        } catch (\Stripe\Exception\ApiErrorException $e) {

            Log::error('Stripe subscription update failed', [
                'user_id' =>
                    $user->id,

                'stripe_subscription_id' =>
                    $localSubscription->stripe_subscription_id,

                'error' =>
                    $e->getMessage(),
            ]);

            return response()->json([
                'message' =>
                    'Unable to change subscription plan.',

                'error' =>
                    $e->getMessage(),
            ], 422);
        }
    }

    public function cancelSubscription(Request $request)
    {
        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Find local subscription
        |--------------------------------------------------------------------------
        */

        $localSubscription = Subscription::where('user_id', $user->id)
            ->whereIn('status', [
                'active',
                'trialing',
                'past_due',
            ])
            ->first();

        if (!$localSubscription) {
            return response()->json([
                'message' => 'User does not have an active subscription.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Already scheduled for cancellation
        |--------------------------------------------------------------------------
        */

        if ($localSubscription->cancel_at_period_end) {
            return response()->json([
                'message' =>
                    'Subscription is already scheduled for cancellation.',

                'subscription' => [
                    'id' =>
                        $localSubscription->id,

                    'stripe_subscription_id' =>
                        $localSubscription->stripe_subscription_id,

                    'status' =>
                        $localSubscription->status,

                    'cancel_at_period_end' =>
                        true,

                    'current_period_end' =>
                        $localSubscription->current_period_end,
                ],
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Stripe client
        |--------------------------------------------------------------------------
        */

        $stripe = new StripeClient(
            config('services.stripe.secret')
        );

        try {

            /*
            |--------------------------------------------------------------------------
            | Cancel subscription at period end
            |--------------------------------------------------------------------------
            */

            $updatedSubscription = $stripe->subscriptions->update(
                $localSubscription->stripe_subscription_id,
                [
                    'cancel_at_period_end' => true,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Get updated subscription item
            |--------------------------------------------------------------------------
            |
            | In your Stripe response, current_period_start and
            | current_period_end are available on the subscription item.
            |
            */

            $subscriptionItem =
                $updatedSubscription->items->data[0] ?? null;

            /*
            |--------------------------------------------------------------------------
            | Current period end
            |--------------------------------------------------------------------------
            */

            $currentPeriodEnd = null;

            if (
                $subscriptionItem &&
                !empty($subscriptionItem->current_period_end)
            ) {
                $currentPeriodEnd = date(
                    'Y-m-d H:i:s',
                    $subscriptionItem->current_period_end
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Update local database
            |--------------------------------------------------------------------------
            */

            DB::transaction(function () use (
                $localSubscription,
                $updatedSubscription,
                $currentPeriodEnd
            ) {

                /*
                | Do NOT change status to canceled here.
                |
                | The subscription is still active until the
                | current billing period ends.
                */

                $localSubscription->status =
                    $updatedSubscription->status;

                $localSubscription->cancel_at_period_end =
                    (bool) $updatedSubscription->cancel_at_period_end;

                $localSubscription->current_period_end =
                    $currentPeriodEnd;

                $localSubscription->canceled_at =
                    !empty($updatedSubscription->canceled_at)
                        ? date(
                            'Y-m-d H:i:s',
                            $updatedSubscription->canceled_at
                        )
                        : null;

                /*
                |--------------------------------------------------------------------------
                | Save Stripe response
                |--------------------------------------------------------------------------
                */

                $localSubscription->metadata =
                    $updatedSubscription->toArray();

                $localSubscription->save();
            });

            /*
            |--------------------------------------------------------------------------
            | Reload from database
            |--------------------------------------------------------------------------
            */

            $localSubscription->refresh();

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'message' =>
                    'Subscription will be canceled at the end of the current billing period.',

                'subscription' => [

                    'id' =>
                        $localSubscription->id,

                    'stripe_subscription_id' =>
                        $localSubscription->stripe_subscription_id,

                    'status' =>
                        $localSubscription->status,

                    'cancel_at_period_end' =>
                        $localSubscription->cancel_at_period_end,

                    'canceled_at' =>
                        $localSubscription->canceled_at,

                    'current_period_end' =>
                        $localSubscription->current_period_end,
                ],
            ]);

        } catch (\Stripe\Exception\ApiErrorException $e) {

            Log::error('Stripe subscription cancellation failed', [
                'user_id' =>
                    $user->id,

                'local_subscription_id' =>
                    $localSubscription->id,

                'stripe_subscription_id' =>
                    $localSubscription->stripe_subscription_id,

                'error' =>
                    $e->getMessage(),
            ]);

            return response()->json([
                'message' =>
                    'Unable to cancel subscription.',

                'error' =>
                    $e->getMessage(),
            ], 422);
        }
    }
}
