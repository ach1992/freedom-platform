<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

use App\Modules\Promotions\Domain\PromotionUsageReservationState;

final readonly class PromotionUsageReservationReceipt
{
    public function __construct(
        public int $reservationId,
        public string $reservationPublicId,
        public string $reservationKey,
        public string $resolutionPublicId,
        public string $quotePublicId,
        public int $userId,
        public int $planOfferingId,
        public string $ruleCode,
        public int $ruleVersion,
        public string $ruleConfigurationHash,
        public int $discountIrr,
        public PromotionUsageReservationState $state,
        public bool $replayed,
    ) {}
}
