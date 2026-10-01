<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Application;

use App\Shared\Application\IrrTomanFormatter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class IrrTomanFormatterTest extends TestCase
{
    /** @requirement DAT-002 LOC-002 */
    public function test_integer_irr_is_converted_to_exact_grouped_toman_without_float(): void
    {
        self::assertSame('125', IrrTomanFormatter::format(1_250));
        self::assertSame('125.1', IrrTomanFormatter::format(1_251));
        self::assertSame('12,345,678.9', IrrTomanFormatter::format(123_456_789));
        self::assertSame('-125.1', IrrTomanFormatter::format(-1_251));
        self::assertSame('0', IrrTomanFormatter::format(0));
    }

    /** @requirement DAT-002 LOC-002 */
    public function test_decimal_irr_rate_is_divided_by_ten_exactly_without_binary_float(): void
    {
        self::assertSame('90,000', IrrTomanFormatter::formatDecimalIrr('900000.00000000'));
        self::assertSame('125.15', IrrTomanFormatter::formatDecimalIrr('1251.50000000'));
        self::assertSame('0.05', IrrTomanFormatter::formatDecimalIrr('0.5'));
        self::assertSame('-0.1', IrrTomanFormatter::formatDecimalIrr('-1.0'));
    }

    public function test_irr_currency_is_presented_as_toman_while_other_currency_is_preserved(): void
    {
        self::assertSame(
            ['amount' => '125.1', 'currency' => 'تومان'],
            IrrTomanFormatter::forCurrency(1_251, 'IRR', 'fa'),
        );
        self::assertSame(
            ['amount' => '1,251', 'currency' => 'USD'],
            IrrTomanFormatter::forCurrency(1_251, 'USD', 'en'),
        );
    }

    public function test_unsupported_locale_and_invalid_decimal_fail_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IrrTomanFormatter::unit('de');
    }
}
