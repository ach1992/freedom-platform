<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

final readonly class AdministratorRoleContextSnapshot
{
    /** @param list<string> $roleCodes */
    public function __construct(
        public int $administratorId,
        public bool $isOwner,
        public array $roleCodes,
    ) {}
}
