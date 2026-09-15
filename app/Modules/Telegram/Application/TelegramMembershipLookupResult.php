<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramMembershipLookupResult
{
    public function __construct(
        public TelegramMembershipEvidence $evidence,
        public string $resultCode,
    ) {
        if (preg_match('/\Atelegram_membership_[a-z0-9_]{1,44}\z/', $resultCode) !== 1) {
            throw new InvalidArgumentException('Telegram membership result code is invalid.');
        }
    }
}
