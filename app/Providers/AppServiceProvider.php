<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One configured client for the whole app, injectable anywhere.
        $this->app->singleton(StripeClient::class, function () {
            return new StripeClient(config('services.stripe.secret'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Turn a misnamed attribute (e.g. "amount" for "amount_paid") into an
        // exception instead of a silently dropped value.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}
