<?php

declare(strict_types=1);

namespace App\Modules\Payments\GiftCard\Application\Contracts;

use DateTimeImmutable;

final readonly class GiftCardProviderEvidence
{
    /** @param array<string, scalar|null> $safeEvidence */
    public function __construct(
        public string $operation,
        public string $outcome,
        public string $status,
        public string $providerEventId,
        public ?string $providerTransactionId,
        public ?int $faceValue,
        public ?string $currency,
        public ?string $brand,
        public ?string $region,
        public DateTimeImmutable $occurredAt,
        public string $evidenceHash,
        public array $safeEvidence = [],
    ) {}
}