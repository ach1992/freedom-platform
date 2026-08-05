<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Customers;

use App\Modules\Customers\Application\CustomerTierCalculator;
use App\Modules\Customers\Application\CustomerTierMetrics;
use App\Modules\Customers\Application\CustomerTierPolicy;
use App\Modules\Customers\Domain\CustomerTierCode;
use PHPUnit\Framework\TestCase;

/** @requirement USR-002 */
final class CustomerTierCalculatorTest extends TestCase
{
    public function test_it_selects_the_highest_eligible_automatic_tier(): void
    {
        $calculator = new CustomerTierCalculator;
        $selected = $calculator->determine($this->policies(), new CustomerTierMetrics(10, 90));

        self::assertSame(CustomerTierCode::Vip, $selected->code);
    }

    public function test_total_spend_threshold_is_ignored_until_explicitly_enabled(): void
    {
        $calculator = new CustomerTierCalculator;
        $policy = new CustomerTierPolicy(
            1,
            CustomerTierCode::Normal,
            20,
            true,
            1,
            0,
            false,
            9_000_000_000,
        );

        self::assertSame(
            CustomerTierCode::Normal,
            $calculator->determine([$policy], new CustomerTierMetrics(1, 0, 0))->code,
        );
    }

    /** @return list<CustomerTierPolicy> */
    private function policies(): array
    {
        return [
            new CustomerTierPolicy(1, CustomerTierCode::New, 10, true, 0, 0, false, 0),
            new CustomerTierPolicy(2, CustomerTierCode::Normal, 20, true, 1, 0, false, 0),
            new CustomerTierPolicy(3, CustomerTierCode::Loyal, 30, true, 3, 30, false, 0),
            new CustomerTierPolicy(4, CustomerTierCode::Vip, 40, true, 10, 90, false, 0),
        ];
    }
}
