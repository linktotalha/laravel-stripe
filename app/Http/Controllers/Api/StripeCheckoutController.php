<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Stripe\StripeClient;
use Illuminate\Support\Facades\Log;

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

    public function success(Request $request)
    {
        $request->validate([
            'session_id' => ['required', 'string'],
        ]);

        $stripe = new StripeClient(
            config('services.stripe.secret')
        );

        try {

            /*
            |--------------------------------------------------------------------------
            | Retrieve Checkout Session
            |--------------------------------------------------------------------------
            */

            $session = $stripe->checkout->sessions->retrieve(
                $request->session_id,
                [
                    'expand' => [
                        'subscription',
                        'customer',
                        'line_items.data.price.product',
                    ],
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Make sure this is a subscription checkout
            |--------------------------------------------------------------------------
            */

            if ($session->mode !== 'subscription') {

                return response()->json([
                    'success' => false,
                    'message' => 'This is not a subscription checkout session.',
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Get Subscription
            |--------------------------------------------------------------------------
            */

            $stripeSubscription = $session->subscription;

            /*
            |--------------------------------------------------------------------------
            | Find Local Subscription
            |--------------------------------------------------------------------------
            */

            $localSubscription = Subscription::where(
                'stripe_subscription_id',
                $stripeSubscription->id
            )->first();

            return response()->json([
                'success' => true,

                'message' => 'Subscription checkout completed successfully.',

                'data' => [

                    'checkout_session' => [
                        'id' => $session->id,

                        'status' => $session->status,

                        'payment_status' =>
                            $session->payment_status,
                    ],

                    'customer' => [
                        'id' => $session->customer->id,

                        'email' =>
                            $session->customer->email,
                    ],

                    'subscription' => [

                        'stripe_subscription_id' =>
                            $stripeSubscription->id,

                        'status' =>
                            $stripeSubscription->status,

                        'current_period_start' =>
                            $this->timestampToDate(
                                $stripeSubscription->current_period_start
                            ),

                        'current_period_end' =>
                            $this->timestampToDate(
                                $stripeSubscription->current_period_end
                            ),

                        'cancel_at_period_end' =>
                            $stripeSubscription->cancel_at_period_end,
                    ],

                    'local_subscription' =>
                        $localSubscription,
                ],
            ]);

        } catch (\Throwable $e) {

            Log::error(
                'Stripe success page error',
                [
                    'session_id' =>
                        $request->session_id,

                    'message' =>
                        $e->getMessage(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => 'Unable to retrieve checkout session.',
            ], 500);
        }
    }
}
