<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Domain;

use App\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /** @requirement DAT-002 QUA-003 */
    public function test_it_adds_and_subtracts_integer_irr_without_float(): void
    {
        $result = Money::irr(1250)->add(Money::irr(750))->subtract(Money::irr(500));

        self::assertSame(1500, $result->amount());
        self::assertSame('IRR', $result->currency());
    }

    public function test_it_converts_irr_to_an_explicit_toman_decimal(): void
    {
        self::assertSame('125', Money::irr(1250)->tomanDecimal());
        self::assertSame('125.1', Money::irr(1251)->tomanDecimal());
        self::assertSame('-125.1', Money::irr(-1251)->tomanDecimal());
    }
}
