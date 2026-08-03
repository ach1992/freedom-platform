<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Contracts;

use App\Shared\Domain\Money;
use DateTimeImmutable;

final readonly class BankTransactionEvidence
{
    public function __construct(
        public ProviderOperationOutcome $outcome,
        public PaymentEvidenceAuthority $authority,
        public PaymentTransactionStatus $status,
        public string $providerTransactionId,
        public ?string $providerEventId,
        public Money $amount,
        public DateTimeImmutable $occurredAt,
        public ?DateTimeImmutable $settledAt,
        public string $destinationHash,
        public ?string $senderHash,
        public string $ingestionMethod,
        public string $reconciliationState,
        public string $payloadHash,
    ) {}
}
