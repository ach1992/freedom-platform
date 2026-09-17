<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramSupportCustomerRateLimitDecision;
use App\Modules\Telegram\Application\TelegramSupportCustomerRateLimitScope;

interface TelegramSupportCustomerRateLimiter
{
    public function consume(int $userId, TelegramSupportCustomerRateLimitScope $scope): TelegramSupportCustomerRateLimitDecision;
}
