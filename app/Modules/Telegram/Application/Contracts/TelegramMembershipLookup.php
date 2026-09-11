<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramMembershipLookupResult;

interface TelegramMembershipLookup
{
    public function lookup(int $chatId, int $telegramUserId): TelegramMembershipLookupResult;
}
