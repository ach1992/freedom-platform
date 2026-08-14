<?php

declare(strict_types=1);

namespace App\Modules\Payments\NowPayments\Application;

use App\Modules\Payments\Usdt\Application\UsdtDecimal;
use DomainException;

final class NowPaymentsDecimal
{
    public const PRICE_PRECISION = 8;

    /** @return numeric-string */
    public static function irrToUsdProxy(int $amountIrr, string $sharedRateIrr): string
    {
        if ($amountIrr <= 0) {
            throw new DomainException('NOWPayments purchase amount must be positive.');
        }
        $rate = UsdtDecimal::rate($sharedRateIrr);
        $amount = (string) $amountIrr;
        $candidate = bcdiv($amount, $rate, self::PRICE_PRECISION);
        if (bccomp(bcmul($candidate, $rate, 20), $amount, 20) < 0) {
            $candidate = bcadd($candidate, '0.00000001', self::PRICE_PRECISION);
        }

        return self::normalize($candidate, self::PRICE_PRECISION);
    }

    /** @return numeric-string */
    public static function normalize(string $value, int $precision = 18): string
    {
        if ($precision < 0 || $precision > 18
            || preg_match('/\A(?:0|[1-9][0-9]{0,29})(?:\.[0-9]{1,18})?\z/', $value) !== 1) {
            throw new DomainException('NOWPayments decimal value is invalid.');
        }
        if (! is_numeric($value)) {
            throw new DomainException('NOWPayments decimal value is invalid.');
        }
        $normalized = bcadd($value, '0', $precision);
        if (bccomp($normalized, '0', $precision) <= 0) {
            throw new DomainException('NOWPayments decimal value must be positive.');
        }

        return $normalized;
    }

    public static function equals(string $left, string $right, int $precision = 18): bool
    {
        return bccomp(self::normalize($left, $precision), self::normalize($right, $precision), $precision) === 0;
    }

    /** @return numeric-string */
    public static function jsonNumber(string $value, int $precision = self::PRICE_PRECISION): string
    {
        $normalized = self::normalize($value, $precision);
        $trimmed = rtrim(rtrim($normalized, '0'), '.');
        $result = $trimmed === '' ? '0' : $trimmed;
        if (! is_numeric($result)) {
            throw new DomainException('NOWPayments decimal JSON value is invalid.');
        }

        return $result;
    }
}
