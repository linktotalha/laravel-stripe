<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

        /*
        | Stripe redirects the browser to these, so they must be frontend pages
        | -- not API routes behind auth:sanctum, which a redirect cannot satisfy.
        | The success page reads session_id from the query string and calls
        | GET /api/subscription/success with the user's token.
        */
        'success_url' => env(
            'STRIPE_SUCCESS_URL',
            env('FRONTEND_URL', env('APP_URL')) . '/subscription/success'
        ),

        'cancel_url' => env(
            'STRIPE_CANCEL_URL',
            env('FRONTEND_URL', env('APP_URL')) . '/subscription/cancel'
        ),
    ],

];
