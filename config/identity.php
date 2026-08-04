<?php

declare(strict_types=1);

return [
    'phone_lookup_key' => env('PHONE_LOOKUP_KEY'),
    'phone_lookup_key_version' => (int) env('PHONE_LOOKUP_KEY_VERSION', 1),
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
        'provider_daily_limit' => (int) env('SMS_PROVIDER_DAILY_LIMIT', 10000),
    ],
];
