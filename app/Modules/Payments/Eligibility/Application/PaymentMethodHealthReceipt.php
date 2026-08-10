<?php

declare(strict_types=1);

namespace App\Modules\Payments\Eligibility\Application;

use DateTimeImmutable;

final readonly class PaymentMethodHealthReceipt
{
    public function __construct(
        public int $observationId,
        public string $methodCode,
        public bool $healthy,
        public DateTimeImmutable $observedAt,
        public DateTimeImmutable $expiresAt,
        public bool $replayed,
    ) {}
}
