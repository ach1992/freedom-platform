<?php

declare(strict_types=1);

return [
    'interval_minutes' => env('SERVICE_NOTIFICATION_INTERVAL_MINUTES', 5),
    'batch_limit' => env('SERVICE_NOTIFICATION_BATCH_LIMIT', 50),
    'expiry_threshold_days' => array_values(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('SERVICE_NOTIFICATION_EXPIRY_DAYS', '7,3,1,0')),
    )),
    // New expiry episodes bind this value durably; later config changes do not rewrite existing authority.
    'expiry_snapshot_max_age_seconds' => env('SERVICE_NOTIFICATION_EXPIRY_SNAPSHOT_MAX_AGE_SECONDS', 1800),
    // Product-specific financial threshold. Zero keeps low-balance warnings disabled until configured.
    'low_balance_irr' => env('SERVICE_NOTIFICATION_LOW_BALANCE_IRR', 0),
    'retry' => [
        'expiry' => [
            'max_retries' => env('SERVICE_NOTIFICATION_EXPIRY_MAX_RETRIES', 2),
            'base_delay_seconds' => env('SERVICE_NOTIFICATION_EXPIRY_RETRY_BASE_SECONDS', 60),
            'max_delay_seconds' => env('SERVICE_NOTIFICATION_EXPIRY_RETRY_MAX_SECONDS', 900),
        ],
        'low_balance' => [
            'max_retries' => env('SERVICE_NOTIFICATION_LOW_BALANCE_MAX_RETRIES', 2),
            'base_delay_seconds' => env('SERVICE_NOTIFICATION_LOW_BALANCE_RETRY_BASE_SECONDS', 120),
            'max_delay_seconds' => env('SERVICE_NOTIFICATION_LOW_BALANCE_RETRY_MAX_SECONDS', 1800),
        ],
        'renewal_failure' => [
            'max_retries' => env('SERVICE_NOTIFICATION_RENEWAL_MAX_RETRIES', 3),
            'base_delay_seconds' => env('SERVICE_NOTIFICATION_RENEWAL_RETRY_BASE_SECONDS', 60),
            'max_delay_seconds' => env('SERVICE_NOTIFICATION_RENEWAL_RETRY_MAX_SECONDS', 1800),
        ],
    ],
];
