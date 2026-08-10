<?php

declare(strict_types=1);

namespace App\Modules\Promotions\BenefitCodes\Application;

use InvalidArgumentException;

final readonly class BenefitCodeRedemptionContext
{
    public function __construct(public int $actorUserId)
    {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('Benefit code redemption actor user ID must be positive.');
        }
    }
}
