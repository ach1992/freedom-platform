<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

use InvalidArgumentException;

final readonly class PromotionResolutionContext
{
    public function __construct(public int $actorUserId)
    {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('Promotion resolution actor user ID must be positive.');
        }
    }
}
