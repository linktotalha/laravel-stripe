<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Stripe\StripeClient;

class StripeCustomerController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->stripe_customer_id) {

            return response()->json([
                'message' => 'Stripe customer already exists',

                'customer_id' =>
                    $user->stripe_customer_id,
            ]);
        }

        $stripe = new StripeClient(
            config('services.stripe.secret')
        );

        $customer = $stripe->customers->create([
            'email' => $user->email,

            'name' => $user->name,

            'metadata' => [
                'user_id' => (string) $user->id,
            ],
        ]);

        $user->update([
            'stripe_customer_id' =>
                $customer->id,
        ]);

        return response()->json([
            'message' => 'Customer created successfully',

            'customer' => [
                'id' => $customer->id,
                'email' => $customer->email,
                'name' => $customer->name,
            ],
        ], 201);
    }
}
