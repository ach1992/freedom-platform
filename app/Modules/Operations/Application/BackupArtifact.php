<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

final readonly class BackupArtifact
{
    public function __construct(
        public string $backupId,
        public BackupKind $kind,
        public string $artifactFilename,
        public string $manifestFilename,
        public int $artifactBytes,
        public string $artifactSha256,
        public string $completedAt,
    ) {}
}
