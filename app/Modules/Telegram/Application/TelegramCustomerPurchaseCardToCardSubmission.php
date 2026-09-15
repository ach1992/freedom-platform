<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class TelegramCustomerPurchaseCardToCardSubmission
{
    public function __construct(
        public string $submissionPublicId,
        public string $paymentIntentPublicId,
        public string $reservationPublicId,
        public int $claimedAmountIrr,
        public bool $replayed,
    ) {
        if (! Str::isUlid($submissionPublicId)
            || ! Str::isUlid($paymentIntentPublicId)
            || ! Str::isUlid($reservationPublicId)
            || $claimedAmountIrr < 1) {
            throw new InvalidArgumentException('Telegram card-to-card submission result is invalid.');
        }
    }
}
