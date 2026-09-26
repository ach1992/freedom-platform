<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramAdministratorServiceOperationPreview;
use App\Modules\Telegram\Application\TelegramAdministratorServiceOperationResult;

interface TelegramAdministratorServiceOperations
{
    public function availableFor(int $actorUserId): bool;

    public function prepareForUser(
        int $actorUserId,
        string $input,
        string $requestKey,
        string $correlationId,
    ): TelegramAdministratorServiceOperationPreview;

    /** @param array<string,mixed> $payload */
    public function executeForUser(
        int $actorUserId,
        array $payload,
        string $requestKey,
        string $correlationId,
    ): TelegramAdministratorServiceOperationResult;
}
