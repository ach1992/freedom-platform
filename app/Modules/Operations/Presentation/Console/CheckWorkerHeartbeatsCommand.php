<?php

declare(strict_types=1);

namespace App\Modules\Operations\Presentation\Console;

use App\Modules\Operations\Application\ScheduledTaskRunRecorder;
use App\Modules\Operations\Application\WorkerHeartbeatService;
use App\Shared\Application\Clock;
use DateTimeZone;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * @requirement OPS-001 OPS-003 RUN-003
 */
final class CheckWorkerHeartbeatsCommand extends Command
{
    protected $signature = 'operations:check-worker-heartbeats
        {--max-age=120 : Maximum allowed heartbeat age in seconds}
        {--json : Emit JSON only}';

    protected $description = 'Detect stale workers and persist deduplicated critical alerts';

    public function handle(
        WorkerHeartbeatService $heartbeats,
        ScheduledTaskRunRecorder $runs,
        Clock $clock,
    ): int {
        $maxAgeSeconds = filter_var(
            $this->option('max-age'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 86400]],
        );
        if ($maxAgeSeconds === false) {
            $this->error('Maximum heartbeat age must be between 1 and 86400 seconds.');

            return self::INVALID;
        }

        $startedAt = $clock->now()->setTimezone(new DateTimeZone('UTC'));
        $startedMonotonic = hrtime(true);
        $runId = $runs->start('operations.check-worker-heartbeats', $startedAt);

        try {
            $staleWorkerIds = $heartbeats->detectAndAlertStale($maxAgeSeconds);
        } catch (InvalidArgumentException $exception) {
            $this->finishRun(
                $runs,
                $clock,
                $runId,
                $startedMonotonic,
                'failed',
                [],
                $exception::class,
                'heartbeat_check_invalid',
            );
            $this->error($exception->getMessage());

            return self::INVALID;
        } catch (Throwable $exception) {
            $this->finishRun(
                $runs,
                $clock,
                $runId,
                $startedMonotonic,
                'failed',
                [],
                $exception::class,
                'heartbeat_check_failed',
            );

            throw $exception;
        }

        $this->finishRun(
            $runs,
            $clock,
            $runId,
            $startedMonotonic,
            'succeeded',
            ['stale_workers' => count($staleWorkerIds)],
            null,
            null,
        );

        $result = [
            'status' => $staleWorkerIds === [] ? 'healthy' : 'unhealthy',
            'stale_worker_ids' => $staleWorkerIds,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } elseif ($staleWorkerIds === []) {
            $this->info('All recorded worker heartbeats are fresh.');
        } else {
            $this->error(sprintf('Stale workers: %s', implode(', ', $staleWorkerIds)));
        }

        return $staleWorkerIds === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, int> $metrics */
    private function finishRun(
        ScheduledTaskRunRecorder $runs,
        Clock $clock,
        string $runId,
        int $startedMonotonic,
        string $state,
        array $metrics,
        ?string $errorClass,
        ?string $errorCode,
    ): void {
        $durationMs = max(0, (int) round((hrtime(true) - $startedMonotonic) / 1_000_000));
        $runs->finish(
            $runId,
            $state,
            $clock->now()->setTimezone(new DateTimeZone('UTC')),
            $durationMs,
            $metrics,
            $errorClass,
            $errorCode,
        );
    }
}
