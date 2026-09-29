<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Infrastructure\BackupBundleWriter;
use App\Modules\Operations\Infrastructure\SodiumBackupCipher;
use RuntimeException;
use Tests\TestCase;

final class BackupArtifactCryptoTest extends TestCase
{
    /** @requirement BAK-001 BAK-002 SEC-001 QUA-001 */
    public function test_secretstream_round_trip_and_tamper_or_wrong_key_fail_closed(): void
    {
        $directory = $this->directory('crypto');
        $source = $directory.'/source.bundle';
        $encrypted = $directory.'/artifact.fbk';
        $decrypted = $directory.'/decrypted.bundle';
        $contents = str_repeat('backup-frame-', 180_000).'tail';

        try {
            file_put_contents($source, $contents);
            $key = random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
            $cipher = new SodiumBackupCipher($key);

            $result = $cipher->encryptFile($source, $encrypted);

            self::assertSame(filesize($encrypted), $result['bytes']);
            self::assertSame(hash_file('sha256', $encrypted), $result['sha256']);
            self::assertStringNotContainsString('backup-frame-', (string) file_get_contents($encrypted));

            $cipher->decryptFile($encrypted, $decrypted);
            self::assertSame(hash_file('sha256', $source), hash_file('sha256', $decrypted));
            unlink($decrypted);

            $tampered = (string) file_get_contents($encrypted);
            $offset = intdiv(strlen($tampered), 2);
            $tampered[$offset] = chr(ord($tampered[$offset]) ^ 0x01);
            file_put_contents($encrypted, $tampered);

            try {
                $cipher->decryptFile($encrypted, $decrypted);
                self::fail('A tampered backup must not decrypt.');
            } catch (RuntimeException) {
                self::assertFileDoesNotExist($decrypted);
            }

            unlink($encrypted);
            $cipher->encryptFile($source, $encrypted);

            try {
                (new SodiumBackupCipher(random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES)))
                    ->decryptFile($encrypted, $decrypted);
                self::fail('A backup must not decrypt with a different key.');
            } catch (RuntimeException) {
                self::assertFileDoesNotExist($decrypted);
            }
        } finally {
            $this->removeTree($directory);
        }
    }

    /** @requirement BAK-001 QUA-001 */
    public function test_bundle_output_is_deterministic_and_unsafe_paths_leave_no_partial_bundle(): void
    {
        $directory = $this->directory('bundle');
        $first = $directory.'/a.txt';
        $second = $directory.'/b.txt';
        $one = $directory.'/one.bundle';
        $two = $directory.'/two.bundle';
        $unsafe = $directory.'/unsafe.bundle';

        try {
            file_put_contents($first, 'alpha');
            file_put_contents($second, 'beta');
            $writer = new BackupBundleWriter;

            $firstResult = $writer->write($one, [
                'private/zeta.txt' => $second,
                'config/alpha.txt' => $first,
            ]);
            $secondResult = $writer->write($two, [
                'config/alpha.txt' => $first,
                'private/zeta.txt' => $second,
            ]);

            self::assertSame(2, $firstResult['entry_count']);
            self::assertSame($firstResult['plaintext_sha256'], $secondResult['plaintext_sha256']);
            self::assertSame(file_get_contents($one), file_get_contents($two));

            try {
                $writer->write($unsafe, ['../escape' => $first]);
                self::fail('Unsafe bundle paths must be rejected.');
            } catch (RuntimeException $exception) {
                self::assertSame('The backup archive path is invalid.', $exception->getMessage());
                self::assertFileDoesNotExist($unsafe);
            }
        } finally {
            $this->removeTree($directory);
        }
    }

    private function directory(string $case): string
    {
        $path = storage_path('framework/testing/backup-artifact-'.$case.'-'.bin2hex(random_bytes(4)));
        mkdir($path, 0700, true);

        return $path;
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $entry = $path.'/'.$name;
            if (is_dir($entry) && ! is_link($entry)) {
                $this->removeTree($entry);
            } else {
                @unlink($entry);
            }
        }

        @rmdir($path);
    }
}
