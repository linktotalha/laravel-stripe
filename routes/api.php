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

    Route::post('/logout', [AuthController::class, 'logout']);

});
