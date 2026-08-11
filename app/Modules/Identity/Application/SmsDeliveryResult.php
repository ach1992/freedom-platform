<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\SmsDeliveryStatus;
use InvalidArgumentException;

final readonly class SmsDeliveryResult
{
    private function __construct(
        public SmsDeliveryStatus $status,
        public ?string $providerMessageId,
        public ?string $errorCode,
    ) {
        if ($providerMessageId !== null && (trim($providerMessageId) === '' || mb_strlen($providerMessageId) > 191)) {
            throw new InvalidArgumentException('SMS provider message ID is invalid.');
        }

        if ($errorCode !== null && preg_match('/\A[a-z0-9_.-]{1,64}\z/', $errorCode) !== 1) {
            throw new InvalidArgumentException('SMS error code is invalid.');
        }
    }

    public static function accepted(?string $providerMessageId = null): self
    {
        return new self(SmsDeliveryStatus::Accepted, $providerMessageId, null);
    }

    public static function definitiveFailure(string $errorCode): self
    {
        return new self(SmsDeliveryStatus::DefinitiveFailure, null, $errorCode);
    }

    public static function uncertain(string $errorCode): self
    {
        return new self(SmsDeliveryStatus::Uncertain, null, $errorCode);
    }
}
