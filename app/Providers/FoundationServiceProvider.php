<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Provisioning\Application\InitialProvisioningOutboxHandler;
use App\Modules\Provisioning\Application\ServiceDeliveryEffectExecutor;
use App\Modules\Provisioning\Application\ServiceDeliveryOutboxHandler;
use App\Modules\Provisioning\Application\ServiceMutationOutboxHandler;
use App\Shared\Application\Clock;
use App\Shared\Application\OutboxEventHandler;
use App\Shared\Application\OutboxMessageRouter;
use App\Shared\Application\OutboxPublisher;
use App\Shared\Application\OutboxRuntime;
use App\Shared\Application\RandomGenerator;
use App\Shared\Infrastructure\DatabaseOutboxDispatcher;
use App\Shared\Infrastructure\DatabaseOutboxPublisher;
use App\Shared\Infrastructure\DatabaseOutboxRuntime;
use App\Shared\Infrastructure\SecureRandomGenerator;
use App\Shared\Infrastructure\SystemClock;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

final class FoundationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(RandomGenerator::class, SecureRandomGenerator::class);
        $this->app->singleton(
            OutboxPublisher::class,
            fn (Application $application): OutboxPublisher => new DatabaseOutboxPublisher(
                $application->make(DatabaseManager::class),
                $application->make(Clock::class),
            ),
        );
        $this->app->singleton(DatabaseOutboxDispatcher::class);
        $this->app->singleton(InitialProvisioningOutboxHandler::class);
        $this->app->singleton(ServiceMutationOutboxHandler::class);
        $this->app->singleton(
            ServiceDeliveryOutboxHandler::class,
            fn (Application $application): ServiceDeliveryOutboxHandler => new ServiceDeliveryOutboxHandler(
                fn (): ServiceDeliveryEffectExecutor => $application->make(ServiceDeliveryEffectExecutor::class),
            ),
        );
        $this->app->tag(
            [InitialProvisioningOutboxHandler::class, ServiceMutationOutboxHandler::class],
            OutboxEventHandler::class,
        );
        $this->app->tag(
            [ServiceDeliveryOutboxHandler::class],
            OutboxEventHandler::class,
        );
        $this->app->singleton(
            OutboxMessageRouter::class,
            fn (Application $application): OutboxMessageRouter => new OutboxMessageRouter(
                $application->tagged(OutboxEventHandler::class),
            ),
        );
        $this->app->singleton(OutboxRuntime::class, DatabaseOutboxRuntime::class);
    }
}
