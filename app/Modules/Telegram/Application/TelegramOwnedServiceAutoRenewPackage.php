<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramOwnedServiceAutoRenewPackage
{
    public function __construct(
        public string $code,
        public string $nameFa,
        public ?string $nameEn,
        public int $priceIrr,
        public int $durationDays,
    ) {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{1,63}\z/', $code) !== 1
            || $nameFa === '' || ! mb_check_encoding($nameFa, 'UTF-8')
            || ($nameEn !== null && ($nameEn === '' || ! mb_check_encoding($nameEn, 'UTF-8')))
            || $priceIrr < 1 || $durationDays < 1) {
            throw new InvalidArgumentException('Telegram Service auto-renew package is invalid.');
        }
    }
}
