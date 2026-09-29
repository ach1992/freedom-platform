<?php

declare(strict_types=1);

namespace App\Modules\Operations\Presentation\Console;

use App\Modules\Operations\Application\BackupKind;
use App\Modules\Operations\Application\BackupManager;
use Illuminate\Console\Command;
use Throwable;

final class CreateBackupCommand extends Command
{
    protected $signature = 'operations:backup
        {--kind=frequent_database : frequent_database, daily_full, or pre_update}
        {--json : Emit JSON only}';

    protected $description = 'Create a protected encrypted application backup artifact';

    /** @requirement BAK-001 SEC-001 QUA-001 */
    public function handle(BackupManager $backups): int
    {
        $kindValue = $this->option('kind');
        $kind = is_string($kindValue) ? BackupKind::tryFrom($kindValue) : null;

        if ($kind === null) {
            return $this->failure('invalid_backup_kind');
        }

        try {
            $artifact = $backups->create($kind);
        } catch (Throwable) {
            return $this->failure('backup_failed');
        }

        $result = [
            'status' => 'completed',
            'backup_id' => $artifact->backupId,
            'kind' => $artifact->kind->value,
            'artifact' => $artifact->artifactFilename,
            'manifest' => $artifact->manifestFilename,
            'artifact_bytes' => $artifact->artifactBytes,
            'artifact_sha256' => $artifact->artifactSha256,
            'completed_at' => $artifact->completedAt,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Backup completed: '.$artifact->backupId);
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
            $this->error('Backup operation failed.');
        }

        return self::FAILURE;
    }
}
