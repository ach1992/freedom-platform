<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramManagedUsdtRateSnapshot;

interface TelegramManagedUsdtRateSettings
{
    public function availableFor(int $actorUserId): bool;

    public function currentFor(int $actorUserId): ?TelegramManagedUsdtRateSnapshot;

    /** @return numeric-string */
    public function validateFor(int $actorUserId, string $rateIrr): string;

    public function setFor(
        int $actorUserId,
        string $rateIrr,
        string $requestKey,
        string $correlationId,
    ): TelegramManagedUsdtRateSnapshot;
}
