<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\RefundDestination;
use DomainException;

final readonly class LedgerRefundabilitySnapshot
{
    public function __construct(
        public IrrMoney $refundableTotal,
        public RefundDestination $defaultDestination,
    ) {
        if ($refundableTotal->isZero()) {
            throw new DomainException('Refundable ledger amount must be positive.');
        }
    }
}
