<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Shared\Application\RestrictedData;
use App\Shared\Application\RestrictedValue;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class TelegramCustomerPurchaseCardToCardDestination implements RestrictedData
{
    public function __construct(
        public string $reservationPublicId,
        public RestrictedValue $cardNumber,
        public string $maskedCardNumber,
        public int $payableAmountIrr,
        public DateTimeImmutable $expiresAt,
    ) {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $reservationPublicId) !== 1
            || $maskedCardNumber === ''
            || mb_strlen($maskedCardNumber) > 32
            || $payableAmountIrr < 1) {
            throw new InvalidArgumentException('Telegram card-to-card destination identity is invalid.');
        }
    }
}
