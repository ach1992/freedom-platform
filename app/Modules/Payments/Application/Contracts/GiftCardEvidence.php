<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Contracts;

final readonly class GiftCardEvidence
{
    public function __construct(
        public ProviderOperationOutcome $outcome,
        public GiftCardAuthority $authority,
        public ?string $providerTransactionId,
        public ?string $providerEventId,
        public string $brand,
        public ?string $region,
        public string $payloadHash,
    ) {}

    public function authorizesCapture(): bool
    {
        return $this->outcome === ProviderOperationOutcome::Success
            && $this->authority === GiftCardAuthority::Captured;
    }
}
