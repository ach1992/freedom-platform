<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use InvalidArgumentException;

final class CatalogText
{
    public static function requiredName(string $value): string
    {
        $normalized = trim($value);
        self::assertText($normalized, 191, false);

        return $normalized;
    }

    public static function optionalName(?string $value): ?string
    {
        return self::optional($value, 191);
    }

    public static function optionalDescription(?string $value): ?string
    {
        return self::optional($value, 5000);
    }

    private static function optional(?string $value, int $maximumLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        self::assertText($normalized, $maximumLength, true);

        return $normalized;
    }

    private static function assertText(string $value, int $maximumLength, bool $emptyAllowed): void
    {
        if ((! $emptyAllowed && $value === '')
            || mb_strlen($value) > $maximumLength
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1
        ) {
            throw new InvalidArgumentException('Catalog text is invalid.');
        }
    }
}
