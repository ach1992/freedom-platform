<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

final readonly class PanelHttpExchange
{
    /** @param array<array-key, mixed>|null $json */
    public function __construct(
        public ?int $status,
        public ?array $json,
        public bool $malformedJson,
        public bool $transportFailure,
        public ?PanelHttpFailureType $failure = null,
    ) {}

    public function successful(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 300;
    }
}
