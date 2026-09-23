<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

final readonly class AdministratorSensitiveMutationService
{
    private const APPROVAL_TTL_SECONDS = 600;

    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $administrators,
        private SensitiveActionApprovalService $approvals,
        private AdministratorAccessService $access,
        private AdministratorLifecycleService $lifecycle,
        private AdministratorRoleCatalogService $roles,
    ) {}

    /** @requirement ADM-002 ACL-001 ACL-002 ACL-003 SEC-002 DAT-003 QUA-001 */
    public function request(
        int $actorUserId,
        AdministratorSensitiveMutation $mutation,
        string $reason,
        string $requestKey,
    ): SensitiveApprovalReceipt {
        $actorAdministratorId = $this->administrators->authorizeUser(
            $actorUserId,
            $mutation->requiredPermission(),
        );
        $isOwner = $this->isActiveOwner($actorAdministratorId);
        $context = $this->context(
            $actorAdministratorId,
            'request',
            $requestKey,
            $mutation,
            $reason,
        );

        return $this->approvals->request(
            $mutation->requiredPermission(),
            $mutation->approvalAction(),
            'administrator_access_mutation',
            $mutation->bindingId(),
            ! $isOwner,
            self::APPROVAL_TTL_SECONDS,
            $context,
        );
    }

    /** @requirement ADM-002 ACL-003 SEC-002 */
    public function approve(
        int $actorUserId,
        string $approvalId,
        string $reason,
        string $requestKey,
    ): SensitiveApprovalReceipt {
        $actorAdministratorId = $this->administrators->authorizeUser(
            $actorUserId,
            'access.sensitive_actions.approve',
        );

        return $this->approvals->approve(
            $approvalId,
            $this->decisionContext($actorAdministratorId, 'approve', $approvalId, $requestKey, $reason),
        );
    }

    /** @requirement ADM-002 ACL-003 SEC-002 */
    public function reject(
        int $actorUserId,
        string $approvalId,
        string $reason,
        string $requestKey,
    ): SensitiveApprovalReceipt {
        $actorAdministratorId = $this->administrators->authorizeUser(
            $actorUserId,
            'access.sensitive_actions.approve',
        );

        return $this->approvals->reject(
            $approvalId,
            $this->decisionContext($actorAdministratorId, 'reject', $approvalId, $requestKey, $reason),
        );
    }

    /** @requirement ADM-002 ACL-003 SEC-002 */
    public function cancel(
        int $actorUserId,
        string $approvalId,
        string $reason,
        string $requestKey,
    ): SensitiveApprovalReceipt {
        $actorAdministratorId = $this->activeAdministratorIdForUser($actorUserId);

        return $this->approvals->cancel(
            $approvalId,
            $this->decisionContext($actorAdministratorId, 'cancel', $approvalId, $requestKey, $reason),
        );
    }

    /** @requirement ADM-002 ACL-001 ACL-002 ACL-003 SEC-002 DAT-003 QUA-001 */
    public function execute(
        int $actorUserId,
        string $approvalId,
        AdministratorSensitiveMutation $mutation,
        string $reason,
        string $requestKey,
    ): AdministratorSensitiveMutationResult {
        $actorAdministratorId = $this->administrators->authorizeUser(
            $actorUserId,
            $mutation->requiredPermission(),
        );

        return $this->database->connection()->transaction(function () use (
            $actorAdministratorId,
            $approvalId,
            $mutation,
            $reason,
            $requestKey,
        ): AdministratorSensitiveMutationResult {
            $consumeContext = $this->context(
                $actorAdministratorId,
                'consume',
                $requestKey,
                $mutation,
                $reason,
            );
            $consume = $this->approvals->consume(
                $approvalId,
                $mutation->approvalAction(),
                'administrator_access_mutation',
                $mutation->bindingId(),
                $consumeContext,
            );

            $mutationContext = $this->context(
                $actorAdministratorId,
                'execute',
                $requestKey,
                $mutation,
                $reason,
            );
            $changed = match ($mutation->operation) {
                AdministratorSensitiveMutation::ROLE_GRANT => $this->access->grantRole(
                    $this->targetAdministratorId($mutation),
                    $this->requiredRole($mutation),
                    $mutationContext,
                )->changed,
                AdministratorSensitiveMutation::ROLE_REVOKE => $this->access->revokeRole(
                    $this->targetAdministratorId($mutation),
                    $this->requiredRole($mutation),
                    $mutationContext,
                )->changed,
                AdministratorSensitiveMutation::PERMISSION_ALLOW,
                AdministratorSensitiveMutation::PERMISSION_DENY,
                AdministratorSensitiveMutation::PERMISSION_INHERIT => $this->access->setPermissionOverride(
                    $this->targetAdministratorId($mutation),
                    $this->requiredPermissionCode($mutation),
                    $mutation->permissionEffect(),
                    $mutationContext,
                )->changed,
                AdministratorSensitiveMutation::ADMINISTRATOR_SUSPEND => $this->lifecycle->suspend(
                    $this->targetAdministratorId($mutation),
                    $mutationContext,
                )->changed,
                AdministratorSensitiveMutation::ADMINISTRATOR_REACTIVATE => $this->lifecycle->reactivate(
                    $this->targetAdministratorId($mutation),
                    $mutationContext,
                )->changed,
                AdministratorSensitiveMutation::ADMINISTRATOR_REVOKE => $this->lifecycle->revoke(
                    $this->targetAdministratorId($mutation),
                    $mutationContext,
                )->changed,
                AdministratorSensitiveMutation::CUSTOM_ROLE_CREATE => $this->roles->createCustomRole(
                    $this->requiredRole($mutation),
                    $mutationContext,
                )->changed,
                AdministratorSensitiveMutation::CUSTOM_ROLE_ENABLE => $this->roles->setCustomRoleActive(
                    $this->requiredRole($mutation),
                    true,
                    $mutationContext,
                )->changed,
                AdministratorSensitiveMutation::CUSTOM_ROLE_DISABLE => $this->roles->setCustomRoleActive(
                    $this->requiredRole($mutation),
                    false,
                    $mutationContext,
                )->changed,
                AdministratorSensitiveMutation::CUSTOM_ROLE_PERMISSION_GRANT => $this->roles->setCustomRolePermission(
                    $this->requiredRole($mutation),
                    $this->requiredPermissionCode($mutation),
                    true,
                    $mutationContext,
                )->changed,
                AdministratorSensitiveMutation::CUSTOM_ROLE_PERMISSION_REVOKE => $this->roles->setCustomRolePermission(
                    $this->requiredRole($mutation),
                    $this->requiredPermissionCode($mutation),
                    false,
                    $mutationContext,
                )->changed,
                default => throw new InvalidArgumentException('Administrator sensitive mutation operation is unsupported.'),
            };

            return new AdministratorSensitiveMutationResult(
                $mutation->operation,
                $changed,
                $consume->replayed,
            );
        }, 3);
    }

    private function targetAdministratorId(AdministratorSensitiveMutation $mutation): int
    {
        if ($mutation->targetUserPublicId === null) {
            throw new InvalidArgumentException('Administrator sensitive mutation target is unavailable.');
        }

        $id = $this->database->connection()
            ->table('administrators as administrator')
            ->join('users as user', 'user.id', '=', 'administrator.user_id')
            ->where('user.public_id', $mutation->targetUserPublicId)
            ->value('administrator.id');

        $normalized = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($normalized === false) {
            throw new RuntimeException('Administrator sensitive mutation target does not exist.');
        }

        return $normalized;
    }

    private function requiredRole(AdministratorSensitiveMutation $mutation): string
    {
        return $mutation->roleCode
            ?? throw new InvalidArgumentException('Administrator sensitive mutation role is unavailable.');
    }

    private function requiredPermissionCode(AdministratorSensitiveMutation $mutation): string
    {
        return $mutation->permissionCode
            ?? throw new InvalidArgumentException('Administrator sensitive mutation permission is unavailable.');
    }

    private function isActiveOwner(int $administratorId): bool
    {
        $row = $this->database->connection()->table('administrators')
            ->where('id', $administratorId)
            ->first(['status', 'is_owner']);

        if ($row === null || $row->status !== 'active') {
            throw new AuthorizationException('Administrator authorization failed.');
        }

        return (bool) $row->is_owner;
    }

    private function activeAdministratorIdForUser(int $userId): int
    {
        if ($userId < 1) {
            throw new AuthorizationException('Administrator authorization failed.');
        }

        $id = $this->database->connection()->table('administrators')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->value('id');
        $normalized = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($normalized === false) {
            throw new AuthorizationException('Administrator authorization failed.');
        }

        return $normalized;
    }

    private function context(
        int $administratorId,
        string $phase,
        string $requestKey,
        AdministratorSensitiveMutation $mutation,
        string $reason,
    ): AccessChangeContext {
        $normalizedReason = trim($reason);
        if ($normalizedReason === '') {
            throw new InvalidArgumentException('Administrator sensitive mutation reason is required.');
        }

        $material = implode('|', [
            'telegram-admin-sensitive',
            $phase,
            $requestKey,
            $mutation->bindingId(),
        ]);

        return new AccessChangeContext(
            hash('sha256', $material),
            'tg-admin:'.substr(hash('sha256', 'correlation|'.$requestKey.'|'.$mutation->bindingId()), 0, 48),
            'telegram_admin_access_'.$phase,
            $mutation->safeSummary().' | '.$normalizedReason,
            $administratorId,
        );
    }

    private function decisionContext(
        int $administratorId,
        string $phase,
        string $approvalId,
        string $requestKey,
        string $reason,
    ): AccessChangeContext {
        $normalizedReason = trim($reason);
        if ($normalizedReason === '') {
            throw new InvalidArgumentException('Administrator sensitive approval reason is required.');
        }

        return new AccessChangeContext(
            hash('sha256', implode('|', ['telegram-admin-sensitive', $phase, $requestKey, $approvalId])),
            'tg-admin:'.substr(hash('sha256', 'correlation|'.$requestKey.'|'.$approvalId), 0, 48),
            'telegram_admin_approval_'.$phase,
            $normalizedReason,
            $administratorId,
        );
    }
}
