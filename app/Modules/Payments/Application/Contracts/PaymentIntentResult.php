<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Contracts;

use DateTimeImmutable;

final readonly class PaymentIntentResult
{
    /** @param array<string, scalar|null> $safePresentation */
    public function __construct(
        public ProviderOperationOutcome $outcome,
        public ?string $providerTransactionId,
        public ?DateTimeImmutable $expiresAt,
        public array $safePresentation,
        public ?string $providerCode,
    ) {}
}
