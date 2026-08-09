<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

enum PaymentEligibilityOutcome: string
{
    case Eligible = 'eligible';
    case MethodDisabled = 'method_disabled';
    case HealthUnavailable = 'health_unavailable';
    case AmountOutsideMethodLimit = 'amount_outside_method_limit';
    case NoMatchingRule = 'no_matching_rule';
    case RuleDenied = 'rule_denied';
}
