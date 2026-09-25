<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramOwnedServiceAutoRenewResult;
use App\Modules\Telegram\Application\TelegramOwnedServiceAutoRenewSnapshot;

interface TelegramOwnedServiceAutoRenewManager
{
    public function snapshotForSelf(int $actorUserId, string $servicePublicId): TelegramOwnedServiceAutoRenewSnapshot;

    public function configureForSelf(
        int $actorUserId,
        string $servicePublicId,
        string $packageCode,
        bool $enabled,
        string $requestKey,
        string $correlationId,
    ): TelegramOwnedServiceAutoRenewResult;
}
