<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Infrastructure;

use App\Modules\Telegram\Application\Contracts\TelegramNowPaymentsNavigationResolver;
use App\Modules\Telegram\Application\TelegramNowPaymentsNavigationHandler;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

final readonly class ContainerTelegramNowPaymentsNavigationResolver implements TelegramNowPaymentsNavigationResolver
{
    public function __construct(private Application $application) {}

    public function resolve(): TelegramNowPaymentsNavigationHandler
    {
        $handler = $this->application->make(TelegramNowPaymentsNavigationHandler::class);
        if (! $handler instanceof TelegramNowPaymentsNavigationHandler) {
            throw new RuntimeException('Telegram NOWPayments navigation resolver returned an invalid handler.');
        }

        return $handler;
    }
}
