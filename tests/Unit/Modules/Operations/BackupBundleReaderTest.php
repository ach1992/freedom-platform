<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Infrastructure\BackupBundleReader;
use RuntimeException;
use Tests\TestCase;

final class BackupBundleReaderTest extends TestCase
{
    /** @requirement BAK-002 SEC-001 QUA-001 */
    public function test_valid_restore_bundle_extracts_only_authorized_regular_entries(): void
    {
        $base = $this->directory('valid');
        $bundle = $base.'/payload.bundle';
        $destination = $base.'/extracted';

        try {
            $this->writeBundle($bundle, [
                ['database/database.sql', 'CREATE TABLE restore_probe (id INT);'],
                ['config/environment', "APP_ENV=testing\n"],
                ['private/application/a/b.txt', 'private-payload'],
            ]);

            $entries = (new BackupBundleReader)->extract($bundle, $destination);

            self::assertSame([
                'config/environment',
                'database/database.sql',
                'private/application/a/b.txt',
            ], array_keys($entries));
            self::assertSame('private-payload', file_get_contents($entries['private/application/a/b.txt']));
            self::assertSame(0600, fileperms($entries['database/database.sql']) & 0777);
            self::assertSame(0700, fileperms($destination) & 0777);
        } finally {
            $this->removeTree($base);
        }
    }

    /** @requirement BAK-002 SEC-001 QUA-001 */
    public function test_traversal_and_unsupported_paths_fail_closed_without_writing_outside_workspace(): void
    {
        foreach ([
            '../escape.txt',
            'private/application/../../escape.txt',
            '/absolute/path',
            'unknown/value.txt',
        ] as $index => $path) {
            $base = $this->directory('unsafe-'.$index);
            $bundle = $base.'/payload.bundle';
            $destination = $base.'/extracted';

            try {
                $this->writeBundle($bundle, [[$path, 'escape']]);

                try {
                    (new BackupBundleReader)->extract($bundle, $destination);
                    self::fail('Unsafe restore bundle path must be rejected.');
                } catch (RuntimeException) {
                    self::assertFileDoesNotExist($base.'/escape.txt');
                }
            } finally {
                $this->removeTree($base);
            }
        }
    }

    /** @requirement BAK-002 SEC-001 QUA-001 */
    public function test_duplicate_entry_and_hash_mismatch_fail_closed(): void
    {
        $base = $this->directory('integrity');

        try {
            $duplicate = $base.'/duplicate.bundle';
            $this->writeBundle($duplicate, [
                ['database/database.sql', 'first'],
                ['database/database.sql', 'second'],
            ]);

            try {
                (new BackupBundleReader)->extract($duplicate, $base.'/duplicate-extracted');
                self::fail('Duplicate restore entries must be rejected.');
            } catch (RuntimeException $exception) {
                self::assertSame('The backup bundle contains a duplicate entry.', $exception->getMessage());
            }

            $mismatch = $base.'/mismatch.bundle';
            $this->writeBundle(
                $mismatch,
                [['database/database.sql', 'payload']],
                hashOverride: str_repeat('0', 64),
            );

            try {
                (new BackupBundleReader)->extract($mismatch, $base.'/mismatch-extracted');
                self::fail('Restore entry hash mismatch must be rejected.');
            } catch (RuntimeException $exception) {
                self::assertSame('A restore bundle entry failed integrity verification.', $exception->getMessage());
            }
        } finally {
            $this->removeTree($base);
        }
    }

    /**
     * @param  list<array{0:string,1:string}>  $entries
     */
    private function writeBundle(string $path, array $entries, ?string $hashOverride = null): void
    {
        $contents = "FREEDOM-BACKUP-BUNDLE-V1\n";

        foreach ($entries as [$logicalPath, $payload]) {
            $header = json_encode([
                'path' => $logicalPath,
                'size' => strlen($payload),
                'sha256' => $hashOverride ?? hash('sha256', $payload),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $contents .= pack('N', strlen($header)).$header.$payload;
        }

        $contents .= pack('N', 0);
        file_put_contents($path, $contents);
        chmod($path, 0600);
    }

    private function directory(string $case): string
    {
        $path = storage_path('framework/testing/restore-bundle-reader-'.$case.'-'.bin2hex(random_bytes(4)));
        mkdir($path, 0700, true);

        return $path;
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
