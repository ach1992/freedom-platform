<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use App\Modules\AccessControl\Domain\PermissionEffect;
use App\Shared\Application\Clock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class AdministratorAccessService
{
    private const ROLE_MANAGEMENT_PERMISSION = 'access.roles.manage';

    private const OVERRIDE_MANAGEMENT_PERMISSION = 'access.permissions.override';

    public function __construct(
        private DatabaseManager $database,
        private AdministratorPermissionAuthorizer $authorizer,
        private AccessMutationAudit $audit,
        private Clock $clock,
    ) {}

    /** @requirement ACL-001 ACL-002 SEC-002 QUA-001 */
    public function grantRole(
        int $administratorId,
        string $roleCode,
        AccessChangeContext $context,
    ): AccessMutationReceipt {
        return $this->changeRole(
            'access.role.grant',
            $administratorId,
            $roleCode,
            true,
            $context,
        );
    }

    /** @requirement ACL-001 ACL-002 SEC-002 QUA-001 */
    public function revokeRole(
        int $administratorId,
        string $roleCode,
        AccessChangeContext $context,
    ): AccessMutationReceipt {
        return $this->changeRole(
            'access.role.revoke',
            $administratorId,
            $roleCode,
            false,
            $context,
        );
    }

    /** @requirement ACL-001 ACL-002 SEC-002 QUA-001 */
    public function setPermissionOverride(
        int $administratorId,
        string $permissionCode,
        PermissionEffect $effect,
        AccessChangeContext $context,
    ): AccessMutationReceipt {
        if (preg_match('/\A[a-z0-9_.-]{1,128}\z/', $permissionCode) !== 1) {
            throw new RuntimeException('Permission code is invalid.');
        }

        $context->requireReason();
        $action = 'access.permission.override';
        $actorAdministratorId = $context->actorAdministratorId;

        return $this->idempotentTransaction(
            $action,
            $administratorId,
            $context,
            function (Connection $connection) use (
                $action,
                $administratorId,
                $permissionCode,
                $effect,
                $context,
                $actorAdministratorId,
            ): AccessMutationReceipt {
                $actorIsOwner = $this->authorizeActor(
                    $connection,
                    $actorAdministratorId,
                    self::OVERRIDE_MANAGEMENT_PERMISSION,
                );

                $existing = $this->audit->existing(
                    $action,
                    $administratorId,
                    $context->requestFingerprint,
                    true,
                );
                if ($existing !== null) {
                    $this->assertReceiptValue($existing, 'permission_code', $permissionCode);
                    $this->assertReceiptValue($existing, 'effect', $effect->value);

                    return $existing;
                }

                $target = $this->manageableTarget($connection, $administratorId);
                $this->assertNotSelfManaged($actorAdministratorId, $administratorId, $actorIsOwner);

                /** @var object{id: int|string}|null $permission */
                $permission = $connection->table('permissions')
                    ->where('code', $permissionCode)
                    ->lockForUpdate()
                    ->first(['id']);

                if ($permission === null) {
                    throw new RuntimeException('Permission does not exist.');
                }

                $permissionId = (int) $permission->id;
                $roleGrant = $this->roleAllows($connection, $administratorId, $permissionId);

                if (! $actorIsOwner && ($effect === PermissionEffect::Allow || ($effect === PermissionEffect::Inherit && $roleGrant))) {
                    $this->authorizer->authorize($actorAdministratorId, $permissionCode);
                }

                /** @var object{effect: string}|null $override */
                $override = $connection->table('administrator_permission_overrides')
                    ->where('administrator_id', $administratorId)
                    ->where('permission_id', $permissionId)
                    ->lockForUpdate()
                    ->first(['effect']);

                $beforeEffect = $override === null
                    ? PermissionEffect::Inherit
                    : PermissionEffect::tryFrom($override->effect);

                if ($beforeEffect === null) {
                    throw new RuntimeException('Stored permission override is invalid.');
                }

                $changed = $beforeEffect !== $effect;
                $beforeVersion = (int) $target->permission_version;
                $afterVersion = $this->nextPermissionVersion(
                    $connection,
                    $administratorId,
                    $beforeVersion,
                    $changed,
                );

                if ($changed) {
                    $now = $this->timestamp();

                    if ($effect === PermissionEffect::Inherit) {
                        $connection->table('administrator_permission_overrides')
                            ->where('administrator_id', $administratorId)
                            ->where('permission_id', $permissionId)
                            ->delete();
                    } elseif ($override === null) {
                        $connection->table('administrator_permission_overrides')->insert([
                            'administrator_id' => $administratorId,
                            'permission_id' => $permissionId,
                            'effect' => $effect->value,
                            'changed_by_administrator_id' => $actorAdministratorId,
                            'reason_code' => $context->reasonCode,
                            'reason' => $context->reason,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    } else {
                        $connection->table('administrator_permission_overrides')
                            ->where('administrator_id', $administratorId)
                            ->where('permission_id', $permissionId)
                            ->update([
                                'effect' => $effect->value,
                                'changed_by_administrator_id' => $actorAdministratorId,
                                'reason_code' => $context->reasonCode,
                                'reason' => $context->reason,
                                'updated_at' => $now,
                            ]);
                    }
                }

                return $this->audit->record(
                    $connection,
                    $action,
                    $administratorId,
                    $context,
                    [
                        'permission_code' => $permissionCode,
                        'effect' => $beforeEffect->value,
                        'role_grant' => $roleGrant,
                        'effective' => $this->effectAllows($roleGrant, $beforeEffect),
                        'permission_version' => $beforeVersion,
                    ],
                    [
                        'permission_code' => $permissionCode,
                        'effect' => $effect->value,
                        'role_grant' => $roleGrant,
                        'effective' => $this->effectAllows($roleGrant, $effect),
                        'permission_version' => $afterVersion,
                    ],
                );
            },
        );
    }

    private function changeRole(
        string $action,
        int $administratorId,
        string $roleCode,
        bool $assigned,
        AccessChangeContext $context,
    ): AccessMutationReceipt {
        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $roleCode) !== 1) {
            throw new RuntimeException('Role code is invalid.');
        }

        $context->requireReason();
        $actorAdministratorId = $context->actorAdministratorId;

        return $this->idempotentTransaction(
            $action,
            $administratorId,
            $context,
            function (Connection $connection) use (
                $action,
                $administratorId,
                $roleCode,
                $assigned,
                $context,
                $actorAdministratorId,
            ): AccessMutationReceipt {
                $actorIsOwner = $this->authorizeActor(
                    $connection,
                    $actorAdministratorId,
                    self::ROLE_MANAGEMENT_PERMISSION,
                );

                $existing = $this->audit->existing(
                    $action,
                    $administratorId,
                    $context->requestFingerprint,
                    true,
                );
                if ($existing !== null) {
                    $this->assertReceiptValue($existing, 'role_code', $roleCode);
                    $this->assertReceiptValue($existing, 'assigned', $assigned);

                    return $existing;
                }

                $target = $this->manageableTarget($connection, $administratorId);
                $this->assertNotSelfManaged($actorAdministratorId, $administratorId, $actorIsOwner);

                /** @var object{id: int|string, is_active: int|bool}|null $role */
                $role = $connection->table('roles')
                    ->where('code', $roleCode)
                    ->lockForUpdate()
                    ->first(['id', 'is_active']);

                if ($role === null || ! (bool) $role->is_active) {
                    throw new RuntimeException('Active role does not exist.');
                }

                $roleId = (int) $role->id;

                if ($assigned && ! $actorIsOwner) {
                    $this->assertRoleDelegationCeiling($connection, $actorAdministratorId, $roleId);
                }

                /** @var object{revoked_at: ?string}|null $assignment */
                $assignment = $connection->table('administrator_role_assignments')
                    ->where('administrator_id', $administratorId)
                    ->where('role_id', $roleId)
                    ->lockForUpdate()
                    ->first(['revoked_at']);

                $beforeAssigned = $assignment !== null && $assignment->revoked_at === null;
                $changed = $beforeAssigned !== $assigned;
                $beforeVersion = (int) $target->permission_version;
                $afterVersion = $this->nextPermissionVersion(
                    $connection,
                    $administratorId,
                    $beforeVersion,
                    $changed,
                );

                if ($changed) {
                    $now = $this->timestamp();

                    if ($assignment === null) {
                        $connection->table('administrator_role_assignments')->insert([
                            'administrator_id' => $administratorId,
                            'role_id' => $roleId,
                            'granted_by_administrator_id' => $actorAdministratorId,
                            'granted_at' => $now,
                            'revoked_at' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    } elseif ($assigned) {
                        $connection->table('administrator_role_assignments')
                            ->where('administrator_id', $administratorId)
                            ->where('role_id', $roleId)
                            ->update([
                                'granted_by_administrator_id' => $actorAdministratorId,
                                'granted_at' => $now,
                                'revoked_at' => null,
                                'updated_at' => $now,
                            ]);
                    } else {
                        $connection->table('administrator_role_assignments')
                            ->where('administrator_id', $administratorId)
                            ->where('role_id', $roleId)
                            ->update([
                                'revoked_at' => $now,
                                'updated_at' => $now,
                            ]);
                    }
                }

                return $this->audit->record(
                    $connection,
                    $action,
                    $administratorId,
                    $context,
                    [
                        'role_code' => $roleCode,
                        'assigned' => $beforeAssigned,
                        'permission_version' => $beforeVersion,
                    ],
                    [
                        'role_code' => $roleCode,
                        'assigned' => $assigned,
                        'permission_version' => $afterVersion,
                    ],
                );
            },
        );
    }

    private function authorizeActor(
        Connection $connection,
        int $actorAdministratorId,
        string $permissionCode,
    ): bool {
        /** @var object{status: string, is_owner: int|bool}|null $actor */
        $actor = $connection->table('administrators')
            ->where('id', $actorAdministratorId)
            ->lockForUpdate()
            ->first(['status', 'is_owner']);

        if ($actor === null || $actor->status !== 'active') {
            throw new AuthorizationException('Administrator authorization failed.');
        }

        $this->authorizer->authorize($actorAdministratorId, $permissionCode);

        return (bool) $actor->is_owner;
    }

    /** @return object{status: string, is_owner: int|bool, permission_version: int|string} */
    private function manageableTarget(Connection $connection, int $administratorId): object
    {
        /** @var object{status: string, is_owner: int|bool, permission_version: int|string}|null $target */
        $target = $connection->table('administrators')
            ->where('id', $administratorId)
            ->lockForUpdate()
            ->first(['status', 'is_owner', 'permission_version']);

        if ($target === null) {
            throw new RuntimeException('Administrator does not exist.');
        }

        if ((bool) $target->is_owner) {
            throw new AuthorizationException('Owner access requires the ownership transfer flow.');
        }

        return $target;
    }

    private function assertNotSelfManaged(
        int $actorAdministratorId,
        int $targetAdministratorId,
        bool $actorIsOwner,
    ): void {
        if (! $actorIsOwner && $actorAdministratorId === $targetAdministratorId) {
            throw new AuthorizationException('Administrators cannot change their own access.');
        }
    }

    private function assertRoleDelegationCeiling(
        Connection $connection,
        int $actorAdministratorId,
        int $roleId,
    ): void {
        /** @var list<string> $permissionCodes */
        $permissionCodes = $connection->table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $roleId)
            ->pluck('permissions.code')
            ->filter(static fn (mixed $code): bool => is_string($code))
            ->values()
            ->all();

        foreach ($permissionCodes as $permissionCode) {
            $this->authorizer->authorize($actorAdministratorId, $permissionCode);
        }
    }

    private function roleAllows(
        Connection $connection,
        int $administratorId,
        int $permissionId,
    ): bool {
        return $connection->table('administrator_role_assignments')
            ->join('roles', 'roles.id', '=', 'administrator_role_assignments.role_id')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->where('administrator_role_assignments.administrator_id', $administratorId)
            ->whereNull('administrator_role_assignments.revoked_at')
            ->where('roles.is_active', true)
            ->where('role_permissions.permission_id', $permissionId)
            ->exists();
    }

    private function effectAllows(bool $roleAllows, PermissionEffect $effect): bool
    {
        return match ($effect) {
            PermissionEffect::Deny => false,
            PermissionEffect::Allow => true,
            PermissionEffect::Inherit => $roleAllows,
        };
    }

    private function nextPermissionVersion(
        Connection $connection,
        int $administratorId,
        int $beforeVersion,
        bool $changed,
    ): int {
        if (! $changed) {
            return $beforeVersion;
        }

        $afterVersion = $beforeVersion + 1;
        $connection->table('administrators')
            ->where('id', $administratorId)
            ->update([
                'permission_version' => $afterVersion,
                'updated_at' => $this->timestamp(),
            ]);

        return $afterVersion;
    }

    private function assertReceiptValue(
        AccessMutationReceipt $receipt,
        string $key,
        bool|string $expected,
    ): void {
        if (($receipt->after[$key] ?? null) !== $expected) {
            throw new RuntimeException('Access mutation fingerprint conflict.');
        }
    }

    /**
     * @param  callable(Connection): AccessMutationReceipt  $operation
     */
    private function idempotentTransaction(
        string $action,
        int $targetAdministratorId,
        AccessChangeContext $context,
        callable $operation,
    ): AccessMutationReceipt {
        try {
            return $this->database->connection()->transaction(
                fn (): AccessMutationReceipt => $operation($this->database->connection()),
            );
        } catch (QueryException $exception) {
            $existing = $this->audit->existing(
                $action,
                $targetAdministratorId,
                $context->requestFingerprint,
            );
            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
