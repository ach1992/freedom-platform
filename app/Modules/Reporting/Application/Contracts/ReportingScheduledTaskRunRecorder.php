<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Contracts;

use DateTimeImmutable;

interface ReportingScheduledTaskRunRecorder
{
    public function start(string $taskName, DateTimeImmutable $startedAtUtc): string;

    /** @param array<string, int> $metrics */
    public function finish(
        string $runId,
        string $state,
        DateTimeImmutable $finishedAtUtc,
        int $durationMs,
        array $metrics,
        ?string $errorClass,
        ?string $errorCode,
    ): void;
}
