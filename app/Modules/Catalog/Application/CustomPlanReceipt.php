<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

final readonly class CustomPlanReceipt
{
    public function __construct(
        public int $calculationId,
        public int $offeringId,
        public int $policyId,
        public int $policyVersion,
        public string $policyConfigurationHash,
        public string $actorType,
        public int $dataGb,
        public int $days,
        public string $normalizedUsername,
        public int $basePriceIrr,
        public int $pricePerGbIrr,
        public int $pricePerDayIrr,
        public int $dataPriceIrr,
        public int $dayPriceIrr,
        public int $subtotalIrr,
        public int $minimumOrderAmountIrr,
        public int $minimumAdjustmentIrr,
        public int $finalPriceIrr,
        public bool $discountEligible,
        public bool $replayed = false,
    ) {}
}
