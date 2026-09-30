<?php

declare(strict_types=1);

namespace App\Modules\Operations\Presentation\Console;

use App\Modules\Operations\Application\UpdateManager;
use App\Modules\Operations\Application\UpdateRunResult;
use Illuminate\Console\Command;
use Throwable;

final class ApplyUpdateCommand extends Command
{
    protected $signature = 'operations:update
        {package : Absolute .tar path inside the controlled update package root}
        {--trusted-sha256= : Out-of-band trusted SHA-256 for the complete package}
        {--apply : Execute the controlled update after preflight}
        {--confirm= : Exact release identifier required with --apply}
        {--json : Emit sanitized JSON only}';

    protected $description = 'Verify and optionally apply a controlled signed/checksummed application release';

    /** @requirement UPD-001 SEC-001 QUA-001 */
    public function handle(UpdateManager $updates): int
    {
        $package = $this->argument('package');
        $checksum = $this->option('trusted-sha256');
        $apply = (bool) $this->option('apply');
        $confirmation = $this->option('confirm');

        if (! is_string($package)
            || ! is_string($checksum)
            || $checksum === ''
            || ($apply && ! is_string($confirmation))
        ) {
            return $this->emitFailure('Controlled update arguments are invalid.');
        }

        try {
            $result = $updates->run(
                $package,
                $checksum,
                $apply,
                is_string($confirmation) ? $confirmation : null,
            );
        } catch (Throwable) {
            return $this->emitFailure('Controlled update preflight failed.');
        }

        $this->emit($result);

        return $result->successful() ? self::SUCCESS : self::FAILURE;
    }

    private function emit(UpdateRunResult $result): void
    {
        $payload = [
            'status' => $result->status,
            'update_run_id' => $result->updateRunId,
            'release_id' => $result->releaseId,
            'previous_release' => $result->previousRelease,
            'pre_update_backup_id' => $result->preUpdateBackupId,
            'pre_update_backup_completed_at' => $result->preUpdateBackupCompletedAt,
            'restore_required' => $result->restoreRequired,
        ];

        if ($result->restoreRequired && $result->preUpdateBackupId !== null) {
            $payload['controlled_restore_command'] = sprintf(
                'php artisan operations:update:recover %s --apply --confirm=%s --json',
                $result->updateRunId,
                $result->updateRunId,
            );
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));

            return;
        }

        $this->line('Controlled update status: '.$result->status);
        $this->line('Update run: '.$result->updateRunId);
        if ($result->restoreRequired) {
            $this->warn('Safe code rollback is not sufficient; use the controlled update-recovery handoff into the existing Restore authority.');
        }
    }

    private function emitFailure(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode(['status' => 'failed', 'error' => 'controlled_update_failed'], JSON_THROW_ON_ERROR));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
