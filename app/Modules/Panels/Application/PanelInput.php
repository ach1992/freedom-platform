<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use InvalidArgumentException;

final class PanelInput
{
    public static function approvalId(string $value): string
    {
        $normalized = strtoupper(trim($value));
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $normalized) !== 1) {
            throw new InvalidArgumentException('Sensitive approval ID is invalid.');
        }

        return $normalized;
    }

    public static function positiveId(int $value, string $label): int
    {
        if ($value < 1) {
            throw new InvalidArgumentException("{$label} must be positive.");
        }

        return $value;
    }

    public static function expectedVersion(int $value): int
    {
        if ($value < 1) {
            throw new InvalidArgumentException('Expected version must be positive.');
        }

        return $value;
    }

    public static function credentialKeyVersion(int $value): int
    {
        if ($value < 1) {
            throw new InvalidArgumentException('Credential key version must be positive.');
        }

        return $value;
    }
}
