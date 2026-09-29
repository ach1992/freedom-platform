<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface BackupDatabaseDumper
{
    public function capture(string $destinationPath): void;
}
