<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application\Contracts;

use DateTimeImmutable;

final readonly class PanelCreateServiceRequest
{
    /** @param array<string, scalar|null> $validatedAttributes */
    public function __construct(
        public string $operationId,
        public string $idempotencyKey,
        public string $username,
        public string $targetReference,
        public ?int $dataLimitBytes,
        public ?DateTimeImmutable $expiresAt,
        public array $validatedAttributes,
    ) {}
}
