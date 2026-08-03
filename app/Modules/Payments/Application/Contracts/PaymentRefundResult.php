<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Contracts;

use App\Shared\Domain\Money;

final readonly class PaymentRefundResult
{
    public function __construct(
        public ProviderOperationOutcome $outcome,
        public ?string $providerRefundId,
        public Money $amount,
        public ?string $providerCode,
    ) {}
}
