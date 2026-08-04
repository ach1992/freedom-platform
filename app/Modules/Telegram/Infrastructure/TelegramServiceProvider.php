<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Infrastructure;

use App\Modules\Telegram\Application\Contracts\TelegramBotApi;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

final class TelegramServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            TelegramRuntimeConfiguration::class,
            function (Application $application): TelegramRuntimeConfiguration {
                $repository = $application->make(Repository::class);
                $configuration = $repository->get('telegram');
                $applicationUrl = $repository->get('app.url');

                return TelegramRuntimeConfiguration::fromArray(
                    is_array($configuration) ? $configuration : [],
                    is_string($applicationUrl) ? $applicationUrl : '',
                );
            },
        );

        $this->app->singleton(
            TelegramBotApi::class,
            fn (Application $application): TelegramBotApi => new HttpTelegramBotApi(
                $application->make(Factory::class),
                $application->make(TelegramRuntimeConfiguration::class),
            ),
        );
    }
}
