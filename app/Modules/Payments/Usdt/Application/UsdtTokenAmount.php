<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

use DomainException;

final class UsdtTokenAmount
{
    public const DECIMALS = 6;
    public const FACTOR = 1_000_000;

    public static function toBaseUnits(string $value): int
    {
        $value = trim($value);
        if (preg_match('/\A(?:0|[1-9][0-9]{0,12})(?:\.([0-9]{1,6}))?\z/', $value, $matches) !== 1) {
            throw new DomainException('USDT amount must be a plain decimal with at most six fractional digits.');
        }
        [$whole] = explode('.', $value.'.', 2);
        $fraction = str_pad($matches[1] ?? '', self::DECIMALS, '0');
        if (strlen($whole) > 12) {
            throw new DomainException('USDT amount exceeds the supported base-unit range.');
        }
        $baseUnits = ((int) $whole * self::FACTOR) + (int) $fraction;
        if ($baseUnits < 1) {
            throw new DomainException('USDT amount must be positive.');
        }

        return $baseUnits;
    }
}
