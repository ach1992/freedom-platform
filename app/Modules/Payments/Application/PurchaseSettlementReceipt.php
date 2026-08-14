<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\PaymentIntentState;
use App\Shared\Domain\Money;
use DateTimeImmutable;

final readonly class PurchaseSettlementReceipt
{
    public function __construct(
        public int $settlementId,
        public string $settlementPublicId,
        public string $intentPublicId,
        public PaymentIntentState $state,
        public int $userId,
        public string $sourceQuotePublicId,
        public string $providerCode,
        public string $providerEventId,
        public string $providerTransactionId,
        public Money $amount,
        public DateTimeImmutable $settledAt,
        public bool $replayed = false,
    ) {}
}
