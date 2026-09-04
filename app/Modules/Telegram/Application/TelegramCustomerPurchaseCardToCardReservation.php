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
        public int $baseAmountIrr,
        public int $adjustmentAmountIrr,
        public int $payableAmountIrr,
        public string $maskedCardNumber,
        public DateTimeImmutable $expiresAt,
        public bool $replayed,
    ) {
        foreach ([$paymentIntentPublicId, $reservationPublicId, $orderPublicId, $quotePublicId, $decisionPublicId] as $publicId) {
            if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $publicId) !== 1) {
                throw new InvalidArgumentException('Telegram card-to-card public identity is invalid.');
            }
        }
        if ($baseAmountIrr < 1
            || $adjustmentAmountIrr < 0
            || $payableAmountIrr !== $baseAmountIrr + $adjustmentAmountIrr
            || $maskedCardNumber === ''
            || mb_strlen($maskedCardNumber) > 32) {
            throw new InvalidArgumentException('Telegram card-to-card reservation identity is invalid.');
        }
    }
}
