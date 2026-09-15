<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramChannelMembershipChannelEvidence
{
    public function __construct(
        public int $requiredChannelId,
        public string $channelKey,
        public TelegramMembershipEvidence $evidence,
        public string $resultCode,
    ) {
        if ($requiredChannelId < 1 || preg_match('/\A[a-z][a-z0-9_.-]{2,63}\z/', $channelKey) !== 1) {
            throw new InvalidArgumentException('Telegram membership evaluation channel identity is invalid.');
        }
        if (preg_match('/\Atelegram_membership_[a-z0-9_]{1,44}\z/', $resultCode) !== 1) {
            throw new InvalidArgumentException('Telegram membership evaluation result code is invalid.');
        }
    }
}
