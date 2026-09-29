<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Infrastructure\FilesystemBackupRepository;
use DateTimeImmutable;
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
            mkdir($root.'/work/backup-20260929T040001Z-0202020202020202', 0700);
            file_put_contents(
                $root.'/work/backup-20260929T040001Z-0202020202020202/plaintext.tmp',
                'partial-plaintext',
            );
            file_put_contents($root.'/completed/operator-note.txt', 'leave-me');

            $repository->recoverIncomplete();

            self::assertFileDoesNotExist($root.'/completed/backup-'.$orphanId.'.fbk');
            self::assertDirectoryDoesNotExist($root.'/work/backup-20260929T040001Z-0202020202020202');
            self::assertFileExists($root.'/completed/operator-note.txt');
        } finally {
            $this->removeTree($base);
        }
    }

    /** @requirement BAK-001 SEC-001 QUA-001 */
    public function test_retention_removes_expired_completed_pairs_but_preserves_unknown_files(): void
    {
        $base = $this->directory('retention');
        $repository = new FilesystemBackupRepository($base.'/backups');

        try {
            $root = $repository->root();
            $oldId = '20260701T040000Z-0101010101010101';
            $newId = '20260928T040000Z-0202020202020202';
            $this->completedPair($root, $oldId, '2026-07-01T04:00:00+00:00');
            $this->completedPair($root, $newId, '2026-09-28T04:00:00+00:00');
            file_put_contents($root.'/completed/operator-note.txt', 'leave-me');
            file_put_contents($root.'/completed/backup-malformed.manifest.json', '{broken');

            $repository->prune(new DateTimeImmutable('2026-09-29T04:00:00+00:00'), 30);

            self::assertFileDoesNotExist($root.'/completed/backup-'.$oldId.'.fbk');
            self::assertFileDoesNotExist($root.'/completed/backup-'.$oldId.'.manifest.json');
            self::assertFileExists($root.'/completed/backup-'.$newId.'.fbk');
            self::assertFileExists($root.'/completed/backup-'.$newId.'.manifest.json');
            self::assertFileExists($root.'/completed/operator-note.txt');
            self::assertFileExists($root.'/completed/backup-malformed.manifest.json');
        } finally {
            $this->removeTree($base);
        }
    }

    private function completedPair(string $root, string $id, string $completedAt): void
    {
        $artifact = 'backup-'.$id.'.fbk';
        file_put_contents($root.'/completed/'.$artifact, 'ciphertext');
        file_put_contents(
            $root.'/completed/backup-'.$id.'.manifest.json',
            json_encode([
                'authority' => 'freedom_platform_backup_v1',
                'completed_at' => $completedAt,
                'artifact' => ['filename' => $artifact],
            ], JSON_THROW_ON_ERROR),
        );
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
