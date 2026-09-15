<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application;

final readonly class CardToCardDestinationReceipt
{
    public function __construct(
        public int $destinationId,
        public string $publicId,
        public string $code,
        public string $maskedCardNumber,
        public string $state,
        public bool $adjustmentEnabled,
        public int $adjustmentMinIrr,
        public int $adjustmentMaxIrr,
        public int $reservationMinutes,
        public int $lateReviewMinutes,
        public ?int $dailyLimitIrr,
        public int $priority,
        public string $verificationProviderCode,
        public bool $replayed,
    ) {}
}
