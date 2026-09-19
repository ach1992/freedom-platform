<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

enum TelegramSourceMessageMode: string
{
    case Forward = 'forward';
    case Copy = 'copy';
}
