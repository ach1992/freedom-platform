<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application;

use App\Modules\Payments\Application\PurchasePaymentIntentReceipt;

final readonly class CardToCardPaymentReceipt
{
    public function __construct(
        public PurchasePaymentIntentReceipt $paymentIntent,
        public int $reservationId,
        public string $reservationPublicId,
        public int $baseAmountIrr,
        public int $adjustmentAmountIrr,
        public int $payableAmountIrr,
        public string $destinationPublicId,
        public string $destinationCode,
        public string $maskedCardNumber,
        public \DateTimeImmutable $expiresAt,
        public \DateTimeImmutable $lateReviewUntil,
        public bool $replayed,
    ) {}
}
