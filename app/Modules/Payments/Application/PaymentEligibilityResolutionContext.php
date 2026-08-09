<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use InvalidArgumentException;

final readonly class PaymentEligibilityResolutionContext
{
    public function __construct(public int $actorUserId)
    {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('Payment eligibility actor user ID must be positive.');
        }
    }
}
