<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramNowPaymentsNavigationHandler;

interface TelegramNowPaymentsNavigationResolver
{
    public function resolve(): TelegramNowPaymentsNavigationHandler;
}
