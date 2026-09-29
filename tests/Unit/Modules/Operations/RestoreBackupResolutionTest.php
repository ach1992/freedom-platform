<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Application\BackupKind;
use App\Modules\Operations\Application\BackupManager;
use App\Modules\Operations\Application\BackupRuntimeConfiguration;
use App\Modules\Operations\Application\Contracts\BackupDatabaseDumper;
use App\Modules\Operations\Infrastructure\BackupBundleWriter;
use App\Modules\Operations\Infrastructure\BackupPayloadCollector;
use App\Modules\Operations\Infrastructure\FilesystemBackupRepository;
use App\Modules\Operations\Infrastructure\SodiumBackupCipherFactory;
use App\Shared\Application\Clock;
use App\Shared\Application\RandomGenerator;
use DateTimeImmutable;
use RuntimeException;
use Tests\TestCase;

final class RestoreBackupResolutionTest extends TestCase
{
    /** @requirement BAK-002 SEC-001 QUA-001 */
    public function test_completed_backup_resolution_rejects_missing_foreign_malformed_and_incomplete_authority_state(): void
    {
        $fixture = $this->fixture();

        try {
            $configuration = $this->configuration($fixture);
            $repository = new FilesystemBackupRepository($configuration->root);

            try {
                $repository->completedBackup('20260929T010000Z-aaaaaaaaaaaaaaaa');
                self::fail('Missing backup pair must be rejected.');
            } catch (RuntimeException $exception) {
                self::assertSame('The completed backup artifact is unavailable.', $exception->getMessage());
            }

            $foreign = $this->createBackup($configuration, '2026-09-29T01:10:00+00:00', "\x11");
            $foreignManifest = $this->manifestPath($configuration->root, $foreign);
            $manifest = json_decode((string) file_get_contents($foreignManifest), true, flags: JSON_THROW_ON_ERROR);
            $manifest['authority'] = 'foreign_backup_authority';
            file_put_contents($foreignManifest, json_encode($manifest, JSON_THROW_ON_ERROR));

            try {
                $repository->completedBackup($foreign);
                self::fail('Foreign backup authority must be rejected.');
            } catch (RuntimeException $exception) {
                self::assertSame('The completed backup manifest is invalid.', $exception->getMessage());
            }

            $malformed = $this->createBackup($configuration, '2026-09-29T01:20:00+00:00', "\x22");
            file_put_contents($this->manifestPath($configuration->root, $malformed), '{broken');

            try {
                $repository->completedBackup($malformed);
                self::fail('Malformed backup manifest must be rejected.');
            } catch (RuntimeException $exception) {
                self::assertSame('The completed backup manifest is invalid.', $exception->getMessage());
            }

            $incomplete = $this->createBackup($configuration, '2026-09-29T01:30:00+00:00', "\x33");
            unlink($configuration->root.'/completed/backup-'.$incomplete.'.fbk');

            try {
                $repository->completedBackup($incomplete);
                self::fail('Incomplete backup pair must be rejected.');
            } catch (RuntimeException $exception) {
                self::assertSame('The completed backup artifact is unavailable.', $exception->getMessage());
            }
        } finally {
            $this->removeTree($fixture['base']);
        }
    }

    /**
     * @param  array{base:string,root:string,environment:string,private:string}  $fixture
     */
    private function configuration(array $fixture): BackupRuntimeConfiguration
    {
        return new BackupRuntimeConfiguration(
            true,
            $fixture['root'],
            str_repeat("\x07", SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES),
            30,
            10,
            '02:30',
            30,
            180,
            2,
            false,
            19_000_000,
            ['environment' => $fixture['environment']],
            ['application' => $fixture['private']],
        );
    }

    private function createBackup(BackupRuntimeConfiguration $configuration, string $time, string $byte): string
    {
        return (new BackupManager(
            $configuration,
            static fn (): BackupDatabaseDumper => new RestoreResolutionDatabaseDumper,
            new BackupPayloadCollector($configuration->configFiles, $configuration->privateDirectories),
            new BackupBundleWriter,
            new SodiumBackupCipherFactory,
            new FilesystemBackupRepository($configuration->root),
            new RestoreResolutionClock($time),
            new RestoreResolutionRandom($byte),
            'test-release',
            base_path('composer.lock'),
            database_path('migrations'),
            'mysql',
        ))->create(BackupKind::DailyFull)->backupId;
    }

    private function manifestPath(string $root, string $backupId): string
    {
        return $root.'/completed/backup-'.$backupId.'.manifest.json';
    }

    /**
     * @return array{base:string,root:string,environment:string,private:string}
     */
    private function fixture(): array
    {
        $base = storage_path('framework/testing/restore-resolution-'.bin2hex(random_bytes(4)));
        $private = $base.'/private';
        mkdir($private, 0700, true);
        file_put_contents($base.'/.env', "APP_ENV=testing\n");
        file_put_contents($private.'/value.txt', 'private');

        return [
            'base' => $base,
            'root' => $base.'/backups',
            'environment' => $base.'/.env',
            'private' => $private,
        ];
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->removeTree($path.'/'.$name);
            }
        }

        @rmdir($path);
    }
}

final class RestoreResolutionDatabaseDumper implements BackupDatabaseDumper
{
    public function capture(string $destinationPath): void
    {
        file_put_contents($destinationPath, 'database');
        chmod($destinationPath, 0600);
    }
}

final readonly class RestoreResolutionClock implements Clock
{
    public function __construct(private string $now) {}

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->now);
    }
}

final readonly class RestoreResolutionRandom implements RandomGenerator
{
    public function __construct(private string $byte) {}

    public function bytes(int $length): string
    {
        return str_repeat($this->byte, $length);
    }

    public function integer(int $minimum, int $maximum): int
    {
        return $minimum;
    }
}
