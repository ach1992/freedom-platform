<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramMutationResult;
use App\Modules\Telegram\Application\TelegramResolvedInlineKeyboardMarkup;
use App\Modules\Telegram\Application\TelegramResolvedSourceMessagePresentation;

interface TelegramSourceMessageSender
{
    public function send(
        int $recipientChatId,
        TelegramResolvedSourceMessagePresentation $source,
        ?TelegramResolvedInlineKeyboardMarkup $inlineKeyboard = null,
    ): TelegramMutationResult;
}
