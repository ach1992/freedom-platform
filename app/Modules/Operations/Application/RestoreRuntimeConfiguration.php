<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use InvalidArgumentException;

final readonly class RestoreRuntimeConfiguration
{
    public function __construct(
        public bool $enabled,
        public string $mariaDbBinary,
        public int $processTimeoutSeconds,
        public int $quiesceSeconds,
    ) {
        if (! str_starts_with($mariaDbBinary, DIRECTORY_SEPARATOR) || str_contains($mariaDbBinary, "\0")) {
            throw new InvalidArgumentException('Restore MariaDB binary configuration is invalid.');
        }

        if ($processTimeoutSeconds < 1 || $processTimeoutSeconds > 86_400) {
            throw new InvalidArgumentException('Restore process timeout configuration is invalid.');
        }

        if ($quiesceSeconds < 1 || $quiesceSeconds > 3600) {
            throw new InvalidArgumentException('Restore quiescence configuration is invalid.');
        }
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        return new self(
            (bool) ($values['enabled'] ?? false),
            self::string($values['mariadb_binary'] ?? null, 'Restore MariaDB binary'),
            self::integer($values['process_timeout_seconds'] ?? null, 'Restore process timeout'),
            self::integer($values['quiesce_seconds'] ?? null, 'Restore quiescence'),
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
}
