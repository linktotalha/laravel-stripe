<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

use App\Http\Controllers\Api\StripeCustomerController;
use App\Http\Controllers\Api\StripeCheckoutController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    Route::post(
        '/stripe/customer',
        [StripeCustomerController::class, 'store']
    );

    Route::post(
        '/stripe/checkout',
        [StripeCheckoutController::class, 'create']
    );

    Route::get(
        '/subscription/success',
        [StripeCheckoutController::class, 'success']
    )->name('subscription.success');

    Route::get(
        '/subscription/cancel',
        [StripeCheckoutController::class, 'cancel']
    )->name('subscription.cancel');

    Route::post('/logout', [AuthController::class, 'logout']);

});
