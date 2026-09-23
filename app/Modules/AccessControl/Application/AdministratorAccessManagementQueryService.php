<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
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

    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $administrators,
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

    /** @requirement ADM-002 ACL-001 ACL-002 ACL-003 SEC-002 DAT-003 */
    public function forUserPublicId(int $actorUserId, string $userPublicId): AdministratorAccessManagementSnapshot
    {
        $this->authorizeViewer($actorUserId);
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
            $roles,
            $overrides,
            $audit,
        );
    }

    /** @return list<AdministratorRoleCatalogItem> */
    public function roles(int $actorUserId): array
    {
        $this->authorizeViewer($actorUserId);
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
                $permissions,
            );
        }

        return $items;
    }

    /** @return list<AdministratorPermissionCatalogItem> */
    public function permissions(int $actorUserId): array
    {
        $this->authorizeViewer($actorUserId);
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

    private function authorizeViewer(int $actorUserId): void
    {
        if (! $this->availableForUser($actorUserId)) {
            throw new AuthorizationException('Administrator access-management visibility denied.');
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

    private function positiveInt(mixed $value, string $label): int
    {
        $normalized = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($normalized === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $normalized;
    }
}
