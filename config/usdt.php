<?php

declare(strict_types=1);

return [
    'rate' => [
        'priority' => ['nobitex', 'tetherland'],
        'side' => env('USDT_RATE_SIDE', 'buy'),
        'max_age_seconds' => (int) env('USDT_RATE_MAX_AGE_SECONDS', 120),
        'min_irr' => env('USDT_RATE_MIN_IRR', '100000'),
        'max_irr' => env('USDT_RATE_MAX_IRR', '10000000'),
        'max_divergence_bps' => (int) env('USDT_RATE_MAX_DIVERGENCE_BPS', 500),
        'emergency_manual_fallback' => (bool) env('USDT_EMERGENCY_MANUAL_FALLBACK', false),
        'manual_irr' => env('USDT_MANUAL_RATE_IRR'),
        'circuit_failure_threshold' => (int) env('USDT_RATE_CIRCUIT_FAILURE_THRESHOLD', 3),
        'circuit_cooldown_seconds' => (int) env('USDT_RATE_CIRCUIT_COOLDOWN_SECONDS', 60),
        'http_timeout_seconds' => (int) env('USDT_RATE_HTTP_TIMEOUT_SECONDS', 4),
        'http_connect_timeout_seconds' => (int) env('USDT_RATE_HTTP_CONNECT_TIMEOUT_SECONDS', 2),
        'http_max_response_bytes' => (int) env('USDT_RATE_HTTP_MAX_RESPONSE_BYTES', 65536),
        'tetherland_irr_multiplier' => (int) env('USDT_TETHERLAND_IRR_MULTIPLIER', 10),
    ],
    'quote' => [
        'margin_bps' => (int) env('USDT_QUOTE_MARGIN_BPS', 0),
        'rounding_precision' => (int) env('USDT_QUOTE_ROUNDING_PRECISION', 6),
        'validity_seconds' => (int) env('USDT_QUOTE_VALIDITY_SECONDS', 900),
        'destination_wallet_code' => env('USDT_DESTINATION_WALLET_CODE', 'primary'),
    ],
];
