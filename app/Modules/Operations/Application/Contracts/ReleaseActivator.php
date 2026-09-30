<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface ReleaseActivator
{
    public function prepare(string $releaseId): string;

    /** Resolve an existing release without mutating shared links or current. */
    public function resolve(string $releaseId): string;

    /**
     * @return array{status: 'activated'|'already_active', release: string, previous_release: string|null}
     */
    public function activate(string $releaseId, bool $automaticRollbackSafe = true): array;

    /**
     * @return array{status: 'rolled_back'|'already_active', release: string, previous_release: string|null}
     */
    public function rollbackTo(string $releaseId): array;
}
