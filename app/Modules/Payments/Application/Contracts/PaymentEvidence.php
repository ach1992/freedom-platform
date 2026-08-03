<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Contracts;

use App\Shared\Domain\Money;
use DateTimeImmutable;

final readonly class PaymentEvidence
{
    /** @param array<string, scalar|null> $safeEvidence */
    public function __construct(
        public ProviderOperationOutcome $outcome,
        public PaymentEvidenceAuthority $authority,
        public PaymentTransactionStatus $status,
        public string $providerTransactionId,
        public ?string $providerEventId,
        public Money $amount,
        public DateTimeImmutable $occurredAt,
        public ?DateTimeImmutable $settledAt,
        public string $payloadHash,
        public array $safeEvidence,
    ) {}

    public function authorizesCapture(): bool
    {
        return $this->outcome === ProviderOperationOutcome::Success
            && $this->authority === PaymentEvidenceAuthority::Authoritative
            && $this->status === PaymentTransactionStatus::Settled;
    }
}
