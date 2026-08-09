<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\PaymentMethodKind;

final readonly class EligiblePaymentMethodReceipt
{
    public function __construct(
        public int $methodId,
        public string $methodPublicId,
        public string $methodCode,
        public PaymentMethodKind $kind,
        public ?string $providerCode,
        public int $methodVersion,
        public string $methodConfigurationHash,
        public int $displayPriority,
        public int $ruleId,
        public string $rulePublicId,
        public string $ruleCode,
        public int $ruleVersion,
        public string $ruleConfigurationHash,
    ) {}
}
