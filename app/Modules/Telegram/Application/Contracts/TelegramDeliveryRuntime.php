<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

interface TelegramDeliveryRuntime
{
    public function botId(): string;
}
