<?php

declare(strict_types=1);

namespace App\Modules\Operations\Presentation\Console;

use App\Modules\Operations\Application\UpdateManager;
use App\Modules\Operations\Application\UpdateRunResult;
use Illuminate\Console\Command;
use Throwable;

final class RecoverUpdateCommand extends Command
{
    protected $signature = 'operations:update:recover
        {update-run : Exact protected update run identifier requiring controlled Restore recovery}
        {--apply : Execute the controlled Restore recovery handoff}
        {--confirm= : Exact update run identifier required with --apply}
        {--json : Emit sanitized JSON only}';

    protected $description = 'Verify and optionally recover an update through its exact protected pre-update backup';

    /** @requirement UPD-001 BAK-002 RUN-002 OPS-003 SEC-001 QUA-001 */
    public function handle(UpdateManager $updates): int
    {
        $updateRunId = $this->argument('update-run');
        $apply = (bool) $this->option('apply');
        $confirmation = $this->option('confirm');

        if (! is_string($updateRunId)
            || $updateRunId === ''
            || ($apply && ! is_string($confirmation))
        ) {
            return $this->emitFailure();
        }

        try {
            $result = $updates->recover(
                $updateRunId,
                $apply,
                is_string($confirmation) ? $confirmation : null,
            );
        } catch (Throwable) {
            return $this->emitFailure();
        }

        $this->emit($result);

        return $result->successful() ? self::SUCCESS : self::FAILURE;
    }

    private function emit(UpdateRunResult $result): void
    {
        $payload = [
            'status' => $result->status,
            'update_run_id' => $result->updateRunId,
            'failed_release_id' => $result->releaseId,
            'target_previous_release' => $result->previousRelease,
            'pre_update_backup_id' => $result->preUpdateBackupId,
            'pre_update_backup_completed_at' => $result->preUpdateBackupCompletedAt,
            'restore_required' => $result->restoreRequired,
        ];

        if ($result->status === 'restore_recovery_dry_run_completed') {
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

        $this->line('Controlled update recovery status: '.$result->status);
        $this->line('Source update run: '.$result->updateRunId);
        if ($result->status === 'restore_recovery_dry_run_completed') {
            $this->warn('Recovery was preflighted only; retained processing containment has not been released.');
        }
    }

    private function emitFailure(): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'status' => 'failed',
                'error' => 'controlled_update_recovery_failed',
            ], JSON_THROW_ON_ERROR));
        } else {
            $this->error('Controlled update recovery failed.');
        }

        return self::FAILURE;
    }
}
