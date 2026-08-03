<?php

declare(strict_types=1);

namespace App\Providers;

use App\Shared\Application\Clock;
use App\Shared\Application\OutboxPublisher;
use App\Shared\Application\RandomGenerator;
use App\Shared\Infrastructure\DatabaseOutboxPublisher;
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
    }
}
