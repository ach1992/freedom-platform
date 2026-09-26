<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Provisioning\Domain\ServiceMutationType;
use LogicException;
use Tests\TestCase;

final class ServiceMutationTypeCapabilityTest extends TestCase
{
    public function test_combined_mutation_exposes_all_required_capabilities_and_rejects_single_capability_access(): void
    {
        self::assertSame(
            ['update_expiry', 'add_data_allowance', 'atomic_service_entitlements'],
            ServiceMutationType::AddDataDays->panelCapabilities(),
        );

        $this->expectException(LogicException::class);
        ServiceMutationType::AddDataDays->panelCapability();
    }

    public function test_administrative_entitlement_grants_reuse_entitlement_capabilities_without_becoming_paid_mutations(): void
    {
        self::assertSame('add_data_allowance', ServiceMutationType::GrantData->panelCapability());
        self::assertSame('update_expiry', ServiceMutationType::GrantDays->panelCapability());
        self::assertSame(
            ['update_expiry', 'add_data_allowance', 'atomic_service_entitlements'],
            ServiceMutationType::GrantDataDays->panelCapabilities(),
        );
        self::assertTrue(ServiceMutationType::GrantData->isAdministrativeEntitlementGrant());
        self::assertTrue(ServiceMutationType::GrantDays->isAdministrativeEntitlementGrant());
        self::assertTrue(ServiceMutationType::GrantDataDays->isAdministrativeEntitlementGrant());
        self::assertTrue(ServiceMutationType::GrantDataDays->isEntitlementMutation());
        self::assertFalse(ServiceMutationType::GrantDataDays->isPaidEntitlement());
        self::assertFalse(ServiceMutationType::GrantDataDays->isPaidCommercialMutation());
    }
}
