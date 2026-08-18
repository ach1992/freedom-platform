<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

interface ProtectedTelegramDeliveryRuntime
{
    /** Return the active Telegram bot identifier used for protected delivery. */
    public function botId(): string;
}
