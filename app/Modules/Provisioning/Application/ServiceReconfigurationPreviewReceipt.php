<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

final readonly class ServiceReconfigurationPreviewReceipt
{
    public function __construct(
        public string $previewPublicId,
        public string $servicePublicId,
        public string $sourceOfferingCode,
        public string $targetOfferingCode,
        public string $targetSalesServerCode,
        public string $targetProtocolProfileCode,
        public bool $changesPlan,
        public bool $changesTarget,
        public bool $changesProtocol,
        public int $priceDifferenceIrr,
        public int $operationFeeIrr,
        public int $totalPriceIrr,
        public bool $discountEligible,
        public string $expiresAt,
        public bool $replayed,
    ) {}

    public function isFree(): bool
    {
        return $this->totalPriceIrr === 0;
    }
}
