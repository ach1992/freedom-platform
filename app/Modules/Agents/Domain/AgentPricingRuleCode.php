<?php

declare(strict_types=1);

namespace App\Modules\Agents\Domain;

use InvalidArgumentException;

final readonly class AgentPricingRuleCode
{
    public string $value;

    public function __construct(string $value)
    {
        $normalized = strtolower(trim($value));
        if (preg_match('/\A[a-z0-9_.:-]{1,128}\z/', $normalized) !== 1) {
            throw new InvalidArgumentException('Agent pricing rule code is invalid.');
        }

        $this->value = $normalized;
    }
}
