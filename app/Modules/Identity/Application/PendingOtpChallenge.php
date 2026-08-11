<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\IranianMobileNumber;
use App\Modules\Identity\Domain\SmsDeliveryStatus;
use DateTimeImmutable;

final readonly class PendingOtpChallenge
{
    public function __construct(
        public string $challengeId,
        public int $phoneNumberId,
        public IranianMobileNumber $number,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $resendAvailableAt,
        public SmsDeliveryStatus $deliveryStatus,
        public ?string $providerCode,
        public ?string $plainCode,
    ) {}

    public function shouldDispatch(): bool
    {
        return $this->plainCode !== null;
    }
}
