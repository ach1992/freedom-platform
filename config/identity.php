<?php

declare(strict_types=1);

return [
    'phone_lookup_key' => env('PHONE_LOOKUP_KEY'),
    'phone_lookup_key_version' => (int) env('PHONE_LOOKUP_KEY_VERSION', 1),
    'identity_items' => [
        'hash_key_version' => (int) env('IDENTITY_ITEM_HASH_KEY_VERSION', 1),
        'required_types' => ['national_id', 'full_name'],
    ],
    'otp' => [
        'hash_key' => env('OTP_HASH_KEY'),
        'hash_key_version' => (int) env('OTP_HASH_KEY_VERSION', 1),
        'ttl_seconds' => (int) env('OTP_TTL_SECONDS', 120),
        'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN_SECONDS', 60),
        'maximum_attempts' => (int) env('OTP_MAXIMUM_ATTEMPTS', 5),
        'daily_phone_limit' => (int) env('OTP_DAILY_PHONE_LIMIT', 10),
        'daily_telegram_account_limit' => (int) env('OTP_DAILY_TELEGRAM_ACCOUNT_LIMIT', 10),
        'daily_ip_limit' => (int) env('OTP_DAILY_IP_LIMIT', 20),
        'redis_prefix' => env('OTP_REDIS_PREFIX', 'freedom:otp-limit:'),
    ],
    'sms' => [
        'primary_provider' => env('SMS_PRIMARY_PROVIDER', 'fake_primary'),
        'fallback_provider' => env('SMS_FALLBACK_PROVIDER', 'fake_fallback'),
        'provider_daily_limit' => (int) env('SMS_PROVIDER_DAILY_LIMIT', 10000),
        'timeout_seconds' => (int) env('SMS_PROVIDER_TIMEOUT_SECONDS', 15),
        'providers' => [
            'melli_payamak' => [
                'enabled' => filter_var(env('MELLI_PAYAMAK_ENABLED', false), FILTER_VALIDATE_BOOL),
                'username' => env('MELLI_PAYAMAK_USERNAME'),
                'password' => env('MELLI_PAYAMAK_PASSWORD'),
                'sender' => env('MELLI_PAYAMAK_SENDER'),
                'timeout_seconds' => (int) env('MELLI_PAYAMAK_TIMEOUT_SECONDS', 15),
            ],
            'kavenegar' => [
                'enabled' => filter_var(env('KAVENEGAR_ENABLED', false), FILTER_VALIDATE_BOOL),
                'api_key' => env('KAVENEGAR_API_KEY'),
                'sender' => env('KAVENEGAR_SENDER'),
                'timeout_seconds' => (int) env('KAVENEGAR_TIMEOUT_SECONDS', 15),
            ],
        ],
    ],
];
