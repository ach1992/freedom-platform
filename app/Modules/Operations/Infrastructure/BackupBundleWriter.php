<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\BackupBundleWriter as BackupBundleWriterContract;
use RuntimeException;

final readonly class BackupBundleWriter implements BackupBundleWriterContract
{
    private const MAGIC = "FREEDOM-BACKUP-BUNDLE-V1\n";

    /**
     * @param  array<string, string>  $entries  logical archive path => absolute source path
     * @return array{entry_count:int,plaintext_sha256:string}
     */
    public function write(string $destinationPath, array $entries): array
    {
        if ($entries === []) {
            throw new RuntimeException('The backup bundle has no entries.');
        }

        ksort($entries, SORT_STRING);
        $output = fopen($destinationPath, 'x+b');
        if ($output === false) {
            throw new RuntimeException('The backup bundle could not be created.');
        }

        $bundleHash = hash_init('sha256');

        $complete = false;
        try {
            $this->writeBytes($output, self::MAGIC, $bundleHash);

            foreach ($entries as $archivePath => $sourcePath) {
                $this->assertArchivePath($archivePath);
                $this->assertSourceFile($sourcePath);

                $size = filesize($sourcePath);
                $sha256 = hash_file('sha256', $sourcePath);
                if (! is_int($size) || ! is_string($sha256)) {
                    throw new RuntimeException('The backup source metadata could not be calculated.');
                }

                $header = json_encode([
                    'path' => $archivePath,
                    'size' => $size,
                    'sha256' => $sha256,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $this->writeBytes($output, pack('N', strlen($header)).$header, $bundleHash);

                $input = fopen($sourcePath, 'rb');
                if ($input === false) {
                    throw new RuntimeException('The backup source could not be opened.');
                }

                $copied = 0;
                $streamHash = hash_init('sha256');
                try {
                    while (! feof($input)) {
                        $chunk = fread($input, 1_048_576);
                        if ($chunk === false) {
                            throw new RuntimeException('The backup source could not be read.');
                        }
                        if ($chunk === '') {
                            break;
                        }
                        $this->writeBytes($output, $chunk, $bundleHash);
                        hash_update($streamHash, $chunk);
                        $copied += strlen($chunk);
                    }
                } finally {
                    fclose($input);
                }

                if ($copied !== $size || ! hash_equals($sha256, hash_final($streamHash))) {
                    throw new RuntimeException('A backup source changed while it was being captured.');
                }
            }

            $this->writeBytes($output, pack('N', 0), $bundleHash);
            if (! fflush($output)) {
                throw new RuntimeException('The backup bundle could not be flushed.');
            }
            $complete = true;
        } finally {
            fclose($output);
            if (! $complete && is_file($destinationPath)) {
                @unlink($destinationPath);
            }
        }

        if (! chmod($destinationPath, 0600)) {
            throw new RuntimeException('The backup bundle permissions could not be secured.');
        }

        return [
            'entry_count' => count($entries),
            'plaintext_sha256' => hash_final($bundleHash),
        ];
    }

    /** @param resource $output */
    private function writeBytes($output, string $bytes, \HashContext $hash): void
    {
        if (fwrite($output, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('The backup bundle could not be written.');
        }
        hash_update($hash, $bytes);
    }

    private function assertArchivePath(string $path): void
    {
        if ($path === ''
            || str_starts_with($path, '/')
            || str_contains($path, "\0")
            || preg_match('#(?:\A|/)\.\.(?:/|\z)#', $path) === 1
            || str_contains($path, '\\')
        ) {
            throw new RuntimeException('The backup archive path is invalid.');
        }
    }

    private function assertSourceFile(string $path): void
    {
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)
            || ! is_file($path)
            || is_link($path)
            || ! is_readable($path)
        ) {
            throw new RuntimeException('A backup source file is unavailable or unsafe.');
        }
    }
}
