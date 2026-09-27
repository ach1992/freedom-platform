<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramRateLimitDecision;

interface TelegramSharedRateLimiter
{
    public function consumeInteraction(int $userId): TelegramRateLimitDecision;

    public function reserveOutbound(int $recipientChatId): TelegramRateLimitDecision;
}
