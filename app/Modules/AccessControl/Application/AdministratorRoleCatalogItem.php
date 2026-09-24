<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use InvalidArgumentException;

final readonly class AdministratorRoleCatalogItem
{
    /** @param list<string> $permissionCodes */
    public function __construct(
        public string $code,
        public bool $isSystem,
        public bool $isActive,
        public array $permissionCodes,
    ) {
        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $code) !== 1) {
            throw new InvalidArgumentException('Administrator role catalog item is invalid.');
        }
    }
}
