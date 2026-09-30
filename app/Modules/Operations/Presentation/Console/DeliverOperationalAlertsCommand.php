<?php

declare(strict_types=1);

namespace App\Modules\Operations\Presentation\Console;

use App\Modules\Operations\Application\OperationalAlertDeliveryRunner;
use App\Modules\Operations\Application\OperationalAlertDeliveryRunSummary;
use App\Modules\Operations\Application\ScheduledTaskRunRecorder;
use App\Shared\Application\Clock;
use DateTimeZone;
use Illuminate\Console\Command;
use Throwable;

/** @requirement OPS-001 OPS-003 QUA-004 RUN-003 */
final class DeliverOperationalAlertsCommand extends Command
{
    protected $signature = 'operations:deliver-alerts
        {--limit=25 : Maximum number of due alert delivery intents to examine}
        {--json : Emit JSON only}';

    protected $description = 'Queue a bounded batch of persisted operational alert deliveries';

    public function handle(
        OperationalAlertDeliveryRunner $runner,
        ScheduledTaskRunRecorder $runs,
        Clock $clock,
    ): int {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 50) {
            return $this->invalidInput();
        }

        $startedAt = $clock->now()->setTimezone(new DateTimeZone('UTC'));
        $startedMonotonic = hrtime(true);
        $runId = $runs->start('operations.deliver-alerts', $startedAt);

        try {
            $summary = $runner->runDue($limit);
        } catch (Throwable $exception) {
            $this->finishRun(
                $runs,
                $clock,
                $runId,
                $startedMonotonic,
                'failed',
                [],
                $exception::class,
                'operational_alert_delivery_failed',
            );

            if ($this->option('json')) {
                $this->line('{"status":"failed","code":"operational_alert_delivery_failed"}');
            } else {
                $this->error('Operational alert delivery failed unexpectedly.');
            }

            return self::FAILURE;
        }

        $this->finishRun(
            $runs,
            $clock,
            $runId,
            $startedMonotonic,
            'succeeded',
            $this->metrics($summary),
            null,
            null,
        );

        $result = [
            'status' => $summary->failed > 0 ? 'manual_review' : 'ok',
            ...$summary->toArray(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } else {
            $this->info(sprintf(
                'Operational alert delivery: examined=%d queued=%d suppressed=%d retry=%d failed=%d',
                $summary->examined,
                $summary->queued,
                $summary->suppressed,
                $summary->retryScheduled,
                $summary->failed,
            ));
        }

        return $summary->failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, int> */
    private function metrics(OperationalAlertDeliveryRunSummary $summary): array
    {
        return [
            'examined' => $summary->examined,
            'queued' => $summary->queued,
            'suppressed' => $summary->suppressed,
            'retry_scheduled' => $summary->retryScheduled,
            'failed' => $summary->failed,
        ];
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

    private function invalidInput(): int
    {
        if ($this->option('json')) {
            $this->line('{"status":"invalid","code":"operational_alert_delivery_invalid_input"}');
        } else {
            $this->error('Operational alert delivery limit must be an integer between 1 and 50.');
        }

        return self::INVALID;
    }
}
