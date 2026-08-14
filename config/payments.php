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
];
