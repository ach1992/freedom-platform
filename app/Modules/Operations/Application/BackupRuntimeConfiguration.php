<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use InvalidArgumentException;
use RuntimeException;

final readonly class BackupRuntimeConfiguration
{
    /**
     * @param  array<string, string>  $configFiles
     * @param  array<string, string>  $privateDirectories
     */
    public function __construct(
        public bool $enabled,
        public string $root,
        private ?string $encryptionKey,
        public int $retentionDays,
        public int $databaseIntervalMinutes,
        public string $dailyTime,
        public int $frequentOverlapMinutes,
        public int $dailyOverlapMinutes,
        public int $priorityLockWaitSeconds,
        public bool $telegramExportEnabled,
        public int $telegramPartBytes,
        public array $configFiles,
        public array $privateDirectories,
    ) {
        if (! str_starts_with($root, DIRECTORY_SEPARATOR) || str_contains($root, "\0")) {
            throw new InvalidArgumentException('Backup root configuration is invalid.');
        }

        if ($enabled && $encryptionKey === null) {
            throw new InvalidArgumentException('Backup encryption key configuration is invalid.');
        }

        if ($encryptionKey !== null
            && strlen($encryptionKey) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES
        ) {
            throw new InvalidArgumentException('Backup encryption key configuration is invalid.');
        }

        if ($retentionDays < 1 || $retentionDays > 3650) {
            throw new InvalidArgumentException('Backup retention configuration is invalid.');
        }

        if ($databaseIntervalMinutes < 1 || $databaseIntervalMinutes > 60 || 60 % $databaseIntervalMinutes !== 0) {
            throw new InvalidArgumentException('Backup database interval configuration is invalid.');
        }

        if (preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $dailyTime) !== 1) {
            throw new InvalidArgumentException('Backup daily time configuration is invalid.');
        }

        foreach ([$frequentOverlapMinutes, $dailyOverlapMinutes] as $minutes) {
            if ($minutes < 1 || $minutes > 1440) {
                throw new InvalidArgumentException('Backup overlap configuration is invalid.');
            }
        }

        if ($priorityLockWaitSeconds < 1 || $priorityLockWaitSeconds > 7200) {
            throw new InvalidArgumentException('Backup priority lock-wait configuration is invalid.');
        }

        // The current protected Telegram document boundary is 20 MB. Keeping the
        // backup part below that bound also satisfies BAK-001's <=45 MB requirement
        // without widening the generic Telegram provider surface.
        if ($telegramPartBytes < 1 || $telegramPartBytes > 20_000_000) {
            throw new InvalidArgumentException('Backup Telegram part-size configuration is invalid.');
        }

        $this->assertPathMap($configFiles, 'Backup configuration-file');
        $this->assertPathMap($privateDirectories, 'Backup private-directory');
    }

    public function encryptionKey(): string
    {
        return $this->encryptionKey
            ?? throw new RuntimeException('Backup encryption key configuration is unavailable.');
    }

    /**
     * @param  array<string, string>  $paths
     */
    private function assertPathMap(array $paths, string $label): void
    {
        foreach ($paths as $name => $path) {
            if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/', $name) !== 1
                || ! str_starts_with($path, DIRECTORY_SEPARATOR)
                || str_contains($path, "\0")
            ) {
                throw new InvalidArgumentException($label.' configuration is invalid.');
            }
        }
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $enabled = (bool) ($values['enabled'] ?? false);
        $encodedKey = $values['encryption_key'] ?? null;
        $key = null;

        if ($encodedKey !== null && $encodedKey !== '') {
            if (! is_string($encodedKey) || $encodedKey !== trim($encodedKey)) {
                throw new InvalidArgumentException('Backup encryption key configuration is invalid.');
            }

            $decoded = base64_decode($encodedKey, true);
            if (! is_string($decoded)) {
                throw new InvalidArgumentException('Backup encryption key configuration is invalid.');
            }
            $key = $decoded;
        }

        return new self(
            $enabled,
            self::string($values['root'] ?? null, 'Backup root'),
            $key,
            self::integer($values['retention_days'] ?? null, 'Backup retention'),
            self::integer($values['database_interval_minutes'] ?? null, 'Backup database interval'),
            self::string($values['daily_time'] ?? null, 'Backup daily time'),
            self::integer($values['frequent_overlap_minutes'] ?? null, 'Backup frequent overlap'),
            self::integer($values['daily_overlap_minutes'] ?? null, 'Backup daily overlap'),
            self::integer($values['priority_lock_wait_seconds'] ?? null, 'Backup priority lock wait'),
            (bool) ($values['telegram_export_enabled'] ?? false),
            self::integer($values['telegram_part_bytes'] ?? null, 'Backup Telegram part size'),
            self::stringMap($values['config_files'] ?? null, 'Backup configuration files'),
            self::stringMap($values['private_directories'] ?? null, 'Backup private directories'),
        );
    }

    private static function string(mixed $value, string $label): string
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException($label.' configuration is invalid.');
        }

        return $value;
    }

    private static function integer(mixed $value, string $label): int
    {
        if (! is_int($value)) {
            throw new InvalidArgumentException($label.' configuration is invalid.');
        }

        return $value;
    }

    /** @return array<string, string> */
    private static function stringMap(mixed $value, string $label): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException($label.' configuration is invalid.');
        }

        $result = [];
        foreach ($value as $key => $path) {
            if (! is_string($key) || ! is_string($path)) {
                throw new InvalidArgumentException($label.' configuration is invalid.');
            }
            $result[$key] = $path;
        }

        return $result;
    }
}
