<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Identity;

use App\Modules\Identity\Domain\IdentityItemType;
use App\Modules\Identity\Domain\IdentityItemValue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdentityItemValueTest extends TestCase
{
    public function test_national_id_accepts_persian_digits_and_masks_the_value(): void
    {
        $ascii = $this->nationalId('123456789');
        $persian = strtr($ascii, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);

        $value = IdentityItemValue::fromInput(IdentityItemType::NationalId, $persian);

        self::assertSame($ascii, $value->canonical);
        self::assertSame('******'.substr($ascii, -4), $value->masked);
    }

    public function test_bank_card_normalizes_separators_and_masks_middle_digits(): void
    {
        $card = $this->bankCard('603799751547777');
        $formatted = substr($card, 0, 4).'-'.substr($card, 4, 4).'-'.substr($card, 8, 4).'-'.substr($card, 12);

        $value = IdentityItemValue::fromInput(IdentityItemType::BankCard, $formatted);

        self::assertSame($card, $value->canonical);
        self::assertSame(substr($card, 0, 4).'-****-****-'.substr($card, -4), $value->masked);
    }

    public function test_full_name_collapses_spacing_and_never_exposes_the_full_value_in_mask(): void
    {
        $value = IdentityItemValue::fromInput(IdentityItemType::FullName, '  علی   رضایی  ');

        self::assertSame('علی رضایی', $value->canonical);
        self::assertSame('ع***ی', $value->masked);
        self::assertStringNotContainsString($value->canonical, $value->masked);
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_identity_values_are_rejected(IdentityItemType $type, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        IdentityItemValue::fromInput($type, $value);
    }

    /** @return iterable<string, array{IdentityItemType, string}> */
    public static function invalidValues(): iterable
    {
        yield 'national ID checksum' => [IdentityItemType::NationalId, '1234567890'];
        yield 'repeated national ID' => [IdentityItemType::NationalId, '1111111111'];
        yield 'bank card checksum' => [IdentityItemType::BankCard, '6037997515477777'];
        yield 'full name digits' => [IdentityItemType::FullName, 'علی 123'];
        yield 'full name control character' => [IdentityItemType::FullName, "علی\x00رضایی"];
    }

    private function nationalId(string $firstNineDigits): string
    {
        $sum = 0;
        for ($index = 0; $index < 9; $index++) {
            $sum += (int) $firstNineDigits[$index] * (10 - $index);
        }
        $remainder = $sum % 11;

        return $firstNineDigits.($remainder < 2 ? $remainder : 11 - $remainder);
    }

    private function bankCard(string $firstFifteenDigits): string
    {
        for ($check = 0; $check <= 9; $check++) {
            $candidate = $firstFifteenDigits.$check;
            $sum = 0;
            for ($index = 0; $index < 16; $index++) {
                $product = (int) $candidate[$index] * ($index % 2 === 0 ? 2 : 1);
                $sum += $product > 9 ? $product - 9 : $product;
            }
            if ($sum % 10 === 0) {
                return $candidate;
            }
        }

        self::fail('Could not generate a valid bank card number.');
    }
}
