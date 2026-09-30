<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface UpdateSafetyInspector
{
    /** @phpstan-impure */
    public function currentSchemaSha256(): string;

    /** @phpstan-impure */
    public function installedSchemaSha256ForRelease(string $releasePath): string;

    public function releaseSchemaSha256(string $releasePath): string;

    public function assertNoUnsafeWork(): void;

    /** @return array<string, string> */
    public function workerBootIds(): array;

    /** @param array<string, string> $previousBootIds */
    public function assertWorkersRestartedAfter(
        string $restartedAfter,
        string $expectedReleaseId,
        array $previousBootIds,
    ): void;
}
