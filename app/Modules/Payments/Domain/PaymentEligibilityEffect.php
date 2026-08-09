<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

enum PaymentEligibilityEffect: string
{
    case Allow = 'allow';
    case Deny = 'deny';
}
