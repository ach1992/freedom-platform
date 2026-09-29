<?php

declare(strict_types=1);

namespace App\Modules\Operations\Presentation\Console;

use App\Modules\Operations\Application\BackupTelegramExportService;
use Illuminate\Console\Command;
use Throwable;

final class QueueBackupTelegramExportCommand extends Command
{
    protected $signature = 'operations:backup:telegram-export
        {backupId : Completed backup identity}
        {--json : Emit JSON only}';

    protected $description = 'Queue a completed encrypted backup for protected multipart Owner delivery';

    /** @requirement BAK-001 SEC-001 SEC-002 OPS-003 QUA-001 */
    public function handle(BackupTelegramExportService $exports): int
    {
        $backupId = $this->argument('backupId');
        if (! is_string($backupId) || $backupId === '') {
            return $this->failure('invalid_backup_identity');
        }

        try {
            $receipt = $exports->queue($backupId);
        } catch (Throwable) {
            return $this->failure('backup_telegram_export_failed');
        }

        $result = [
            'status' => 'queued',
            'backup_id' => $receipt->backupId,
            'part_count' => $receipt->partCount,
            'part_bytes' => $receipt->partBytes,
            'manifest_sha256' => $receipt->manifestSha256,
            'delivery_operation_count' => count($receipt->deliveryOperationPublicIds),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'Backup Telegram export queued: %s (%d parts + manifest)',
                $receipt->backupId,
                $receipt->partCount,
            ));
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
            $this->error('Backup Telegram export failed.');
        }

        return self::FAILURE;
    }
}
