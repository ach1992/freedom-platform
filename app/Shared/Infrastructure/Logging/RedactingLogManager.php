<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as Monolog;

final class RedactingLogManager extends LogManager
{
    /**
     * Keep Laravel's emergency fallback behavior while applying the same
     * sensitive-data processor used by configured application channels.
     */
    protected function createEmergencyLogger()
    {
        $config = $this->configurationFor('emergency');

        $handler = new StreamHandler(
            $config['path'] ?? $this->app->storagePath().'/logs/laravel.log',
            Level::Debug,
        );

        $monolog = new Monolog('laravel', [$this->prepareHandler($handler)]);
        $monolog->pushProcessor(new RedactSensitiveDataProcessor);

        return new Logger($monolog, $this->app->make(Dispatcher::class));
    }
}
