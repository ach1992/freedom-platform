<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Infrastructure;

use App\Modules\Telegram\Application\Contracts\ProtectedTelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\ProtectedTelegramMessageSender;
use App\Modules\Telegram\Application\Contracts\TelegramBotApi;
use App\Modules\Telegram\Application\Contracts\TelegramInteractionHandler;
use App\Modules\Telegram\Application\Contracts\TelegramRuntime;
use App\Modules\Telegram\Application\TelegramInteractionHandlerRegistry;
use App\Modules\Telegram\Application\TelegramInteractionPolicy;
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
            TelegramRuntime::class,
            fn (Application $application): TelegramRuntime => $application->make(TelegramRuntimeConfiguration::class),
        );
        $this->app->singleton(
            TelegramInteractionPolicy::class,
            function (Application $application): TelegramInteractionPolicy {
                $repository = $application->make(Repository::class);

                return new TelegramInteractionPolicy(
                    (int) $repository->get('telegram.interaction_session_ttl_seconds', 1800),
                    (int) $repository->get('telegram.interaction_callback_ttl_seconds', 900),
                );
            },
        );
        $this->app->singleton(
            TelegramInteractionHandlerRegistry::class,
            fn (Application $application): TelegramInteractionHandlerRegistry => new TelegramInteractionHandlerRegistry(
                $application->tagged(TelegramInteractionHandler::class),
            ),
        );
        $this->app->singleton(
            ProtectedTelegramDeliveryRuntime::class,
            fn (Application $application): ProtectedTelegramDeliveryRuntime => $application->make(TelegramRuntime::class),
        );
        $this->app->singleton(
            TelegramBotApi::class,
            fn (Application $application): TelegramBotApi => new HttpTelegramBotApi(
                $application->make(Factory::class),
                $application->make(TelegramRuntimeConfiguration::class),
            ),
        );
        $this->app->singleton(
            ProtectedTelegramMessageSender::class,
            fn (Application $application): ProtectedTelegramMessageSender => new HttpProtectedTelegramMessageSender(
                $application->make(Factory::class),
                $application->make(TelegramRuntimeConfiguration::class),
            ),
        );
    }
}
