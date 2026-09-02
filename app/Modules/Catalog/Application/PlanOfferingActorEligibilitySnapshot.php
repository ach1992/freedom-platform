<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use InvalidArgumentException;

final readonly class PlanOfferingActorEligibilitySnapshot
{
    /** @param list<int> $tagIds */
    public function __construct(
        public ?string $tierCode,
        public array $tagIds,
    ) {
        foreach ($tagIds as $tagId) {
            if (! is_int($tagId) || $tagId < 1) {
                throw new InvalidArgumentException('Plan Offering actor eligibility tag is invalid.');
            }
        }
    }
}
