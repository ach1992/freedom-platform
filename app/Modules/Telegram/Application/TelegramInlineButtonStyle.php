<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

// Temporary CI sharding validation sentinel; removed before merge.
enum TelegramInlineButtonStyle: string
{
    case Primary = 'primary';
    case Success = 'success';
    case Danger = 'danger';
}
