<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

interface TelegramBotIdentityVerifier
{
    public function assertBotIdentity(): void;
}
