<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Infrastructure;

use App\Modules\Telegram\Application\Contracts\TelegramBroadcastNavigationResolver;
use App\Modules\Telegram\Application\TelegramBroadcastNavigationHandler;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

final readonly class ContainerTelegramBroadcastNavigationResolver implements TelegramBroadcastNavigationResolver
{
    public function __construct(private Application $application) {}

    public function resolve(): TelegramBroadcastNavigationHandler
    {
        $handler = $this->application->make(TelegramBroadcastNavigationHandler::class);
        if (! $handler instanceof TelegramBroadcastNavigationHandler) {
            throw new RuntimeException('Telegram broadcast navigation resolver returned an invalid handler.');
        }

        return $handler;
    }
}
