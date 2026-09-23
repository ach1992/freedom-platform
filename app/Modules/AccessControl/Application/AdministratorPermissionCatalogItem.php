<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use InvalidArgumentException;

final readonly class AdministratorPermissionCatalogItem
{
    public function __construct(
        public string $code,
        public string $module,
        public string $riskLevel,
        public bool $requiresApproval,
    ) {
        if (preg_match('/\A[a-z0-9_.-]{1,128}\z/', $code) !== 1
            || preg_match('/\A[a-z0-9_.-]{1,64}\z/', $module) !== 1
            || preg_match('/\A[a-z][a-z0-9_-]{1,31}\z/', $riskLevel) !== 1) {
            throw new InvalidArgumentException('Administrator permission catalog item is invalid.');
        }
    }
}
