<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramAdministratorCustomerTarget
{
    public function __construct(
        public string $selectionToken,
        public string $telegramUserId,
        public string $accountPublicId,
        public string $accountType,
        public string $accountStatus,
        public ?string $maskedUsername,
        public string $locale,
    ) {
        if (preg_match('/\A[0-9a-f]{40}\z/', $selectionToken) !== 1
            || preg_match('/\A[1-9][0-9]{0,19}\z/', $telegramUserId) !== 1
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $accountPublicId) !== 1
            || $accountType !== 'customer'
            || preg_match('/\A[a-z_]{3,32}\z/', $accountStatus) !== 1
            || ($maskedUsername !== null
                && (trim($maskedUsername) !== $maskedUsername
                    || mb_strlen($maskedUsername) > 40
                    || ! mb_check_encoding($maskedUsername, 'UTF-8')))
            || ! in_array($locale, ['fa', 'en'], true)) {
            throw new InvalidArgumentException('Telegram administrator customer target is invalid.');
        }
    }
}
