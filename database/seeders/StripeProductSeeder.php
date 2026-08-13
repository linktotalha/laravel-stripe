<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Stripe\StripeClient;


class StripeProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $stripe = new StripeClient(
            config('services.stripe.secret')
        );

        $plans = [
            [
                'name' => 'Basic Plan',
                'description' => 'Basic monthly subscription',
                'amount' => 1000,
                'currency' => 'usd',
                'interval' => 'month',
            ],

            [
                'name' => 'Pro Plan',
                'description' => 'Professional monthly subscription',
                'amount' => 2500,
                'currency' => 'usd',
                'interval' => 'month',
            ],

            [
                'name' => 'Premium Plan',
                'description' => 'Premium monthly subscription',
                'amount' => 5000,
                'currency' => 'usd',
                'interval' => 'month',
            ],
        ];

        foreach ($plans as $planData) {

            /*
            |--------------------------------------------------------------------------
            | 1. Create Stripe Product
            |--------------------------------------------------------------------------
            */

            $product = $stripe->products->create([
                'name' => $planData['name'],

                'description' =>
                    $planData['description'],

                'active' => true,
            ]);

            /*
            |--------------------------------------------------------------------------
            | 2. Create Stripe Price
            |--------------------------------------------------------------------------
            */

            $price = $stripe->prices->create([
                'product' => $product->id,

                'unit_amount' =>
                    $planData['amount'],

                'currency' =>
                    $planData['currency'],

                'recurring' => [
                    'interval' =>
                        $planData['interval'],
                ],
            ]);

            /*
            |--------------------------------------------------------------------------
            | 3. Save Product + Price in Local Database
            |--------------------------------------------------------------------------
            */

            $plan = Plan::create([
                'name' =>
                    $planData['name'],

                'description' =>
                    $planData['description'],

                'stripe_product_id' =>
                    $product->id,

                'stripe_price_id' =>
                    $price->id,

                'amount' =>
                    $planData['amount'],

                'currency' =>
                    $planData['currency'],

                'interval' =>
                    $planData['interval'],

                'active' => true,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Console Output
            |--------------------------------------------------------------------------
            */

            $this->command->info(
                "Plan created: {$plan->name}"
            );

            $this->command->info(
                "Local Plan ID: {$plan->id}"
            );

            $this->command->info(
                "Stripe Product: {$product->id}"
            );

            $this->command->info(
                "Stripe Price: {$price->id}"
            );

            $this->command->newLine();
        }
    }
}
