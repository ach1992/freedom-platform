<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramServiceNotificationPreferenceResult;
use App\Modules\Telegram\Application\TelegramServiceNotificationPreferenceSnapshot;

interface TelegramServiceNotificationPreferenceManager
{
    public function snapshotForSelf(int $actorUserId, ?string $servicePublicId): TelegramServiceNotificationPreferenceSnapshot;

    public function configureForSelf(
        int $actorUserId,
        ?string $servicePublicId,
        string $notificationType,
        string $thresholdCode,
        bool $enabled,
        string $requestKey,
        string $correlationId,
    ): TelegramServiceNotificationPreferenceResult;
}
