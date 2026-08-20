<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StripeCheckoutController;
use App\Http\Controllers\Api\StripeCustomerController;
use App\Http\Controllers\Api\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/stripe/customer', [StripeCustomerController::class, 'store']);

    Route::post('/stripe/checkout', [StripeCheckoutController::class, 'create']);

    /*
    | Called by the frontend success page with the session_id Stripe put in the
    | redirect URL. Stripe itself never hits this route.
    */
    Route::get('/subscription/success', [StripeCheckoutController::class, 'success'])
        ->name('subscription.success');

    Route::get('/subscription', [StripeCheckoutController::class, 'show']);

    Route::post('/subscription/change-plan', [StripeCheckoutController::class, 'changePlan']);

    Route::post('/subscription/cancel', [StripeCheckoutController::class, 'cancelSubscription']);

    Route::post('/subscription/resume', [StripeCheckoutController::class, 'resumeSubscription']);
});

/*
| Public by necessity: Stripe cannot authenticate. Signature verification in
| the controller is what protects this route.
*/
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle']);
