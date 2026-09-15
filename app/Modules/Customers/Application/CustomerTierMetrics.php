<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use InvalidArgumentException;

final readonly class CustomerTierMetrics
{
    public function __construct(
        public int $successfulPurchaseCount,
        public int $membershipDays,
        public int $totalSpendIrr = 0,
    ) {
        if ($successfulPurchaseCount < 0 || $membershipDays < 0 || $totalSpendIrr < 0) {
            throw new InvalidArgumentException('Customer tier metrics cannot be negative.');
        }
    }
}
