<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use DomainException;

final readonly class PurchaseWalletRefundExecutionReceipt
{
    public function __construct(
        public string $refundPublicId,
        public string $purchaseSettlementPublicId,
        public int $amountIrr,
        public int $cumulativeRefundedIrr,
        public string $resultingPaymentState,
        public bool $replayed,
    ) {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $refundPublicId) !== 1
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $purchaseSettlementPublicId) !== 1
            || $amountIrr < 1
            || $cumulativeRefundedIrr < $amountIrr
            || ! in_array($resultingPaymentState, ['partially_refunded', 'refunded'], true)) {
            throw new DomainException('Wallet purchase refund execution receipt is invalid.');
        }
    }
}
