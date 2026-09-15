<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use InvalidArgumentException;

final class PanelText
{
    public static function required(string $value, int $maximumLength = 191): string
    {
        $normalized = trim($value);
        self::assertValid($normalized, $maximumLength, false);

        return $normalized;
    }

    public static function optional(?string $value, int $maximumLength = 191): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = trim($value);
        self::assertValid($normalized, $maximumLength, true);

        return $normalized;
    }

    private static function assertValid(string $value, int $maximumLength, bool $emptyAllowed): void
    {
        if ((! $emptyAllowed && $value === '')
            || mb_strlen($value) > $maximumLength
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1
        ) {
            throw new InvalidArgumentException('Panel text is invalid.');
        }
    }
}
