<?php

declare(strict_types=1);

return [
    'card_to_card' => [
        'lookup_key' => env('C2C_LOOKUP_KEY'),
        'reservation_minutes' => env('C2C_RESERVATION_MINUTES', 30),
        'late_review_minutes' => env('C2C_LATE_REVIEW_MINUTES', 1440),
        'adjustment_min_irr' => env('C2C_ADJUSTMENT_MIN_IRR', 1000),
        'adjustment_max_irr' => env('C2C_ADJUSTMENT_MAX_IRR', 9990),
    ],
    'gift_card' => [
        'code_lookup_key' => env('GIFT_CARD_CODE_LOOKUP_KEY'),
        'code_lookup_key_version' => env('GIFT_CARD_CODE_LOOKUP_KEY_VERSION', 1),
        'code_lookup_previous_key' => env('GIFT_CARD_CODE_LOOKUP_PREVIOUS_KEY'),
        'code_lookup_previous_key_version' => env('GIFT_CARD_CODE_LOOKUP_PREVIOUS_KEY_VERSION'),
    ],
];