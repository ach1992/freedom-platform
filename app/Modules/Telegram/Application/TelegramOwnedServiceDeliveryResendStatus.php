<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

enum TelegramOwnedServiceDeliveryResendStatus: string
{
    case Queued = 'queued';
    case TemporarilyBlocked = 'temporarily_blocked';
    case Unavailable = 'unavailable';
}
