<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

use App\Modules\Promotions\Domain\ReferralRewardState;

final readonly class ReferralRewardLifecycleReceipt
{
    public function __construct(
        public int $rewardId,
        public string $rewardPublicId,
        public ReferralRewardState $state,
        public ?int $releaseLedgerTransactionId,
        public ?int $reversalLedgerTransactionId,
        public ?int $purchaseRefundId,
        public bool $changed,
        public bool $replayed,
    ) {}
}
