<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\PaymentIntentState;
use App\Shared\Domain\Money;
use DateTimeImmutable;

final readonly class PurchaseRefundReceipt
{
    public function __construct(
        public int $refundId,
        public string $publicId,
        public string $refundKey,
        public string $purchaseSettlementPublicId,
        public string $paymentIntentPublicId,
        public string $providerRefundId,
        public Money $amount,
        public Money $cumulativeRefunded,
        public PaymentIntentState $state,
        public DateTimeImmutable $refundedAt,
        public bool $replayed,
    ) {}
}
