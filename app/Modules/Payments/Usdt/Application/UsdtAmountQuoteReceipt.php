<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

use DateTimeImmutable;

final readonly class UsdtAmountQuoteReceipt
{
    public function __construct(
        public int $quoteId,
        public string $publicId,
        public string $quoteKey,
        public string $sourceQuotePublicId,
        public int $userId,
        public string $network,
        public string $destinationAddress,
        public int $destinationWalletVersion,
        public string $rateSource,
        public string $rawRateIrr,
        public int $marginBps,
        public string $finalRateIrr,
        public int $orderAmountIrr,
        public string $exactUsdt,
        public int $roundingPrecision,
        public DateTimeImmutable $rateFetchedAt,
        public DateTimeImmutable $expiresAt,
        public string $providerResponseHash,
        public string $configurationSnapshotHash,
        public bool $replayed,
    ) {}
}
