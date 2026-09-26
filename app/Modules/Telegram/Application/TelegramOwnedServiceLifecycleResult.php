<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramOwnedServiceLifecycleResult
{
    public function __construct(
        public TelegramOwnedServiceAction $action,
        public TelegramOwnedServiceLifecycleStatus $status,
    ) {
        if (! $action->isLifecycleAction()) {
            throw new InvalidArgumentException('Telegram Service lifecycle result action is invalid.');
        }
    }
}
