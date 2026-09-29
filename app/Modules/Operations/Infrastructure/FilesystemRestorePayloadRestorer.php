<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\RestorePayloadFilesystem;
use App\Modules\Operations\Application\Contracts\RestorePayloadRestorer;
use RuntimeException;
use Throwable;

final readonly class FilesystemRestorePayloadRestorer implements RestorePayloadRestorer
{
    /**
     * These values define runtime authority/containment location rather than
     * application data. Controlled full restore may rotate secrets and other
     * non-authority configuration, but it must not silently redirect critical
     * database/Redis/queue/cache/maintenance authority.
     *
     * @var list<string>
     */
    private const CRITICAL_ENVIRONMENT_KEYS = [
        'APP_NAME',
        'APP_ENV',
        'APP_DEBUG',
        'APP_MAINTENANCE_DRIVER',
        'APP_MAINTENANCE_STORE',
        'DB_CONNECTION',
        'DB_URL',
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_SOCKET',
        'DB_CACHE_CONNECTION',
        'DB_CACHE_TABLE',
        'DB_CACHE_LOCK_CONNECTION',
        'DB_CACHE_LOCK_TABLE',
        'QUEUE_CONNECTION',
        'REDIS_QUEUE_CONNECTION',
        'REDIS_QUEUE',
        'CACHE_STORE',
        'CACHE_PREFIX',
        'REDIS_CACHE_CONNECTION',
        'REDIS_CACHE_LOCK_CONNECTION',
        'REDIS_CLIENT',
        'REDIS_CLUSTER',
        'REDIS_PREFIX',
        'REDIS_URL',
        'REDIS_HOST',
        'REDIS_PORT',
        'REDIS_USERNAME',
        'REDIS_DB',
        'REDIS_CACHE_DB',
    ];

    /**
     * @param  array<string, string>  $configFiles
     * @param  array<string, string>  $privateDirectories
     */
    public function __construct(
        private array $configFiles,
        private array $privateDirectories,
        private RestorePayloadFilesystem $filesystem,
    ) {}

    /** @param array<string, string> $entries */
    public function restore(array $entries, string $restoreRunId): void
    {
        $this->assertRunId($restoreRunId);
        $this->preflight($entries);
        $operations = [];

        try {
            foreach ($this->configFiles as $name => $configuredTarget) {
                $source = $entries['config/'.$name];
                $target = $this->safeFileTarget($configuredTarget);
                $operations[] = $this->fileOperation($source, $target, $restoreRunId);
                $operationIndex = count($operations) - 1;
                $this->copyRegularFile($source, $operations[$operationIndex]['stage']);
            }

            foreach ($this->privateDirectories as $name => $configuredTarget) {
                $target = $this->safeDirectoryTarget($configuredTarget);
                $operations[] = $this->directoryOperation($target, $restoreRunId);
                $operationIndex = array_key_last($operations);
                $stage = $operations[$operationIndex]['stage'];

                if (! mkdir($stage, 0700) || ! chmod($stage, 0700)) {
                    throw new RuntimeException('A private restore staging directory could not be secured.');
                }

                $prefix = 'private/'.$name.'/';
                foreach ($entries as $logicalPath => $source) {
                    if (! str_starts_with($logicalPath, $prefix)) {
                        continue;
                    }

                    $relative = substr($logicalPath, strlen($prefix));
                    if ($relative === '') {
                        throw new RuntimeException('A private restore entry is invalid.');
                    }

                    $destination = $stage.'/'.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                    $this->createDirectoriesUnder($stage, dirname($destination));
                    $this->copyRegularFile($source, $destination);
                }
            }
        } catch (Throwable $throwable) {
            if (! $this->cleanupStages($operations)) {
                throw new RuntimeException('Restore payload staging cleanup was incomplete.', 0, $throwable);
            }

            throw $throwable;
        }

        try {
            foreach ($operations as $index => $operation) {
                if ($operation['had_original']) {
                    $this->ensureRecoveryDirectory($operation['recovery_directory']);
                    if (! $this->filesystem->move($operation['target'], $operation['recovery'])) {
                        throw new RuntimeException('A restore target could not enter protected recovery state.');
                    }

                    $operations[$index]['original_moved'] = true;
                }

                if (! $this->filesystem->move($operation['stage'], $operation['target'])) {
                    throw new RuntimeException('A staged restore target could not be activated.');
                }

                $operations[$index]['activated'] = true;
            }
        } catch (Throwable $throwable) {
            $rollbackComplete = $this->rollbackOperations($operations);
            $stagingClean = $this->cleanupStages($operations);
            $recoveryClean = $this->cleanupEmptyRecoveryDirectories($operations);

            if (! $rollbackComplete || ! $stagingClean || ! $recoveryClean) {
                throw new RuntimeException('Restore payload rollback or cleanup was incomplete.', 0, $throwable);
            }

            throw $throwable;
        }

        if (! $this->cleanupStages($operations) || ! $this->discardProtectedRecovery($operations)) {
            throw new RuntimeException('Restore payload recovery cleanup was incomplete after activation.');
        }
    }

    /** @param array<string, string> $entries */
    public function preflight(array $entries): void
    {
        if (! isset($entries['database/database.sql'])) {
            throw new RuntimeException('The restore bundle database payload is missing.');
        }

        foreach ($this->configFiles as $name => $_path) {
            if (! isset($entries['config/'.$name])) {
                throw new RuntimeException('A required restore configuration payload is missing.');
            }
        }

        foreach ($entries as $logicalPath => $path) {
            if (! is_file($path) || is_link($path) || ! is_readable($path)) {
                throw new RuntimeException('A restore payload source is unavailable or unsafe.');
            }

            if ($logicalPath === 'database/database.sql') {
                continue;
            }

            $segments = explode('/', $logicalPath);
            if ($segments[0] === 'config') {
                if (count($segments) !== 2 || ! array_key_exists($segments[1], $this->configFiles)) {
                    throw new RuntimeException('The restore bundle contains an unknown configuration target.');
                }

                continue;
            }

            if ($segments[0] === 'private') {
                if (count($segments) < 3 || ! array_key_exists($segments[1], $this->privateDirectories)) {
                    throw new RuntimeException('The restore bundle contains an unknown private target.');
                }

                continue;
            }

            throw new RuntimeException('The restore bundle contains an unsupported payload target.');
        }

        $this->assertCriticalEnvironmentAuthority($entries);
    }

    /**
     * @return array{
     *   target:string,
     *   stage:string,
     *   recovery_directory:string,
     *   recovery:string,
     *   had_original:bool,
     *   original_moved:bool,
     *   activated:bool
     * }
     */
    private function fileOperation(string $source, string $target, string $restoreRunId): array
    {
        if (! is_file($source) || is_link($source) || ! is_readable($source)) {
            throw new RuntimeException('A restore configuration payload source is unsafe.');
        }

        return $this->operation($target, $restoreRunId);
    }

    /**
     * @return array{
     *   target:string,
     *   stage:string,
     *   recovery_directory:string,
     *   recovery:string,
     *   had_original:bool,
     *   original_moved:bool,
     *   activated:bool
     * }
     */
    private function directoryOperation(string $target, string $restoreRunId): array
    {
        return $this->operation($target, $restoreRunId);
    }

    /**
     * @return array{
     *   target:string,
     *   stage:string,
     *   recovery_directory:string,
     *   recovery:string,
     *   had_original:bool,
     *   original_moved:bool,
     *   activated:bool
     * }
     */
    private function operation(string $target, string $restoreRunId): array
    {
        $suffix = hash('sha256', $target);
        $stage = dirname($target).'/.restore-'.$restoreRunId.'-'.$suffix.'.next';
        $recoveryDirectory = dirname($target).'/.restore-recovery-'.$restoreRunId;
        $recovery = $recoveryDirectory.'/'.$suffix;

        $this->assertSwapPathsAvailable($stage, $recovery);

        return [
            'target' => $target,
            'stage' => $stage,
            'recovery_directory' => $recoveryDirectory,
            'recovery' => $recovery,
            'had_original' => file_exists($target),
            'original_moved' => false,
            'activated' => false,
        ];
    }

    /** @param array<int, array<string, mixed>> $operations */
    private function rollbackOperations(array $operations): bool
    {
        $complete = true;

        for ($index = count($operations) - 1; $index >= 0; $index--) {
            $operation = $operations[$index];

            if (($operation['activated'] ?? false) === true
                && (file_exists($operation['target']) || is_link($operation['target']))
            ) {
                try {
                    $this->filesystem->remove($operation['target']);
                } catch (Throwable) {
                    $complete = false;
                }
            }

            if (($operation['original_moved'] ?? false) === true
                && (file_exists($operation['recovery']) || is_link($operation['recovery']))
            ) {
                if (! $this->filesystem->move($operation['recovery'], $operation['target'])) {
                    $complete = false;
                }
            }
        }

        return $complete;
    }

    /** @param array<int, array<string, mixed>> $operations */
    private function cleanupStages(array $operations): bool
    {
        $complete = true;

        foreach ($operations as $operation) {
            if (! isset($operation['stage'])
                || (! file_exists($operation['stage']) && ! is_link($operation['stage']))
            ) {
                continue;
            }

            try {
                $this->filesystem->remove($operation['stage']);
            } catch (Throwable) {
                $complete = false;
            }
        }

        return $complete;
    }

    /** @param array<int, array<string, mixed>> $operations */
    private function discardProtectedRecovery(array $operations): bool
    {
        $complete = true;

        foreach ($operations as $operation) {
            if (($operation['original_moved'] ?? false) !== true
                || (! file_exists($operation['recovery']) && ! is_link($operation['recovery']))
            ) {
                continue;
            }

            try {
                $this->filesystem->remove($operation['recovery']);
            } catch (Throwable) {
                $complete = false;
            }
        }

        return $this->cleanupEmptyRecoveryDirectories($operations) && $complete;
    }

    /** @param array<int, array<string, mixed>> $operations */
    private function cleanupEmptyRecoveryDirectories(array $operations): bool
    {
        $complete = true;
        $directories = [];

        foreach ($operations as $operation) {
            $directory = $operation['recovery_directory'] ?? null;
            if (is_string($directory) && $directory !== '') {
                $directories[$directory] = true;
            }
        }

        foreach (array_keys($directories) as $directory) {
            if (! is_dir($directory) || is_link($directory)) {
                continue;
            }

            $entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
            if ($entries !== []) {
                continue;
            }

            if (! rmdir($directory)) {
                $complete = false;
            }
        }

        return $complete;
    }

    private function ensureRecoveryDirectory(string $directory): void
    {
        if (is_link($directory) || (file_exists($directory) && ! is_dir($directory))) {
            throw new RuntimeException('A restore protected recovery directory is unsafe.');
        }

        if (! is_dir($directory) && ! mkdir($directory, 0700)) {
            throw new RuntimeException('A restore protected recovery directory could not be created.');
        }

        if (! chmod($directory, 0700)) {
            throw new RuntimeException('A restore protected recovery directory could not be secured.');
        }
    }

    /** @param array<string, string> $entries */
    private function assertCriticalEnvironmentAuthority(array $entries): void
    {
        $configured = $this->configFiles['environment'] ?? null;
        $candidate = $entries['config/environment'] ?? null;

        if ($configured === null && $candidate === null) {
            return;
        }

        if (! is_string($configured) || ! is_string($candidate)) {
            throw new RuntimeException('Restore critical environment authority is unavailable.');
        }

        $currentIdentity = $this->criticalEnvironmentIdentity($configured);
        $candidateIdentity = $this->criticalEnvironmentIdentity($candidate);

        if ($currentIdentity !== $candidateIdentity) {
            throw new RuntimeException('Restore critical runtime authority configuration does not match the current deployment.');
        }
    }

    /** @return array<string, string> */
    private function criticalEnvironmentIdentity(string $path): array
    {
        if (! is_file($path) || is_link($path) || ! is_readable($path)) {
            throw new RuntimeException('Restore critical environment authority is unavailable.');
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Restore critical environment authority could not be read.');
        }

        $identity = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            if (preg_match('/\A([A-Z][A-Z0-9_]*)\s*=(.*)\z/', $line, $matches) !== 1) {
                continue;
            }

            $key = $matches[1];
            if (! in_array($key, self::CRITICAL_ENVIRONMENT_KEYS, true)) {
                continue;
            }

            if (array_key_exists($key, $identity)) {
                throw new RuntimeException('Restore critical environment authority contains duplicate keys.');
            }

            $identity[$key] = hash('sha256', trim($matches[2]));
        }

        ksort($identity, SORT_STRING);

        return $identity;
    }

    private function safeFileTarget(string $configuredPath): string
    {
        if (! str_starts_with($configuredPath, DIRECTORY_SEPARATOR)
            || str_contains($configuredPath, "\0")
            || is_link($configuredPath)
        ) {
            throw new RuntimeException('A restore configuration target is unsafe.');
        }

        $parent = realpath(dirname($configuredPath));
        if ($parent === false || ! is_dir($parent)) {
            throw new RuntimeException('A restore configuration target parent is unavailable.');
        }

        $target = $parent.'/'.basename($configuredPath);
        if (file_exists($target) && ! is_file($target)) {
            throw new RuntimeException('A restore configuration target is not a regular file.');
        }

        return $target;
    }

    private function safeDirectoryTarget(string $configuredPath): string
    {
        if (! str_starts_with($configuredPath, DIRECTORY_SEPARATOR)
            || str_contains($configuredPath, "\0")
            || is_link($configuredPath)
        ) {
            throw new RuntimeException('A private restore target is unsafe.');
        }

        $parent = realpath(dirname($configuredPath));
        if ($parent === false || ! is_dir($parent)) {
            throw new RuntimeException('A private restore target parent is unavailable.');
        }

        $target = $parent.'/'.basename($configuredPath);
        if (file_exists($target) && ! is_dir($target)) {
            throw new RuntimeException('A private restore target is not a directory.');
        }

        return $target;
    }

    private function assertSwapPathsAvailable(string $stage, string $recovery): void
    {
        foreach ([$stage, $recovery] as $path) {
            if (file_exists($path) || is_link($path)) {
                throw new RuntimeException('A restore swap or recovery path already exists.');
            }
        }
    }

    private function copyRegularFile(string $source, string $destination): void
    {
        if (! is_file($source) || is_link($source) || ! is_readable($source)
            || file_exists($destination) || is_link($destination)
        ) {
            throw new RuntimeException('A restore file copy boundary is unsafe.');
        }

        if (! copy($source, $destination) || ! chmod($destination, 0600)) {
            throw new RuntimeException('A restore file could not be staged securely.');
        }
    }

    private function createDirectoriesUnder(string $root, string $directory): void
    {
        if (is_dir($directory)) {
            $real = realpath($directory);
            $rootReal = realpath($root);
            if ($real === false || $rootReal === false
                || ($real !== $rootReal && ! str_starts_with($real, $rootReal.DIRECTORY_SEPARATOR))
            ) {
                throw new RuntimeException('A private restore staging path is unsafe.');
            }

            return;
        }

        if (file_exists($directory) || is_link($directory)
            || ! mkdir($directory, 0700, true)
            || ! chmod($directory, 0700)
        ) {
            throw new RuntimeException('A private restore staging path could not be secured.');
        }
    }

    private function assertRunId(string $restoreRunId): void
    {
        if (preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}\z/', $restoreRunId) !== 1) {
            throw new RuntimeException('The restore run identifier is invalid.');
        }
    }
}
