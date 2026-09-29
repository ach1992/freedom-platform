<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

final readonly class ResolvedBackup
{
    public function __construct(
        public string $backupId,
        public BackupKind $kind,
        public string $artifactPath,
        public int $artifactBytes,
        public string $artifactSha256,
        public string $completedAt,
        public string $applicationVersion,
        public string $phpVersion,
        public string $databaseDriver,
        public string $composerLockSha256,
        public string $migrationsSha256,
        public int $entryCount,
        public bool $includesPrivateFiles,
        public string $encryptionAlgorithm,
        public string $encryptionKeyId,
    ) {}
}
