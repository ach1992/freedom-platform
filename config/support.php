<?php

declare(strict_types=1);

return [
    'rate_limits' => [
        'prefix' => 'freedom:telegram-support-rate-limit:',
        'ticket_creation' => [
            'max_attempts' => env('SUPPORT_TICKET_CREATION_RATE_LIMIT_MAX', 5),
            'window_seconds' => env('SUPPORT_TICKET_CREATION_RATE_LIMIT_WINDOW_SECONDS', 600),
        ],
        'customer_content' => [
            'max_attempts' => env('SUPPORT_CUSTOMER_CONTENT_RATE_LIMIT_MAX', 30),
            'window_seconds' => env('SUPPORT_CUSTOMER_CONTENT_RATE_LIMIT_WINDOW_SECONDS', 600),
        ],
    ],
];
