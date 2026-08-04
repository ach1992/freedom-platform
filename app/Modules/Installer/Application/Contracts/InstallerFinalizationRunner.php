<?php

declare(strict_types=1);

namespace App\Modules\Installer\Application\Contracts;

interface InstallerFinalizationRunner
{
    public function clearConfiguration(): void;

    public function migrate(): void;

    public function cacheConfiguration(): void;
}
