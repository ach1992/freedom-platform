<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

interface TelegramReportChannelVerifier
{
    public function verifyReportChannel(int $chatId): void;
}
