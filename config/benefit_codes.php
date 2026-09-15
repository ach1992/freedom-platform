<?php

declare(strict_types=1);

$previousVersion = env('BENEFIT_CODE_LOOKUP_KEY_PREVIOUS_VERSION');

return [
    'lookup' => [
        'current' => [
            'version' => (int) env('BENEFIT_CODE_LOOKUP_KEY_CURRENT_VERSION', 1),
            'key' => env('BENEFIT_CODE_LOOKUP_KEY_CURRENT'),
        ],
        'previous' => [
            'version' => is_numeric($previousVersion) ? (int) $previousVersion : null,
            'key' => env('BENEFIT_CODE_LOOKUP_KEY_PREVIOUS'),
        ],
    ],
];
