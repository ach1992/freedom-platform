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
            WorkerRuntimeConfiguration::class,
            static fn (): WorkerRuntimeConfiguration => WorkerRuntimeConfiguration::resolve([
                'enabled' => config('operations.worker_heartbeat.enabled', false),
                'worker_id' => config('operations.worker_heartbeat.worker_id'),
                'queue_group' => config('operations.worker_heartbeat.queue_group', 'default'),
                'release_version' => config('operations.worker_heartbeat.release_version'),
                'interval_seconds' => config('operations.worker_heartbeat.interval_seconds', 30),
            ]),
        );

        $this->app->singleton(
            QueueWorkerHeartbeatReporter::class,
            function (Application $application): QueueWorkerHeartbeatReporter {
                $runtime = $application->make(WorkerRuntimeConfiguration::class);

                return new QueueWorkerHeartbeatReporter(
                    $application->make(WorkerHeartbeatService::class),
                    $application->make(Clock::class),
                    $application->make(LoggerInterface::class),
                    $runtime->enabled,
                    $runtime->workerId,
                    $runtime->queueGroup,
                    $runtime->releaseVersion,
                    $runtime->intervalSeconds,
                );
            },
        );
    }

    public function boot(): void
    {
        $runtime = $this->app->make(WorkerRuntimeConfiguration::class);

        if (! $runtime->enabled) {
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
}
