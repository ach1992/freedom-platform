<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramOwnedServiceDeliveryResendStatus;

interface TelegramOwnedServiceDeliveryResender
{
    public function resendForSelf(
        int $actorUserId,
        string $servicePublicId,
        string $requestKey,
        string $correlationId,
    ): TelegramOwnedServiceDeliveryResendStatus;
}
