<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

use DateTimeImmutable;

final readonly class UsdtAmountQuotePreparation
{
    public function __construct(
        private object $seal,
        public string $publicId,
        public string $quoteKey,
        public string $requestPayloadHash,
        public int $sourceQuoteId,
        public string $sourceQuotePublicId,
        public int $userId,
        public int $destinationWalletVersionId,
        public string $destinationWalletCode,
        public int $destinationWalletVersion,
        public string $destinationAddress,
        public string $network,
        public string $destinationConfigurationHash,
        public string $rateSource,
        public string $rawRateIrr,
        public int $marginBps,
        public string $finalRateIrr,
        public int $orderAmountIrr,
        public string $exactUsdt,
        public int $roundingPrecision,
        public int $rateMaxAgeSeconds,
        public int $quoteValiditySeconds,
        public DateTimeImmutable $rateFetchedAt,
        public DateTimeImmutable $expiresAt,
        public string $providerResponseHash,
        public string $configurationSnapshot,
        public string $configurationSnapshotHash,
        public DateTimeImmutable $createdAt,
    ) {}

    public function isSealedBy(object $seal): bool
    {
        return $this->seal === $seal;
    }
}
