<?php

declare(strict_types=1);

namespace App\Modules\Payments\Eligibility\Application;

final readonly class PaymentEligibilityRuleVersionReceipt
{
    public function __construct(
        public int $versionId,
        public string $methodCode,
        public string $ruleCode,
        public int $version,
        public string $configurationSnapshotHash,
        public bool $replayed,
    ) {}
}
