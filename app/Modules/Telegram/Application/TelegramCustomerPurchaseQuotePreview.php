<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class TelegramCustomerPurchaseQuotePreview
{
    public function __construct(
        public TelegramCustomerPurchaseOffering $offering,
        public string $quotePublicId,
        public string $configurationSnapshotHash,
        public int $basePriceIrr,
        public int $effectivePriceIrr,
        public int $discountIrr,
        public int $finalPriceIrr,
        public string $currency,
        public DateTimeImmutable $validFrom,
        public DateTimeImmutable $expiresAt,
        public bool $replayed,
    ) {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $quotePublicId) !== 1) {
            throw new InvalidArgumentException('Telegram purchase Quote public identity is invalid.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $configurationSnapshotHash) !== 1) {
            throw new InvalidArgumentException('Telegram purchase Quote snapshot identity is invalid.');
        }
        if ($basePriceIrr < 0 || $effectivePriceIrr < 0 || $discountIrr < 0 || $finalPriceIrr < 0) {
            throw new InvalidArgumentException('Telegram purchase Quote commercial values are invalid.');
        }
        if ($discountIrr > $effectivePriceIrr || $effectivePriceIrr - $discountIrr !== $finalPriceIrr) {
            throw new InvalidArgumentException('Telegram purchase Quote commercial identity is inconsistent.');
        }
        if ($currency !== 'IRR') {
            throw new InvalidArgumentException('Telegram purchase Quote currency is invalid.');
        }
        if ($expiresAt <= $validFrom) {
            throw new InvalidArgumentException('Telegram purchase Quote validity window is invalid.');
        }
    }
}
