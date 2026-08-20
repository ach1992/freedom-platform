<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use InvalidArgumentException;

final readonly class ServicePackageQuoteContext
{
    public string $servicePublicId;
    public string $packageCode;

    public function __construct(string $servicePublicId, string $packageCode)
    {
        $servicePublicId = strtoupper(trim($servicePublicId));
        $packageCode = strtolower(trim($packageCode));

        if (strlen($servicePublicId) !== 26 || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $servicePublicId) !== 1) {
            throw new InvalidArgumentException('Service package Quote Service public ID is invalid.');
        }
        if (strlen($packageCode) < 1 || strlen($packageCode) > 64 || preg_match('/\A[a-z0-9._-]+\z/', $packageCode) !== 1) {
            throw new InvalidArgumentException('Service package Quote package code is invalid.');
        }

        $this->servicePublicId = $servicePublicId;
        $this->packageCode = $packageCode;
    }
}
