<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

use DateTimeImmutable;

final readonly class UsdtPaymentAuthorityReceipt
{
    public function __construct(
        public int $authorityId,
        public string $publicId,
        public string $paymentIntentPublicId,
        public string $amountQuotePublicId,
        public int $userId,
        public int $amountIrr,
        public string $network,
        public int $chainId,
        public string $tokenContract,
        public int $tokenDecimals,
        public string $destinationAddress,
        public string $expectedAmountBaseUnits,
        public int $minimumConfirmations,
        public DateTimeImmutable $quoteExpiresAt,
        public bool $replayed,
    ) {}
}
