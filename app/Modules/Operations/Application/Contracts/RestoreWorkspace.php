<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface RestoreWorkspace
{
    public function create(string $restoreRunId): string;

    public function discard(string $workspacePath): void;

    /** @param array<string, mixed> $report */
    public function storeReport(string $restoreRunId, array $report): void;

    /** @return array{latest_status:string|null,latest_failure_code:string|null,latest_completed_at:string|null} */
    public function operationalStatus(): array;
}
