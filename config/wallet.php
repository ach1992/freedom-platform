<?php

declare(strict_types=1);

return [
    'transfers' => [
        'enabled' => false,
        'allowed_buckets' => [],
        'minimum_irr' => 0,
        'maximum_irr' => 0,
        'daily_limit_irr' => 0,
        'fixed_fee_irr' => 0,
        'fee_basis_points' => 0,
        'confirmation_ttl_seconds' => 900,
        'fee_account_code' => null,
    ],
];
