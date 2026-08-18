<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\ProtectedTelegramSendResult;

interface ProtectedTelegramMessageSender
{
    /**
     * Perform exactly one protected Telegram send attempt. Implementations must
     * not hide transport retries because timeout-after-send is externally ambiguous.
     */
    public function send(int $telegramUserId, string $text): ProtectedTelegramSendResult;
}
