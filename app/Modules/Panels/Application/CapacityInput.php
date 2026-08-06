<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use InvalidArgumentException;

final class CapacityInput
{
    public static function units(int $value, string $label = 'Capacity units'): int
    {
        if ($value < 1) {
            throw new InvalidArgumentException($label.' must be positive.');
        }

        return $value;
    }

    public static function hardLimit(int $value): int
    {
        if ($value < 1) {
            throw new InvalidArgumentException('Capacity hard limit must be positive.');
        }

        return $value;
    }

    public static function commandKey(string $value): string
    {
        $normalized = trim($value);
        if (strlen($normalized) < 24
            || strlen($normalized) > 128
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9:_.-]+\z/', $normalized) !== 1
        ) {
            throw new InvalidArgumentException('Capacity command key is invalid.');
        }

        return $normalized;
    }

    public static function code(string $value, string $label): string
    {
        $normalized = strtolower(trim($value));
        if (preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $normalized) !== 1) {
            throw new InvalidArgumentException($label.' is invalid.');
        }

        return $normalized;
    }
}
