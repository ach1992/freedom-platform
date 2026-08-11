<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\RefundDestination;

final readonly class WalletRefundReceipt
{
    public function __construct(
        public int $refundId,
        public int $sourceLedgerTransactionId,
        public RefundDestination $defaultDestination,
        public RefundDestination $destination,
        public IrrMoney $amount,
        public int $ledgerTransactionId,
        public bool $destinationOverridden,
        public bool $replayed,
    ) {}
}
