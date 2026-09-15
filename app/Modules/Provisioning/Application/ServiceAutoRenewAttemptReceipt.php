<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\AutoRenewAttemptState;

final readonly class ServiceAutoRenewAttemptReceipt
{
    public function __construct(
        public string $attemptPublicId,
        public string $servicePublicId,
        public AutoRenewAttemptState $state,
        public ?string $reasonCode,
        public ?string $quotePublicId,
        public ?string $paymentIntentPublicId,
        public ?string $purchaseSettlementPublicId,
        public ?string $provisioningOperationPublicId,
        public bool $replayed,
    ) {}
}
