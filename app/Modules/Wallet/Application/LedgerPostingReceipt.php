<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\Wallet\Domain\IrrMoney;

final readonly class LedgerPostingReceipt
{
    public function __construct(
        public int $transactionId,
        public IrrMoney $total,
        public int $entryCount,
        public bool $replayed,
    ) {}
}
