<?php

declare(strict_types=1);

return [
    'reapplication_cooldown_days' => (int) env('AGENT_REAPPLICATION_COOLDOWN_DAYS', 30),
    'default_pricing_profile_code' => env('AGENT_DEFAULT_PRICING_PROFILE_CODE', 'default'),
];
