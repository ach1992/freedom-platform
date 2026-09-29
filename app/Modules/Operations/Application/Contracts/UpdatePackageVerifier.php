<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

use App\Modules\Operations\Application\VerifiedUpdatePackage;

interface UpdatePackageVerifier
{
    public function verify(string $packagePath, string $trustedPackageSha256): VerifiedUpdatePackage;

    public function extract(VerifiedUpdatePackage $package, string $destination): void;
}
