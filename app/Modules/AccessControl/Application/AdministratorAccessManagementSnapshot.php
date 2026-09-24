<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use InvalidArgumentException;

final readonly class AdministratorAccessManagementSnapshot
{
    /**
     * @param  list<string>  $roleCodes
     * @param  list<string>  $permissionOverrides
     * @param  list<string>  $recentAuditActions
     */
    public function __construct(
        public int $administratorId,
        public string $userPublicId,
        public string $status,
        public bool $isOwner,
        public int $permissionVersion,
        public ?string $lastAuthenticatedAt,
        public array $roleCodes,
        public bool $rolesVisible,
        public array $permissionOverrides,
        public bool $permissionOverridesVisible,
        public array $recentAuditActions,
    ) {
        if ($administratorId < 1
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $userPublicId) !== 1
            || preg_match('/\A[a-z][a-z0-9_-]{1,31}\z/', $status) !== 1
            || $permissionVersion < 1) {
            throw new InvalidArgumentException('Administrator access-management snapshot is invalid.');
        }
    }
}
