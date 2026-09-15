<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use InvalidArgumentException;

final readonly class IdentityItemValue
{
    private function __construct(
        public IdentityItemType $type,
        public string $canonical,
        public string $masked,
    ) {}

    public static function fromInput(IdentityItemType $type, string $value): self
    {
        return match ($type) {
            IdentityItemType::NationalId => self::nationalId($value),
            IdentityItemType::BankCard => self::bankCard($value),
            IdentityItemType::FullName => self::fullName($value),
        };
    }

    private static function nationalId(string $value): self
    {
        $digits = self::digits($value);
        if (strlen($digits) !== 10 || preg_match('/\A([0-9])\1{9}\z/', $digits) === 1) {
            throw new InvalidArgumentException('Iranian national ID is invalid.');
        }

        $sum = 0;
        for ($index = 0; $index < 9; $index++) {
            $sum += (int) $digits[$index] * (10 - $index);
        }
        $remainder = $sum % 11;
        $expected = $remainder < 2 ? $remainder : 11 - $remainder;
        if ((int) $digits[9] !== $expected) {
            throw new InvalidArgumentException('Iranian national ID is invalid.');
        }

        return new self(
            IdentityItemType::NationalId,
            $digits,
            '******'.substr($digits, -4),
        );
    }

    private static function bankCard(string $value): self
    {
        $digits = self::digits($value);
        if (strlen($digits) !== 16 || preg_match('/\A([0-9])\1{15}\z/', $digits) === 1) {
            throw new InvalidArgumentException('Bank card number is invalid.');
        }

        $sum = 0;
        for ($index = 0; $index < 16; $index++) {
            $product = (int) $digits[$index] * ($index % 2 === 0 ? 2 : 1);
            $sum += $product > 9 ? $product - 9 : $product;
        }
        if ($sum % 10 !== 0) {
            throw new InvalidArgumentException('Bank card number is invalid.');
        }

        return new self(
            IdentityItemType::BankCard,
            $digits,
            substr($digits, 0, 4).'-****-****-'.substr($digits, -4),
        );
    }

    private static function fullName(string $value): self
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value));
        if (! is_string($normalized)
            || mb_strlen($normalized) < 2
            || mb_strlen($normalized) > 191
            || preg_match("/\A[\p{L}\p{M}][\p{L}\p{M} '\u{2019}-]{1,190}\z/u", $normalized) !== 1) {
            throw new InvalidArgumentException('Full name is invalid.');
        }

        $first = mb_substr($normalized, 0, 1);
        $last = mb_substr($normalized, -1, 1);

        return new self(
            IdentityItemType::FullName,
            $normalized,
            $first.'***'.$last,
        );
    }

    private static function digits(string $value): string
    {
        $ascii = strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $digits = preg_replace('/[^0-9]/', '', $ascii);

        return is_string($digits) ? $digits : '';
    }
}
