<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

use InvalidArgumentException;

final readonly class PromotionUsageContext
{
    public function __construct(public int $actorUserId)
    {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('Promotion usage actor user ID must be positive.');
        }
    }
}
