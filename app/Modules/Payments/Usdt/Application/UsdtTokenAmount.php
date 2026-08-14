<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

use DomainException;

final class UsdtTokenAmount
{
    public const QUOTE_DECIMALS = 6;

    public const MAX_BASE_UNIT_DIGITS = 65;

    public static function toBaseUnits(string $value, int $tokenDecimals): string
    {
        $value = trim($value);
        if ($tokenDecimals < 0 || $tokenDecimals > 36) {
            throw new DomainException('USDT token decimals are outside the supported fixed-precision range.');
        }
        if (preg_match('/\A(?:0|[1-9][0-9]{0,35})(?:\.([0-9]{1,6}))?\z/', $value, $matches) !== 1) {
            throw new DomainException('USDT amount must be a plain decimal with at most six fractional digits.');
        }

        [$whole] = explode('.', $value.'.', 2);
        $fraction = $matches[1] ?? '';
        if (strlen($fraction) > $tokenDecimals) {
            $discarded = substr($fraction, $tokenDecimals);
            if (trim($discarded, '0') !== '') {
                throw new DomainException('USDT quote precision cannot be represented exactly by the token decimals.');
            }
            $fraction = substr($fraction, 0, $tokenDecimals);
        }
        $fraction = str_pad($fraction, $tokenDecimals, '0');
        $baseUnits = ltrim($whole.$fraction, '0');
        $baseUnits = $baseUnits === '' ? '0' : $baseUnits;
        if ($baseUnits === '0') {
            throw new DomainException('USDT amount must be positive.');
        }
        if (strlen($baseUnits) > self::MAX_BASE_UNIT_DIGITS) {
            throw new DomainException('USDT raw token amount exceeds the supported decimal range.');
        }

        return $baseUnits;
    }

    public static function normalizeBaseUnits(string|int $value): string
    {
        if (is_int($value)) {
            if ($value < 1) {
                throw new DomainException('USDT raw token amount must be positive.');
            }

            return (string) $value;
        }

        $value = trim($value);
        if (preg_match('/\A[0-9]{1,65}\z/', $value) !== 1) {
            throw new DomainException('USDT raw token amount must be an unsigned decimal integer string.');
        }
        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        if ($normalized === '0') {
            throw new DomainException('USDT raw token amount must be positive.');
        }

        return $normalized;
    }

    public static function compareBaseUnits(string|int $left, string|int $right): int
    {
        $left = self::normalizeBaseUnits($left);
        $right = self::normalizeBaseUnits($right);
        if (strlen($left) !== strlen($right)) {
            return strlen($left) <=> strlen($right);
        }

        return strcmp($left, $right) <=> 0;
    }
}
