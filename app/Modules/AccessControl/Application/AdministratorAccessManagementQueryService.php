<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use App\Shared\Application\Clock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use RuntimeException;

final readonly class AdministratorAccessManagementQueryService
{
    private const MANAGEMENT_PERMISSIONS = [
        'admins.accounts.manage',
        'access.roles.manage',
        'access.permissions.override',
        'admins.transfer_ownership',
        'access.sensitive_actions.approve',
    ];

    private const APPROVE_PERMISSION = 'access.sensitive_actions.approve';

    private const TARGET_MANAGEMENT_PERMISSIONS = [
        'admins.accounts.manage',
        'access.roles.manage',
        'access.permissions.override',
        'admins.transfer_ownership',
    ];

    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $administrators,
        private Clock $clock,
    ) {}

    public function availableForUser(int $actorUserId): bool
    {
        foreach (self::MANAGEMENT_PERMISSIONS as $permission) {
            if ($this->administrators->allowsUser($actorUserId, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function actorIsOwner(int $actorUserId): bool
    {
        $administratorId = $this->activeAdministratorIdForUser($actorUserId);
        $isOwner = $this->database->connection()->table('administrators')
            ->where('id', $administratorId)
            ->value('is_owner');

        return (bool) $isOwner;
    }

    /** @requirement ADM-002 ACL-001 ACL-002 ACL-003 SEC-002 DAT-003 */
    public function searchTarget(
        int $actorUserId,
        string $botId,
        string $query,
    ): AdministratorAccessTargetSearchResult {
        $this->authorizeTargetViewer($actorUserId);
        $this->assertBotId($botId);

        $normalized = trim($query);
        if ($normalized === ''
            || strlen($normalized) > 128
            || ! mb_check_encoding($normalized, 'UTF-8')
            || str_contains($normalized, "\0")) {
            return AdministratorAccessTargetSearchResult::notFound();
        }

        $builder = $this->targetQuery($botId);
        if (str_starts_with($normalized, '@')) {
            $username = substr($normalized, 1);
            if (preg_match('/\A[A-Za-z0-9_]{5,32}\z/', $username) !== 1) {
                return AdministratorAccessTargetSearchResult::notFound();
            }
            $builder->where('account.username', $username);
        } else {
            $publicId = preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $normalized) === 1
                ? strtoupper($normalized)
                : null;
            $telegramUserId = preg_match('/\A[1-9][0-9]{0,19}\z/', $normalized) === 1
                ? $normalized
                : null;
            $username = preg_match('/\A[A-Za-z0-9_]{5,32}\z/', $normalized) === 1
                ? $normalized
                : null;

            if ($publicId === null && $telegramUserId === null && $username === null) {
                return AdministratorAccessTargetSearchResult::notFound();
            }

            $builder->where(function (Builder $exact) use ($publicId, $telegramUserId, $username): void {
                if ($publicId !== null) {
                    $exact->orWhere('user.public_id', $publicId);
                }
                if ($telegramUserId !== null) {
                    $exact->orWhere('account.telegram_user_id', $telegramUserId);
                }
                if ($username !== null) {
                    $exact->orWhere('account.username', $username);
                }
            });
        }

        $rows = $builder->orderBy('user.id')->limit(3)->get($this->targetColumns());
        if ($rows->isEmpty()) {
            return AdministratorAccessTargetSearchResult::notFound();
        }

        $byUser = [];
        foreach ($rows as $row) {
            $byUser[$this->positiveInt($row->internal_user_id ?? null, 'Administrator access target user ID')] = $row;
        }
        if (count($byUser) !== 1) {
            return AdministratorAccessTargetSearchResult::ambiguous();
        }

        return AdministratorAccessTargetSearchResult::matched(
            $this->target($actorUserId, $botId, array_values($byUser)[0]),
        );
    }

    /** @requirement ADM-002 ACL-001 ACL-002 SEC-002 DAT-003 */
    public function resolveTarget(
        int $actorUserId,
        string $botId,
        string $selectionToken,
    ): AdministratorAccessTarget {
        $this->authorizeTargetViewer($actorUserId);
        $this->assertBotId($botId);
        if (preg_match('/\A[0-9a-f]{40}\z/', $selectionToken) !== 1) {
            throw new AuthorizationException('Administrator access target is unavailable.');
        }

        $row = $this->targetQuery($botId)
            ->whereRaw(
                "LEFT(SHA2(CONCAT('telegram-admin-access-target-v1:', ?, ':', ?, ':', user.public_id), 256), 40) = ?",
                [(string) $actorUserId, $botId, $selectionToken],
            )
            ->first($this->targetColumns());
        if ($row === null) {
            throw new AuthorizationException('Administrator access target is unavailable.');
        }

        return $this->target($actorUserId, $botId, $row);
    }

    /** @requirement ADM-002 ACL-001 ACL-002 ACL-003 SEC-002 DAT-003 */
    public function forUserPublicId(int $actorUserId, string $userPublicId): AdministratorAccessManagementSnapshot
    {
        $this->authorizeTargetViewer($actorUserId);
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $userPublicId) !== 1) {
            throw new RuntimeException('Administrator target public ID is invalid.');
        }

        $connection = $this->database->connection();
        $row = $connection->table('administrators as administrator')
            ->join('users as user', 'user.id', '=', 'administrator.user_id')
            ->where('user.public_id', strtoupper($userPublicId))
            ->first([
                'administrator.id',
                'administrator.status',
                'administrator.is_owner',
                'administrator.permission_version',
                'administrator.last_authenticated_at',
                'user.public_id',
            ]);

        if ($row === null) {
            throw new RuntimeException('Administrator target does not exist.');
        }

        $administratorId = $this->positiveInt($row->id ?? null, 'Administrator target ID');
        $roles = $connection->table('administrator_role_assignments as assignment')
            ->join('roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('assignment.administrator_id', $administratorId)
            ->whereNull('assignment.revoked_at')
            ->orderBy('role.code')
            ->pluck('role.code')
            ->map(fn (mixed $value): string => $this->token($value, 64, 'Administrator role code'))
            ->values()
            ->all();

        $overrides = $connection->table('administrator_permission_overrides as override_row')
            ->join('permissions as permission', 'permission.id', '=', 'override_row.permission_id')
            ->where('override_row.administrator_id', $administratorId)
            ->orderBy('permission.code')
            ->get(['permission.code', 'override_row.effect'])
            ->map(function (object $override): string {
                return $this->token($override->code ?? null, 128, 'Administrator override permission')
                    .'='.$this->token($override->effect ?? null, 16, 'Administrator override effect');
            })
            ->values()
            ->all();

        $audit = $connection->table('audit_logs')
            ->where('target_type', 'administrator')
            ->where('target_id', (string) $administratorId)
            ->orderByDesc('id')
            ->limit(8)
            ->pluck('action')
            ->map(fn (mixed $value): string => $this->token($value, 191, 'Administrator audit action'))
            ->values()
            ->all();

        $lastAuthenticatedAt = $row->last_authenticated_at ?? null;
        if ($lastAuthenticatedAt !== null && ! is_string($lastAuthenticatedAt)) {
            throw new RuntimeException('Administrator last-authenticated timestamp is invalid.');
        }

        return new AdministratorAccessManagementSnapshot(
            $administratorId,
            $this->ulid($row->public_id ?? null, 'Administrator target public ID'),
            $this->token($row->status ?? null, 32, 'Administrator target status'),
            (bool) ($row->is_owner ?? false),
            $this->positiveInt($row->permission_version ?? null, 'Administrator permission version'),
            $lastAuthenticatedAt,
            array_values($roles),
            array_values($overrides),
            array_values($audit),
        );
    }

    /** @return list<AdministratorRoleCatalogItem> */
    public function roles(int $actorUserId): array
    {
        $this->authorizeRoleViewer($actorUserId);
        $connection = $this->database->connection();
        $rows = $connection->table('roles')->orderBy('code')->get(['id', 'code', 'is_system', 'is_active']);
        $items = [];
        foreach ($rows as $row) {
            $roleId = $this->positiveInt($row->id ?? null, 'Role ID');
            $permissions = $connection->table('role_permissions as role_permission')
                ->join('permissions as permission', 'permission.id', '=', 'role_permission.permission_id')
                ->where('role_permission.role_id', $roleId)
                ->orderBy('permission.code')
                ->pluck('permission.code')
                ->map(fn (mixed $value): string => $this->token($value, 128, 'Role permission code'))
                ->values()
                ->all();

            $items[] = new AdministratorRoleCatalogItem(
                $this->token($row->code ?? null, 64, 'Role code'),
                (bool) ($row->is_system ?? false),
                (bool) ($row->is_active ?? false),
                array_values($permissions),
            );
        }

        return $items;
    }

    /** @return list<AdministratorPermissionCatalogItem> */
    public function permissions(int $actorUserId): array
    {
        $this->authorizePermissionViewer($actorUserId);
        $rows = $this->database->connection()->table('permissions')
            ->orderBy('code')
            ->get(['code', 'module', 'risk_level', 'requires_approval']);

        $items = [];
        foreach ($rows as $row) {
            $items[] = new AdministratorPermissionCatalogItem(
                $this->token($row->code ?? null, 128, 'Permission code'),
                $this->token($row->module ?? null, 64, 'Permission module'),
                $this->token($row->risk_level ?? null, 32, 'Permission risk level'),
                (bool) ($row->requires_approval ?? false),
            );
        }

        return $items;
    }

    public function roleSelectionToken(int $actorUserId, string $roleCode): string
    {
        $this->authorizeRoleViewer($actorUserId);
        $normalized = strtolower(trim($roleCode));
        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $normalized) !== 1
            || ! $this->database->connection()->table('roles')->where('code', $normalized)->exists()) {
            throw new RuntimeException('Role does not exist.');
        }

        return $this->selectionToken('role', $actorUserId, $normalized);
    }

    public function resolveRoleSelectionToken(int $actorUserId, string $selectionToken): string
    {
        $this->authorizeRoleViewer($actorUserId);
        $this->assertSelectionToken($selectionToken);

        $code = $this->database->connection()->table('roles')
            ->whereRaw(
                "LEFT(SHA2(CONCAT('telegram-admin-access-role-v1:', ?, ':', code), 256), 40) = ?",
                [(string) $actorUserId, $selectionToken],
            )
            ->value('code');

        return $this->token($code, 64, 'Administrator role selection');
    }

    public function permissionSelectionToken(int $actorUserId, string $permissionCode): string
    {
        $this->authorizePermissionViewer($actorUserId);
        $normalized = strtolower(trim($permissionCode));
        if (preg_match('/\A[a-z0-9_.-]{1,128}\z/', $normalized) !== 1
            || ! $this->database->connection()->table('permissions')->where('code', $normalized)->exists()) {
            throw new RuntimeException('Permission does not exist.');
        }

        return $this->selectionToken('permission', $actorUserId, $normalized);
    }

    public function resolvePermissionSelectionToken(int $actorUserId, string $selectionToken): string
    {
        $this->authorizePermissionViewer($actorUserId);
        $this->assertSelectionToken($selectionToken);

        $code = $this->database->connection()->table('permissions')
            ->whereRaw(
                "LEFT(SHA2(CONCAT('telegram-admin-access-permission-v1:', ?, ':', code), 256), 40) = ?",
                [(string) $actorUserId, $selectionToken],
            )
            ->value('code');

        return $this->token($code, 128, 'Administrator permission selection');
    }

    /** @return list<AdministratorSensitiveApprovalSummary> */
    public function pendingApprovals(int $actorUserId): array
    {
        $this->administrators->authorizeUser($actorUserId, self::APPROVE_PERMISSION);
        $rows = $this->database->connection()
            ->table('sensitive_action_approvals as approval')
            ->join('administrators as requester', 'requester.id', '=', 'approval.requested_by_administrator_id')
            ->join('users as requester_user', 'requester_user.id', '=', 'requester.user_id')
            ->where('approval.state', 'pending')
            ->where('approval.target_type', 'administrator_access_mutation')
            ->where('approval.action', 'like', 'access.telegram.%')
            ->where('approval.expires_at', '>', $this->timestamp())
            ->orderBy('approval.created_at')
            ->limit(10)
            ->get([
                'approval.id',
                'approval.state',
                'approval.action',
                'approval.request_reason',
                'approval.expires_at',
                'requester_user.public_id as requester_public_id',
            ]);

        $items = [];
        foreach ($rows as $row) {
            $approvalId = $this->ulid($row->id ?? null, 'Sensitive approval ID');
            $items[] = new AdministratorSensitiveApprovalSummary(
                $this->selectionToken('approval', $actorUserId, $approvalId),
                $this->token($row->state ?? null, 32, 'Sensitive approval state'),
                $this->token($row->action ?? null, 128, 'Sensitive approval action'),
                $this->ulid($row->requester_public_id ?? null, 'Sensitive approval requester public ID'),
                $this->safeText($row->request_reason ?? null, 1000, 'Sensitive approval reason'),
                $this->safeText($row->expires_at ?? null, 64, 'Sensitive approval expiry'),
            );
        }

        return $items;
    }

    public function approvalSelectionTokenForUser(int $actorUserId, string $approvalId): string
    {
        $actorAdministratorId = $this->activeAdministratorIdForUser($actorUserId);
        $this->assertUlid($approvalId, 'Sensitive approval ID');
        $row = $this->database->connection()->table('sensitive_action_approvals')
            ->where('id', $approvalId)
            ->where('target_type', 'administrator_access_mutation')
            ->where('action', 'like', 'access.telegram.%')
            ->first(['requested_by_administrator_id']);
        if ($row === null) {
            throw new AuthorizationException('Sensitive approval is unavailable.');
        }

        if ((int) $row->requested_by_administrator_id !== $actorAdministratorId
            && ! $this->administrators->allowsUser($actorUserId, self::APPROVE_PERMISSION)) {
            throw new AuthorizationException('Sensitive approval is unavailable.');
        }

        return $this->selectionToken('approval', $actorUserId, $approvalId);
    }

    public function resolveApprovalSelectionToken(int $actorUserId, string $selectionToken): string
    {
        $actorAdministratorId = $this->activeAdministratorIdForUser($actorUserId);
        $this->assertSelectionToken($selectionToken);
        $row = $this->database->connection()->table('sensitive_action_approvals')
            ->where('target_type', 'administrator_access_mutation')
            ->where('action', 'like', 'access.telegram.%')
            ->whereRaw(
                "LEFT(SHA2(CONCAT('telegram-admin-access-approval-v1:', ?, ':', id), 256), 40) = ?",
                [(string) $actorUserId, $selectionToken],
            )
            ->first(['id', 'requested_by_administrator_id']);
        if ($row === null) {
            throw new AuthorizationException('Sensitive approval is unavailable.');
        }
        if ((int) $row->requested_by_administrator_id !== $actorAdministratorId
            && ! $this->administrators->allowsUser($actorUserId, self::APPROVE_PERMISSION)) {
            throw new AuthorizationException('Sensitive approval is unavailable.');
        }

        return $this->ulid($row->id ?? null, 'Sensitive approval ID');
    }

    /** @return list<AdministratorOwnerTransferSummary> */
    public function ownerTransfersForUser(int $actorUserId): array
    {
        $administratorId = $this->activeAdministratorIdForUser($actorUserId);
        $rows = $this->database->connection()
            ->table('owner_transfer_requests as transfer')
            ->join('administrators as current_owner', 'current_owner.id', '=', 'transfer.current_owner_administrator_id')
            ->join('users as current_owner_user', 'current_owner_user.id', '=', 'current_owner.user_id')
            ->join('administrators as target', 'target.id', '=', 'transfer.target_administrator_id')
            ->join('users as target_user', 'target_user.id', '=', 'target.user_id')
            ->where('transfer.state', 'pending')
            ->where(function (Builder $query) use ($administratorId): void {
                $query->where('transfer.current_owner_administrator_id', $administratorId)
                    ->orWhere('transfer.target_administrator_id', $administratorId);
            })
            ->orderByDesc('transfer.created_at')
            ->limit(5)
            ->get([
                'transfer.id',
                'transfer.state',
                'transfer.current_owner_administrator_id',
                'transfer.target_administrator_id',
                'transfer.expires_at',
                'current_owner_user.public_id as current_owner_public_id',
                'target_user.public_id as target_public_id',
            ]);

        $items = [];
        foreach ($rows as $row) {
            $transferId = $this->ulid($row->id ?? null, 'Owner transfer ID');
            $ownerId = $this->positiveInt($row->current_owner_administrator_id ?? null, 'Owner transfer current Owner ID');
            $targetId = $this->positiveInt($row->target_administrator_id ?? null, 'Owner transfer target ID');
            $items[] = new AdministratorOwnerTransferSummary(
                $this->selectionToken('owner-transfer', $actorUserId, $transferId),
                $this->token($row->state ?? null, 32, 'Owner transfer state'),
                $this->ulid($row->current_owner_public_id ?? null, 'Owner transfer current Owner public ID'),
                $this->ulid($row->target_public_id ?? null, 'Owner transfer target public ID'),
                $this->safeText($row->expires_at ?? null, 64, 'Owner transfer expiry'),
                $ownerId === $administratorId,
                $targetId === $administratorId,
            );
        }

        return $items;
    }

    public function ownerTransferSelectionTokenForUser(int $actorUserId, string $transferId): string
    {
        $administratorId = $this->activeAdministratorIdForUser($actorUserId);
        $this->assertUlid($transferId, 'Owner transfer ID');
        $row = $this->database->connection()->table('owner_transfer_requests')
            ->where('id', $transferId)
            ->first(['current_owner_administrator_id', 'target_administrator_id']);
        if ($row === null
            || ($administratorId !== (int) $row->current_owner_administrator_id
                && $administratorId !== (int) $row->target_administrator_id)) {
            throw new AuthorizationException('Owner transfer is unavailable.');
        }

        return $this->selectionToken('owner-transfer', $actorUserId, $transferId);
    }

    public function resolveOwnerTransferSelectionToken(int $actorUserId, string $selectionToken): string
    {
        $administratorId = $this->activeAdministratorIdForUser($actorUserId);
        $this->assertSelectionToken($selectionToken);
        $row = $this->database->connection()->table('owner_transfer_requests')
            ->whereRaw(
                "LEFT(SHA2(CONCAT('telegram-admin-access-owner-transfer-v1:', ?, ':', id), 256), 40) = ?",
                [(string) $actorUserId, $selectionToken],
            )
            ->first(['id', 'current_owner_administrator_id', 'target_administrator_id']);
        if ($row === null
            || ($administratorId !== (int) $row->current_owner_administrator_id
                && $administratorId !== (int) $row->target_administrator_id)) {
            throw new AuthorizationException('Owner transfer is unavailable.');
        }

        return $this->ulid($row->id ?? null, 'Owner transfer ID');
    }

    private function authorizeViewer(int $actorUserId): void
    {
        if (! $this->availableForUser($actorUserId)) {
            throw new AuthorizationException('Administrator access-management visibility denied.');
        }
    }

    private function authorizeTargetViewer(int $actorUserId): void
    {
        foreach (self::TARGET_MANAGEMENT_PERMISSIONS as $permission) {
            if ($this->administrators->allowsUser($actorUserId, $permission)) {
                return;
            }
        }

        throw new AuthorizationException('Administrator target-management visibility denied.');
    }

    private function authorizeRoleViewer(int $actorUserId): void
    {
        $this->administrators->authorizeUser($actorUserId, 'access.roles.manage');
    }

    private function authorizePermissionViewer(int $actorUserId): void
    {
        if ($this->administrators->allowsUser($actorUserId, 'access.roles.manage')
            || $this->administrators->allowsUser($actorUserId, 'access.permissions.override')) {
            return;
        }

        throw new AuthorizationException('Administrator permission-catalog visibility denied.');
    }

    private function activeAdministratorIdForUser(int $actorUserId): int
    {
        if ($actorUserId < 1) {
            throw new AuthorizationException('Administrator authorization failed.');
        }

        $id = $this->database->connection()->table('administrators')
            ->where('user_id', $actorUserId)
            ->where('status', 'active')
            ->value('id');

        $normalized = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($normalized === false) {
            throw new AuthorizationException('Administrator authorization failed.');
        }

        return $normalized;
    }

    private function targetQuery(string $botId): Builder
    {
        return $this->database->connection()
            ->table('users as user')
            ->leftJoin('telegram_accounts as account', function ($join) use ($botId): void {
                $join->on('account.user_id', '=', 'user.id')
                    ->where('account.bot_id', '=', $botId);
            })
            ->leftJoin('administrators as administrator', 'administrator.user_id', '=', 'user.id')
            ->where('user.account_status', '<>', 'deleted');
    }

    /** @return list<string> */
    private function targetColumns(): array
    {
        return [
            'user.id as internal_user_id',
            'user.public_id',
            'user.account_type',
            'user.account_status',
            'account.username',
            'administrator.status as administrator_status',
            'administrator.is_owner',
        ];
    }

    private function target(int $actorUserId, string $botId, object $row): AdministratorAccessTarget
    {
        $publicId = $this->ulid($row->public_id ?? null, 'Administrator access target public ID');
        $username = $row->username ?? null;
        if ($username !== null
            && (! is_string($username) || preg_match('/\A[A-Za-z0-9_]{5,32}\z/', $username) !== 1)) {
            throw new RuntimeException('Administrator access target username is invalid.');
        }
        $administratorStatus = $row->administrator_status ?? null;
        if ($administratorStatus !== null) {
            $administratorStatus = $this->token($administratorStatus, 32, 'Administrator access target status');
        }

        return new AdministratorAccessTarget(
            $this->selectionToken('target', $actorUserId, $botId.':'.$publicId),
            $publicId,
            $this->token($row->account_type ?? null, 32, 'Administrator access target account type'),
            $this->token($row->account_status ?? null, 32, 'Administrator access target account status'),
            $username === null ? null : $this->maskUsername($username),
            $administratorStatus,
            (bool) ($row->is_owner ?? false),
        );
    }

    private function selectionToken(string $kind, int $actorUserId, string $identity): string
    {
        return substr(
            hash('sha256', "telegram-admin-access-{$kind}-v1:{$actorUserId}:{$identity}"),
            0,
            40,
        );
    }

    private function maskUsername(string $username): string
    {
        $length = strlen($username);
        if ($length <= 5) {
            return $username[0].str_repeat('*', max(1, $length - 2)).$username[$length - 1];
        }

        return substr($username, 0, 2).str_repeat('*', $length - 4).substr($username, -2);
    }

    private function assertBotId(string $botId): void
    {
        if (preg_match('/\A[1-9][0-9]{0,19}\z/', $botId) !== 1) {
            throw new RuntimeException('Administrator access bot ID is invalid.');
        }
    }

    private function assertSelectionToken(string $selectionToken): void
    {
        if (preg_match('/\A[0-9a-f]{40}\z/', $selectionToken) !== 1) {
            throw new AuthorizationException('Administrator access selection is unavailable.');
        }
    }

    private function assertUlid(string $value, string $label): void
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $value) !== 1) {
            throw new RuntimeException($label.' is invalid.');
        }
    }

    private function ulid(mixed $value, string $label): string
    {
        if (! is_string($value) || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $value) !== 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $value;
    }

    private function token(mixed $value, int $maximumLength, string $label): string
    {
        if (! is_string($value)
            || $value === ''
            || mb_strlen($value) > $maximumLength
            || ! mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $value;
    }

    private function safeText(mixed $value, int $maximumLength, string $label): string
    {
        if (! is_string($value)
            || trim($value) === ''
            || mb_strlen($value) > $maximumLength
            || ! mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $value;
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $normalized = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($normalized === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $normalized;
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
