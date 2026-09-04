<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class TelegramCustomerPurchaseCardToCardReservation
{
    public function __construct(
        public string $paymentIntentPublicId,
        public string $reservationPublicId,
        public string $orderPublicId,
        public string $quotePublicId,
        public string $decisionPublicId,
        public int $payableAmountIrr,
        public string $maskedCardNumber,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $lateReviewUntil,
        public bool $replayed,
    ) {
        foreach ([$paymentIntentPublicId, $reservationPublicId, $orderPublicId, $quotePublicId, $decisionPublicId] as $publicId) {
            if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $publicId) !== 1) {
                throw new InvalidArgumentException('Telegram card-to-card reservation public identity is invalid.');
            }
        }
        if ($payableAmountIrr < 1 || trim($maskedCardNumber) === '' || mb_strlen($maskedCardNumber) > 32) {
            throw new InvalidArgumentException('Telegram card-to-card reservation payment details are invalid.');
        }
        if ($lateReviewUntil < $expiresAt) {
            throw new InvalidArgumentException('Telegram card-to-card reservation review window is invalid.');
        }
    }
}
