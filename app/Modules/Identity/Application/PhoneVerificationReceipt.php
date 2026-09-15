<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\IranianMobileNumber;
use App\Modules\Identity\Domain\PhoneVerificationMethod;
use App\Modules\Identity\Domain\PhoneVerificationPolicy;

final readonly class PhoneVerificationReceipt
{
    public function __construct(
        public int $phoneNumberId,
        public IranianMobileNumber $number,
        public PhoneVerificationMethod $method,
        public PhoneVerificationPolicy $policy,
        public int $policyVersion,
        public bool $policySatisfied,
    ) {}
}
