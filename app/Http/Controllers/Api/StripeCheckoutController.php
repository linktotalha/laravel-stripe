<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Stripe\StripeClient;

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
}
