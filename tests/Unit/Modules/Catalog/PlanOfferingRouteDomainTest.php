<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Catalog;

use App\Modules\Catalog\Domain\PlanOfferingRouteDefinition;
use App\Modules\Catalog\Domain\PlanOfferingRoutePolicyDefinition;
use App\Modules\Catalog\Domain\PlanOfferingRouteType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** @requirement CAT-002 CAT-008 QUA-001 */
final class PlanOfferingRouteDomainTest extends TestCase
{
    public function test_policy_requires_one_primary_contiguous_unique_targets_and_persian_fallback_disclosure(): void
    {
        $policy = new PlanOfferingRoutePolicyDefinition([
            new PlanOfferingRouteDefinition(10, 20, PlanOfferingRouteType::Primary, 0, true, null, null),
            new PlanOfferingRouteDefinition(11, 21, PlanOfferingRouteType::Fallback, 1, false, "\u{062C}\u{0627}\u{06CC}\u{06AF}\u{0632}\u{06CC}\u{0646}", 'Fallback'),
        ]);
        self::assertCount(2, $policy->routes);
        self::assertSame(20, $policy->routes[0]->serviceTargetId);
        self::assertSame(21, $policy->routes[1]->serviceTargetId);

        $this->expectException(InvalidArgumentException::class);
        new PlanOfferingRoutePolicyDefinition([
            new PlanOfferingRouteDefinition(10, 20, PlanOfferingRouteType::Primary, 0, true, null, null),
            new PlanOfferingRouteDefinition(11, 21, PlanOfferingRouteType::Fallback, 2, false, "\u{062C}\u{0627}\u{06CC}\u{06AF}\u{0632}\u{06CC}\u{0646}", null),
        ]);
    }

    public function test_fallback_requires_persian_disclosure(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PlanOfferingRouteDefinition(11, 21, PlanOfferingRouteType::Fallback, 1, false, null, 'Fallback');
    }
}
