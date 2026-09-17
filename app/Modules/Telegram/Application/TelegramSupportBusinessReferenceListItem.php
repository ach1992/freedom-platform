<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramSupportBusinessReferenceListItem
{
    public function __construct(
        public string $selectionToken,
        public string $publicId,
        public int $amountIrr,
        public string $currency,
    ) {
        if (preg_match('/\A[0-9a-f]{40}\z/', $selectionToken) !== 1) {
            throw new InvalidArgumentException('Telegram Support business-reference selection token is invalid.');
        }
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $publicId) !== 1) {
            throw new InvalidArgumentException('Telegram Support business-reference public identifier is invalid.');
        }
        if ($amountIrr < 0 || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw new InvalidArgumentException('Telegram Support business-reference amount is invalid.');
        }
    }
}
