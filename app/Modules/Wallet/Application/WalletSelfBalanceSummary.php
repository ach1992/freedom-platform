<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

final readonly class WalletSelfBalanceSummary
{
    public function __construct(
        public bool $cashAccountExists,
        public int $cashLedgerBalanceIrr,
        public int $cashActiveHoldsIrr,
        public int $cashAvailableBalanceIrr,
        public bool $promotionalAccountExists,
        public int $promotionalLedgerBalanceIrr,
        public int $promotionalActiveHoldsIrr,
        public int $promotionalAvailableBalanceIrr,
    ) {}
}
