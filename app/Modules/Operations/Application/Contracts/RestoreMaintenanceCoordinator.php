<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface RestoreMaintenanceCoordinator
{
    public function enter(string $restoreRunId): void;

    public function refreshRuntime(string $restoreRunId): void;

    public function leave(string $restoreRunId): void;

    /** @return array{maintenance_owned:bool,scheduler_fence_held:bool,workers_quiesced:bool} */
    public function retain(string $restoreRunId): array;
}
