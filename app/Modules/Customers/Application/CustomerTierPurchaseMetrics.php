<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use InvalidArgumentException;

final readonly class CustomerTierPurchaseMetrics
{
    public function __construct(
        public int $successfulPurchaseCount,
        public int $totalSpendIrr,
    ) {
        if ($successfulPurchaseCount < 0 || $totalSpendIrr < 0) {
            throw new InvalidArgumentException('Customer tier purchase metrics cannot be negative.');
        }
    }
}
