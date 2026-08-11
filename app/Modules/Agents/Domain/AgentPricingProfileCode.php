<?php

declare(strict_types=1);

namespace App\Modules\Agents\Domain;

use InvalidArgumentException;

final readonly class AgentPricingProfileCode
{
    public string $value;

    public function __construct(string $value)
    {
        $normalized = strtolower(trim($value));
        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $normalized) !== 1) {
            throw new InvalidArgumentException('Agent pricing profile code is invalid.');
        }

        $this->value = $normalized;
    }
}
