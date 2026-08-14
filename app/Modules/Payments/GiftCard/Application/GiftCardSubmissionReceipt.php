<?php

declare(strict_types=1);

namespace App\Modules\Payments\GiftCard\Application;

final readonly class GiftCardSubmissionReceipt
{
    public function __construct(
        public int $submissionId,
        public string $publicId,
        public string $paymentIntentPublicId,
        public string $typeCode,
        public string $maskedCode,
        public int $claimedFaceValue,
        public string $claimedCurrency,
        public string $state,
        public bool $replayed,
    ) {}
}