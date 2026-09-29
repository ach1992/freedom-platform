<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Infrastructure\FilesystemBackupRepository;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class FilesystemBackupRepositoryTest extends TestCase
{
    /** @requirement BAK-001 SEC-001 QUA-001 */
    public function test_recovery_removes_only_authority_shaped_incomplete_state(): void
    {
        $base = $this->directory('recovery');
        $repository = new FilesystemBackupRepository($base.'/backups');

        try {
            $root = $repository->root();
            $orphanId = '20260929T040000Z-0101010101010101';
            file_put_contents($root.'/completed/backup-'.$orphanId.'.fbk', 'orphan-ciphertext');
            file_put_contents($root.'/completed/backup-'.$orphanId.'.telegram-export.json', 'orphan-export');
            mkdir($root.'/work/backup-20260929T040001Z-0202020202020202', 0700);
            file_put_contents(
                $root.'/work/backup-20260929T040001Z-0202020202020202/plaintext.tmp',
                'partial-plaintext',
            );
            file_put_contents($root.'/completed/operator-note.txt', 'leave-me');

            $repository->recoverIncomplete();

            self::assertFileDoesNotExist($root.'/completed/backup-'.$orphanId.'.fbk');
            self::assertFileDoesNotExist($root.'/completed/backup-'.$orphanId.'.telegram-export.json');
            self::assertDirectoryDoesNotExist($root.'/work/backup-20260929T040001Z-0202020202020202');
            self::assertFileExists($root.'/completed/operator-note.txt');
        } finally {
            $this->removeTree($base);
        }
    }

    /** @requirement BAK-001 SEC-001 QUA-001 */
    public function test_retention_removes_expired_completed_pairs_and_export_sidecars_but_preserves_unknown_files(): void
    {
        $base = $this->directory('retention');
        $repository = new FilesystemBackupRepository($base.'/backups');

        try {
            $root = $repository->root();
            $oldId = '20260701T040000Z-0101010101010101';
            $newId = '20260928T040000Z-0202020202020202';
            $this->completedPair($root, $oldId, '2026-07-01T04:00:00+00:00');
            $this->completedPair($root, $newId, '2026-09-28T04:00:00+00:00');
            file_put_contents($root.'/completed/backup-'.$oldId.'.telegram-export.json', '{}');
            file_put_contents($root.'/completed/backup-'.$newId.'.telegram-export.json', '{}');
            file_put_contents($root.'/completed/operator-note.txt', 'leave-me');
            file_put_contents($root.'/completed/backup-malformed.manifest.json', '{broken');

            $repository->prune(new DateTimeImmutable('2026-09-29T04:00:00+00:00'), 30);

            self::assertFileDoesNotExist($root.'/completed/backup-'.$oldId.'.fbk');
            self::assertFileDoesNotExist($root.'/completed/backup-'.$oldId.'.manifest.json');
            self::assertFileDoesNotExist($root.'/completed/backup-'.$oldId.'.telegram-export.json');
            self::assertFileExists($root.'/completed/backup-'.$newId.'.fbk');
            self::assertFileExists($root.'/completed/backup-'.$newId.'.manifest.json');
            self::assertFileExists($root.'/completed/backup-'.$newId.'.telegram-export.json');
            self::assertFileExists($root.'/completed/operator-note.txt');
            self::assertFileExists($root.'/completed/backup-malformed.manifest.json');
        } finally {
            $this->removeTree($base);
        }
    }

    /** @requirement BAK-001 SEC-001 QUA-001 */
    public function test_completed_artifact_slice_and_telegram_manifest_are_integrity_bound_and_replay_safe(): void
    {
        $base = $this->directory('export');
        $repository = new FilesystemBackupRepository($base.'/backups');

        try {
            $root = $repository->root();
            $id = '20260929T050000Z-0303030303030303';
            $ciphertext = $this->completedPair($root, $id, '2026-09-29T05:00:00+00:00');

            self::assertSame([
                'filename' => 'backup-'.$id.'.fbk',
                'bytes' => strlen($ciphertext),
                'sha256' => hash('sha256', $ciphertext),
                'completed_at' => '2026-09-29T05:00:00+00:00',
            ], $repository->completedArtifactMetadata($id));
            self::assertSame(substr($ciphertext, 3, 8), $repository->readArtifactSlice($id, 3, 8));

            $telegramManifest = "{\"authority\":\"test\"}\n";
            $repository->storeTelegramExportManifest($id, $telegramManifest);
            $repository->storeTelegramExportManifest($id, $telegramManifest);
            self::assertSame($telegramManifest, $repository->telegramExportManifest($id));
            self::assertSame(
                0600,
                fileperms($root.'/completed/backup-'.$id.'.telegram-export.json') & 0777,
            );

            try {
                $repository->storeTelegramExportManifest($id, "{\"authority\":\"different\"}\n");
                self::fail('Conflicting Telegram export manifest must be rejected.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'The backup Telegram export manifest conflicts with existing state.',
                    $exception->getMessage(),
                );
            }

            $artifactPath = $root.'/completed/backup-'.$id.'.fbk';
            $tampered = $ciphertext;
            $tampered[0] = $tampered[0] === 'x' ? 'y' : 'x';
            file_put_contents($artifactPath, $tampered);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('The completed backup artifact hash failed verification.');
            $repository->completedArtifactMetadata($id);
        } finally {
            $this->removeTree($base);
        }
    }

    /** @requirement BAK-001 OPS-003 QUA-001 */
    public function test_priority_backup_waits_for_active_frequent_and_blocks_later_frequent_work(): void
    {
        $base = $this->directory('priority');
        $repository = new FilesystemBackupRepository($base.'/backups');

        try {
            $root = $repository->root();
            $autoload = base_path('vendor/autoload.php');
            $holderScript = $base.'/hold-frequent.php';
            $contenderScript = $base.'/try-frequent.php';

            file_put_contents($holderScript, sprintf(<<<'PHP'
<?php
require %s;

$root = $argv[1];
$repository = new App\Modules\Operations\Infrastructure\FilesystemBackupRepository($root);
$repository->synchronized(function () use ($root): void {
    file_put_contents($root.'/holder-ready', '1');
    usleep(500000);
});
PHP, var_export($autoload, true)));

            file_put_contents($contenderScript, sprintf(<<<'PHP'
<?php
require %s;

$root = $argv[1];
usleep(100000);
$repository = new App\Modules\Operations\Infrastructure\FilesystemBackupRepository($root);

try {
    $repository->synchronized(function () use ($root): void {
        file_put_contents($root.'/late-frequent-ran', '1');
    });
    file_put_contents($root.'/late-frequent-result', 'ran');
} catch (RuntimeException $exception) {
    file_put_contents($root.'/late-frequent-result', $exception->getMessage());
}
PHP, var_export($autoload, true)));

            $holder = new Process([PHP_BINARY, $holderScript, $root]);
            $holder->start();
            $this->waitForFile($root.'/holder-ready');

            $contender = new Process([PHP_BINARY, $contenderScript, $root]);
            $contender->start();

            $result = $repository->synchronized(
                function () use ($root): string {
                    file_put_contents($root.'/daily-ran', '1');

                    return 'daily-completed';
                },
                true,
                2_000,
            );

            $holder->wait();
            $contender->wait();

            self::assertSame('daily-completed', $result);
            self::assertTrue($holder->isSuccessful(), $holder->getErrorOutput());
            self::assertTrue($contender->isSuccessful(), $contender->getErrorOutput());
            self::assertFileExists($root.'/daily-ran');
            self::assertFileDoesNotExist($root.'/late-frequent-ran');
            self::assertSame(
                'A prioritized backup operation is waiting or active.',
                file_get_contents($root.'/late-frequent-result'),
            );
            self::assertSame(0600, fileperms($root.'/backup.priority.lock') & 0777);
            self::assertSame(0600, fileperms($root.'/backup.lock') & 0777);
        } finally {
            $this->removeTree($base);
        }
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 2.0;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                self::fail('Timed out waiting for concurrent backup fixture.');
            }
            usleep(10_000);
        }
    }

    private function completedPair(string $root, string $id, string $completedAt): string
    {
        $artifact = 'backup-'.$id.'.fbk';
        $contents = 'ciphertext-'.$id.'-payload';
        file_put_contents($root.'/completed/'.$artifact, $contents);
        file_put_contents(
            $root.'/completed/backup-'.$id.'.manifest.json',
            json_encode([
                'version' => 1,
                'authority' => 'freedom_platform_backup_v1',
                'backup_id' => $id,
                'completed_at' => $completedAt,
                'artifact' => [
                    'filename' => $artifact,
                    'bytes' => strlen($contents),
                    'sha256' => hash('sha256', $contents),
                ],
            ], JSON_THROW_ON_ERROR),
        );

        return $contents;
    }

    private function directory(string $case): string
    {
        $path = storage_path('framework/testing/backup-repository-'.$case.'-'.bin2hex(random_bytes(4)));
        mkdir($path, 0700, true);

        return $path;
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
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
