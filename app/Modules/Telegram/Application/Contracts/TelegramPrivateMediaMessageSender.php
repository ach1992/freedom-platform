<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramMutationResult;
use App\Modules\Telegram\Application\TelegramResolvedPrivateMediaPresentation;

interface TelegramPrivateMediaMessageSender
{
    /**
     * Perform exactly one Telegram media send attempt. Implementations must not
     * hide transport retries because timeout-after-send is externally ambiguous.
     */
    public function send(
        int $recipientChatId,
        TelegramResolvedPrivateMediaPresentation $presentation,
    ): TelegramMutationResult;
}
