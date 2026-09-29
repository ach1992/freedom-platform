<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface BackupBundleWriter
{
    /**
     * @param  array<string, string>  $entries
     * @return array{entry_count:int,plaintext_sha256:string}
     */
    public function write(string $destinationPath, array $entries): array;
}
