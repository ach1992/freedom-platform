<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

final readonly class WalletCashAccountReceipt
{
    public function __construct(
        public int $accountId,
        public int $ledgerBalanceIrr,
        public int $activeHoldsIrr,
        public int $availableBalanceIrr,
    ) {}
}
