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
}
