<?php

declare(strict_types=1);

return [
    'phone_lookup_key' => env('PHONE_LOOKUP_KEY'),
    'phone_lookup_key_version' => (int) env('PHONE_LOOKUP_KEY_VERSION', 1),
];
