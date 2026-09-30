<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\BackupKind;
use App\Modules\Operations\Application\BackupManager;
use App\Modules\Operations\Application\Contracts\VerifiedPreUpdateBackupProvider;
use App\Modules\Operations\Application\RestoreManager;
use App\Modules\Operations\Application\VerifiedPreUpdateBackup;
use RuntimeException;

final readonly class RestoreVerifiedPreUpdateBackupProvider implements VerifiedPreUpdateBackupProvider
{
    public function __construct(
        private BackupManager $backups,
        private RestoreManager $restores,
    ) {}

    /** @requirement UPD-001 BAK-001 BAK-002 QUA-001 */
    public function createVerified(): VerifiedPreUpdateBackup
    {
        $artifact = $this->backups->create(BackupKind::PreUpdate);
        if ($artifact->kind !== BackupKind::PreUpdate) {
            throw new RuntimeException('The pre-update backup authority returned the wrong backup kind.');
        }

        $verification = $this->restores->run($artifact->backupId, false);
        if ($verification->status !== 'dry_run_completed'
            || ! hash_equals($artifact->backupId, $verification->sourceBackupId)
        ) {
            throw new RuntimeException('The pre-update backup could not be independently verified.');
        }

        return new VerifiedPreUpdateBackup($artifact->backupId, $artifact->completedAt);
    }
}
