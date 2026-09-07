<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class TelegramCustomerPurchaseUsdtInstructions
{
    public function __construct(
        public string $authorityPublicId,
        public string $paymentIntentPublicId,
        public string $amountQuotePublicId,
        public string $network,
        public string $exactUsdt,
        public string $destinationAddress,
        public DateTimeImmutable $expiresAt,
        public bool $replayed,
    ) {
        foreach ([$authorityPublicId, $paymentIntentPublicId, $amountQuotePublicId] as $publicId) {
            if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $publicId) !== 1) {
                throw new InvalidArgumentException('Telegram USDT public identity is invalid.');
            }
        }
        if ($network !== 'BEP20'
            || preg_match('/\A0x[a-f0-9]{40}\z/', $destinationAddress) !== 1
            || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?\z/', $exactUsdt) !== 1
            || (float) $exactUsdt <= 0.0) {
            throw new InvalidArgumentException('Telegram USDT instructions are invalid.');
        }
    }
}
