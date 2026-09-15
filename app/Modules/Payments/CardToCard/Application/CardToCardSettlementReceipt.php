<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application;

final readonly class CardToCardSettlementReceipt
{
    public function __construct(
        public int $matchId,
        public string $matchPublicId,
        public string $bankTransactionPublicId,
        public string $paymentIntentPublicId,
        public int $purchaseSettlementId,
        public string $purchaseSettlementPublicId,
        public int $baseAmountIrr,
        public int $adjustmentAmountIrr,
        public int $payableAmountIrr,
        public bool $replayed = false,
    ) {}
}
