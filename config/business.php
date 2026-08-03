<?php

declare(strict_types=1);

return [
    'display_timezone' => env('BUSINESS_TIMEZONE', 'Asia/Tehran'),

    'money' => [
        'storage_currency' => 'IRR',
        'display_unit' => 'TOMAN',
        'irr_per_toman' => 10,
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'owner_id' => env('OWNER_TELEGRAM_ID'),
        'report_chat_id' => env('REPORT_CHAT_ID'),
    ],
];
