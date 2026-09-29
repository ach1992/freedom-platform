<?php

declare(strict_types=1);

namespace App\Modules\Operations\Presentation\Console;

use App\Modules\Operations\Application\UpdateManager;
use App\Modules\Operations\Application\UpdateRunResult;
use Illuminate\Console\Command;
use Throwable;

final class RollbackUpdateCommand extends Command
{
    protected $signature = 'operations:update:rollback
        {--apply : Execute a compatibility-proven code rollback}
        {--confirm= : Exact active release identifier required with --apply}
        {--json : Emit sanitized JSON only}';

    protected $description = 'Evaluate and optionally execute compatibility-aware controlled code rollback';

    /** @requirement UPD-001 BAK-002 SEC-001 QUA-001 */
    public function handle(UpdateManager $updates): int
    {
        $apply = (bool) $this->option('apply');
        $confirmation = $this->option('confirm');

        if ($apply && ! is_string($confirmation)) {
            return $this->emitFailure();
        }

        try {
            $result = $updates->rollback(
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
            'release_id' => $result->releaseId,
            'previous_release' => $result->previousRelease,
            'pre_update_backup_id' => $result->preUpdateBackupId,
            'pre_update_backup_completed_at' => $result->preUpdateBackupCompletedAt,
            'restore_required' => $result->restoreRequired,
        ];

        if ($result->restoreRequired && $result->preUpdateBackupId !== null) {
            $payload['controlled_restore_command'] = sprintf(
                'php artisan operations:restore %s --apply --confirm=%s --json',
                $result->preUpdateBackupId,
                $result->preUpdateBackupId,
            );
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));

            return;
        }

        $this->line('Controlled rollback status: '.$result->status);
        if ($result->restoreRequired) {
            $this->warn('Code rollback is schema-incompatible; use the verified pre-update backup through controlled Restore.');
        }
    }

    private function emitFailure(): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode(['status' => 'failed', 'error' => 'controlled_rollback_failed'], JSON_THROW_ON_ERROR));
        } else {
            $this->error('Controlled rollback preflight failed.');
        }

        return self::FAILURE;
    }
}
