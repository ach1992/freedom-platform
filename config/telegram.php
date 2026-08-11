<?php

declare(strict_types=1);

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    'webhook_path' => env('TELEGRAM_WEBHOOK_PATH', 'api/telegram/webhook'),
    'max_body_bytes' => (int) env('TELEGRAM_WEBHOOK_MAX_BODY_BYTES', 1_048_576),
    'queue' => env('TELEGRAM_WEBHOOK_QUEUE', 'telegram-ingress'),
    'processing_lease_seconds' => (int) env('TELEGRAM_PROCESSING_LEASE_SECONDS', 120),
    'api_base_url' => 'https://api.telegram.org',
    'api_timeout_seconds' => (int) env('TELEGRAM_API_TIMEOUT_SECONDS', 15),
];
