<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramAdministratorCustomerTarget;
use App\Modules\Telegram\Application\TelegramAdministratorCustomerTargetSearchResult;

interface TelegramAdministratorCustomerTargetDiscovery
{
    public function availableFor(int $actorUserId): bool;

    public function search(
        int $actorUserId,
        string $botId,
        string $query,
    ): TelegramAdministratorCustomerTargetSearchResult;

    public function resolve(
        int $actorUserId,
        string $botId,
        string $selectionToken,
    ): TelegramAdministratorCustomerTarget;
}
