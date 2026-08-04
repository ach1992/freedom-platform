<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\SmsDeliveryStatus;
use DateTimeImmutable;

final readonly class OtpIssueReceipt
{
    public function __construct(
        public string $challengeId,
        public int $phoneNumberId,
        public string $maskedDestination,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $resendAvailableAt,
        public SmsDeliveryStatus $deliveryStatus,
        public ?string $providerCode,
    ) {}
}
