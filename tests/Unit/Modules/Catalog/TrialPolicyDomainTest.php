<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Catalog;

use App\Modules\Catalog\Domain\PlanOfferingTagMatchMode;
use App\Modules\Catalog\Domain\TrialPolicyDefinition;
use App\Modules\Catalog\Domain\TrialReservationState;
use App\Modules\Identity\Domain\PhoneVerificationPolicy;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** @requirement CAT-006 SEC-002 QUA-001 */
final class TrialPolicyDomainTest extends TestCase
{
    public function test_policy_normalizes_eligibility_and_snapshots_all_controls(): void
    {
        $policy = new TrialPolicyDefinition(
            true,
            1_073_741_824,
            2,
            25,
            PhoneVerificationPolicy::Either,
            true,
            true,
            true,
            true,
            true,
            PlanOfferingTagMatchMode::All,
            'service.trial.delivery',
            ['vip', 'normal', 'normal'],
            [12, 4, 12],
        );

        self::assertSame(['normal', 'vip'], $policy->eligibleTierCodes);
        self::assertSame([4, 12], $policy->eligibleTagIds);
        self::assertSame('either', $policy->payload()['phone_verification_policy']);
        self::assertTrue($policy->payload()['fallback_allowed']);
    }

    public function test_policy_rejects_phone_only_uniqueness_without_verified_phone_requirement(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Phone-only trial uniqueness requires verified-phone eligibility.');

        new TrialPolicyDefinition(
            true,
            1,
            1,
            1,
            PhoneVerificationPolicy::None,
            false,
            false,
            true,
            false,
            false,
            PlanOfferingTagMatchMode::Any,
            'service.trial.delivery',
            [],
            [],
        );
    }

    public function test_reservation_state_only_allows_one_terminal_transition(): void
    {
        TrialReservationState::Reserved->assertCanTransitionTo(TrialReservationState::Committed);
        TrialReservationState::Reserved->assertCanTransitionTo(TrialReservationState::Released);
        TrialReservationState::Reserved->assertCanTransitionTo(TrialReservationState::Expired);
        self::assertTrue(true);

        $this->expectException(DomainException::class);
        TrialReservationState::Committed->assertCanTransitionTo(TrialReservationState::Released);
    }
}
