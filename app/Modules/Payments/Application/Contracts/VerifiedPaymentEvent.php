<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Contracts;

final readonly class VerifiedPaymentEvent
{
    public function __construct(
        public string $providerEventId,
        public string $payloadHash,
        public PaymentEvidence $evidence,
    ) {}
}
