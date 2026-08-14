<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application\Contracts;

use DateTimeImmutable;

final readonly class BankTransactionObservation
{
    public function __construct(
        public string $providerTransactionId,
        public string $providerEventId,
        public string $destinationCardNumber,
        public int $amountIrr,
        public string $status,
        public DateTimeImmutable $occurredAt,
        public ?string $senderCardNumber,
        public ?string $senderName,
        public ?string $reference,
        public string $evidencePayloadHash,
    ) {}
}
