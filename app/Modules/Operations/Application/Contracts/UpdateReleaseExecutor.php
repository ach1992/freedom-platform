<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

use App\Modules\Operations\Application\VerifiedUpdatePackage;

interface UpdateReleaseExecutor
{
    public function assertPrerequisites(VerifiedUpdatePackage $package): void;

    public function prepare(string $releasePath, VerifiedUpdatePackage $package): void;

    public function prepareRuntime(string $releasePath): void;

    public function migrate(string $releasePath): void;

    public function verifyRelease(string $releasePath, ?VerifiedUpdatePackage $package = null): void;
}
