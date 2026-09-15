<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

use InvalidArgumentException;

final class UsdtDecimal
{
    /** @return numeric-string */
    public static function rate(string $value): string
    {
        if (preg_match('/\A(?:0|[1-9][0-9]{0,19})(?:\.[0-9]{1,8})?\z/', $value) !== 1) {
            throw new InvalidArgumentException('USDT rate must be a plain fixed-precision decimal.');
        }

        $normalized = bcadd(self::numeric($value), '0', 8);
        if (bccomp($normalized, '0', 8) <= 0) {
            throw new InvalidArgumentException('USDT rate must be positive.');
        }

        return $normalized;
    }

    /** @return numeric-string */
    public static function applyMargin(string $rawRateIrr, int $marginBps): string
    {
        $raw = self::rate($rawRateIrr);
        if ($marginBps < 0 || $marginBps >= 10_000) {
            throw new InvalidArgumentException('USDT margin basis points are invalid.');
        }

        $multiplier = self::numeric((string) (10_000 - $marginBps));
        $final = bcdiv(bcmul($raw, $multiplier, 8), '10000', 8);
        if (bccomp($final, '0', 8) <= 0) {
            throw new InvalidArgumentException('USDT final rate must be positive.');
        }

        return $final;
    }

    /** @return numeric-string */
    public static function roundUpIrrToUsdt(int $amountIrr, string $finalRateIrr, int $precision): string
    {
        if ($amountIrr <= 0) {
            throw new InvalidArgumentException('USDT quote amount must be positive.');
        }
        if ($precision < 0 || $precision > 6) {
            throw new InvalidArgumentException('USDT rounding precision must be between zero and six decimals.');
        }

        $amount = self::numeric((string) $amountIrr);
        $rate = self::rate($finalRateIrr);
        $candidate = bcdiv($amount, $rate, $precision);
        if (bccomp(bcmul($candidate, $rate, 20), $amount, 20) < 0) {
            $unit = self::numeric($precision === 0 ? '1' : '0.'.str_repeat('0', $precision - 1).'1');
            $candidate = bcadd($candidate, $unit, $precision);
        }

        return bcadd($candidate, '0', 6);
    }

    /** @return numeric-string */
    public static function divergenceBps(string $left, string $right): string
    {
        $a = self::rate($left);
        $b = self::rate($right);
        $smaller = bccomp($a, $b, 8) <= 0 ? $a : $b;
        $difference = bccomp($a, $b, 8) >= 0 ? bcsub($a, $b, 8) : bcsub($b, $a, 8);

        return bcdiv(bcmul($difference, '10000', 8), $smaller, 4);
    }

    /** @return numeric-string */
    private static function numeric(string $value): string
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('USDT numeric value is invalid.');
        }

        return $value;
    }
}
