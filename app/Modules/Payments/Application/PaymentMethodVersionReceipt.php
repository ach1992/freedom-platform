<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\PaymentConfigurationState;
use App\Modules\Payments\Domain\PaymentMethodKind;

final readonly class PaymentMethodVersionReceipt
{
    public function __construct(
        public int $methodId,
        public string $methodPublicId,
        public string $methodCode,
        public PaymentMethodKind $kind,
        public ?string $providerCode,
        public int $versionId,
        public int $version,
        public PaymentConfigurationState $state,
        public int $displayPriority,
        public ?int $minimumAmountIrr,
        public ?int $maximumAmountIrr,
        public bool $allowDegradedHealth,
        public string $configurationHash,
        public bool $replayed,
    ) {}
}
