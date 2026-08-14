<?php

declare(strict_types=1);

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

    'zarinpal' => [
        'enabled' => env('ZARINPAL_ENABLED', false),
        'merchant_id' => env('ZARINPAL_MERCHANT_ID'),
        'callback_url' => env(
            'ZARINPAL_CALLBACK_URL',
            rtrim((string) env('APP_URL', 'http://localhost:8000'), '/').'/payments/zarinpal/callback',
        ),
        'connect_timeout_seconds' => env('ZARINPAL_CONNECT_TIMEOUT_SECONDS', 5),
        'timeout_seconds' => env('ZARINPAL_TIMEOUT_SECONDS', 15),
    ],

];
