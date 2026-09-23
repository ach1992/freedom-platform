<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use App\Shared\Application\Clock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class AdministratorRoleCatalogService
{
    private const MANAGE_PERMISSION = 'access.roles.manage';

    public function __construct(
        private DatabaseManager $database,
        private AdministratorPermissionAuthorizer $authorizer,
        private Clock $clock,
    ) {}

    /** @requirement ADM-002 ACL-001 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function createCustomRole(string $roleCode, AccessChangeContext $context): AdministratorRoleMutationReceipt
    {
        $roleCode = $this->customRoleCode($roleCode);
        $context->requireReason();

        return $this->mutate(
            'access.role_definition.create',
            $roleCode,
            $context,
            ['exists' => true],
            function (Connection $connection) use ($roleCode, $context): array {
                $this->authorizeActor($context->actorAdministratorId);
                $existing = $connection->table('roles')->where('code', $roleCode)->lockForUpdate()->first([
                    'is_system',
                    'is_active',
                ]);
                if ($existing !== null) {
                    if ((bool) $existing->is_system) {
                        throw new AuthorizationException('System role definitions are code-owned.');
                    }

                    return [
                        ['exists' => true, 'active' => (bool) $existing->is_active],
                        ['exists' => true, 'active' => (bool) $existing->is_active],
                    ];
                }

                $now = $this->timestamp();
                $connection->table('roles')->insert([
                    'code' => $roleCode,
                    'name_translation_key' => 'roles.'.$roleCode,
                    'is_system' => false,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return [
                    ['exists' => false, 'active' => false],
                    ['exists' => true, 'active' => true],
                ];
            },
        );
    }

    /** @requirement ADM-002 ACL-001 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function setCustomRoleActive(
        string $roleCode,
        bool $active,
        AccessChangeContext $context,
    ): AdministratorRoleMutationReceipt {
        $roleCode = $this->customRoleCode($roleCode);
        $context->requireReason();

        return $this->mutate(
            'access.role_definition.status',
            $roleCode,
            $context,
            ['active' => $active],
            function (Connection $connection) use ($roleCode, $active, $context): array {
                $actorIsOwner = $this->authorizeActor($context->actorAdministratorId);
                $role = $this->lockCustomRoleWithAssignedAdministrators($connection, $roleCode);
                $before = (bool) $role->is_active;
                $invalidatedAdministrators = 0;
                if ($before !== $active) {
                    if ($active && ! $actorIsOwner) {
                        $this->assertRoleDelegationCeiling(
                            $connection,
                            $context->actorAdministratorId,
                            (int) $role->id,
                        );
                    }
                    $connection->table('roles')->where('id', (int) $role->id)->update([
                        'is_active' => $active,
                        'updated_at' => $this->timestamp(),
                    ]);
                    $invalidatedAdministrators = $this->invalidateAssignedAdministratorPermissions(
                        $connection,
                        (int) $role->id,
                    );
                }

                return [
                    [
                        'active' => $before,
                        'invalidated_administrators' => 0,
                    ],
                    [
                        'active' => $active,
                        'invalidated_administrators' => $invalidatedAdministrators,
                    ],
                ];
            },
        );
    }

    /** @requirement ADM-002 ACL-001 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function setCustomRolePermission(
        string $roleCode,
        string $permissionCode,
        bool $granted,
        AccessChangeContext $context,
    ): AdministratorRoleMutationReceipt {
        $roleCode = $this->customRoleCode($roleCode);
        if (preg_match('/\A[a-z0-9_.-]{1,128}\z/', $permissionCode) !== 1) {
            throw new RuntimeException('Permission code is invalid.');
        }
        $context->requireReason();

        return $this->mutate(
            'access.role_definition.permission',
            $roleCode,
            $context,
            ['permission_code' => $permissionCode, 'granted' => $granted],
            function (Connection $connection) use ($roleCode, $permissionCode, $granted, $context): array {
                $actorIsOwner = $this->authorizeActor($context->actorAdministratorId);
                $role = $this->lockCustomRoleWithAssignedAdministrators($connection, $roleCode);
                $permission = $connection->table('permissions')->where('code', $permissionCode)->lockForUpdate()->first(['id']);
                if ($permission === null) {
                    throw new RuntimeException('Permission does not exist.');
                }
                if ($granted && ! $actorIsOwner) {
                    $this->authorizer->authorize($context->actorAdministratorId, $permissionCode);
                }

                $exists = $connection->table('role_permissions')
                    ->where('role_id', (int) $role->id)
                    ->where('permission_id', (int) $permission->id)
                    ->exists();

                $invalidatedAdministrators = 0;
                if ($exists !== $granted) {
                    if ($granted) {
                        $now = $this->timestamp();
                        $connection->table('role_permissions')->insert([
                            'role_id' => (int) $role->id,
                            'permission_id' => (int) $permission->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    } else {
                        $connection->table('role_permissions')
                            ->where('role_id', (int) $role->id)
                            ->where('permission_id', (int) $permission->id)
                            ->delete();
                    }
                    if ((bool) $role->is_active) {
                        $invalidatedAdministrators = $this->invalidateAssignedAdministratorPermissions(
                            $connection,
                            (int) $role->id,
                        );
                    }
                }

                return [
                    [
                        'permission_code' => $permissionCode,
                        'granted' => $exists,
                        'invalidated_administrators' => 0,
                    ],
                    [
                        'permission_code' => $permissionCode,
                        'granted' => $granted,
                        'invalidated_administrators' => $invalidatedAdministrators,
                    ],
                ];
            },
        );
    }

    /**
     * @param  array<string,bool|int|string|null>  $expectedAfter
     * @param  callable(Connection):array{0:array<string,bool|int|string|null>,1:array<string,bool|int|string|null>}  $operation
     */
    private function mutate(
        string $action,
        string $roleCode,
        AccessChangeContext $context,
        array $expectedAfter,
        callable $operation,
    ): AdministratorRoleMutationReceipt {
        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $action,
                $roleCode,
                $context,
                $expectedAfter,
                $operation,
            ): AdministratorRoleMutationReceipt {
                $existing = $this->existing($connection, $action, $roleCode, $context->requestFingerprint, true);
                if ($existing !== null) {
                    $this->assertExpectedAfter($existing, $expectedAfter);

                    return $existing;
                }

                [$before, $after] = $operation($connection);
                $connection->table('audit_logs')->insert([
                    'actor_type' => 'administrator',
                    'actor_id' => (string) $context->actorAdministratorId,
                    'action' => $action,
                    'target_type' => 'role',
                    'target_id' => $roleCode,
                    'before_safe_data' => json_encode($before, JSON_THROW_ON_ERROR),
                    'after_safe_data' => json_encode($after, JSON_THROW_ON_ERROR),
                    'reason_code' => $context->reasonCode,
                    'reason' => $context->reason,
                    'correlation_id' => $context->correlationId,
                    'request_fingerprint' => $context->requestFingerprint,
                    'created_at' => $this->timestamp(),
                ]);

                return new AdministratorRoleMutationReceipt(
                    $action,
                    $roleCode,
                    $before,
                    $after,
                    $before !== $after,
                );
            });
        } catch (QueryException $exception) {
            $existing = $this->existing(
                $this->database->connection(),
                $action,
                $roleCode,
                $context->requestFingerprint,
            );
            if ($existing !== null) {
                $this->assertExpectedAfter($existing, $expectedAfter);

                return $existing;
            }

            throw $exception;
        }
    }

    /** @return object{id:int|string,is_active:int|bool} */
    private function assertRoleDelegationCeiling(
        Connection $connection,
        int $actorAdministratorId,
        int $roleId,
    ): void {
        /** @var list<string> $permissionCodes */
        $permissionCodes = $connection->table('role_permissions as role_permission')
            ->join('permissions as permission', 'permission.id', '=', 'role_permission.permission_id')
            ->where('role_permission.role_id', $roleId)
            ->orderBy('permission.code')
            ->pluck('permission.code')
            ->filter(static fn (mixed $code): bool => is_string($code))
            ->values()
            ->all();

        foreach ($permissionCodes as $permissionCode) {
            $this->authorizer->authorize($actorAdministratorId, $permissionCode);
        }
    }

    private function lockCustomRoleWithAssignedAdministrators(
        Connection $connection,
        string $roleCode,
    ): object {
        $unlockedRole = $this->customRole($connection, $roleCode, false);
        $roleId = (int) $unlockedRole->id;
        if ($roleId < 1) {
            throw new RuntimeException('Custom role identity is invalid.');
        }

        $knownAdministratorIds = $this->assignedAdministratorIds($connection, $roleId, false);
        $this->lockAdministrators($connection, $knownAdministratorIds);

        // AdministratorAccessService locks the target administrator before the role.
        // Match that order first. Once the role is locked, a concurrent canonical
        // assignment cannot become visible until this mutation commits. A locking
        // current-read then captures assignments that committed before our role lock.
        $role = $this->customRole($connection, $roleCode, true);
        $currentAdministratorIds = $this->assignedAdministratorIds($connection, $roleId, true);
        $newAdministratorIds = array_values(array_diff(
            $currentAdministratorIds,
            $knownAdministratorIds,
        ));
        $this->lockAdministrators($connection, $newAdministratorIds);

        return $role;
    }

    /** @return list<int> */
    private function assignedAdministratorIds(
        Connection $connection,
        int $roleId,
        bool $lock,
    ): array {
        $query = $connection->table('administrator_role_assignments')
            ->where('role_id', $roleId)
            ->whereNull('revoked_at')
            ->orderBy('administrator_id');

        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var list<int> $administratorIds */
        $administratorIds = $query
            ->pluck('administrator_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();

        return $administratorIds;
    }

    /** @param list<int> $administratorIds */
    private function lockAdministrators(Connection $connection, array $administratorIds): void
    {
        if ($administratorIds === []) {
            return;
        }

        $lockedIds = $connection->table('administrators')
            ->whereIn('id', $administratorIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->values()
            ->all();

        sort($administratorIds);
        sort($lockedIds);
        if ($lockedIds !== $administratorIds) {
            throw new RuntimeException('Custom role assignment references an invalid administrator.');
        }
    }

    private function invalidateAssignedAdministratorPermissions(
        Connection $connection,
        int $roleId,
    ): int {
        $administratorIds = $this->assignedAdministratorIds(
            $connection,
            $roleId,
            true,
        );

        if ($administratorIds === []) {
            return 0;
        }

        /** @var list<object{id:int|string,permission_version:int|string}> $administrators */
        $administrators = $connection->table('administrators')
            ->whereIn('id', $administratorIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'permission_version'])
            ->all();

        if (count($administrators) !== count($administratorIds)) {
            throw new RuntimeException('Custom role assignment references an invalid administrator.');
        }

        $now = $this->timestamp();
        foreach ($administrators as $administrator) {
            $administratorId = (int) $administrator->id;
            $beforeVersion = (int) $administrator->permission_version;
            if ($administratorId < 1 || $beforeVersion < 1 || $beforeVersion === PHP_INT_MAX) {
                throw new RuntimeException('Administrator permission version is invalid.');
            }

            $updated = $connection->table('administrators')
                ->where('id', $administratorId)
                ->where('permission_version', $beforeVersion)
                ->update([
                    'permission_version' => $beforeVersion + 1,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Administrator permission version invalidation lost its lock.');
            }
        }

        return count($administrators);
    }

    /** @param  array<string,bool|int|string|null>  $expected */
    private function assertExpectedAfter(
        AdministratorRoleMutationReceipt $receipt,
        array $expected,
    ): void {
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $receipt->after) || $receipt->after[$key] !== $value) {
                throw new RuntimeException('Role mutation fingerprint conflict.');
            }
        }
    }

    private function existing(
        Connection $connection,
        string $action,
        string $roleCode,
        string $fingerprint,
        bool $lock = false,
    ): ?AdministratorRoleMutationReceipt {
        $query = $connection->table('audit_logs')
            ->where('action', $action)
            ->where('request_fingerprint', $fingerprint);
        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first(['target_type', 'target_id', 'before_safe_data', 'after_safe_data']);
        if ($row === null) {
            return null;
        }
        if ($row->target_type !== 'role' || $row->target_id !== $roleCode) {
            throw new RuntimeException('Role mutation fingerprint conflict.');
        }

        $before = $this->decode($row->before_safe_data ?? null);
        $after = $this->decode($row->after_safe_data ?? null);

        return new AdministratorRoleMutationReceipt($action, $roleCode, $before, $after, $before !== $after, true);
    }

    /** @return object{id:int|string,is_active:int|bool} */
    private function customRole(Connection $connection, string $roleCode, bool $lock): object
    {
        $query = $connection->table('roles')->where('code', $roleCode);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var object{id:int|string,is_system:int|bool,is_active:int|bool}|null $role */
        $role = $query->first(['id', 'is_system', 'is_active']);
        if ($role === null) {
            throw new RuntimeException('Custom role does not exist.');
        }
        if ((bool) $role->is_system) {
            throw new AuthorizationException('System role definitions are code-owned.');
        }

        return $role;
    }

    private function authorizeActor(int $administratorId): bool
    {
        $row = $this->database->connection()->table('administrators')
            ->where('id', $administratorId)
            ->first(['status', 'is_owner']);
        if ($row === null || $row->status !== 'active') {
            throw new AuthorizationException('Administrator authorization failed.');
        }

        $this->authorizer->authorize($administratorId, self::MANAGE_PERMISSION);

        return (bool) $row->is_owner;
    }

    private function customRoleCode(string $roleCode): string
    {
        $normalized = strtolower(trim($roleCode));
        if (preg_match('/\Acustom\.[a-z0-9][a-z0-9_.-]{0,55}\z/', $normalized) !== 1) {
            throw new RuntimeException('Custom role code must use the custom.* namespace.');
        }

        return $normalized;
    }

    /** @return array<string,bool|int|string|null> */
    private function decode(mixed $encoded): array
    {
        if (! is_string($encoded)) {
            return [];
        }
        $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Stored role audit state is invalid.');
        }

        $safe = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key) || (! is_bool($value) && ! is_int($value) && ! is_string($value) && $value !== null)) {
                throw new RuntimeException('Stored role audit state is invalid.');
            }
            $safe[$key] = $value;
        }

        return $safe;
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
