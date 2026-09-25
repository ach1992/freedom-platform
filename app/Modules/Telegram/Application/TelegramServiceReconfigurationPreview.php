<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramServiceReconfigurationPreview
{
    public function __construct(
        public string $previewPublicId,
        public string $servicePublicId,
        public string $offeringSelectionToken,
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
        public string $expiresAt,
        public bool $replayed,
    ) {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $previewPublicId) !== 1
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $servicePublicId) !== 1
            || preg_match('/\A[0-9a-f]{40}\z/', $offeringSelectionToken) !== 1
            || $priceDifferenceIrr < 0 || $operationFeeIrr < 0 || $totalPriceIrr < 0
            || $priceDifferenceIrr + $operationFeeIrr !== $totalPriceIrr) {
            throw new InvalidArgumentException('Telegram Service reconfiguration preview is invalid.');
        }
    }

    public function requiresPayment(): bool
    {
        return $this->totalPriceIrr > 0;
    }
}
