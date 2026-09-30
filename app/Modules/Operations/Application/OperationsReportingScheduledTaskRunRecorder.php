<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Reporting\Application\Contracts\ReportingScheduledTaskRunRecorder;
use DateTimeImmutable;

final readonly class OperationsReportingScheduledTaskRunRecorder implements ReportingScheduledTaskRunRecorder
{
    public function __construct(private ScheduledTaskRunRecorder $runs) {}

    public function start(string $taskName, DateTimeImmutable $startedAtUtc): string
    {
        return $this->runs->start($taskName, $startedAtUtc);
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
        $this->runs->finish(
            $runId,
            $state,
            $finishedAtUtc,
            $durationMs,
            $metrics,
            $errorClass,
            $errorCode,
        );
    }
}
