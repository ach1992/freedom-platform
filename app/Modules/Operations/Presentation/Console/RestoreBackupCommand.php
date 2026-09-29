<?php

declare(strict_types=1);

namespace App\Modules\Operations\Presentation\Console;

use App\Modules\Operations\Application\RestoreManager;
use Illuminate\Console\Command;
use Throwable;

final class RestoreBackupCommand extends Command
{
    protected $signature = 'operations:restore
        {backup_id : Completed authority-owned backup identifier}
        {--apply : Execute the controlled replacement; omit for dry-run}
        {--confirm= : Required for apply and must exactly match backup_id}
        {--json : Emit JSON only}';

    protected $description = 'Preflight or execute a fail-closed controlled restore workflow';

    /** @requirement BAK-002 SEC-001 QUA-001 */
    public function handle(RestoreManager $restores): int
    {
        $backupId = $this->argument('backup_id');
        $apply = (bool) $this->option('apply');
        $confirmation = $this->option('confirm');

        if (! is_string($backupId) || $backupId === '') {
            return $this->failure('invalid_backup_id');
        }

        if ($apply && (! is_string($confirmation) || ! hash_equals($backupId, $confirmation))) {
            return $this->failure('restore_confirmation_required');
        }

        try {
            $result = $restores->run($backupId, $apply);
        } catch (Throwable) {
            return $this->failure('restore_failed');
        }

        $payload = [
            'status' => $result->status,
            'restore_run_id' => $result->restoreRunId,
            'source_backup_id' => $result->sourceBackupId,
            'safety_backup_id' => $result->safetyBackupId,
        ];

        if ($result->status === 'completed_reporting_failed') {
            if ($this->option('json')) {
                $this->line(json_encode($payload + ['error_code' => 'final_report_unavailable'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error('Restore finished, but final reporting did not complete.');
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(
                $result->status === 'completed'
                    ? 'Controlled restore completed: '.$result->restoreRunId
                    : 'Restore dry-run completed: '.$result->restoreRunId,
            );
        }

        return self::SUCCESS;
    }

    private function failure(string $code): int
    {
        if ($this->option('json')) {
            $this->line(json_encode([
                'status' => 'failed',
                'error_code' => $code,
            ], JSON_THROW_ON_ERROR));
        } else {
            $this->error('Restore operation failed.');
        }

        return self::FAILURE;
    }
}
