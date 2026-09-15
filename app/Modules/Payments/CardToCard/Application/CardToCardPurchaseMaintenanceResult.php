<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application;

final readonly class CardToCardPurchaseMaintenanceResult
{
    public function __construct(
        public int $intentsExamined,
        public int $expiredIntents,
        public int $failures,
    ) {}
}
