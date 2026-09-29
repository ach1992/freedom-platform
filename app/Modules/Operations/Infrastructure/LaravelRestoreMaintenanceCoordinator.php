<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\RestoreMaintenanceCoordinator;
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
            $this->maintenance->activate([
                'except' => [],
                'redirect' => null,
                'retry' => null,
                'refresh' => null,
                'secret' => null,
                'status' => 503,
                'template' => null,
                'restore_run_id' => $restoreRunId,
            ]);
            $activated = true;

            if ($this->console->call('queue:restart', ['--no-interaction' => true]) !== 0) {
                throw new RuntimeException('Queue worker quiescence could not be requested.');
            }

            ($this->waiter ?? static function (int $seconds): void {
                sleep($seconds);
            })($this->quiesceSeconds);

            $data = $this->maintenance->data();
            if (! $this->maintenance->active()
                || ! is_string($data['restore_run_id'] ?? null)
                || ! hash_equals($restoreRunId, $data['restore_run_id'])
            ) {
                throw new RuntimeException('Restore maintenance ownership could not be verified.');
            }
        } catch (Throwable $throwable) {
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

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function leave(string $restoreRunId): void
    {
        $this->assertRunId($restoreRunId);

        if (! $this->maintenance->active()) {
            throw new RuntimeException('Restore maintenance mode is no longer active.');
        }

        $data = $this->maintenance->data();
        if (! is_string($data['restore_run_id'] ?? null)
            || ! hash_equals($restoreRunId, $data['restore_run_id'])
        ) {
            throw new RuntimeException('Restore maintenance ownership changed unexpectedly.');
        }

        $this->maintenance->deactivate();

        if ($this->maintenance->active()) {
            throw new RuntimeException('Restore maintenance mode could not be released.');
        }
    }

    private function assertRunId(string $restoreRunId): void
    {
        if (preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}\z/', $restoreRunId) !== 1) {
            throw new RuntimeException('The restore run identifier is invalid.');
        }
    }
}
