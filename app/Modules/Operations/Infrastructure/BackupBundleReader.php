<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\BackupBundleReader as BackupBundleReaderContract;
use RuntimeException;
use Throwable;

final readonly class BackupBundleReader implements BackupBundleReaderContract
{
    private const MAGIC = "FREEDOM-BACKUP-BUNDLE-V1\n";

    private const MAX_HEADER_BYTES = 8192;

    /** @return array<string, string> */
    public function extract(string $bundlePath, string $destinationDirectory): array
    {
        if (! is_file($bundlePath) || is_link($bundlePath) || ! is_readable($bundlePath)) {
            throw new RuntimeException('The decrypted backup bundle is unavailable or unsafe.');
        }

        if (file_exists($destinationDirectory) || is_link($destinationDirectory)) {
            throw new RuntimeException('The restore extraction destination already exists.');
        }

        if (! mkdir($destinationDirectory, 0700, true)
            || ! chmod($destinationDirectory, 0700)
        ) {
            throw new RuntimeException('The restore extraction destination could not be secured.');
        }

        $root = realpath($destinationDirectory);
        $input = fopen($bundlePath, 'rb');
        $bundleBytes = filesize($bundlePath);

        if ($root === false || $input === false || ! is_int($bundleBytes) || $bundleBytes < strlen(self::MAGIC) + 4) {
            if (is_resource($input)) {
                fclose($input);
            }

            throw new RuntimeException('The decrypted backup bundle could not be opened.');
        }

        $entries = [];

        try {
            if (! hash_equals(self::MAGIC, $this->readExact($input, strlen(self::MAGIC)))) {
                throw new RuntimeException('The backup bundle header is invalid.');
            }

            while (true) {
                $length = unpack('Nlength', $this->readExact($input, 4))['length'] ?? -1;

                if ($length === 0) {
                    if (fread($input, 1) !== '') {
                        throw new RuntimeException('The backup bundle contains trailing data.');
                    }
                    break;
                }

                if ($length < 2 || $length > self::MAX_HEADER_BYTES) {
                    throw new RuntimeException('The backup bundle entry header length is invalid.');
                }

                try {
                    $header = json_decode($this->readExact($input, $length), true, 16, JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    throw new RuntimeException('The backup bundle entry header is invalid.');
                }

                $path = is_array($header) ? ($header['path'] ?? null) : null;
                $size = is_array($header) ? ($header['size'] ?? null) : null;
                $sha256 = is_array($header) ? ($header['sha256'] ?? null) : null;

                if (! is_string($path)
                    || ! is_int($size)
                    || $size < 0
                    || $size > $bundleBytes
                    || ! is_string($sha256)
                    || preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1
                ) {
                    throw new RuntimeException('The backup bundle entry metadata is invalid.');
                }

                $this->assertLogicalPath($path);

                if (array_key_exists($path, $entries)) {
                    throw new RuntimeException('The backup bundle contains a duplicate entry.');
                }

                $destination = $this->destinationPath($root, $path);
                $this->createParentDirectories($root, dirname($destination));

                $output = fopen($destination, 'x+b');
                if ($output === false) {
                    throw new RuntimeException('A restore bundle entry could not be created.');
                }

                $remaining = $size;
                $hash = hash_init('sha256');

                try {
                    while ($remaining > 0) {
                        $chunk = $this->readExact($input, min(1_048_576, $remaining));
                        if (fwrite($output, $chunk) !== strlen($chunk)) {
                            throw new RuntimeException('A restore bundle entry could not be written.');
                        }

                        hash_update($hash, $chunk);
                        $remaining -= strlen($chunk);
                    }

                    if (! fflush($output)) {
                        throw new RuntimeException('A restore bundle entry could not be flushed.');
                    }
                } finally {
                    fclose($output);
                }

                if (! hash_equals($sha256, hash_final($hash)) || ! chmod($destination, 0600)) {
                    throw new RuntimeException('A restore bundle entry failed integrity verification.');
                }

                $entries[$path] = $destination;
            }
        } finally {
            fclose($input);
        }

        if ($entries === []) {
            throw new RuntimeException('The backup bundle contains no entries.');
        }

        ksort($entries, SORT_STRING);

        return $entries;
    }

    /** @param resource $handle */
    private function readExact($handle, int $length): string
    {
        if ($length < 1) {
            throw new RuntimeException('The backup bundle read length is invalid.');
        }

        $buffer = '';

        while (strlen($buffer) < $length) {
            $chunk = fread($handle, max(1, $length - strlen($buffer)));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('The backup bundle is truncated.');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function assertLogicalPath(string $path): void
    {
        if ($path === ''
            || str_starts_with($path, '/')
            || str_contains($path, "\0")
            || str_contains($path, '\\')
        ) {
            throw new RuntimeException('The backup bundle entry path is invalid.');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('The backup bundle entry path is invalid.');
            }
        }

        $valid = $path === 'database/database.sql'
            || (count($segments) === 2 && $segments[0] === 'config')
            || (count($segments) >= 3 && $segments[0] === 'private');

        if (! $valid) {
            throw new RuntimeException('The backup bundle entry path is outside restore authority.');
        }
    }

    private function destinationPath(string $root, string $logicalPath): string
    {
        $destination = $root.'/'.str_replace('/', DIRECTORY_SEPARATOR, $logicalPath);
        $normalizedRoot = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (! str_starts_with($destination, $normalizedRoot)) {
            throw new RuntimeException('The backup bundle entry escapes the restore workspace.');
        }

        return $destination;
    }

    private function createParentDirectories(string $root, string $directory): void
    {
        if (is_dir($directory)) {
            $real = realpath($directory);
            if ($real === false || ! str_starts_with($real.DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('A restore extraction directory is unsafe.');
            }

            return;
        }

        if (file_exists($directory) || is_link($directory)
            || ! mkdir($directory, 0700, true)
            || ! chmod($directory, 0700)
        ) {
            throw new RuntimeException('A restore extraction directory could not be secured.');
        }
    }
}
