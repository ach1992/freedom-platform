<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

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
    public function synchronized(Closure $callback): mixed;

    public function recoverIncomplete(): void;

    public function workingDirectory(string $backupId): string;

    public function discardWorkingDirectory(string $path): void;

    /** @return array{artifact:string,manifest:string} */
    public function publish(string $backupId, string $artifactPath, string $manifestPath): array;

    public function prune(DateTimeImmutable $now, int $retentionDays): void;

    /** @return array{filename:string,bytes:int,sha256:string,completed_at:string} */
    public function completedArtifactMetadata(string $backupId): array;

    public function readArtifactSlice(string $backupId, int $offset, int $length): string;

    public function storeTelegramExportManifest(string $backupId, string $contents): void;

    public function telegramExportManifest(string $backupId): string;
}
