<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\BackupKind;
use App\Modules\Operations\Application\Contracts\BackupRepository;
use App\Modules\Operations\Application\ResolvedBackup;
use Closure;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final readonly class FilesystemBackupRepository implements BackupRepository
{
    private const AUTHORITY = 'freedom_platform_backup_v1';

    public function __construct(private string $configuredRoot) {}

    public function authority(): string
    {
        return self::AUTHORITY;
    }

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
    ): mixed {
        if ($waitMilliseconds < 0 || (! $priority && $waitMilliseconds !== 0)) {
            throw new RuntimeException('The backup lock coordination request is invalid.');
        }

        $root = $this->root();
        $priorityPath = $root.'/backup.priority.lock';
        $priorityHandle = fopen($priorityPath, 'c+b');
        if ($priorityHandle === false || ! chmod($priorityPath, 0600)) {
            if (is_resource($priorityHandle)) {
                fclose($priorityHandle);
            }

            throw new RuntimeException('The backup priority lock could not be prepared.');
        }

        $deadline = $priority && $waitMilliseconds > 0
            ? microtime(true) + ($waitMilliseconds / 1000)
            : null;
        $priorityAcquired = $priority
            ? $this->acquireExclusiveLock($priorityHandle, $deadline)
            : flock($priorityHandle, LOCK_SH | LOCK_NB);

        if (! $priorityAcquired) {
            fclose($priorityHandle);

            throw new RuntimeException(
                $priority
                    ? 'The prioritized backup operation could not acquire coordination in time.'
                    : 'A prioritized backup operation is waiting or active.',
            );
        }

        $lockPath = $root.'/backup.lock';
        $handle = fopen($lockPath, 'c+b');
        if ($handle === false || ! chmod($lockPath, 0600)) {
            flock($priorityHandle, LOCK_UN);
            fclose($priorityHandle);
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new RuntimeException('The backup lock could not be prepared.');
        }

        if (! $this->acquireExclusiveLock($handle, $priority ? $deadline : null)) {
            fclose($handle);
            flock($priorityHandle, LOCK_UN);
            fclose($priorityHandle);

            throw new RuntimeException(
                $priority
                    ? 'The prioritized backup operation could not acquire the shared backup lock in time.'
                    : 'Another backup operation is already active.',
            );
        }

        // A normal frequent backup only needs the shared priority gate while it races
        // for the execution lock. Once it owns backup.lock, a priority backup may
        // claim the gate and wait for this one to finish; no later frequent backup
        // can enter ahead of that priority waiter.
        if (! $priority) {
            flock($priorityHandle, LOCK_UN);
            fclose($priorityHandle);
            $priorityHandle = null;
        }

        try {
            return $callback($this);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);

            if (is_resource($priorityHandle)) {
                flock($priorityHandle, LOCK_UN);
                fclose($priorityHandle);
            }
        }
    }

    /**
     * @param  resource  $handle
     */
    private function acquireExclusiveLock($handle, ?float $deadline): bool
    {
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return true;
            }

            if ($deadline === null || microtime(true) >= $deadline) {
                return false;
            }

            usleep(50_000);
        } while (true);
    }

    public function recoverIncomplete(): void
    {
        $root = $this->root();
        $work = $root.'/work';

        foreach (scandir($work) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            if (preg_match('/\Abackup-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}\z/', $name) !== 1) {
                throw new RuntimeException('The backup work directory contains an unrecognized entry.');
            }

            $this->removeTree($work.'/'.$name);
        }

        $completed = $root.'/completed';
        foreach (scandir($completed) ?: [] as $name) {
            if (preg_match('/\Abackup-([0-9]{8}T[0-9]{6}Z-[a-f0-9]{16})\.fbk\z/', $name, $matches) === 1) {
                $backupId = $matches[1];
                $manifest = $completed.'/backup-'.$backupId.'.manifest.json';
                if (! is_file($manifest)) {
                    $this->unlinkRegularFile($completed.'/'.$name, 'An incomplete backup artifact could not be recovered safely.');
                    $this->unlinkIfRegular($completed.'/backup-'.$backupId.'.telegram-export.json');
                }

                continue;
            }

            if (preg_match('/\Abackup-([0-9]{8}T[0-9]{6}Z-[a-f0-9]{16})\.manifest\.json\z/', $name, $matches) === 1) {
                $backupId = $matches[1];
                $artifact = $completed.'/backup-'.$backupId.'.fbk';
                if (! is_file($artifact)) {
                    $this->unlinkIfRegular($completed.'/backup-'.$backupId.'.telegram-export.json');
                    $this->unlinkRegularFile($completed.'/'.$name, 'An incomplete backup manifest could not be recovered safely.');
                }

                continue;
            }

            if (preg_match('/\Abackup-([0-9]{8}T[0-9]{6}Z-[a-f0-9]{16})\.telegram-export\.json\z/', $name, $matches) === 1) {
                $backupId = $matches[1];
                if (! is_file($completed.'/backup-'.$backupId.'.fbk')
                    || ! is_file($completed.'/backup-'.$backupId.'.manifest.json')
                ) {
                    $this->unlinkIfRegular($completed.'/'.$name);
                }
            }
        }
    }

    public function workingDirectory(string $backupId): string
    {
        $this->assertBackupId($backupId);
        $path = $this->root().'/work/backup-'.$backupId;

        if (! mkdir($path, 0700) || ! is_dir($path)) {
            throw new RuntimeException('The backup working directory could not be created.');
        }

        return $path;
    }

    public function discardWorkingDirectory(string $path): void
    {
        $workRoot = realpath($this->root().'/work');
        $parent = realpath(dirname($path));

        if ($workRoot === false
            || $parent !== $workRoot
            || preg_match('/\Abackup-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}\z/', basename($path)) !== 1
        ) {
            throw new RuntimeException('The backup working directory is outside authority.');
        }

        if (file_exists($path) || is_link($path)) {
            $this->removeTree($path);
        }
    }

    /** @return array{artifact:string,manifest:string} */
    public function publish(string $backupId, string $artifactPath, string $manifestPath): array
    {
        $this->assertBackupId($backupId);
        $work = realpath($this->root().'/work/backup-'.$backupId);
        $completed = $this->root().'/completed';

        if ($work === false) {
            throw new RuntimeException('The backup publication work directory is unavailable.');
        }

        foreach ([$artifactPath, $manifestPath] as $source) {
            if (! is_file($source)
                || is_link($source)
                || realpath(dirname($source)) !== $work
            ) {
                throw new RuntimeException('The backup publication source is invalid.');
            }
        }

        $artifactName = 'backup-'.$backupId.'.fbk';
        $manifestName = 'backup-'.$backupId.'.manifest.json';
        $artifactDestination = $completed.'/'.$artifactName;
        $manifestDestination = $completed.'/'.$manifestName;

        if (file_exists($artifactDestination)
            || file_exists($manifestDestination)
            || ! rename($artifactPath, $artifactDestination)
        ) {
            throw new RuntimeException('The encrypted backup artifact could not be published.');
        }

        if (! rename($manifestPath, $manifestDestination)) {
            throw new RuntimeException('The backup manifest could not be published.');
        }

        return ['artifact' => $artifactName, 'manifest' => $manifestName];
    }

    public function prune(DateTimeImmutable $now, int $retentionDays): void
    {
        if ($retentionDays < 1) {
            throw new RuntimeException('The backup retention period is invalid.');
        }

        $threshold = $now->modify('-'.$retentionDays.' days');
        $completed = $this->root().'/completed';

        foreach (scandir($completed) ?: [] as $name) {
            if (preg_match('/\Abackup-([0-9]{8}T[0-9]{6}Z-[a-f0-9]{16})\.manifest\.json\z/', $name, $matches) !== 1) {
                continue;
            }

            $backupId = $matches[1];
            $manifestPath = $completed.'/'.$name;
            if (is_link($manifestPath) || ! is_file($manifestPath)) {
                throw new RuntimeException('A backup manifest path is unsafe.');
            }

            $contents = file_get_contents($manifestPath);
            $manifest = is_string($contents) ? json_decode($contents, true) : null;
            if (! is_array($manifest)
                || ($manifest['authority'] ?? null) !== self::AUTHORITY
                || ($manifest['backup_id'] ?? null) !== $backupId
                || ! is_string($manifest['completed_at'] ?? null)
                || ! is_string($manifest['artifact']['filename'] ?? null)
            ) {
                continue;
            }

            $expectedArtifact = 'backup-'.$backupId.'.fbk';
            if (! hash_equals($expectedArtifact, $manifest['artifact']['filename'])) {
                continue;
            }

            try {
                $completedAt = new DateTimeImmutable($manifest['completed_at']);
            } catch (Throwable) {
                continue;
            }

            if ($completedAt >= $threshold) {
                continue;
            }

            $artifactPath = $completed.'/'.$expectedArtifact;
            if (is_link($artifactPath)) {
                throw new RuntimeException('A backup artifact path is unsafe.');
            }

            $this->unlinkIfRegular($completed.'/backup-'.$backupId.'.telegram-export.json');

            if (is_file($artifactPath) && ! unlink($artifactPath)) {
                throw new RuntimeException('An expired backup artifact could not be removed.');
            }

            if (! unlink($manifestPath)) {
                throw new RuntimeException('An expired backup manifest could not be removed.');
            }
        }
    }

    public function completedBackup(string $backupId): ResolvedBackup
    {
        $this->assertBackupId($backupId);
        $completed = $this->root().'/completed';
        $artifactFilename = 'backup-'.$backupId.'.fbk';
        $artifactPath = $completed.'/'.$artifactFilename;
        $manifestPath = $completed.'/backup-'.$backupId.'.manifest.json';

        if (! is_file($artifactPath)
            || is_link($artifactPath)
            || ! is_file($manifestPath)
            || is_link($manifestPath)
        ) {
            throw new RuntimeException('The completed backup artifact is unavailable.');
        }

        $manifestJson = file_get_contents($manifestPath);
        try {
            $manifest = is_string($manifestJson)
                ? json_decode($manifestJson, true, 64, JSON_THROW_ON_ERROR)
                : null;
        } catch (Throwable) {
            $manifest = null;
        }

        $kind = is_array($manifest) && is_string($manifest['kind'] ?? null)
            ? BackupKind::tryFrom($manifest['kind'])
            : null;
        $compatibility = is_array($manifest) && is_array($manifest['compatibility'] ?? null)
            ? $manifest['compatibility']
            : [];
        $contents = is_array($manifest) && is_array($manifest['contents'] ?? null)
            ? $manifest['contents']
            : [];
        $artifact = is_array($manifest) && is_array($manifest['artifact'] ?? null)
            ? $manifest['artifact']
            : [];
        $encryption = is_array($artifact['encryption'] ?? null) ? $artifact['encryption'] : [];

        $bytes = $artifact['bytes'] ?? null;
        $sha256 = $artifact['sha256'] ?? null;
        $completedAt = is_array($manifest) ? ($manifest['completed_at'] ?? null) : null;
        $entryCount = $contents['entry_count'] ?? null;
        $includesPrivateFiles = $contents['includes_private_files'] ?? null;

        if (! is_array($manifest)
            || ($manifest['version'] ?? null) !== 1
            || ($manifest['authority'] ?? null) !== self::AUTHORITY
            || ($manifest['backup_id'] ?? null) !== $backupId
            || $kind === null
            || ($artifact['filename'] ?? null) !== $artifactFilename
            || ! is_int($bytes)
            || $bytes < 1
            || ! is_string($sha256)
            || preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1
            || ! is_string($completedAt)
            || ! is_int($entryCount)
            || $entryCount < 1
            || ! is_bool($includesPrivateFiles)
            || $includesPrivateFiles !== $kind->includesPrivateFiles()
            || ! self::compatibilityValue($compatibility, 'application_version')
            || ! self::compatibilityValue($compatibility, 'php_version')
            || ! self::compatibilityValue($compatibility, 'database_driver')
            || ! self::sha256Value($compatibility, 'composer_lock_sha256')
            || ! self::sha256Value($compatibility, 'migrations_sha256')
            || ! is_string($encryption['algorithm'] ?? null)
            || $encryption['algorithm'] === ''
            || ! is_string($encryption['key_id'] ?? null)
            || preg_match('/\A[0-9a-f]{16}\z/', $encryption['key_id']) !== 1
        ) {
            throw new RuntimeException('The completed backup manifest is invalid.');
        }

        try {
            new DateTimeImmutable($completedAt);
        } catch (Throwable) {
            throw new RuntimeException('The completed backup timestamp is invalid.');
        }

        $actualBytes = filesize($artifactPath);
        if (! is_int($actualBytes) || $actualBytes !== $bytes) {
            throw new RuntimeException('The completed backup artifact size failed verification.');
        }

        $actualHash = hash_file('sha256', $artifactPath);
        if (! is_string($actualHash) || ! hash_equals($sha256, $actualHash)) {
            throw new RuntimeException('The completed backup artifact hash failed verification.');
        }

        return new ResolvedBackup(
            $backupId,
            $kind,
            $artifactPath,
            $bytes,
            $sha256,
            $completedAt,
            $compatibility['application_version'],
            $compatibility['php_version'],
            $compatibility['database_driver'],
            $compatibility['composer_lock_sha256'],
            $compatibility['migrations_sha256'],
            $entryCount,
            $includesPrivateFiles,
            $encryption['algorithm'],
            $encryption['key_id'],
        );
    }

    /** @return array{filename:string,bytes:int,sha256:string,completed_at:string} */
    public function completedArtifactMetadata(string $backupId): array
    {
        $completed = $this->validatedCompletedArtifact($backupId, true);

        return [
            'filename' => $completed['filename'],
            'bytes' => $completed['bytes'],
            'sha256' => $completed['sha256'],
            'completed_at' => $completed['completed_at'],
        ];
    }

    public function readArtifactSlice(string $backupId, int $offset, int $length): string
    {
        if ($offset < 0 || $length < 1) {
            throw new RuntimeException('The backup artifact slice is invalid.');
        }

        $completed = $this->validatedCompletedArtifact($backupId, false);
        if ($offset > $completed['bytes'] || $length > $completed['bytes'] - $offset) {
            throw new RuntimeException('The backup artifact slice exceeds the completed artifact.');
        }

        $handle = fopen($completed['path'], 'rb');
        if ($handle === false) {
            throw new RuntimeException('The completed backup artifact could not be opened.');
        }

        try {
            if (fseek($handle, $offset, SEEK_SET) !== 0) {
                throw new RuntimeException('The completed backup artifact slice could not be positioned.');
            }

            $contents = '';
            while (($remaining = $length - strlen($contents)) > 0) {
                $chunk = fread($handle, max(1, $remaining));
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('The completed backup artifact slice is truncated.');
                }
                $contents .= $chunk;
            }

            return $contents;
        } finally {
            fclose($handle);
        }
    }

    public function storeTelegramExportManifest(string $backupId, string $contents): void
    {
        $this->assertBackupId($backupId);
        if ($contents === '' || strlen($contents) > 20_000_000) {
            throw new RuntimeException('The backup Telegram export manifest is invalid.');
        }

        $this->validatedCompletedArtifact($backupId, false);
        $completed = $this->root().'/completed';
        $path = $completed.'/backup-'.$backupId.'.telegram-export.json';

        if (is_file($path)) {
            if (is_link($path)) {
                throw new RuntimeException('The backup Telegram export manifest path is unsafe.');
            }

            $existing = file_get_contents($path);
            if (! is_string($existing) || ! hash_equals($existing, $contents)) {
                throw new RuntimeException('The backup Telegram export manifest conflicts with existing state.');
            }

            return;
        }

        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('The backup Telegram export manifest path is unsafe.');
        }

        $temporary = tempnam($completed, '.backup-telegram-export-');
        if ($temporary === false) {
            throw new RuntimeException('The backup Telegram export manifest temporary file could not be created.');
        }

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)
                || ! chmod($temporary, 0600)
                || ! rename($temporary, $path)
            ) {
                throw new RuntimeException('The backup Telegram export manifest could not be published.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function telegramExportManifest(string $backupId): string
    {
        $this->assertBackupId($backupId);
        $this->validatedCompletedArtifact($backupId, false);

        $path = $this->root().'/completed/backup-'.$backupId.'.telegram-export.json';
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('The backup Telegram export manifest is unavailable.');
        }

        $contents = file_get_contents($path);
        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('The backup Telegram export manifest could not be read.');
        }

        return $contents;
    }

    public function root(): string
    {
        if (! str_starts_with($this->configuredRoot, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The backup root must be absolute.');
        }

        if (! file_exists($this->configuredRoot)
            && ! mkdir($this->configuredRoot, 0700, true)
            && ! is_dir($this->configuredRoot)
        ) {
            throw new RuntimeException('The backup root could not be created.');
        }

        if (is_link($this->configuredRoot)) {
            throw new RuntimeException('The backup root cannot be a symbolic link.');
        }

        $root = realpath($this->configuredRoot);
        if ($root === false || ! is_dir($root) || ! chmod($root, 0700)) {
            throw new RuntimeException('The backup root is unavailable or unsafe.');
        }

        foreach (['work', 'completed'] as $directory) {
            $path = $root.'/'.$directory;

            if (! is_dir($path) && ! mkdir($path, 0700) && ! is_dir($path)) {
                throw new RuntimeException('The backup storage directories could not be created.');
            }

            if (is_link($path)
                || realpath(dirname($path)) !== $root
                || ! chmod($path, 0700)
            ) {
                throw new RuntimeException('The backup storage directory is unsafe.');
            }
        }

        return $root;
    }

    /**
     * @return array{path:string,filename:string,bytes:int,sha256:string,completed_at:string}
     */
    private function validatedCompletedArtifact(string $backupId, bool $verifyHash): array
    {
        $this->assertBackupId($backupId);
        $completed = $this->root().'/completed';
        $artifactFilename = 'backup-'.$backupId.'.fbk';
        $artifactPath = $completed.'/'.$artifactFilename;
        $manifestPath = $completed.'/backup-'.$backupId.'.manifest.json';

        if (! is_file($artifactPath)
            || is_link($artifactPath)
            || ! is_file($manifestPath)
            || is_link($manifestPath)
        ) {
            throw new RuntimeException('The completed backup artifact is unavailable.');
        }

        $manifestJson = file_get_contents($manifestPath);
        $manifest = is_string($manifestJson) ? json_decode($manifestJson, true) : null;
        $bytes = is_array($manifest) ? ($manifest['artifact']['bytes'] ?? null) : null;
        $sha256 = is_array($manifest) ? ($manifest['artifact']['sha256'] ?? null) : null;
        $completedAt = is_array($manifest) ? ($manifest['completed_at'] ?? null) : null;

        if (! is_array($manifest)
            || ($manifest['authority'] ?? null) !== self::AUTHORITY
            || ($manifest['backup_id'] ?? null) !== $backupId
            || ($manifest['artifact']['filename'] ?? null) !== $artifactFilename
            || ! is_int($bytes)
            || $bytes < 1
            || ! is_string($sha256)
            || preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1
            || ! is_string($completedAt)
        ) {
            throw new RuntimeException('The completed backup manifest is invalid.');
        }

        try {
            new DateTimeImmutable($completedAt);
        } catch (Throwable) {
            throw new RuntimeException('The completed backup timestamp is invalid.');
        }

        $actualBytes = filesize($artifactPath);
        if (! is_int($actualBytes) || $actualBytes !== $bytes) {
            throw new RuntimeException('The completed backup artifact size failed verification.');
        }

        if ($verifyHash) {
            $actualHash = hash_file('sha256', $artifactPath);
            if (! is_string($actualHash) || ! hash_equals($sha256, $actualHash)) {
                throw new RuntimeException('The completed backup artifact hash failed verification.');
            }
        }

        return [
            'path' => $artifactPath,
            'filename' => $artifactFilename,
            'bytes' => $bytes,
            'sha256' => $sha256,
            'completed_at' => $completedAt,
        ];
    }

    private function assertBackupId(string $backupId): void
    {
        if (preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}\z/', $backupId) !== 1) {
            throw new RuntimeException('The backup identifier is invalid.');
        }
    }

    /** @param array<string, mixed> $values */
    private static function compatibilityValue(array $values, string $key): bool
    {
        return is_string($values[$key] ?? null)
            && $values[$key] !== ''
            && strlen($values[$key]) <= 255;
    }

    /** @param array<string, mixed> $values */
    private static function sha256Value(array $values, string $key): bool
    {
        return is_string($values[$key] ?? null)
            && preg_match('/\A[0-9a-f]{64}\z/', $values[$key]) === 1;
    }

    private function unlinkIfRegular(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }

        $this->unlinkRegularFile($path, 'A backup-owned sidecar could not be removed safely.');
    }

    private function unlinkRegularFile(string $path, string $message): void
    {
        if (is_link($path) || ! is_file($path) || ! unlink($path)) {
            throw new RuntimeException($message);
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('A backup-owned directory contains an unsafe symbolic link.');
        }

        if (is_file($path)) {
            if (! unlink($path)) {
                throw new RuntimeException('A backup-owned file could not be removed.');
            }

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $this->removeTree($path.'/'.$name);
        }

        if (! rmdir($path)) {
            throw new RuntimeException('A backup-owned directory could not be removed.');
        }
    }
}
