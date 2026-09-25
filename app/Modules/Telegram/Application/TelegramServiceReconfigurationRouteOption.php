<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramServiceReconfigurationRouteOption
{
    public function __construct(
        public string $selectionToken,
        public string $serverCode,
        public string $nameFa,
        public ?string $nameEn,
    ) {
        if (preg_match('/\A[0-9a-f]{40}\z/', $selectionToken) !== 1
            || preg_match('/\A[a-z0-9][a-z0-9_.-]{1,63}\z/', $serverCode) !== 1
            || $nameFa === '' || ! mb_check_encoding($nameFa, 'UTF-8')
            || ($nameEn !== null && ($nameEn === '' || ! mb_check_encoding($nameEn, 'UTF-8')))) {
            throw new InvalidArgumentException('Telegram Service reconfiguration route option is invalid.');
        }
    }
}
