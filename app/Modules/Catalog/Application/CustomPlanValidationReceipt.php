<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

final readonly class CustomPlanValidationReceipt
{
    public function __construct(
        public int $validationId,
        public int $calculationId,
        public string $stage,
        public string $policyConfigurationHash,
        public string $eligibilitySnapshotHash,
        public bool $replayed = false,
    ) {}
}
