<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface UpdateWorkspace
{
    public function recoverInterruptedPreMutationRuns(): void;

    /** @param array<string, mixed> $report */
    public function storeReport(string $updateRunId, array $report): void;

    public function createStaging(string $updateRunId, string $releaseId): string;

    public function publishRelease(string $stagingPath, string $releaseId): string;

    public function discardStaging(string $stagingPath): void;

    public function discardInactiveRelease(string $releaseId): void;

    public function currentReleaseId(): ?string;

    /** @return array<string, mixed>|null */
    public function installedIdentity(): ?array;

    /** @param array<string, mixed> $identity */
    public function storeInstalledIdentity(array $identity): void;

    /** @param list<string> $protectedReleaseIds */
    public function pruneReleases(array $protectedReleaseIds, int $retention): void;
}
