<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use DomainException;

final readonly class LedgerEntryDraft
{
    public function __construct(
        public int $accountId,
        public LedgerDirection $direction,
        public IrrMoney $amount,
    ) {
        if ($accountId < 1) {
            throw new DomainException('Ledger account ID must be positive.');
        }
        if ($amount->isZero()) {
            throw new DomainException('Ledger entry amount must be positive.');
        }
    }
}
