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
        $root = realpath($directory);
        if ($root === false || ! is_dir($root) || is_link($directory)) {
            throw new RuntimeException('The migration compatibility directory is unavailable.');
        }

        $files = glob($root.'/*.php');
        if ($files === false || $files === []) {
            throw new RuntimeException('Migration compatibility inputs are unavailable.');
        }

        sort($files, SORT_STRING);
        $hash = hash_init('sha256');

        foreach ($files as $file) {
            hash_update($hash, basename($file)."\0".$this->fileHash($file)."\n");
        }

        return hash_final($hash);
    }
}
