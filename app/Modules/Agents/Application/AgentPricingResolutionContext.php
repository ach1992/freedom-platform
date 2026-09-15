<?php

declare(strict_types=1);

namespace App\Modules\Agents\Application;

use InvalidArgumentException;

final readonly class AgentPricingResolutionContext
{
    public function __construct(public int $actorUserId)
    {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('Agent pricing resolution actor user ID must be positive.');
        }
    }
}
