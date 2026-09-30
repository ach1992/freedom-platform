<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use RuntimeException;

final readonly class BackupCompatibilityIdentity
{
    public function __construct(
        private string $applicationVersion,
        private string $composerLockPath,
        private string $migrationsDirectory,
        private string $databaseDriver,
    ) {}

    /** @return array{application_version:string,php_version:string,database_driver:string,composer_lock_sha256:string,migrations_sha256:string} */
    public function current(): array
    {
        return [
            'application_version' => $this->applicationVersion,
            'php_version' => PHP_VERSION,
            'database_driver' => $this->databaseDriver,
            'composer_lock_sha256' => $this->fileHash($this->composerLockPath),
            'migrations_sha256' => $this->migrationsHash($this->migrationsDirectory),
        ];
    }

    public function assertCompatible(ResolvedBackup $backup): void
    {
        $current = $this->current();

        if (! hash_equals($current['application_version'], $backup->applicationVersion)
            || ! hash_equals($current['database_driver'], $backup->databaseDriver)
            || ! hash_equals($current['composer_lock_sha256'], $backup->composerLockSha256)
            || ! hash_equals($current['migrations_sha256'], $backup->migrationsSha256)
            || ! $this->phpRuntimeCompatible($current['php_version'], $backup->phpVersion)
        ) {
            throw new RuntimeException('The backup is not compatible with the current runtime.');
        }
    }

    public function assertCompatibleWithRelease(
        ResolvedBackup $backup,
        string $applicationVersion,
        string $releasePath,
    ): void {
        $release = realpath($releasePath);
        if ($release === false
            || ! is_dir($release)
            || is_link($releasePath)
            || $applicationVersion === ''
            || strlen($applicationVersion) > 64
        ) {
            throw new RuntimeException('The predecessor release compatibility authority is unavailable.');
        }

        $identity = [
            'application_version' => $applicationVersion,
            'php_version' => PHP_VERSION,
            'database_driver' => $this->databaseDriver,
            'composer_lock_sha256' => $this->fileHash($release.'/composer.lock'),
            'migrations_sha256' => $this->migrationsHash($release.'/database/migrations'),
        ];

        if (! hash_equals($identity['application_version'], $backup->applicationVersion)
            || ! hash_equals($identity['database_driver'], $backup->databaseDriver)
            || ! hash_equals($identity['composer_lock_sha256'], $backup->composerLockSha256)
            || ! hash_equals($identity['migrations_sha256'], $backup->migrationsSha256)
            || ! $this->phpRuntimeCompatible($identity['php_version'], $backup->phpVersion)
        ) {
            throw new RuntimeException('The backup is not compatible with the exact predecessor release.');
        }
    }

    private function phpRuntimeCompatible(string $current, string $backup): bool
    {
        $currentParts = explode('.', $current);
        $backupParts = explode('.', $backup);

        return count($currentParts) >= 2
            && count($backupParts) >= 2
            && $currentParts[0] === $backupParts[0]
            && $currentParts[1] === $backupParts[1];
    }

    private function fileHash(string $path): string
    {
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('A backup compatibility input is unavailable.');
        }

        $hash = hash_file('sha256', $path);
        if (! is_string($hash)) {
            throw new RuntimeException('A backup compatibility hash could not be calculated.');
        }

        return $hash;
    }

    private function migrationsHash(string $directory): string
    {
        return UpdateMigrationIdentity::fromDirectory($directory);
    }
}
