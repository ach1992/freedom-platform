<?php

declare(strict_types=1);

namespace App\Modules\Payments\Eligibility\Domain;

enum PaymentEligibilityRuleEffect: string
{
    case Allow = 'allow';
    case Deny = 'deny';
}
