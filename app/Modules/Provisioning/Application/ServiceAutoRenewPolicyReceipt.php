<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\AutoRenewPriceChangeMode;

final readonly class ServiceAutoRenewPolicyReceipt
{
    public function __construct(
        public int $policyId,
        public int $planOfferingId,
        public AutoRenewPriceChangeMode $mode,
        public ?int $absoluteIncreaseLimitIrr,
        public ?int $percentageIncreaseLimitBps,
        public int $version,
        public bool $replayed,
    ) {}
}
