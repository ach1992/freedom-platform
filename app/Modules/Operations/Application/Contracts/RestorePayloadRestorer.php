<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface RestorePayloadRestorer
{
    /** @param array<string, string> $entries */
    public function restore(array $entries, string $restoreRunId): void;
}
