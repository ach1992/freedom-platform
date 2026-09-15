<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Domain;

enum TelegramDeliveryAction: string
{
    case Send = 'send';
    case Edit = 'edit';
    case Delete = 'delete';
}
