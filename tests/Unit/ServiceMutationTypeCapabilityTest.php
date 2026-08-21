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
}
