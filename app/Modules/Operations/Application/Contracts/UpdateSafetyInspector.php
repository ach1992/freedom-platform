<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface UpdateSafetyInspector
{
    /** @phpstan-impure */
    public function currentSchemaSha256(): string;

    public function releaseSchemaSha256(string $releasePath): string;

    public function assertNoUnsafeWork(): void;

    public function assertWorkersRestartedAfter(string $activatedAfter): void;
}
