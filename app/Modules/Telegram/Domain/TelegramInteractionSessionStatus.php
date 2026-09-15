<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Domain;

enum TelegramInteractionSessionStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case Expired = 'expired';
}
