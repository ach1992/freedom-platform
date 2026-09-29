<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\BackupRepository;
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
    public function synchronized(Closure $callback): mixed
    {
        $root = $this->root();
        $lockPath = $root.'/backup.lock';
        $handle = fopen($lockPath, 'c+b');
        if ($handle === false || ! chmod($lockPath, 0600)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new RuntimeException('The backup lock could not be prepared.');
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new RuntimeException('Another backup operation is already active.');
        }

        try {
            return $callback($this);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
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
            if (preg_match('/\A(backup-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16})\.fbk\z/', $name, $matches) !== 1) {
                continue;
            }

            $manifest = $completed.'/'.$matches[1].'.manifest.json';
            if (! is_file($manifest)) {
                $path = $completed.'/'.$name;
                if (is_link($path) || ! is_file($path) || ! unlink($path)) {
                    throw new RuntimeException('An incomplete backup artifact could not be recovered safely.');
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
            if (preg_match('/\Abackup-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}\.manifest\.json\z/', $name) !== 1) {
                continue;
            }

            $manifestPath = $completed.'/'.$name;
            if (is_link($manifestPath) || ! is_file($manifestPath)) {
                throw new RuntimeException('A backup manifest path is unsafe.');
            }

            $contents = file_get_contents($manifestPath);
            $manifest = is_string($contents) ? json_decode($contents, true) : null;
            if (! is_array($manifest)
                || ($manifest['authority'] ?? null) !== self::AUTHORITY
                || ! is_string($manifest['completed_at'] ?? null)
                || ! is_string($manifest['artifact']['filename'] ?? null)
            ) {
                continue;
            }

            $expectedArtifact = substr($name, 0, -strlen('.manifest.json')).'.fbk';
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

            if (is_file($artifactPath) && ! unlink($artifactPath)) {
                throw new RuntimeException('An expired backup artifact could not be removed.');
            }

            if (! unlink($manifestPath)) {
                throw new RuntimeException('An expired backup manifest could not be removed.');
            }
        }
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

    private function assertBackupId(string $backupId): void
    {
        if (preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}\z/', $backupId) !== 1) {
            throw new RuntimeException('The backup identifier is invalid.');
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
