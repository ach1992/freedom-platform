<?php

declare(strict_types=1);

namespace App\Modules\Operations\Presentation\Console;

use App\Modules\Operations\Application\ScheduledTaskRunRecorder;
use App\Shared\Application\Clock;
use App\Shared\Application\OutboxRuntime;
use App\Shared\Application\OutboxRuntimeResult;
use DateTimeZone;
use Illuminate\Console\Command;
use Throwable;

/** @requirement ARCH-004 OPS-003 QUA-004 SEC-008 RUN-003 */
final class DispatchOutboxCommand extends Command
{
    private const MAX_BATCH_SIZE = 1000;

    protected $signature = 'operations:dispatch-outbox
        {--limit=100 : Maximum number of due Outbox messages to examine}
        {--json : Emit JSON only}';

    protected $description = 'Dispatch a bounded batch from the common Transactional Outbox';

    public function handle(
        OutboxRuntime $runtime,
        ScheduledTaskRunRecorder $runs,
        Clock $clock,
    ): int {
        $rawLimit = $this->option('limit');
        $limit = filter_var($rawLimit, FILTER_VALIDATE_INT);

        if ($limit === false || $limit < 1 || $limit > self::MAX_BATCH_SIZE) {
            return $this->invalidInput('Outbox dispatch limit must be an integer between 1 and '.self::MAX_BATCH_SIZE.'.');
        }

        $startedAt = $clock->now()->setTimezone(new DateTimeZone('UTC'));
        $startedMonotonic = hrtime(true);
        $runId = $runs->start('operations.dispatch-outbox', $startedAt);

        try {
            $result = $runtime->dispatchBatch($limit);
        } catch (Throwable $exception) {
            $this->finishRun(
                $runs,
                $clock,
                $runId,
                $startedMonotonic,
                'failed',
                [],
                $exception::class,
                'outbox_dispatch_failed',
            );

            if ($this->option('json')) {
                $this->line('{"status":"failed","code":"outbox_dispatch_failed"}');
            } else {
                $this->error('Outbox dispatch failed unexpectedly.');
            }

            return self::FAILURE;
        }

        $this->finishRun(
            $runs,
            $clock,
            $runId,
            $startedMonotonic,
            'succeeded',
            $this->metrics($result),
            null,
            null,
        );

        if ($this->option('json')) {
            $this->line(json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        } else {
            $this->info(sprintf(
                'Outbox dispatch %s: examined=%d success=%d retryable=%d definitive=%d uncertain=%d due=%d oldest_due_age_seconds=%s review_required=%d',
                $result->status(),
                $result->examined,
                $result->success,
                $result->retryableFailure,
                $result->definitiveFailure,
                $result->uncertainResult,
                $result->dueBacklog,
                $result->oldestDueAgeSeconds === null ? 'none' : (string) $result->oldestDueAgeSeconds,
                $result->reviewRequired,
            ));
        }

        return self::SUCCESS;
    }

    /** @return array<string,int> */
    private function metrics(OutboxRuntimeResult $result): array
    {
        return [
            'examined' => $result->examined,
            'success' => $result->success,
            'retryable_failure' => $result->retryableFailure,
            'definitive_failure' => $result->definitiveFailure,
            'uncertain_result' => $result->uncertainResult,
            'due_backlog' => $result->dueBacklog,
            'review_required' => $result->reviewRequired,
        ];
    }

    /** @param array<string,int> $metrics */
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

    private function invalidInput(string $message): int
    {
        if ($this->option('json')) {
            $this->line('{"status":"invalid","code":"outbox_dispatch_invalid_input"}');
        } else {
            $this->error($message);
        }

        return self::INVALID;
    }
}
