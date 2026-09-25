<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramOwnedServiceAction;
use App\Modules\Telegram\Application\TelegramOwnedServiceLifecycleResult;

interface TelegramOwnedServiceLifecycleExecutor
{
    public function executeForSelf(
        int $actorUserId,
        string $servicePublicId,
        TelegramOwnedServiceAction $action,
        string $requestKey,
        string $correlationId,
    ): TelegramOwnedServiceLifecycleResult;
}
