<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Product;
use App\Models\Price;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Stripe\StripeClient;


class StripeProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    // public function run(): void
    // {
    //     $stripe = new StripeClient(
    //         config('services.stripe.secret')
    //     );

    //     $plans = [
    //         [
    //             'name' => 'Basic Plan',
    //             'description' => 'Basic monthly subscription',
    //             'amount' => 1000,
    //             'currency' => 'usd',
    //             'interval' => 'month',
    //         ],

    //         [
    //             'name' => 'Pro Plan',
    //             'description' => 'Professional monthly subscription',
    //             'amount' => 2500,
    //             'currency' => 'usd',
    //             'interval' => 'month',
    //         ],

    //         [
    //             'name' => 'Premium Plan',
    //             'description' => 'Premium monthly subscription',
    //             'amount' => 5000,
    //             'currency' => 'usd',
    //             'interval' => 'month',
    //         ],
    //     ];

    //     foreach ($plans as $planData) {

    //         /*
    //         |--------------------------------------------------------------------------
    //         | 1. Create Stripe Product
    //         |--------------------------------------------------------------------------
    //         */

    //         $product = $stripe->products->create([
    //             'name' => $planData['name'],

    //             'description' =>
    //                 $planData['description'],

    //             'active' => true,
    //         ]);

    //         /*
    //         |--------------------------------------------------------------------------
    //         | 2. Create Stripe Price
    //         |--------------------------------------------------------------------------
    //         */

    //         $price = $stripe->prices->create([
    //             'product' => $product->id,

    //             'unit_amount' =>
    //                 $planData['amount'],

    //             'currency' =>
    //                 $planData['currency'],

    //             'recurring' => [
    //                 'interval' =>
    //                     $planData['interval'],
    //             ],
    //         ]);

    //         /*
    //         |--------------------------------------------------------------------------
    //         | 3. Save Product + Price in Local Database
    //         |--------------------------------------------------------------------------
    //         */

    //         $plan = Plan::create([
    //             'name' =>
    //                 $planData['name'],

    //             'description' =>
    //                 $planData['description'],

    //             'stripe_product_id' =>
    //                 $product->id,

    //             'stripe_price_id' =>
    //                 $price->id,

    //             'amount' =>
    //                 $planData['amount'],

    //             'currency' =>
    //                 $planData['currency'],

    //             'interval' =>
    //                 $planData['interval'],

    //             'active' => true,
    //         ]);

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Console Output
    //         |--------------------------------------------------------------------------
    //         */

    //         $this->command->info(
    //             "Plan created: {$plan->name}"
    //         );

    //         $this->command->info(
    //             "Local Plan ID: {$plan->id}"
    //         );

    //         $this->command->info(
    //             "Stripe Product: {$product->id}"
    //         );

    //         $this->command->info(
    //             "Stripe Price: {$price->id}"
    //         );

    //         $this->command->newLine();
    //     }
    // }

    public function run(): void
    {
        $stripe = new StripeClient(
            config('services.stripe.secret')
        );

        $products = [

            /*
            |--------------------------------------------------------------------------
            | Basic Plan
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'Basic Plan',

                'description' => 'Basic subscription plan',

                'prices' => [

                    // Daily
                    [
                        'amount' => 100,
                        'currency' => 'usd',
                        'interval' => 'day',
                        'interval_count' => 1,
                    ],

                    // Monthly
                    [
                        'amount' => 1000,
                        'currency' => 'usd',
                        'interval' => 'month',
                        'interval_count' => 1,
                    ],

                    // Yearly
                    [
                        'amount' => 10000,
                        'currency' => 'usd',
                        'interval' => 'year',
                        'interval_count' => 1,
                    ],
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | Pro Plan
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'Pro Plan',

                'description' => 'Professional subscription plan',

                'prices' => [

                    // Daily
                    [
                        'amount' => 250,
                        'currency' => 'usd',
                        'interval' => 'day',
                        'interval_count' => 1,
                    ],

                    // Monthly
                    [
                        'amount' => 2500,
                        'currency' => 'usd',
                        'interval' => 'month',
                        'interval_count' => 1,
                    ],

                    // Yearly
                    [
                        'amount' => 25000,
                        'currency' => 'usd',
                        'interval' => 'year',
                        'interval_count' => 1,
                    ],
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | Premium Plan
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'Premium Plan',

                'description' => 'Premium subscription plan',

                'prices' => [

                    // Daily
                    [
                        'amount' => 500,
                        'currency' => 'usd',
                        'interval' => 'day',
                        'interval_count' => 1,
                    ],

                    // Monthly
                    [
                        'amount' => 5000,
                        'currency' => 'usd',
                        'interval' => 'month',
                        'interval_count' => 1,
                    ],

                    // Yearly
                    [
                        'amount' => 50000,
                        'currency' => 'usd',
                        'interval' => 'year',
                        'interval_count' => 1,
                    ],
                ],
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Create Products & Prices
        |--------------------------------------------------------------------------
        */

        foreach ($products as $productData) {

            /*
            |--------------------------------------------------------------------------
            | Create Product in Stripe
            |--------------------------------------------------------------------------
            */

            $stripeProduct = $stripe->products->create([
                'name' => $productData['name'],

                'description' => $productData['description'],

                'active' => true,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Save Product Locally
            |--------------------------------------------------------------------------
            */

            $product = Product::create([
                'name' => $productData['name'],

                'description' => $productData['description'],

                'stripe_product_id' => $stripeProduct->id,

                'active' => true,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Create Prices
            |--------------------------------------------------------------------------
            */

            foreach ($productData['prices'] as $priceData) {

                /*
                |--------------------------------------------------------------------------
                | Create Price in Stripe
                |--------------------------------------------------------------------------
                */

                $stripePrice = $stripe->prices->create([

                    'product' => $stripeProduct->id,

                    'unit_amount' => $priceData['amount'],

                    'currency' => $priceData['currency'],

                    'recurring' => [
                        'interval' => $priceData['interval'],

                        'interval_count' =>
                            $priceData['interval_count'],
                    ],
                ]);

                /*
                |--------------------------------------------------------------------------
                | Save Price Locally
                |--------------------------------------------------------------------------
                */

                Price::create([
                    'product_id' => $product->id,

                    'stripe_price_id' => $stripePrice->id,

                    'amount' => $priceData['amount'],

                    'currency' => $priceData['currency'],

                    'interval' => $priceData['interval'],

                    'interval_count' =>
                        $priceData['interval_count'],

                    'active' => true,
                ]);

                $this->command->info(
                    "Created Price: {$stripePrice->id}"
                );
            }

            $this->command->info(
                "Created Product: {$stripeProduct->id}"
            );

            $this->command->newLine();
        }
    }
}
