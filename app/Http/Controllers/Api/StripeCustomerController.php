<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Illuminate\Support\Facades\Log;

class StripeCustomerController extends Controller
{
    public function __construct(
        private readonly StripeClient $stripe,
    ) {}

    /**
     * Create the Stripe customer up front.
     *
     * Optional: checkout creates it on demand if it does not exist yet.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->stripe_customer_id) {
            return response()->json([
                'message' => 'Stripe customer already exists',
                'customer_id' => $user->stripe_customer_id,
            ]);
        }

        try {
            $customer = $this->stripe->customers->create([
                'email' => $user->email,
                'name' => $user->name,
                'metadata' => [
                    'user_id' => (string) $user->id,
                ],
            ]);
        } catch (ApiErrorException $e) {
            Log::error('Unable to create Stripe customer.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to create Stripe customer.',
            ], 422);
        }

        $user->update(['stripe_customer_id' => $customer->id]);

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
