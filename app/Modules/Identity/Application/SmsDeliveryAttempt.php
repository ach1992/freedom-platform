<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use InvalidArgumentException;

final readonly class SmsDeliveryAttempt
{
    public function __construct(
        public string $providerCode,
        public SmsDeliveryResult $result,
    ) {
        if (preg_match('/\A[a-z0-9_-]{2,64}\z/', $providerCode) !== 1) {
            throw new InvalidArgumentException('SMS provider code is invalid.');
        }
    }
}
