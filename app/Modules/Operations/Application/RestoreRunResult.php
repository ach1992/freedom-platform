<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

final readonly class RestoreRunResult
{
    public function __construct(
        public string $restoreRunId,
        public string $status,
        public string $sourceBackupId,
        public ?string $safetyBackupId,
    ) {}
}
