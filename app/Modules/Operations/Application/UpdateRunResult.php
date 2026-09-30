<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

final readonly class UpdateRunResult
{
    public function __construct(
        public string $updateRunId,
        public string $status,
        public string $releaseId,
        public ?string $previousRelease,
        public ?string $preUpdateBackupId,
        public ?string $preUpdateBackupCompletedAt,
        public bool $restoreRequired,
    ) {}

    public function successful(): bool
    {
        return in_array($this->status, [
            'dry_run_completed',
            'completed',
            'rollback_dry_run_completed',
            'rollback_completed',
            'restore_recovery_dry_run_completed',
            'restore_recovered',
        ], true);
    }
}
