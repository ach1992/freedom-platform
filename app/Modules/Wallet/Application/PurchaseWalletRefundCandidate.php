<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use DateTimeImmutable;
use DomainException;

final readonly class PurchaseWalletRefundCandidate
{
    public function __construct(
        public string $purchaseSettlementPublicId,
        public int $remainingIrr,
        public DateTimeImmutable $settledAt,
    ) {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $purchaseSettlementPublicId) !== 1
            || $remainingIrr < 1) {
            throw new DomainException('Refundable wallet purchase candidate is invalid.');
        }
    }
}
