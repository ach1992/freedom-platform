<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

enum TelegramOwnedServiceLifecycleStatus: string
{
    case Queued = 'queued';
    case Refreshed = 'refreshed';
    case RefreshAttention = 'refresh_attention';
}
