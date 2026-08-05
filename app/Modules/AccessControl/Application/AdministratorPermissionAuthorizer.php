<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use App\Modules\AccessControl\Domain\PermissionEffect;
use App\Modules\AccessControl\Domain\PermissionResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

final readonly class AdministratorPermissionAuthorizer
{
    public function __construct(
        private DatabaseManager $database,
        private PermissionResolver $resolver,
    ) {}

    /** @requirement ACL-001 ACL-002 SEC-002 */
    public function authorize(int $administratorId, string $permissionCode): void
    {
        $connection = $this->database->connection();

        /** @var object{status: string, is_owner: int|bool}|null $administrator */
        $administrator = $connection->table('administrators')
            ->where('id', $administratorId)
            ->first(['status', 'is_owner']);

        if ($administrator === null || $administrator->status !== 'active') {
            throw new AuthorizationException('Administrator authorization failed.');
        }

        if ((bool) $administrator->is_owner) {
            return;
        }

        $permissionId = $connection->table('permissions')
            ->where('code', $permissionCode)
            ->where('is_active', true)
            ->value('id');

        if (! is_int($permissionId) && ! is_string($permissionId)) {
            throw new AuthorizationException('Administrator authorization failed.');
        }

        $roleGrant = $connection->table('administrator_role_assignments as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->where('assignments.administrator_id', $administratorId)
            ->whereNull('assignments.revoked_at')
            ->where('roles.is_active', true)
            ->where('role_permissions.permission_id', (int) $permissionId)
            ->exists();

        /** @var list<string> $effects */
        $effects = $connection->table('administrator_permission_overrides')
            ->where('administrator_id', $administratorId)
            ->where('permission_id', (int) $permissionId)
            ->pluck('effect')
            ->filter(static fn (mixed $effect): bool => is_string($effect))
            ->values()
            ->all();

        $normalized = array_values(array_filter(array_map(
            static fn (string $effect): ?PermissionEffect => PermissionEffect::tryFrom($effect),
            $effects,
        )));

        if (! $this->resolver->allows($roleGrant, $normalized)) {
            throw new AuthorizationException('Administrator authorization failed.');
        }
    }
}
