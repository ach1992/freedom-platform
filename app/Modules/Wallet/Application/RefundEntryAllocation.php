<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\Wallet\Domain\IrrMoney;
use InvalidArgumentException;

final readonly class RefundEntryAllocation
{
    public function __construct(
        public int $sourceLedgerEntryId,
        public IrrMoney $amount,
    ) {
        if ($sourceLedgerEntryId < 1) {
            throw new InvalidArgumentException('Refund source ledger entry ID must be positive.');
        }

        if ($amount->isZero()) {
            throw new InvalidArgumentException('Refund entry allocation must be positive.');
        }
    }
}
