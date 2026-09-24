<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramAdministratorSearchItem;

interface TelegramAdministratorSearchSource
{
    public function availableFor(int $actorUserId): bool;

    /**
     * @return list<TelegramAdministratorSearchItem>
     */
    public function search(
        int $actorUserId,
        string $botId,
        string $query,
    ): array;
}
