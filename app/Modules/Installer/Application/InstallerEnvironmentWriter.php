<?php

declare(strict_types=1);

namespace App\Modules\Installer\Application;

use App\Shared\Application\RandomGenerator;
use RuntimeException;
use Throwable;

final class InstallerEnvironmentWriter
{
    /**
     * @param  list<string>  $allowedKeys
     *
     * @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011
     */
    public function __construct(
        private readonly RandomGenerator $random,
        private readonly string $environmentPath,
        private readonly string $snapshotPath,
        private readonly array $allowedKeys,
    ) {}

    /**
     * @param  array<string, string>  $values
     * @return array{created: bool, changed_keys: list<string>, generated_app_key: bool, snapshot_checksum: string}
     */
    public function write(array $values): array
    {
        return $this->synchronized(function () use ($values): array {
            $validated = $this->validateValues($values);
            $original = $this->readEnvironment();
            $created = $original === null;
            $generatedAppKey = false;

            if (! array_key_exists('APP_KEY', $validated) && ! $this->hasConfiguredAppKey($original ?? '')) {
                if (! in_array('APP_KEY', $this->allowedKeys, true)) {
                    throw new RuntimeException('The installer environment policy does not allow the required application key.');
                }

                $validated['APP_KEY'] = 'base64:'.base64_encode($this->random->bytes(32));
                $generatedAppKey = true;
            }

            $snapshotChecksum = $this->ensureSnapshot($original);
            $contents = $this->merge($original ?? '', $validated);
            $this->atomicWrite($this->environmentPath, $contents);

            $changedKeys = array_keys($validated);
            sort($changedKeys);

            return [
                'created' => $created,
                'changed_keys' => $changedKeys,
                'generated_app_key' => $generatedAppKey,
                'snapshot_checksum' => $snapshotChecksum,
            ];
        });
    }

    public function rollback(): void
    {
        $this->synchronized(function (): void {
            $metadata = $this->snapshotMetadata();

            if ($metadata === null) {
                return;
            }

            $snapshot = $this->readFile($this->snapshotPath);

            if ($snapshot === null || ! hash_equals($metadata['checksum'], hash('sha256', $snapshot))) {
                throw new RuntimeException('The installer environment rollback snapshot is invalid.');
            }

            if ($metadata['original_existed']) {
                $this->atomicWrite($this->environmentPath, $snapshot);
            } elseif (is_file($this->environmentPath) && ! unlink($this->environmentPath)) {
                throw new RuntimeException('The installer environment rollback could not remove the new environment file.');
            }

            $this->deleteSnapshotFiles();
        });
    }

    public function commit(): void
    {
        $this->synchronized(function (): void {
            $this->deleteSnapshotFiles();
        });
    }

    /** @param  array<string, string>  $values */
    private function validateValues(array $values): array
    {
        $validated = [];

        foreach ($values as $key => $value) {
            if (! is_string($key) || ! in_array($key, $this->allowedKeys, true)) {
                throw new RuntimeException('The installer environment contains a key that is not allowed.');
            }

            if (! is_string($value) || str_contains($value, "\n") || str_contains($value, "\r") || str_contains($value, "\0")) {
                throw new RuntimeException('The installer environment contains an invalid value.');
            }

            $validated[$key] = $value;
        }

        ksort($validated);

        return $validated;
    }

    private function readEnvironment(): ?string
    {
        if (! is_file($this->environmentPath)) {
            return null;
        }

        $contents = $this->readFile($this->environmentPath);

        if ($contents === null) {
            throw new RuntimeException('The installer could not read the environment file.');
        }

        return $contents;
    }

    private function hasConfiguredAppKey(string $contents): bool
    {
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?APP_KEY\s*=\s*(.+?)\s*$/', $line, $matches) === 1) {
                $value = trim($matches[1], " \t\n\r\0\x0B\"'");

                return $value !== '';
            }
        }

        return false;
    }

    /** @param  array<string, string>  $values */
    private function merge(string $contents, array $values): string
    {
        $lines = $contents === '' ? [] : preg_split('/\R/', rtrim($contents, "\r\n"));
        $lines = is_array($lines) ? $lines : [];
        $written = [];
        $merged = [];

        foreach ($lines as $line) {
            $key = $this->lineKey($line);

            if ($key === null || ! array_key_exists($key, $values)) {
                $merged[] = $line;

                continue;
            }

            if (! isset($written[$key])) {
                $merged[] = $this->render($key, $values[$key]);
                $written[$key] = true;
            }
        }

        foreach ($values as $key => $value) {
            if (! isset($written[$key])) {
                $merged[] = $this->render($key, $value);
            }
        }

        return implode("\n", $merged)."\n";
    }

    private function lineKey(string $line): ?string
    {
        if (preg_match('/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=/', $line, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function render(string $key, string $value): string
    {
        $escaped = str_replace(
            ['\\', '"', '$'],
            ['\\\\', '\\"', '\\$'],
            $value,
        );

        return $key.'="'.$escaped.'"';
    }

    private function ensureSnapshot(?string $original): string
    {
        $metadata = $this->snapshotMetadata();

        if ($metadata !== null) {
            $snapshot = $this->readFile($this->snapshotPath);

            if ($snapshot === null || ! hash_equals($metadata['checksum'], hash('sha256', $snapshot))) {
                throw new RuntimeException('The installer environment rollback snapshot is invalid.');
            }

            return $metadata['checksum'];
        }

        $snapshot = $original ?? '';
        $checksum = hash('sha256', $snapshot);
        $this->atomicWrite($this->snapshotPath, $snapshot);
        $this->atomicWrite($this->metadataPath(), json_encode([
            'version' => 1,
            'original_existed' => $original !== null,
            'checksum' => $checksum,
        ], JSON_THROW_ON_ERROR)."\n");

        return $checksum;
    }

    /** @return array{original_existed: bool, checksum: string}|null */
    private function snapshotMetadata(): ?array
    {
        $contents = $this->readFile($this->metadataPath());

        if ($contents === null) {
            return null;
        }

        try {
            $metadata = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('The installer environment rollback metadata is invalid.');
        }

        if (! is_array($metadata)
            || ($metadata['version'] ?? null) !== 1
            || ! is_bool($metadata['original_existed'] ?? null)
            || ! is_string($metadata['checksum'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $metadata['checksum']) !== 1
        ) {
            throw new RuntimeException('The installer environment rollback metadata is invalid.');
        }

        return [
            'original_existed' => $metadata['original_existed'],
            'checksum' => $metadata['checksum'],
        ];
    }

    private function deleteSnapshotFiles(): void
    {
        foreach ([$this->snapshotPath, $this->metadataPath()] as $path) {
            if (is_file($path) && ! unlink($path)) {
                throw new RuntimeException('The installer environment rollback files could not be removed.');
            }
        }
    }

    private function metadataPath(): string
    {
        return $this->snapshotPath.'.json';
    }

    private function readFile(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) ? $contents : null;
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('The installer could not prepare a private environment directory.');
        }

        $temporary = tempnam($directory, '.installer-');

        if ($temporary === false) {
            throw new RuntimeException('The installer could not create a temporary environment file.');
        }

        $handle = null;

        try {
            $handle = fopen($temporary, 'wb');

            if ($handle === false) {
                throw new RuntimeException('The installer could not open a temporary environment file.');
            }

            if (fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
                throw new RuntimeException('The installer could not write the environment file.');
            }

            if (function_exists('fsync') && ! fsync($handle)) {
                throw new RuntimeException('The installer could not flush the environment file.');
            }

            fclose($handle);
            $handle = null;

            if (! chmod($temporary, 0600)) {
                throw new RuntimeException('The installer could not secure the environment file.');
            }

            if (! rename($temporary, $path)) {
                throw new RuntimeException('The installer could not activate the environment file.');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @template T @param  callable(): T  $callback @return T */
    private function synchronized(callable $callback): mixed
    {
        $directory = dirname($this->snapshotPath);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('The installer could not prepare the environment lock directory.');
        }

        $handle = fopen($this->snapshotPath.'.lock', 'c+b');

        if ($handle === false) {
            throw new RuntimeException('The installer could not open the environment lock.');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('The installer could not acquire the environment lock.');
            }

            chmod($this->snapshotPath.'.lock', 0600);

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
