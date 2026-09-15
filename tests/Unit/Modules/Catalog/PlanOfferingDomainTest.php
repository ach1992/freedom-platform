<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Catalog;

use App\Modules\Catalog\Domain\OfferingOperationCode;
use App\Modules\Catalog\Domain\OfferingOperationPolicy;
use App\Modules\Catalog\Domain\OfferingPackageDefinition;
use App\Modules\Catalog\Domain\OfferingPackageType;
use App\Modules\Catalog\Domain\OfferingProtocolAssignment;
use App\Modules\Catalog\Domain\PlanOfferingAudience;
use App\Modules\Catalog\Domain\PlanOfferingDefinition;
use App\Modules\Catalog\Domain\PlanOfferingProtocolSelectionMode;
use App\Modules\Catalog\Domain\PlanOfferingServerSelectionMode;
use App\Modules\Catalog\Domain\PlanOfferingServiceMode;
use App\Modules\Catalog\Domain\PlanOfferingTagMatchMode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** @requirement CAT-002 CAT-003 CAT-004 QUA-001 */
final class PlanOfferingDomainTest extends TestCase
{
    public function test_definition_normalizes_typed_policies_and_unlimited_limits(): void
    {
        $definition = $this->definition();

        self::assertSame('premium-tehran', $definition->code);
        self::assertSame('shared', $definition->serviceMode->code);
        self::assertNull($definition->dataAllowanceBytes);
        self::assertNull($definition->deviceLimit);
        self::assertSame(['loyal', 'vip'], $definition->tierCodes);
        self::assertSame([3, 9], $definition->tagIds);
        self::assertSame(['create_service', 'fetch_status'], $definition->requiredCapabilities);
        self::assertSame(1, count(array_filter($definition->protocols, static fn (OfferingProtocolAssignment $profile): bool => $profile->default)));
    }

    public function test_fixed_protocol_policy_requires_exactly_one_non_selectable_profile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PlanOfferingDefinition(
            'invalid-fixed',
            1,
            null,
            2,
            3,
            new PlanOfferingServiceMode('shared', 'اشتراکی', 'Shared'),
            PlanOfferingAudience::Customers,
            PlanOfferingServerSelectionMode::Customer,
            PlanOfferingProtocolSelectionMode::Fixed,
            PlanOfferingTagMatchMode::All,
            1000,
            30,
            null,
            null,
            0,
            1,
            1,
            true,
            false,
            false,
            false,
            [],
            [],
            [
                new OfferingProtocolAssignment(10, false, true),
                new OfferingProtocolAssignment(11, false, false),
            ],
            [],
            [],
            [],
        );
    }

    public function test_package_shape_and_integer_irr_bounds_fail_closed(): void
    {
        try {
            new OfferingPackageDefinition(
                'bad-data',
                OfferingPackageType::AddData,
                'حجم',
                null,
                1000,
                10,
                1024,
                true,
                0,
            );
            self::fail('Expected package shape rejection.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        new OfferingOperationPolicy(
            OfferingOperationCode::Renew,
            true,
            false,
            -1,
            true,
            'create_service',
        );
    }

    private function definition(): PlanOfferingDefinition
    {
        return new PlanOfferingDefinition(
            'premium-tehran',
            1,
            null,
            2,
            3,
            new PlanOfferingServiceMode('shared', 'اشتراکی', 'Shared'),
            PlanOfferingAudience::Both,
            PlanOfferingServerSelectionMode::Hybrid,
            PlanOfferingProtocolSelectionMode::Customer,
            PlanOfferingTagMatchMode::All,
            2_500_000,
            30,
            null,
            null,
            10,
            1,
            3,
            true,
            true,
            false,
            false,
            ['vip', 'loyal', 'vip'],
            [9, 3, 9],
            [
                new OfferingProtocolAssignment(7, true, true),
                new OfferingProtocolAssignment(8, true, false),
            ],
            ['fetch_status', 'create_service', 'fetch_status'],
            [
                new OfferingOperationPolicy(
                    OfferingOperationCode::Renew,
                    true,
                    true,
                    0,
                    true,
                    'create_service',
                ),
            ],
            [
                new OfferingPackageDefinition(
                    'extra-10gb',
                    OfferingPackageType::AddData,
                    'ده گیگابایت',
                    '10 GB',
                    500_000,
                    null,
                    10 * 1024 * 1024 * 1024,
                    true,
                    10,
                ),
            ],
        );
    }
}
