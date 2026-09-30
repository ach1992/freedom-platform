<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use InvalidArgumentException;

final readonly class OperationalAlertDeliveryRunSummary
{
    public function __construct(
        public int $examined,
        public int $queued,
        public int $suppressed,
        public int $retryScheduled,
        public int $failed,
    ) {
        foreach ([$examined, $queued, $suppressed, $retryScheduled, $failed] as $count) {
            if ($count < 0) {
                throw new InvalidArgumentException('Operational alert delivery counts must not be negative.');
            }
        }

        if ($examined !== $queued + $suppressed + $retryScheduled + $failed) {
            throw new InvalidArgumentException('Operational alert delivery outcomes must equal examined count.');
        }
    }

    /** @return array{examined:int,queued:int,suppressed:int,retry_scheduled:int,failed:int} */
    public function toArray(): array
    {
        return [
            'examined' => $this->examined,
            'queued' => $this->queued,
            'suppressed' => $this->suppressed,
            'retry_scheduled' => $this->retryScheduled,
            'failed' => $this->failed,
        ];
    }
}
