<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\Money;
use InvalidArgumentException;

final class IrrTomanFormatter
{
    /** @requirement DAT-002 LOC-002 */
    public static function format(int $amountIrr): string
    {
        return self::formatTomanDecimal(Money::irr($amountIrr)->tomanDecimal());
    }

    /** @requirement DAT-002 LOC-002 */
    public static function formatDecimalIrr(string $amountIrr): string
    {
        if (preg_match('/\A(-?)([0-9]+)(?:\.([0-9]+))?\z/', $amountIrr, $matches) !== 1) {
            throw new InvalidArgumentException('IRR decimal presentation value is invalid.');
        }

        $wholeIrr = $matches[2];
        $fractionIrr = $matches[3] ?? '';
        $lastWholeDigit = substr($wholeIrr, -1);
        $wholeToman = substr($wholeIrr, 0, -1);
        $wholeToman = ltrim($wholeToman === '' ? '0' : $wholeToman, '0');
        if ($wholeToman === '') {
            $wholeToman = '0';
        }

        $fractionToman = rtrim($lastWholeDigit.$fractionIrr, '0');
        $isZero = trim($wholeIrr, '0') === '' && trim($fractionIrr, '0') === '';
        $sign = $matches[1] === '-' && ! $isZero ? '-' : '';

        return $sign.self::groupDigits($wholeToman)
            .($fractionToman === '' ? '' : '.'.$fractionToman);
    }

    public static function unit(string $locale): string
    {
        return match ($locale) {
            'fa' => 'تومان',
            'en' => 'Toman',
            default => throw new InvalidArgumentException('Fiat presentation locale is unsupported.'),
        };
    }

    /** @return array{amount:string,currency:string} */
    public static function forCurrency(int $amount, string $currency, string $locale): array
    {
        if ($currency === 'IRR') {
            return [
                'amount' => self::format($amount),
                'currency' => self::unit($locale),
            ];
        }

        return [
            'amount' => number_format($amount, 0, '.', ','),
            'currency' => $currency,
        ];
    }

    private static function formatTomanDecimal(string $amountToman): string
    {
        if (preg_match('/\A(-?)([0-9]+)(?:\.([0-9]+))?\z/', $amountToman, $matches) !== 1) {
            throw new InvalidArgumentException('Toman presentation value is invalid.');
        }

        $whole = ltrim($matches[2], '0');
        if ($whole === '') {
            $whole = '0';
        }
        $fraction = rtrim($matches[3] ?? '', '0');
        $isZero = $whole === '0' && $fraction === '';
        $sign = $matches[1] === '-' && ! $isZero ? '-' : '';

        return $sign.self::groupDigits($whole)
            .($fraction === '' ? '' : '.'.$fraction);
    }

    private static function groupDigits(string $digits): string
    {
        $grouped = preg_replace('/\B(?=(?:[0-9]{3})+(?![0-9]))/', ',', $digits);
        if (! is_string($grouped)) {
            throw new InvalidArgumentException('Fiat presentation grouping failed.');
        }

        return $grouped;
    }
}
