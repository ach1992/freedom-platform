<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

use DateTimeImmutable;

final readonly class PromotionUsageFinalizationReceipt
{
    public function __construct(
        public int $redemptionId,
        public string $redemptionPublicId,
        public string $reservationPublicId,
        public string $purchaseSettlementPublicId,
        public int $userId,
        public int $discountIrr,
        public DateTimeImmutable $redeemedAt,
        public bool $replayed = false,
    ) {}
}
