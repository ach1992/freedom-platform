<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

final readonly class ReferralSelfSummary
{
    public function __construct(
        public string $referralToken,
        public bool $hasInviter,
        public bool $locked,
        public ?string $boundAt,
        public ?string $lockedAt,
    ) {}
}
