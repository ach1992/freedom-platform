<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

final readonly class AdministratorUserPermissionAuthorizer
{
    public function __construct(
        private DatabaseManager $database,
        private AdministratorPermissionAuthorizer $authorizer,
    ) {}

    /** @requirement ACL-001 ACL-002 SEC-002 */
    public function authorizeUser(int $userId, string $permissionCode): int
    {
        if ($userId < 1) {
            throw new AuthorizationException('Administrator authorization failed.');
        }

        $administratorId = $this->database->connection()
            ->table('administrators')
            ->where('user_id', $userId)
            ->value('id');

        if (! is_int($administratorId) && ! is_string($administratorId)) {
            throw new AuthorizationException('Administrator authorization failed.');
        }

        $normalizedAdministratorId = (int) $administratorId;
        if ($normalizedAdministratorId < 1) {
            throw new AuthorizationException('Administrator authorization failed.');
        }

        $this->authorizer->authorize($normalizedAdministratorId, $permissionCode);

        return $normalizedAdministratorId;
    }

    public function allowsUser(int $userId, string $permissionCode): bool
    {
        try {
            $this->authorizeUser($userId, $permissionCode);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }
}
