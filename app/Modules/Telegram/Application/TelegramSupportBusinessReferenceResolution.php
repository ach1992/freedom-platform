<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramSupportBusinessReferenceResolution
{
    public function __construct(
        public int $internalId,
        public string $publicId,
    ) {
        if ($internalId < 1) {
            throw new InvalidArgumentException('Telegram Support business-reference internal identifier is invalid.');
        }
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $publicId) !== 1) {
            throw new InvalidArgumentException('Telegram Support business-reference public identifier is invalid.');
        }
    }
}
