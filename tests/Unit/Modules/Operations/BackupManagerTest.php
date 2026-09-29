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
use App\Modules\Operations\Infrastructure\SodiumBackupCipher;
use App\Modules\Operations\Infrastructure\SodiumBackupCipherFactory;
use App\Shared\Application\Clock;
use App\Shared\Application\RandomGenerator;
use DateTimeImmutable;
use RuntimeException;
use Tests\TestCase;

final class BackupManagerTest extends TestCase
{
    /** @requirement BAK-001 BAK-002 SEC-001 QUA-001 */
    public function test_daily_full_backup_publishes_encrypted_artifact_and_manifest_then_removes_plaintext_work(): void
    {
        $fixture = $this->fixture('full');

        try {
            file_put_contents($fixture['environment'], "APP_SECRET=test-only-backup-secret\n");
            file_put_contents($fixture['private'].'/payload.txt', 'private-test-payload');

            $configuration = $this->configuration($fixture);
            $manager = $this->manager(
                $configuration,
                new RecordingBackupDatabaseDumper('database-test-payload'),
            );

            $artifact = $manager->create(BackupKind::DailyFull);

            $completed = $fixture['root'].'/completed';
            $artifactPath = $completed.'/'.$artifact->artifactFilename;
            $manifestPath = $completed.'/'.$artifact->manifestFilename;
            self::assertFileExists($artifactPath);
            self::assertFileExists($manifestPath);
            self::assertSame(0600, fileperms($artifactPath) & 0777);
            self::assertSame(0600, fileperms($manifestPath) & 0777);
            self::assertSame([], array_values(array_diff(scandir($fixture['root'].'/work') ?: [], ['.', '..'])));

            $manifestJson = (string) file_get_contents($manifestPath);
            $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('freedom_platform_backup_v1', $manifest['authority']);
            self::assertSame('daily_full', $manifest['kind']);
            self::assertSame(3, $manifest['contents']['entry_count']);
            self::assertTrue($manifest['contents']['includes_private_files']);
            self::assertSame($artifact->artifactSha256, $manifest['artifact']['sha256']);
            self::assertSame(hash_file('sha256', $artifactPath), $manifest['artifact']['sha256']);
            self::assertSame('xchacha20poly1305-secretstream-v1', $manifest['artifact']['encryption']['algorithm']);
            self::assertStringNotContainsString('test-only-backup-secret', $manifestJson);
            self::assertStringNotContainsString('private-test-payload', $manifestJson);
            self::assertStringNotContainsString('test-only-backup-secret', (string) file_get_contents($artifactPath));
            self::assertStringNotContainsString('private-test-payload', (string) file_get_contents($artifactPath));

            $decrypted = $fixture['base'].'/decrypted.bundle';
            (new SodiumBackupCipher($configuration->encryptionKey()))->decryptFile($artifactPath, $decrypted);
            $bundle = (string) file_get_contents($decrypted);
            self::assertStringContainsString('database-test-payload', $bundle);
            self::assertStringContainsString('test-only-backup-secret', $bundle);
            self::assertStringContainsString('private-test-payload', $bundle);
        } finally {
            $this->removeTree($fixture['base']);
        }
    }

    /** @requirement BAK-001 QUA-001 */
    public function test_frequent_database_backup_does_not_require_full_backup_inputs(): void
    {
        $fixture = $this->fixture('frequent');
        $configuration = new BackupRuntimeConfiguration(
            true,
            $fixture['root'],
            random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES),
            30,
            10,
            '02:30',
            30,
            180,
            1800,
            false,
            19_000_000,
            ['environment' => $fixture['base'].'/missing.env'],
            ['application' => $fixture['base'].'/missing-private'],
        );

        try {
            $artifact = $this->manager(
                $configuration,
                new RecordingBackupDatabaseDumper('database-only'),
            )->create(BackupKind::FrequentDatabase);

            $manifest = json_decode(
                (string) file_get_contents($fixture['root'].'/completed/'.$artifact->manifestFilename),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            self::assertSame(1, $manifest['contents']['entry_count']);
            self::assertFalse($manifest['contents']['includes_private_files']);
        } finally {
            $this->removeTree($fixture['base']);
        }
    }

    /** @requirement BAK-001 SEC-001 QUA-001 */
    public function test_database_failure_leaves_no_completed_or_plaintext_backup_state(): void
    {
        $fixture = $this->fixture('failure');
        $configuration = $this->configuration($fixture);

        try {
            try {
                $this->manager(
                    $configuration,
                    new FailingBackupDatabaseDumper,
                )->create(BackupKind::PreUpdate);
                self::fail('A database dump failure must abort backup publication.');
            } catch (RuntimeException $exception) {
                self::assertSame('test-only database dump failure', $exception->getMessage());
            }

            $root = $fixture['root'];
            self::assertSame([], array_values(array_diff(scandir($root.'/completed') ?: [], ['.', '..'])));
            self::assertSame([], array_values(array_diff(scandir($root.'/work') ?: [], ['.', '..'])));
        } finally {
            $this->removeTree($fixture['base']);
        }
    }

    /** @param array{base:string,root:string,environment:string,private:string} $fixture */
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
            1800,
            false,
            19_000_000,
            ['environment' => $fixture['environment']],
            ['application' => $fixture['private']],
        );
    }

    private function manager(
        BackupRuntimeConfiguration $configuration,
        BackupDatabaseDumper $database,
    ): BackupManager {
        return new BackupManager(
            $configuration,
            static fn (): BackupDatabaseDumper => $database,
            new BackupPayloadCollector(
                $configuration->configFiles,
                $configuration->privateDirectories,
            ),
            new BackupBundleWriter,
            new SodiumBackupCipherFactory,
            new FilesystemBackupRepository($configuration->root),
            new FixedBackupClock,
            new FixedBackupRandomGenerator,
            'test-release',
            base_path('composer.lock'),
            database_path('migrations'),
            'mysql',
        );
    }

    /** @return array{base:string,root:string,environment:string,private:string} */
    private function fixture(string $case): array
    {
        $base = storage_path('framework/testing/backup-manager-'.$case.'-'.bin2hex(random_bytes(4)));
        $private = $base.'/private';
        mkdir($private, 0700, true);

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
            if ($name === '.' || $name === '..') {
                continue;
            }
            $this->removeTree($path.'/'.$name);
        }

        @rmdir($path);
    }
}

final readonly class RecordingBackupDatabaseDumper implements BackupDatabaseDumper
{
    public function __construct(private string $contents) {}

    public function capture(string $destinationPath): void
    {
        file_put_contents($destinationPath, $this->contents);
        chmod($destinationPath, 0600);
    }
}

final class FailingBackupDatabaseDumper implements BackupDatabaseDumper
{
    public function capture(string $destinationPath): void
    {
        file_put_contents($destinationPath, 'partial-plaintext-database');

        throw new RuntimeException('test-only database dump failure');
    }
}

final class FixedBackupClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-29T04:00:00+00:00');
    }
}

final class FixedBackupRandomGenerator implements RandomGenerator
{
    public function bytes(int $length): string
    {
        return str_repeat("\x01", $length);
    }

    public function integer(int $minimum, int $maximum): int
    {
        return $minimum;
    }
}
