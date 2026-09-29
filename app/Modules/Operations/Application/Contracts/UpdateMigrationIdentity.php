<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface UpdateMigrationIdentity
{
    public function driver(): string;

    public function installedHash(): string;

    public function assertInstalledMatchesDirectory(string $directory): void;
}
