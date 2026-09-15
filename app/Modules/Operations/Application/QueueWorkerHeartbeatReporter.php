<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Shared\Application\Clock;
use App\Shared\Application\SafeLogContext;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

final class QueueWorkerHeartbeatReporter
{
    private ?DateTimeImmutable $lastRecordedAt = null;

    /** @requirement OPS-001 OPS-003 RUN-003 RUN-004 */
    public function __construct(
        private readonly WorkerHeartbeatService $heartbeats,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled,
        private readonly string $workerId,
        private readonly string $queueGroup,
        private readonly ?string $releaseVersion,
        private readonly int $intervalSeconds,
    ) {}

    public function report(?string $queue = null): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $now = $this->clock->now();

        if ($this->lastRecordedAt !== null
            && ($now->getTimestamp() - $this->lastRecordedAt->getTimestamp()) < max(1, $this->intervalSeconds)
        ) {
            return false;
        }

        $this->heartbeats->record(
            $this->workerId,
            $queue === null || trim($queue) === '' ? $this->queueGroup : $queue,
            $this->releaseVersion,
        );
        $this->lastRecordedAt = $now;

        return true;
    }

    public function reportSafely(?string $queue = null): void
    {
        try {
            $this->report($queue);
        } catch (Throwable $exception) {
            $this->logger->warning(
                'Queue worker heartbeat could not be recorded.',
                SafeLogContext::from([
                    'event' => 'operations.worker_heartbeat_record_failed',
                    'exception_class' => $exception::class,
                ])->values(),
            );
        }
    }
}
