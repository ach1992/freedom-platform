<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\PaymentIntentState;
use App\Shared\Domain\Money;

final readonly class PurchasePaymentIntentReceipt
{
    public function __construct(
        public string $intentPublicId,
        public PaymentIntentState $state,
        public int $userId,
        public string $sourceQuotePublicId,
        public string $eligibilityDecisionPublicId,
        public string $methodCode,
        public int $methodVersion,
        public Money $amount,
        public bool $replayed = false,
    ) {}
}
