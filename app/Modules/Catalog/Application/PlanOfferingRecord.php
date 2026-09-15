<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

final readonly class PlanOfferingRecord
{
    public function __construct(
        public int $id,
        public string $code,
        public int $productId,
        public ?int $variantId,
        public int $salesServerId,
        public int $serviceTargetId,
        public string $serviceModeCode,
        public string $serviceModeLabelFa,
        public ?string $serviceModeLabelEn,
        public string $audience,
        public string $serverSelectionMode,
        public string $protocolSelectionMode,
        public string $tagMatchMode,
        public int $basePriceIrr,
        public int $durationDays,
        public ?int $dataAllowanceBytes,
        public ?int $deviceLimit,
        public int $sortOrder,
        public int $minPurchaseQuantity,
        public int $maxPurchaseQuantity,
        public bool $discountEligible,
        public bool $autoRenewAllowed,
        public bool $customPlanAllowed,
        public bool $trialAllowed,
        public string $state,
        public string $visibility,
        public int $version,
    ) {}
}
