<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application\Contracts;

use App\Modules\Wallet\Application\PurchaseWalletRefundCandidate;
use App\Modules\Wallet\Application\PurchaseWalletRefundExecutionReceipt;

interface PurchaseWalletRefundAuthority
{
    /** @return list<PurchaseWalletRefundCandidate> */
    public function refundablePurchases(int $customerUserId, int $limit): array;

    public function refund(
        string $refundKey,
        int $customerUserId,
        string $purchaseSettlementPublicId,
        int $amountIrr,
        string $correlationId,
    ): PurchaseWalletRefundExecutionReceipt;
}
