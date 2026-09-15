<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use App\Modules\AccessControl\Domain\SensitiveApprovalState;
use App\Shared\Application\Clock;
use DateInterval;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-type SensitiveApprovalRow object{
 *     id: string,
 *     requested_by_administrator_id: int|string,
 *     permission_id: int|string|null,
 *     decided_by_administrator_id: int|string|null,
 *     action: string,
 *     target_type: ?string,
 *     target_id: ?string,
 *     request_fingerprint: string,
 *     independent_approval_required: int|bool,
 *     requester_permission_version: int|string|null,
 *     approver_permission_version: int|string|null,
 *     state: string,
 *     expires_at: string,
 *     execution_fingerprint: ?string,
 *     consumed_by_administrator_id: int|string|null,
 *     consumed_at: ?string
 * }
 */
final readonly class SensitiveActionApprovalService
{
    private const APPROVE_PERMISSION = 'access.sensitive_actions.approve';

    private const MINIMUM_TTL_SECONDS = 60;

    private const MAXIMUM_TTL_SECONDS = 900;

    public function __construct(
        private DatabaseManager $database,
        private AdministratorPermissionAuthorizer $authorizer,
        private SensitiveApprovalAudit $audit,
        private Clock $clock,
    ) {}

    /** @requirement ACL-003 SEC-002 QUA-001 */
    public function request(
        string $permissionCode,
        string $action,
        ?string $targetType,
        ?string $targetId,
        bool $independentApprovalRequired,
        int $ttlSeconds,
        AccessChangeContext $context,
    ): SensitiveApprovalReceipt {
        $this->assertFingerprint($context->requestFingerprint);
        $this->assertPermissionCode($permissionCode);
        $this->assertBinding($action, $targetType, $targetId);
        $context->requireReason();

        if ($ttlSeconds < self::MINIMUM_TTL_SECONDS || $ttlSeconds > self::MAXIMUM_TTL_SECONDS) {
            throw new RuntimeException('Sensitive approval lifetime is invalid.');
        }

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $permissionCode,
                $action,
                $targetType,
                $targetId,
                $independentApprovalRequired,
                $ttlSeconds,
                $context,
            ): SensitiveApprovalReceipt {
                $existing = $this->requestByFingerprint(
                    $connection,
                    $context->requestFingerprint,
                    true,
                );
                if ($existing !== null) {
                    $this->assertRequestMatches(
                        $existing,
                        $permissionCode,
                        $action,
                        $targetType,
                        $targetId,
                        $independentApprovalRequired,
                        $context->actorAdministratorId,
                    );

                    return $this->receiptFromRow('access.sensitive.request', $existing, true);
                }

                $requester = $this->activeAdministrator(
                    $connection,
                    $context->actorAdministratorId,
                    true,
                );
                $this->authorizer->authorize($context->actorAdministratorId, $permissionCode);

                /** @var object{id: int|string, requires_approval: int|bool}|null $permission */
                $permission = $connection->table('permissions')
                    ->where('code', $permissionCode)
                    ->lockForUpdate()
                    ->first(['id', 'requires_approval']);

                if ($permission === null || ! (bool) $permission->requires_approval) {
                    throw new RuntimeException('Permission is not configured for sensitive approval.');
                }

                $approvalId = (string) Str::ulid();
                $now = $this->clock->now();
                $timestamp = $now->format('Y-m-d H:i:s.u');
                $expiresAt = $now->add(new DateInterval('PT'.$ttlSeconds.'S'))->format('Y-m-d H:i:s.u');

                $connection->table('sensitive_action_approvals')->insert([
                    'id' => $approvalId,
                    'requested_by_administrator_id' => $context->actorAdministratorId,
                    'permission_id' => (int) $permission->id,
                    'decided_by_administrator_id' => null,
                    'action' => $action,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'request_fingerprint' => $context->requestFingerprint,
                    'request_reason_code' => $context->reasonCode,
                    'request_reason' => $context->reason,
                    'correlation_id' => $context->correlationId,
                    'independent_approval_required' => $independentApprovalRequired,
                    'requester_permission_version' => (int) $requester->permission_version,
                    'approver_permission_version' => null,
                    'state' => SensitiveApprovalState::Pending->value,
                    'decision_reason_code' => null,
                    'decision_reason' => null,
                    'expires_at' => $expiresAt,
                    'decided_at' => null,
                    'execution_fingerprint' => null,
                    'consumed_by_administrator_id' => null,
                    'consumed_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                return $this->audit->record(
                    $connection,
                    'access.sensitive.request',
                    $approvalId,
                    $context,
                    [],
                    [
                        'state' => SensitiveApprovalState::Pending->value,
                        'consumed' => false,
                        'permission_code' => $permissionCode,
                        'action' => $action,
                        'independent_approval_required' => $independentApprovalRequired,
                        'requester_permission_version' => (int) $requester->permission_version,
                    ],
                );
            });
        } catch (QueryException $exception) {
            $existing = $this->requestByFingerprint(
                $this->database->connection(),
                $context->requestFingerprint,
            );
            if ($existing !== null) {
                $this->assertRequestMatches(
                    $existing,
                    $permissionCode,
                    $action,
                    $targetType,
                    $targetId,
                    $independentApprovalRequired,
                    $context->actorAdministratorId,
                );

                return $this->receiptFromRow('access.sensitive.request', $existing, true);
            }

            throw $exception;
        }
    }

    /** @requirement ACL-003 SEC-002 QUA-001 */
    public function approve(string $approvalId, AccessChangeContext $context): SensitiveApprovalReceipt
    {
        return $this->decide($approvalId, SensitiveApprovalState::Approved, $context);
    }

    /** @requirement ACL-003 SEC-002 QUA-001 */
    public function reject(string $approvalId, AccessChangeContext $context): SensitiveApprovalReceipt
    {
        return $this->decide($approvalId, SensitiveApprovalState::Rejected, $context);
    }

    /** @requirement ACL-003 SEC-002 QUA-001 */
    public function cancel(string $approvalId, AccessChangeContext $context): SensitiveApprovalReceipt
    {
        $this->assertApprovalId($approvalId);
        $this->assertFingerprint($context->requestFingerprint);
        $context->requireReason();
        $action = 'access.sensitive.cancel';

        return $this->idempotentAuditTransaction(
            $action,
            $approvalId,
            $context,
            function (Connection $connection) use ($approvalId, $context, $action): SensitiveApprovalReceipt {
                $row = $this->approval($connection, $approvalId, true);

                if ((int) $row->requested_by_administrator_id !== $context->actorAdministratorId) {
                    throw new AuthorizationException('Only the requester can cancel a sensitive approval.');
                }

                $this->activeAdministrator($connection, $context->actorAdministratorId, true);
                $state = $this->state($row->state);

                if ($state !== SensitiveApprovalState::Pending) {
                    throw new RuntimeException('Sensitive approval is already terminal.');
                }

                $next = $this->isExpired($row->expires_at)
                    ? SensitiveApprovalState::Expired
                    : SensitiveApprovalState::Cancelled;
                $now = $this->timestamp();

                $connection->table('sensitive_action_approvals')
                    ->where('id', $approvalId)
                    ->update([
                        'state' => $next->value,
                        'decision_reason_code' => $next === SensitiveApprovalState::Expired
                            ? 'approval_expired'
                            : $context->reasonCode,
                        'decision_reason' => $next === SensitiveApprovalState::Expired
                            ? null
                            : $context->reason,
                        'decided_at' => $now,
                        'updated_at' => $now,
                    ]);

                return $this->audit->record(
                    $connection,
                    $action,
                    $approvalId,
                    $context,
                    $this->safeState($row),
                    [
                        'state' => $next->value,
                        'consumed' => false,
                        'action' => $row->action,
                    ],
                );
            },
        );
    }

    /** @requirement ACL-003 SEC-002 QUA-001 */
    public function consume(
        string $approvalId,
        string $action,
        ?string $targetType,
        ?string $targetId,
        AccessChangeContext $context,
    ): SensitiveApprovalReceipt {
        $this->assertApprovalId($approvalId);
        $this->assertFingerprint($context->requestFingerprint);
        $this->assertBinding($action, $targetType, $targetId);
        $auditAction = 'access.sensitive.consume';

        return $this->idempotentAuditTransaction(
            $auditAction,
            $approvalId,
            $context,
            function (Connection $connection) use (
                $approvalId,
                $action,
                $targetType,
                $targetId,
                $context,
                $auditAction,
            ): SensitiveApprovalReceipt {
                $row = $this->approval($connection, $approvalId, true);
                $this->assertBoundAction($row, $action, $targetType, $targetId);

                if ((int) $row->requested_by_administrator_id !== $context->actorAdministratorId) {
                    throw new AuthorizationException('Sensitive approval execution is bound to its requester.');
                }

                if ($this->state($row->state) !== SensitiveApprovalState::Approved) {
                    throw new RuntimeException('Sensitive approval is not approved.');
                }

                if ($this->isExpired($row->expires_at)) {
                    throw new RuntimeException('Sensitive approval has expired.');
                }

                if ($row->consumed_at !== null) {
                    if ($row->execution_fingerprint === $context->requestFingerprint) {
                        return $this->receiptFromRow($auditAction, $row, true);
                    }

                    throw new RuntimeException('Sensitive approval has already been consumed.');
                }

                $permissionCode = $this->permissionCode($connection, (int) $row->permission_id);
                $this->activeAdministrator($connection, $context->actorAdministratorId, true);
                $this->authorizer->authorize($context->actorAdministratorId, $permissionCode);
                $now = $this->timestamp();

                $connection->table('sensitive_action_approvals')
                    ->where('id', $approvalId)
                    ->update([
                        'execution_fingerprint' => $context->requestFingerprint,
                        'consumed_by_administrator_id' => $context->actorAdministratorId,
                        'consumed_at' => $now,
                        'updated_at' => $now,
                    ]);

                return $this->audit->record(
                    $connection,
                    $auditAction,
                    $approvalId,
                    $context,
                    $this->safeState($row),
                    [
                        'state' => SensitiveApprovalState::Approved->value,
                        'consumed' => true,
                        'action' => $action,
                        'permission_code' => $permissionCode,
                    ],
                );
            },
        );
    }

    private function decide(
        string $approvalId,
        SensitiveApprovalState $decision,
        AccessChangeContext $context,
    ): SensitiveApprovalReceipt {
        $this->assertApprovalId($approvalId);
        $this->assertFingerprint($context->requestFingerprint);
        $context->requireReason();

        if (! in_array($decision, [SensitiveApprovalState::Approved, SensitiveApprovalState::Rejected], true)) {
            throw new RuntimeException('Sensitive approval decision is invalid.');
        }

        $action = $decision === SensitiveApprovalState::Approved
            ? 'access.sensitive.approve'
            : 'access.sensitive.reject';

        return $this->idempotentAuditTransaction(
            $action,
            $approvalId,
            $context,
            function (Connection $connection) use (
                $approvalId,
                $decision,
                $context,
                $action,
            ): SensitiveApprovalReceipt {
                $approver = $this->activeAdministrator(
                    $connection,
                    $context->actorAdministratorId,
                    true,
                );
                $this->authorizer->authorize($context->actorAdministratorId, self::APPROVE_PERMISSION);
                $row = $this->approval($connection, $approvalId, true);
                $state = $this->state($row->state);

                if ($state !== SensitiveApprovalState::Pending) {
                    throw new RuntimeException('Sensitive approval is already terminal.');
                }

                $next = $decision;
                $decidedBy = $context->actorAdministratorId;
                $reasonCode = $context->reasonCode;
                $reason = $context->reason;

                if ($this->isExpired($row->expires_at)) {
                    $next = SensitiveApprovalState::Expired;
                    $decidedBy = null;
                    $reasonCode = 'approval_expired';
                    $reason = null;
                } elseif ((bool) $row->independent_approval_required
                    && (int) $row->requested_by_administrator_id === $context->actorAdministratorId) {
                    throw new AuthorizationException('Independent sensitive approval cannot be self-approved.');
                }

                $now = $this->timestamp();
                $connection->table('sensitive_action_approvals')
                    ->where('id', $approvalId)
                    ->update([
                        'state' => $next->value,
                        'decided_by_administrator_id' => $decidedBy,
                        'approver_permission_version' => $decidedBy === null
                            ? null
                            : (int) $approver->permission_version,
                        'decision_reason_code' => $reasonCode,
                        'decision_reason' => $reason,
                        'decided_at' => $now,
                        'updated_at' => $now,
                    ]);

                return $this->audit->record(
                    $connection,
                    $action,
                    $approvalId,
                    $context,
                    $this->safeState($row),
                    [
                        'state' => $next->value,
                        'consumed' => false,
                        'action' => $row->action,
                        'approver_permission_version' => $decidedBy === null
                            ? null
                            : (int) $approver->permission_version,
                    ],
                );
            },
        );
    }

    /**
     * @param  callable(Connection): SensitiveApprovalReceipt  $operation
     */
    private function idempotentAuditTransaction(
        string $action,
        string $approvalId,
        AccessChangeContext $context,
        callable $operation,
    ): SensitiveApprovalReceipt {
        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $action,
                $approvalId,
                $context,
                $operation,
            ): SensitiveApprovalReceipt {
                $existing = $this->audit->existing(
                    $action,
                    $approvalId,
                    $context->requestFingerprint,
                    true,
                );

                return $existing ?? $operation($connection);
            });
        } catch (QueryException $exception) {
            $existing = $this->audit->existing(
                $action,
                $approvalId,
                $context->requestFingerprint,
            );
            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    /** @return object{status: string, permission_version: int|string} */
    private function activeAdministrator(
        Connection $connection,
        int $administratorId,
        bool $lock,
    ): object {
        $query = $connection->table('administrators')->where('id', $administratorId);

        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var object{status: string, permission_version: int|string}|null $administrator */
        $administrator = $query->first(['status', 'permission_version']);

        if ($administrator === null || $administrator->status !== 'active') {
            throw new AuthorizationException('Administrator authorization failed.');
        }

        return $administrator;
    }

    /** @return SensitiveApprovalRow */
    private function approval(Connection $connection, string $approvalId, bool $lock): object
    {
        $query = $connection->table('sensitive_action_approvals')->where('id', $approvalId);

        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var SensitiveApprovalRow|null $row */
        $row = $query->first([
            'id',
            'requested_by_administrator_id',
            'permission_id',
            'decided_by_administrator_id',
            'action',
            'target_type',
            'target_id',
            'request_fingerprint',
            'independent_approval_required',
            'requester_permission_version',
            'approver_permission_version',
            'state',
            'expires_at',
            'execution_fingerprint',
            'consumed_by_administrator_id',
            'consumed_at',
        ]);

        if ($row === null) {
            throw new RuntimeException('Sensitive approval does not exist.');
        }

        return $row;
    }

    /** @return SensitiveApprovalRow|null */
    private function requestByFingerprint(
        Connection $connection,
        string $requestFingerprint,
        bool $lock = false,
    ): ?object {
        $query = $connection->table('sensitive_action_approvals')
            ->where('request_fingerprint', $requestFingerprint);

        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var SensitiveApprovalRow|null $row */
        $row = $query->first([
            'id',
            'requested_by_administrator_id',
            'permission_id',
            'decided_by_administrator_id',
            'action',
            'target_type',
            'target_id',
            'request_fingerprint',
            'independent_approval_required',
            'requester_permission_version',
            'approver_permission_version',
            'state',
            'expires_at',
            'execution_fingerprint',
            'consumed_by_administrator_id',
            'consumed_at',
        ]);

        return $row;
    }

    /** @param SensitiveApprovalRow $row */
    private function assertRequestMatches(
        object $row,
        string $permissionCode,
        string $action,
        ?string $targetType,
        ?string $targetId,
        bool $independentApprovalRequired,
        int $requesterId,
    ): void {
        $storedPermissionCode = $this->permissionCode(
            $this->database->connection(),
            (int) $row->permission_id,
        );

        if ($storedPermissionCode !== $permissionCode
            || $row->action !== $action
            || $row->target_type !== $targetType
            || $row->target_id !== $targetId
            || (bool) $row->independent_approval_required !== $independentApprovalRequired
            || (int) $row->requested_by_administrator_id !== $requesterId) {
            throw new RuntimeException('Sensitive approval fingerprint conflict.');
        }
    }

    /** @param SensitiveApprovalRow $row */
    private function assertBoundAction(
        object $row,
        string $action,
        ?string $targetType,
        ?string $targetId,
    ): void {
        if ($row->action !== $action || $row->target_type !== $targetType || $row->target_id !== $targetId) {
            throw new RuntimeException('Sensitive approval binding does not match.');
        }
    }

    private function permissionCode(Connection $connection, int $permissionId): string
    {
        $permissionCode = $connection->table('permissions')
            ->where('id', $permissionId)
            ->value('code');

        if (! is_string($permissionCode)) {
            throw new RuntimeException('Sensitive approval permission is invalid.');
        }

        return $permissionCode;
    }

    /** @param SensitiveApprovalRow $row */
    private function receiptFromRow(
        string $action,
        object $row,
        bool $replayed,
    ): SensitiveApprovalReceipt {
        return new SensitiveApprovalReceipt(
            $action,
            (string) $row->id,
            $this->state((string) $row->state),
            $row->consumed_at !== null,
            false,
            $replayed,
        );
    }

    /**
     * @param  SensitiveApprovalRow  $row
     * @return array<string, bool|int|string|null>
     */
    private function safeState(object $row): array
    {
        return [
            'state' => (string) $row->state,
            'consumed' => $row->consumed_at !== null,
            'action' => (string) $row->action,
            'requester_permission_version' => $row->requester_permission_version === null
                ? null
                : (int) $row->requester_permission_version,
            'approver_permission_version' => $row->approver_permission_version === null
                ? null
                : (int) $row->approver_permission_version,
        ];
    }

    private function state(string $state): SensitiveApprovalState
    {
        return SensitiveApprovalState::tryFrom($state)
            ?? throw new RuntimeException('Sensitive approval state is invalid.');
    }

    private function isExpired(string $expiresAt): bool
    {
        $timestamp = strtotime($expiresAt);

        return $timestamp === false || $this->clock->now()->getTimestamp() >= $timestamp;
    }

    private function assertBinding(string $action, ?string $targetType, ?string $targetId): void
    {
        if (preg_match('/\A[a-z0-9_.-]{1,128}\z/', $action) !== 1) {
            throw new RuntimeException('Sensitive action code is invalid.');
        }

        if (($targetType === null) !== ($targetId === null)) {
            throw new RuntimeException('Sensitive action target binding is incomplete.');
        }

        if ($targetType !== null && preg_match('/\A[a-z0-9_.-]{1,128}\z/', $targetType) !== 1) {
            throw new RuntimeException('Sensitive action target type is invalid.');
        }

        if ($targetId !== null && preg_match('/\A[A-Za-z0-9:_-]{1,191}\z/', $targetId) !== 1) {
            throw new RuntimeException('Sensitive action target ID is invalid.');
        }
    }

    private function assertPermissionCode(string $permissionCode): void
    {
        if (preg_match('/\A[a-z0-9_.-]{1,128}\z/', $permissionCode) !== 1) {
            throw new RuntimeException('Sensitive approval permission code is invalid.');
        }
    }

    private function assertApprovalId(string $approvalId): void
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $approvalId) !== 1) {
            throw new RuntimeException('Sensitive approval ID is invalid.');
        }
    }

    private function assertFingerprint(string $fingerprint): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1) {
            throw new RuntimeException('Sensitive approval fingerprint is invalid.');
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
