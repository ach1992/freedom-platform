<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use InvalidArgumentException;

final readonly class CustomerWalletTransferRecipient
{
    public function __construct(
        public string $accountPublicId,
        public string $telegramUserId,
        public ?string $maskedUsername,
        public string $locale,
    ) {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $accountPublicId) !== 1
            || preg_match('/\A[1-9][0-9]{0,19}\z/', $telegramUserId) !== 1
            || ($maskedUsername !== null
                && (trim($maskedUsername) !== $maskedUsername
                    || mb_strlen($maskedUsername) > 40
                    || ! mb_check_encoding($maskedUsername, 'UTF-8')))
            || ! in_array($locale, ['fa', 'en'], true)) {
            throw new InvalidArgumentException('Wallet transfer recipient projection is invalid.');
        }
    }
}
