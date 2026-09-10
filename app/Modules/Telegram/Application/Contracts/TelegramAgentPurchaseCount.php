<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

interface TelegramAgentPurchaseCount
{
    public function forSelf(int $actorUserId, int $subjectUserId): int;
}
