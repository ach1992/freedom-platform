<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramBroadcastNavigationHandler;

interface TelegramBroadcastNavigationResolver
{
    public function resolve(): TelegramBroadcastNavigationHandler;
}
