<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Panels;

use App\Modules\Panels\Application\CapacityInput;
use App\Modules\Panels\Domain\CapacityReservationState;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** @requirement CAT-008 SEC-002 QUA-001 */
final class TargetCapacityDomainTest extends TestCase
{
    public function test_capacity_input_and_lifecycle_are_fail_closed(): void
    {
        self::assertSame(10, CapacityInput::hardLimit(10));
        self::assertSame('route.selection-0000000001', CapacityInput::commandKey('route.selection-0000000001'));
        CapacityReservationState::Held->assertCanTransitionTo(CapacityReservationState::Committed);
        CapacityReservationState::Committed->assertCanTransitionTo(CapacityReservationState::Released);

        try {
            CapacityReservationState::Released->assertCanTransitionTo(CapacityReservationState::Committed);
            self::fail('Expected terminal reservation transition rejection.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        CapacityInput::hardLimit(0);
    }
}
