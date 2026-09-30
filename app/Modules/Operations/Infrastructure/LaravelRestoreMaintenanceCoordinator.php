<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\RestoreMaintenanceCoordinator;
use App\Modules\Operations\Application\Contracts\RestoreSchedulerMutationLock;
use App\Modules\Operations\Application\RuntimeDeploymentInvariants;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use RuntimeException;
use Throwable;

final readonly class LaravelRestoreMaintenanceCoordinator implements RestoreMaintenanceCoordinator
{
    public function __construct(
        private MaintenanceMode $maintenance,
        private Kernel $console,
        private RuntimeDeploymentInvariants $deploymentInvariants,
        private RestoreSchedulerMutationLock $schedulerMutationLock,
        private string $supervisorTemplatePath,
        private int $quiesceSeconds,
        private ?Closure $waiter = null,
    ) {
        if ($quiesceSeconds < 1 || $quiesceSeconds > 3600
            || ! str_starts_with($supervisorTemplatePath, DIRECTORY_SEPARATOR)
        ) {
            throw new RuntimeException('Restore quiescence configuration is invalid.');
        }
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function enter(string $restoreRunId): void
    {
        $this->assertRunId($restoreRunId);

        if ($this->maintenance->active()) {
            throw new RuntimeException('Application maintenance mode is already active.');
        }

        if (! is_file($this->supervisorTemplatePath)
            || is_link($this->supervisorTemplatePath)
            || ! is_readable($this->supervisorTemplatePath)
        ) {
            throw new RuntimeException('Restore worker quiescence authority is unavailable.');
        }

        $supervisorTemplate = file_get_contents($this->supervisorTemplatePath);
        if (! is_string($supervisorTemplate)
            || ! $this->deploymentInvariants->restoreQuiescenceCompatible(
                $this->quiesceSeconds,
                $supervisorTemplate,
            )
        ) {
            throw new RuntimeException('Restore quiescence is shorter than the reviewed worker timeout boundary.');
        }

        $activated = false;

        try {
            $this->maintenance->activate($this->maintenancePayload($restoreRunId));
            $activated = true;

            $this->schedulerMutationLock->acquire($this->quiesceSeconds);

            if ($this->console->call('queue:restart', ['--no-interaction' => true]) !== 0) {
                throw new RuntimeException('Queue worker quiescence could not be requested.');
            }

            $this->waitForWorkers();
            $this->assertOwned($restoreRunId);
        } catch (Throwable $throwable) {
            try {
                $this->schedulerMutationLock->release();
            } catch (Throwable) {
                // The process still owns any unreleased file lock until it terminates.
            }

            if ($activated) {
                try {
                    $data = $this->maintenance->active() ? $this->maintenance->data() : [];
                    if (($data['restore_run_id'] ?? null) === $restoreRunId) {
                        $this->maintenance->deactivate();
                    }
                } catch (Throwable) {
                    // Fail closed: an uncertain maintenance state must not be hidden.
                }
            }

            throw $throwable;
        }
    }

    /** @requirement UPD-001 BAK-002 OPS-003 QUA-001 */
    public function adopt(string $restoreRunId): void
    {
        $this->assertRunId($restoreRunId);
        $this->assertOwned($restoreRunId);

        if (! is_file($this->supervisorTemplatePath)
            || is_link($this->supervisorTemplatePath)
            || ! is_readable($this->supervisorTemplatePath)
        ) {
            throw new RuntimeException('Restore worker quiescence authority is unavailable.');
        }

        $supervisorTemplate = file_get_contents($this->supervisorTemplatePath);
        if (! is_string($supervisorTemplate)
            || ! $this->deploymentInvariants->restoreQuiescenceCompatible(
                $this->quiesceSeconds,
                $supervisorTemplate,
            )
        ) {
            throw new RuntimeException('Restore quiescence is shorter than the reviewed worker timeout boundary.');
        }

        if (! $this->schedulerMutationLock->held()) {
            $this->schedulerMutationLock->acquire($this->quiesceSeconds);
        }
        if (! $this->schedulerMutationLock->held()) {
            throw new RuntimeException('Retained Restore Scheduler mutation fence could not be adopted.');
        }

        if ($this->console->call('queue:restart', ['--no-interaction' => true]) !== 0) {
            throw new RuntimeException('Retained queue worker containment could not be re-established.');
        }

        $this->waitForWorkers();
        $this->assertOwned($restoreRunId);
        if (! $this->schedulerMutationLock->held()) {
            throw new RuntimeException('Retained Restore Scheduler mutation fence was lost during adoption.');
        }
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function refreshRuntime(string $restoreRunId): void
    {
        $this->assertRunId($restoreRunId);
        $this->assertOwned($restoreRunId);

        if ($this->console->call('config:clear', ['--no-ansi' => true, '--no-interaction' => true]) !== 0) {
            throw new RuntimeException('Restored configuration cache could not be cleared.');
        }

        if ($this->console->call('queue:restart', ['--no-interaction' => true]) !== 0) {
            throw new RuntimeException('Restored queue workers could not be restarted.');
        }

        $this->waitForWorkers();
        $this->assertOwned($restoreRunId);
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function leave(string $restoreRunId): void
    {
        $this->assertRunId($restoreRunId);

        $this->assertOwned($restoreRunId);

        $this->maintenance->deactivate();

        if ($this->maintenance->active()) {
            throw new RuntimeException('Restore maintenance mode could not be released.');
        }

        $this->schedulerMutationLock->release();
        if ($this->schedulerMutationLock->held()) {
            throw new RuntimeException('Restore Scheduler mutation fence could not be released.');
        }
    }

    /**
     * Re-establish and prove the post-mutation containment state after an
     * uncertain failure. Full containment additionally requires queue-worker
     * quiescence after maintenance and the Scheduler fence are re-established.
     *
     * @return array{maintenance_owned:bool,scheduler_fence_held:bool,workers_quiesced:bool}
     *
     * @requirement BAK-002 OPS-003 QUA-001
     */
    public function retain(string $restoreRunId): array
    {
        $this->assertRunId($restoreRunId);
        $maintenanceOwned = false;
        $schedulerFenceHeld = false;
        $workersQuiesced = false;

        try {
            if (! $this->maintenance->active()) {
                $this->maintenance->activate($this->maintenancePayload($restoreRunId));
            }

            $this->assertOwned($restoreRunId);
            $maintenanceOwned = true;
        } catch (Throwable) {
            // Keep collecting independent containment evidence below.
        }

        try {
            if (! $this->schedulerMutationLock->held()) {
                $this->schedulerMutationLock->acquire($this->quiesceSeconds);
            }

            $schedulerFenceHeld = $this->schedulerMutationLock->held();
        } catch (Throwable) {
            $schedulerFenceHeld = false;
        }

        if ($maintenanceOwned && $schedulerFenceHeld) {
            try {
                if ($this->console->call('queue:restart', ['--no-interaction' => true]) !== 0) {
                    throw new RuntimeException('Queue worker recontainment could not be requested.');
                }

                $this->waitForWorkers();
                $this->assertOwned($restoreRunId);
                $maintenanceOwned = true;
                $schedulerFenceHeld = $this->schedulerMutationLock->held();
                $workersQuiesced = $schedulerFenceHeld;
            } catch (Throwable) {
                try {
                    $this->assertOwned($restoreRunId);
                    $maintenanceOwned = true;
                } catch (Throwable) {
                    $maintenanceOwned = false;
                }

                try {
                    $schedulerFenceHeld = $this->schedulerMutationLock->held();
                } catch (Throwable) {
                    $schedulerFenceHeld = false;
                }

                $workersQuiesced = false;
            }
        }

        return [
            'maintenance_owned' => $maintenanceOwned,
            'scheduler_fence_held' => $schedulerFenceHeld,
            'workers_quiesced' => $workersQuiesced,
        ];
    }

    /** @return array<string, mixed> */
    private function maintenancePayload(string $restoreRunId): array
    {
        return [
            'except' => [],
            'redirect' => null,
            'retry' => null,
            'refresh' => null,
            'secret' => null,
            'status' => 503,
            'template' => null,
            'restore_run_id' => $restoreRunId,
        ];
    }

    private function assertOwned(string $restoreRunId): void
    {
        if (! $this->maintenance->active()) {
            throw new RuntimeException('Restore maintenance mode is no longer active.');
        }

        $data = $this->maintenance->data();
        if (! is_string($data['restore_run_id'] ?? null)
            || ! hash_equals($restoreRunId, $data['restore_run_id'])
        ) {
            throw new RuntimeException('Restore maintenance ownership changed unexpectedly.');
        }
    }

    private function waitForWorkers(): void
    {
        ($this->waiter ?? static function (int $seconds): void {
            sleep($seconds);
        })($this->quiesceSeconds);
    }

    private function assertRunId(string $restoreRunId): void
    {
        if (preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}\z/', $restoreRunId) !== 1) {
            throw new RuntimeException('The restore run identifier is invalid.');
        }
    }
}
