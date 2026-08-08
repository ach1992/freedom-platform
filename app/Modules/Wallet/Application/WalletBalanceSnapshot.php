<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\Wallet\Domain\IrrMoney;

final readonly class WalletBalanceSnapshot
{
    public function __construct(
        public IrrMoney $ledgerBalance,
        public IrrMoney $activeHolds,
        public IrrMoney $availableBalance,
    ) {}
}
