<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\PhoneVerificationPolicy;

final readonly class OtpVerificationReceipt
{
    public function __construct(
        public string $challengeId,
        public int $phoneNumberId,
        public PhoneVerificationPolicy $policy,
        public int $policyVersion,
        public bool $policySatisfied,
    ) {}
}
