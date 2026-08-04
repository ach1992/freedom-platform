<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\QueueWorkerHeartbeatReporter;
use App\Modules\Operations\Application\WorkerHeartbeatService;
use App\Shared\Application\Clock;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

final class OperationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            QueueWorkerHeartbeatReporter::class,
            fn (Application $application): QueueWorkerHeartbeatReporter => new QueueWorkerHeartbeatReporter(
                $application->make(WorkerHeartbeatService::class),
                $application->make(Clock::class),
                $application->make(LoggerInterface::class),
                (bool) config('operations.worker_heartbeat.enabled', false),
                (string) config('operations.worker_heartbeat.worker_id', ''),
                (string) config('operations.worker_heartbeat.queue_group', 'default'),
                $this->nullableString(config('operations.worker_heartbeat.release_version')),
                max(1, (int) config('operations.worker_heartbeat.interval_seconds', 30)),
            ),
        );
    }

    public function boot(): void
    {
        if (! (bool) config('operations.worker_heartbeat.enabled', false)) {
            return;
        }

        $reporter = $this->app->make(QueueWorkerHeartbeatReporter::class);

        Queue::looping(static function () use ($reporter): void {
            $reporter->reportSafely();
        });
        Queue::before(static function (JobProcessing $event) use ($reporter): void {
            $reporter->reportSafely($event->job->getQueue());
        });
        Queue::after(static function (JobProcessed $event) use ($reporter): void {
            $reporter->reportSafely($event->job->getQueue());
        });
        Queue::exceptionOccurred(static function (JobExceptionOccurred $event) use ($reporter): void {
            $reporter->reportSafely($event->job->getQueue());
        });
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
