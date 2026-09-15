<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\PaymentIntentState;
use App\Shared\Domain\Money;

final readonly class WalletTopUpSettlementReceipt
{
    public function __construct(
        public int $settlementId,
        public string $intentPublicId,
        public PaymentIntentState $state,
        public int $walletAccountId,
        public string $providerCode,
        public string $providerEventId,
        public string $providerTransactionId,
        public Money $amount,
        public int $ledgerTransactionId,
        public bool $replayed = false,
    ) {}
}
