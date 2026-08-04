<?php

declare(strict_types=1);

namespace Tests\Unit\Modules;

use App\Modules\AccessControl\Domain\PermissionEffect;
use App\Modules\AccessControl\Domain\PermissionResolver;
use App\Modules\AccessControl\Domain\SensitiveApprovalState;
use App\Modules\Agents\Domain\AgentApplicationState;
use App\Modules\Agents\Domain\AgentStatus;
use App\Modules\Customers\Domain\CustomerTierCode;
use App\Modules\Identity\Domain\AccountStatus;
use App\Modules\Identity\Domain\AccountType;
use App\Modules\Identity\Domain\VerificationStatus;
use PHPUnit\Framework\TestCase;

/** @requirement ONB-001 ONB-004 ONB-005 USR-001 USR-002 AGT-001 AGT-002 ACL-001 ACL-002 ACL-003 SEC-002 */
final class IdentityAccessDomainTest extends TestCase
{
    public function test_authoritative_account_and_tier_values_are_stable(): void
    {
        $this->assertSame(['customer', 'agent'], $this->values(AccountType::cases()));
        $this->assertSame(['active', 'limited', 'suspended', 'blocked'], $this->values(AccountStatus::cases()));
        $this->assertSame(['unverified', 'pending', 'verified', 'rejected'], $this->values(VerificationStatus::cases()));
        $this->assertSame(['new', 'normal', 'loyal', 'vip'], $this->values(CustomerTierCode::cases()));
        $this->assertSame(['active', 'suspended'], $this->values(AgentStatus::cases()));
    }

    public function test_agent_application_lifecycle_supports_claim_release_decision_and_reapplication(): void
    {
        $this->assertTrue(AgentApplicationState::Submitted->canTransitionTo(AgentApplicationState::Claimed));
        $this->assertTrue(AgentApplicationState::Claimed->canTransitionTo(AgentApplicationState::Submitted));
        $this->assertTrue(AgentApplicationState::Claimed->canTransitionTo(AgentApplicationState::Approved));
        $this->assertTrue(AgentApplicationState::Claimed->canTransitionTo(AgentApplicationState::Rejected));
        $this->assertTrue(AgentApplicationState::Rejected->canTransitionTo(AgentApplicationState::Submitted));
        $this->assertFalse(AgentApplicationState::Approved->canTransitionTo(AgentApplicationState::Rejected));
        $this->assertFalse(AgentApplicationState::Withdrawn->canTransitionTo(AgentApplicationState::Submitted));
        $this->assertTrue(AgentApplicationState::Submitted->keepsActiveApplicationSlot());
        $this->assertTrue(AgentApplicationState::Claimed->keepsActiveApplicationSlot());
        $this->assertFalse(AgentApplicationState::Rejected->keepsActiveApplicationSlot());
    }

    public function test_permission_resolution_enforces_explicit_deny_precedence(): void
    {
        $resolver = new PermissionResolver;

        $this->assertTrue($resolver->allows(true, []));
        $this->assertFalse($resolver->allows(false, []));
        $this->assertTrue($resolver->allows(false, [PermissionEffect::Inherit, PermissionEffect::Allow]));
        $this->assertFalse($resolver->allows(true, [PermissionEffect::Allow, PermissionEffect::Deny]));
        $this->assertFalse($resolver->allows(false, [PermissionEffect::Deny, PermissionEffect::Allow]));
    }

    public function test_sensitive_approval_is_single_decision_from_pending(): void
    {
        foreach ([
            SensitiveApprovalState::Approved,
            SensitiveApprovalState::Rejected,
            SensitiveApprovalState::Expired,
            SensitiveApprovalState::Cancelled,
        ] as $terminal) {
            $this->assertTrue(SensitiveApprovalState::Pending->canTransitionTo($terminal));
            $this->assertFalse($terminal->canTransitionTo(SensitiveApprovalState::Approved));
        }
    }

    /**
     * @template T of \BackedEnum
     * @param  list<T>  $cases
     * @return list<int|string>
     */
    private function values(array $cases): array
    {
        return array_map(static fn (\BackedEnum $case): int|string => $case->value, $cases);
    }
}
