<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class ScheduledTaskRunRecorder
{
    public function __construct(private DatabaseManager $database) {}

    /** @requirement OPS-003 RUN-003 DAT-003 */
    public function start(string $taskName, DateTimeImmutable $startedAtUtc): string
    {
        if (preg_match('/\A[a-z0-9._-]{1,191}\z/', $taskName) !== 1) {
            throw new RuntimeException('Scheduled task name is invalid.');
        }

        $runId = (string) Str::uuid();
        $timestamp = $startedAtUtc
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');

        $this->database->connection()->table('scheduled_task_runs')->insert([
            'task_name' => $taskName,
            'run_id' => $runId,
            'state' => 'running',
            'started_at' => $timestamp,
            'finished_at' => null,
            'duration_ms' => null,
            'metrics' => null,
            'error_class' => null,
            'error_code' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $runId;
    }

    /** @param array<string, int> $metrics */
    public function finish(
        string $runId,
        string $state,
        DateTimeImmutable $finishedAtUtc,
        int $durationMs,
        array $metrics,
        ?string $errorClass,
        ?string $errorCode,
    ): void {
        if (preg_match('/\A[0-9a-f-]{36}\z/', $runId) !== 1
            || ! in_array($state, ['succeeded', 'failed'], true)
            || $durationMs < 0
            || $durationMs > 4_294_967_295
            || ($errorClass !== null
                && ($errorClass === ''
                    || strlen($errorClass) > 191
                    || preg_match('/[\x00-\x1F\x7F]/', $errorClass) === 1))
            || ($errorCode !== null
                && preg_match('/\A[a-z0-9._-]{1,64}\z/', $errorCode) !== 1)
        ) {
            throw new RuntimeException('Scheduled task run completion is invalid.');
        }

        foreach ($metrics as $key => $value) {
            if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $key) !== 1 || $value < 0) {
                throw new RuntimeException('Scheduled task run metric is invalid.');
            }
        }

        if ($state === 'succeeded' && ($errorClass !== null || $errorCode !== null)) {
            throw new RuntimeException('Successful scheduled task run cannot retain error metadata.');
        }
        if ($state === 'failed' && $errorCode === null) {
            throw new RuntimeException('Failed scheduled task run requires a safe error code.');
        }

        $timestamp = $finishedAtUtc
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
        $updated = $this->database->connection()->table('scheduled_task_runs')
            ->where('run_id', $runId)
            ->where('state', 'running')
            ->update([
                'state' => $state,
                'finished_at' => $timestamp,
                'duration_ms' => $durationMs,
                'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR),
                'error_class' => $errorClass,
                'error_code' => $errorCode,
                'updated_at' => $timestamp,
            ]);

        if ($updated !== 1) {
            throw new RuntimeException('Scheduled task run completion could not be recorded exactly once.');
        }
    }
}
