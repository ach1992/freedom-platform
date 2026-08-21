<?php

declare(strict_types=1);

return [
    'window_hours' => (int) env('AUTO_RENEW_WINDOW_HOURS', 24),
    'quote_ttl_minutes' => (int) env('AUTO_RENEW_QUOTE_TTL_MINUTES', 15),
    'batch_limit' => (int) env('AUTO_RENEW_BATCH_LIMIT', 50),
    'max_retry_count' => (int) env('AUTO_RENEW_MAX_RETRY_COUNT', 5),
    'retry_initial_delay_minutes' => (int) env('AUTO_RENEW_RETRY_INITIAL_DELAY_MINUTES', 15),
    'retry_max_delay_minutes' => (int) env('AUTO_RENEW_RETRY_MAX_DELAY_MINUTES', 240),
];
