<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Operations\Application\Contracts\BackupArtifactCipherFactory;
use App\Modules\Operations\Application\Contracts\BackupBundleWriter;
use App\Modules\Operations\Application\Contracts\BackupDatabaseDumper;
use App\Modules\Operations\Application\Contracts\BackupPayloadCollector;
use App\Modules\Operations\Application\Contracts\BackupRepository;
use App\Shared\Application\Clock;
use App\Shared\Application\RandomGenerator;
use Closure;
use RuntimeException;

final readonly class BackupManager
{
    public function __construct(
        private BackupRuntimeConfiguration $configuration,
        private Closure $databaseResolver,
        private BackupPayloadCollector $payloads,
        private BackupBundleWriter $bundleWriter,
        private BackupArtifactCipherFactory $cipherFactory,
        private BackupRepository $repository,
        private Clock $clock,
        private RandomGenerator $random,
        private string $applicationVersion,
        private string $composerLockPath,
        private string $migrationsDirectory,
        private string $databaseDriver,
    ) {}

    /** @requirement BAK-001 BAK-002 SEC-001 QUA-001 */
    public function create(BackupKind $kind): BackupArtifact
    {
        if (! $this->configuration->enabled) {
            throw new RuntimeException('Backup execution is disabled.');
        }

        $priority = $kind->requiresPriorityLock();
        $waitMilliseconds = $priority ? $this->configuration->priorityLockWaitSeconds * 1000 : 0;

        return $this->repository->synchronized(function () use ($kind): BackupArtifact {
            $now = $this->clock->now();
            $this->repository->recoverIncomplete();
            $this->repository->prune($now, $this->configuration->retentionDays);

            $backupId = $now->format('Ymd\THis\Z').'-'.bin2hex($this->random->bytes(8));
            $work = $this->repository->workingDirectory($backupId);

            try {
                $databasePath = $work.'/database.sql';
                $database = ($this->databaseResolver)();
                if (! $database instanceof BackupDatabaseDumper) {
                    throw new RuntimeException('Backup database dumper resolution failed.');
                }
                $database->capture($databasePath);

                $entries = ['database/database.sql' => $databasePath];
                if ($kind->includesPrivateFiles()) {
                    $entries += $this->payloads->fullPayload();
                }

                $bundlePath = $work.'/payload.bundle';
                $bundle = $this->bundleWriter->write($bundlePath, $entries);

                $artifactPath = $work.'/payload.fbk';
                $cipher = $this->cipherFactory->create($this->configuration->encryptionKey());
                $encrypted = $cipher->encryptFile($bundlePath, $artifactPath);

                if (! unlink($bundlePath)) {
                    throw new RuntimeException('The plaintext backup bundle could not be removed.');
                }

                $completedAt = $this->clock->now()->format(DATE_ATOM);
                $artifactFilename = 'backup-'.$backupId.'.fbk';
                $manifest = [
                    'version' => 1,
                    'authority' => $this->repository->authority(),
                    'backup_id' => $backupId,
                    'kind' => $kind->value,
                    'completed_at' => $completedAt,
                    'compatibility' => [
                        'application_version' => $this->applicationVersion,
                        'php_version' => PHP_VERSION,
                        'database_driver' => $this->databaseDriver,
                        'composer_lock_sha256' => $this->fileHash($this->composerLockPath),
                        'migrations_sha256' => $this->migrationsHash($this->migrationsDirectory),
                    ],
                    'contents' => [
                        'entry_count' => $bundle['entry_count'],
                        'includes_private_files' => $kind->includesPrivateFiles(),
                    ],
                    'artifact' => [
                        'filename' => $artifactFilename,
                        'bytes' => $encrypted['bytes'],
                        'sha256' => $encrypted['sha256'],
                        'encryption' => [
                            'algorithm' => $cipher->algorithm(),
                            'key_id' => $cipher->keyId(),
                        ],
                    ],
                ];

                $manifestPath = $work.'/manifest.json';
                $manifestJson = json_encode(
                    $manifest,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )."\n";

                if (file_put_contents($manifestPath, $manifestJson, LOCK_EX) !== strlen($manifestJson)
                    || ! chmod($manifestPath, 0600)
                ) {
                    throw new RuntimeException('The backup manifest could not be written.');
                }

                $published = $this->repository->publish(
                    $backupId,
                    $artifactPath,
                    $manifestPath,
                );

                return new BackupArtifact(
                    $backupId,
                    $kind,
                    $published['artifact'],
                    $published['manifest'],
                    $encrypted['bytes'],
                    $encrypted['sha256'],
                    $completedAt,
                );
            } finally {
                $this->repository->discardWorkingDirectory($work);
            }
        }, $priority, $waitMilliseconds);
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
            hash_update(
                $hash,
                basename($file)."\0".$this->fileHash($file)."\n",
            );
        }

        return hash_final($hash);
    }
}
