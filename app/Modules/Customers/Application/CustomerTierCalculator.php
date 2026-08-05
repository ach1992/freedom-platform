<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use RuntimeException;

final readonly class CustomerTierCalculator
{
    /**
     * @param list<CustomerTierPolicy> $policies
     */
    public function determine(array $policies, CustomerTierMetrics $metrics): CustomerTierPolicy
    {
        $eligible = array_values(array_filter(
            $policies,
            static fn (CustomerTierPolicy $policy): bool => $policy->accepts($metrics),
        ));

        if ($eligible === []) {
            throw new RuntimeException('No automatic customer tier policy matched the supplied metrics.');
        }

        usort(
            $eligible,
            static fn (CustomerTierPolicy $left, CustomerTierPolicy $right): int => $right->sortOrder <=> $left->sortOrder,
        );

        return $eligible[0];
    }
}
