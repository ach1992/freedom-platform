<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use App\Modules\AccessControl\Domain\AdministratorStatus;
use App\Shared\Application\Clock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class AdministratorLifecycleService
{
    private const MANAGE_PERMISSION = 'admins.accounts.manage';

    public function __construct(
        private DatabaseManager $database,
        private AdministratorPermissionAuthorizer $authorizer,
        private AccessMutationAudit $audit,
        private Clock $clock,
    ) {}

    /** @requirement ADM-001 ACL-002 SEC-002 QUA-001 */
    public function suspend(int $administratorId, AccessChangeContext $context): AccessMutationReceipt
    {
        return $this->transition($administratorId, AdministratorStatus::Suspended, $context);
    }

    /** @requirement ADM-001 ACL-002 SEC-002 QUA-001 */
    public function reactivate(int $administratorId, AccessChangeContext $context): AccessMutationReceipt
    {
        return $this->transition($administratorId, AdministratorStatus::Active, $context);
    }

    /** @requirement ADM-001 ACL-002 SEC-002 QUA-001 */
    public function revoke(int $administratorId, AccessChangeContext $context): AccessMutationReceipt
    {
        return $this->transition($administratorId, AdministratorStatus::Revoked, $context);
    }

    private function transition(
        int $administratorId,
        AdministratorStatus $next,
        AccessChangeContext $context,
    ): AccessMutationReceipt {
        if ($administratorId < 1) {
            throw new RuntimeException('Administrator lifecycle target is invalid.');
        }

        $actorId = $context->actorAdministratorId;
        $context->requireReason();
        if ($actorId === $administratorId) {
            throw new AuthorizationException('Administrator self-management is not allowed.');
        }

        $this->authorizer->authorize($actorId, self::MANAGE_PERMISSION);
        $action = 'access.administrator.'.$next->value;
        $existing = $this->audit->existing($action, $administratorId, $context->requestFingerprint);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $administratorId,
                $next,
                $context,
                $action,
                $actorId,
            ): AccessMutationReceipt {
                $existing = $this->audit->existing(
                    $action,
                    $administratorId,
                    $context->requestFingerprint,
                    true,
                );
                if ($existing !== null) {
                    return $existing;
                }

                $this->authorizer->authorize($actorId, self::MANAGE_PERMISSION);

                /** @var object{status: string, is_owner: int|bool, permission_version: int|string}|null $row */
                $row = $connection->table('administrators')
                    ->where('id', $administratorId)
                    ->lockForUpdate()
                    ->first(['status', 'is_owner', 'permission_version']);
                if ($row === null) {
                    throw new RuntimeException('Administrator does not exist.');
                }
                if ((bool) $row->is_owner) {
                    throw new AuthorizationException('Owner lifecycle changes require the ownership-transfer flow.');
                }

                $current = AdministratorStatus::tryFrom($row->status)
                    ?? throw new RuntimeException('Administrator status is invalid.');
                if (! $current->canTransitionTo($next)) {
                    throw new RuntimeException('Administrator lifecycle transition is not allowed.');
                }

                $now = $this->timestamp();
                $permissionVersion = (int) $row->permission_version + 1;
                $revokedRoles = 0;
                $removedOverrides = 0;
                if ($next === AdministratorStatus::Revoked) {
                    $revokedRoles = $connection->table('administrator_role_assignments')
                        ->where('administrator_id', $administratorId)
                        ->whereNull('revoked_at')
                        ->update([
                            'revoked_at' => $now,
                            'updated_at' => $now,
                        ]);
                    $removedOverrides = $connection->table('administrator_permission_overrides')
                        ->where('administrator_id', $administratorId)
                        ->delete();
                }

                $connection->table('administrators')->where('id', $administratorId)->update([
                    'status' => $next->value,
                    'permission_version' => $permissionVersion,
                    'last_authenticated_at' => $next === AdministratorStatus::Active
                        ? $row->status === AdministratorStatus::Suspended->value ? null : null
                        : null,
                    'suspended_at' => $next === AdministratorStatus::Suspended ? $now : null,
                    'revoked_at' => $next === AdministratorStatus::Revoked ? $now : null,
                    'status_reason_code' => $context->reasonCode,
                    'updated_at' => $now,
                ]);

                $connection->table('administrator_status_histories')->insert([
                    'administrator_id' => $administratorId,
                    'from_status' => $current->value,
                    'to_status' => $next->value,
                    'actor_administrator_id' => $actorId,
                    'reason_code' => $context->reasonCode,
                    'reason' => $context->reason,
                    'correlation_id' => $context->correlationId,
                    'permission_version' => $permissionVersion,
                    'revoked_role_count' => $revokedRoles,
                    'removed_override_count' => $removedOverrides,
                    'created_at' => $now,
                ]);

                return $this->audit->record(
                    $connection,
                    $action,
                    $administratorId,
                    $context,
                    [
                        'status' => $current->value,
                        'permission_version' => (int) $row->permission_version,
                    ],
                    [
                        'status' => $next->value,
                        'permission_version' => $permissionVersion,
                        'revoked_role_count' => $revokedRoles,
                        'removed_override_count' => $removedOverrides,
                    ],
                );
            });
        } catch (QueryException $exception) {
            $existing = $this->audit->existing($action, $administratorId, $context->requestFingerprint);
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
