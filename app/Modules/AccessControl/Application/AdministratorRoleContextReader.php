<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class AdministratorRoleContextReader
{
    public function __construct(private DatabaseManager $database) {}

    /** @requirement ACL-001 ACL-002 SEC-002 */
    public function forAdministrator(int $administratorId): AdministratorRoleContextSnapshot
    {
        if ($administratorId < 1) {
            throw new AuthorizationException('Administrator role context is unavailable.');
        }

        $connection = $this->database->connection();
        /** @var object{status:string,is_owner:int|bool}|null $administrator */
        $administrator = $connection->table('administrators')
            ->where('id', $administratorId)
            ->first(['status', 'is_owner']);
        if ($administrator === null || $administrator->status !== 'active') {
            throw new AuthorizationException('Administrator role context is unavailable.');
        }

        /** @var list<string> $roleCodes */
        $roleCodes = $connection->table('administrator_role_assignments as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.administrator_id', $administratorId)
            ->whereNull('assignments.revoked_at')
            ->where('roles.is_active', true)
            ->orderBy('roles.code')
            ->pluck('roles.code')
            ->filter(static fn (mixed $code): bool => is_string($code))
            ->values()
            ->all();

        return new AdministratorRoleContextSnapshot(
            $administratorId,
            (bool) $administrator->is_owner,
            $roleCodes,
        );
    }

    /** @requirement ACL-001 SEC-002 */
    public function requireActiveRole(string $roleCode): string
    {
        $normalized = trim($roleCode);
        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $normalized) !== 1) {
            throw new RuntimeException('Role code is invalid.');
        }

        $role = $this->database->connection()->table('roles')
            ->where('code', $normalized)
            ->where('is_active', true)
            ->sharedLock()
            ->first(['id']);
        if ($role === null) {
            throw new RuntimeException('Active role does not exist.');
        }

        return $normalized;
    }
}
