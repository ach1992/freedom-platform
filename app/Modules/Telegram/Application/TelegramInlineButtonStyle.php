<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

enum TelegramInlineButtonStyle: string
{
    case Primary = 'primary';
    case Success = 'success';
    case Danger = 'danger';
}
