<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramServiceAutoRenewPolicyResult;
use App\Modules\Telegram\Application\TelegramServiceAutoRenewPolicySnapshot;

interface TelegramServiceAutoRenewPolicyManager
{
    public function availableFor(int $actorUserId): bool;

    public function snapshotForUser(int $actorUserId, string $offeringCode): TelegramServiceAutoRenewPolicySnapshot;

    public function configureForUser(
        int $actorUserId,
        string $offeringCode,
        string $mode,
        ?int $absoluteIncreaseLimitIrr,
        ?int $percentageIncreaseLimitBps,
        string $requestKey,
        string $correlationId,
    ): TelegramServiceAutoRenewPolicyResult;
}
