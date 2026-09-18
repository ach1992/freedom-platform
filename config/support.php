<?php

declare(strict_types=1);

return [
    'reopen_window_hours' => env('SUPPORT_TICKET_REOPEN_WINDOW_HOURS', 72),

    'contact' => [
        'display_mode' => env('SUPPORT_CONTACT_DISPLAY_MODE', 'internal'),
        'external_username' => env('SUPPORT_EXTERNAL_USERNAME'),
    ],

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
