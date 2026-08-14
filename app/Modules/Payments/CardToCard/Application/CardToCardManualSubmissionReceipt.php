<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application;

final readonly class CardToCardManualSubmissionReceipt
{
    public function __construct(
        public int $submissionId,
        public string $publicId,
        public string $paymentIntentPublicId,
        public string $reservationPublicId,
        public int $claimedAmountIrr,
        public \DateTimeImmutable $claimedPaidAt,
        public bool $replayed,
    ) {}
}
