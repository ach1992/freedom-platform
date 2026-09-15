<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

use DateTimeImmutable;

final readonly class ReferralRewardAccrualReceipt
{
    /** @param list<string> $rewardPublicIds */
    public function __construct(
        public int $accrualId,
        public string $publicId,
        public string $purchaseSettlementPublicId,
        public int $rewardAmountIrr,
        public DateTimeImmutable $releaseAt,
        public ?DateTimeImmutable $expiresAt,
        public array $rewardPublicIds,
        public bool $replayed,
    ) {}
}
