<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

final readonly class ReportScheduleRunSummary
{
    public function __construct(
        public int $examined,
        public int $queued,
        public int $retryScheduled,
        public int $disabled,
    ) {}

    /** @return array{examined:int,queued:int,retry_scheduled:int,disabled:int} */
    public function toArray(): array
    {
        return [
            'examined' => $this->examined,
            'queued' => $this->queued,
            'retry_scheduled' => $this->retryScheduled,
            'disabled' => $this->disabled,
        ];
    }
}
