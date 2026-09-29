<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface BackupBundleReader
{
    /** @return array<string, string> logical bundle path => absolute extracted path */
    public function extract(string $bundlePath, string $destinationDirectory): array;
}
