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
    'corrections' => [
        // Conservative default: every non-owner correction requires independent approval
        // until the owner explicitly configures a positive large-correction threshold.
        'dual_approval_threshold_irr' => (int) env('WALLET_CORRECTION_DUAL_APPROVAL_THRESHOLD_IRR', 0),
        'approval_ttl_seconds' => (int) env('WALLET_CORRECTION_APPROVAL_TTL_SECONDS', 600),
    ],
];
