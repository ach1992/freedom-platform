<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Wallet;

use App\Modules\Wallet\Domain\IrrMoney;
use DomainException;
use OverflowException;
use PHPUnit\Framework\TestCase;

/** @requirement DAT-002 QUA-001 */
final class IrrMoneyTest extends TestCase
{
    public function test_integer_irr_arithmetic_is_exact(): void
    {
        $left = IrrMoney::positive(1_234_567);
        $right = IrrMoney::positive(765_433);

        self::assertSame(2_000_000, $left->add($right)->amount);
        self::assertSame(469_134, $left->subtract(IrrMoney::positive(765_433))->amount);
        self::assertTrue(IrrMoney::zero()->isZero());
        self::assertTrue(IrrMoney::fromInt(50)->equals(IrrMoney::positive(50)));
    }

    public function test_negative_and_non_positive_amounts_fail_closed(): void
    {
        try {
            IrrMoney::fromInt(-1);
            self::fail('Expected negative amount rejection.');
        } catch (DomainException $exception) {
            self::assertSame('IRR amount cannot be negative.', $exception->getMessage());
        }

        try {
            IrrMoney::positive(0);
            self::fail('Expected non-positive amount rejection.');
        } catch (DomainException $exception) {
            self::assertSame('IRR amount must be positive.', $exception->getMessage());
        }
    }

    public function test_underflow_and_overflow_are_rejected(): void
    {
        $this->expectException(OverflowException::class);
        IrrMoney::fromInt(PHP_INT_MAX)->add(IrrMoney::positive(1));
    }

    public function test_subtraction_cannot_create_negative_money(): void
    {
        $this->expectException(DomainException::class);
        IrrMoney::positive(10)->subtract(IrrMoney::positive(11));
    }
}
