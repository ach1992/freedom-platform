<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

final readonly class PurchasePromotionUsageMaintenanceResult
{
    public function __construct(
        public int $examined,
        public int $released,
        public int $failures,
    ) {}
}
