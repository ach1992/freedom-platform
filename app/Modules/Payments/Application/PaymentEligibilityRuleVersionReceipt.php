<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\PaymentConfigurationState;
use App\Modules\Payments\Domain\PaymentEligibilityEffect;

final readonly class PaymentEligibilityRuleVersionReceipt
{
    public function __construct(
        public int $ruleId,
        public string $rulePublicId,
        public string $methodCode,
        public string $ruleCode,
        public int $versionId,
        public int $version,
        public PaymentConfigurationState $state,
        public PaymentEligibilityEffect $effect,
        public int $priority,
        public bool $isOverride,
        public int $specificity,
        public string $configurationHash,
        public bool $replayed,
    ) {}
}
