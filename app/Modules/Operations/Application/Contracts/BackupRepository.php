<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

use App\Modules\Operations\Application\ResolvedBackup;
use Closure;
use DateTimeImmutable;

interface BackupRepository
{
    public function authority(): string;

    /**
     * @template T
     *
     * @param  Closure(self):T  $callback
     * @return T
     */
    public function synchronized(
        Closure $callback,
        bool $priority = false,
        int $waitMilliseconds = 0,
    ): mixed;

    public function recoverIncomplete(): void;

    public function workingDirectory(string $backupId): string;

    public function discardWorkingDirectory(string $path): void;

    /** @return array{artifact:string,manifest:string} */
    public function publish(string $backupId, string $artifactPath, string $manifestPath): array;

    public function prune(DateTimeImmutable $now, int $retentionDays): void;

    public function completedBackup(string $backupId): ResolvedBackup;

    /** @return array{filename:string,bytes:int,sha256:string,completed_at:string} */
    public function completedArtifactMetadata(string $backupId): array;

    /**
     * completed_count is the number of validated completed backups observed inside the bounded inspection.
     *
     * @return array{
     *     completed_count:int,
     *     inspection_complete:bool,
     *     inspected_entries:int,
     *     latest_bytes:int|null,
     *     latest_completed_at:string|null
     * }
     */
    public function operationalStatus(): array;

    public function readArtifactSlice(string $backupId, int $offset, int $length): string;

    public function storeTelegramExportManifest(string $backupId, string $contents): void;

    public function telegramExportManifest(string $backupId): string;
}
