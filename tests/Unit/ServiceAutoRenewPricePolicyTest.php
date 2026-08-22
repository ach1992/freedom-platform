<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Provisioning\Application\ServiceAutoRenewPricePolicy;
use App\Modules\Provisioning\Domain\AutoRenewPriceChangeMode;
use Tests\TestCase;

/** @requirement SVC-007 DAT-002 QUA-003 */
final class ServiceAutoRenewPricePolicyTest extends TestCase
{
    private ServiceAutoRenewPricePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ServiceAutoRenewPricePolicy;
    }

    public function test_stop_allows_unchanged_price_and_blocks_any_change(): void
    {
        self::assertTrue($this->policy->allows(600_000, 600_000, AutoRenewPriceChangeMode::Stop, null, null));
        self::assertFalse($this->policy->allows(600_000, 600_001, AutoRenewPriceChangeMode::Stop, null, null));
        self::assertFalse($this->policy->allows(600_000, 599_999, AutoRenewPriceChangeMode::Stop, null, null));
    }

    public function test_continue_accepts_both_increase_and_decrease(): void
    {
        self::assertTrue($this->policy->allows(600_000, 750_000, AutoRenewPriceChangeMode::Continue, null, null));
        self::assertTrue($this->policy->allows(600_000, 500_000, AutoRenewPriceChangeMode::Continue, null, null));
    }

    public function test_within_limit_requires_all_configured_bounds_and_accepts_decrease(): void
    {
        self::assertTrue($this->policy->allows(600_000, 500_000, AutoRenewPriceChangeMode::WithinLimit, 1, 1));
        self::assertTrue($this->policy->allows(600_000, 630_000, AutoRenewPriceChangeMode::WithinLimit, 30_000, 500));
        self::assertFalse($this->policy->allows(600_000, 630_001, AutoRenewPriceChangeMode::WithinLimit, 30_000, 10_000));
        self::assertFalse($this->policy->allows(600_000, 630_000, AutoRenewPriceChangeMode::WithinLimit, 100_000, 499));
    }

    public function test_within_limit_fails_closed_without_a_bound_and_handles_zero_baseline(): void
    {
        self::assertFalse($this->policy->allows(600_000, 600_001, AutoRenewPriceChangeMode::WithinLimit, null, null));
        self::assertFalse($this->policy->allows(0, 1, AutoRenewPriceChangeMode::WithinLimit, null, 10_000));
        self::assertTrue($this->policy->allows(0, 1, AutoRenewPriceChangeMode::WithinLimit, 1, null));
    }

    public function test_percentage_boundary_uses_integer_basis_points_without_float_rounding(): void
    {
        self::assertTrue($this->policy->allows(999_999, 1_049_998, AutoRenewPriceChangeMode::WithinLimit, null, 500));
        self::assertFalse($this->policy->allows(999_999, 1_049_999, AutoRenewPriceChangeMode::WithinLimit, null, 500));
    }
}
