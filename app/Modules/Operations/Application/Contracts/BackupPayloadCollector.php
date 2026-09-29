<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface BackupPayloadCollector
{
    /** @return array<string, string> */
    public function fullPayload(): array;
}
