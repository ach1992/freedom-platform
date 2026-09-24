<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use App\Modules\AccessControl\Domain\PermissionEffect;
use InvalidArgumentException;

final readonly class AdministratorSensitiveMutation
{
    public const ROLE_GRANT = 'role_grant';

    public const ROLE_REVOKE = 'role_revoke';

    public const PERMISSION_ALLOW = 'permission_allow';

    public const PERMISSION_DENY = 'permission_deny';

    public const PERMISSION_INHERIT = 'permission_inherit';

    public const ADMINISTRATOR_SUSPEND = 'administrator_suspend';

    public const ADMINISTRATOR_REACTIVATE = 'administrator_reactivate';

    public const ADMINISTRATOR_REVOKE = 'administrator_revoke';

    public const ADMINISTRATOR_ENABLE = 'administrator_enable';

    private const OPERATIONS = [
        self::ROLE_GRANT,
        self::ROLE_REVOKE,
        self::PERMISSION_ALLOW,
        self::PERMISSION_DENY,
        self::PERMISSION_INHERIT,
        self::ADMINISTRATOR_SUSPEND,
        self::ADMINISTRATOR_REACTIVATE,
        self::ADMINISTRATOR_REVOKE,
        self::ADMINISTRATOR_ENABLE,
    ];

    public function __construct(
        public string $operation,
        public ?string $targetUserPublicId = null,
        public ?string $roleCode = null,
        public ?string $permissionCode = null,
    ) {
        if (! in_array($operation, self::OPERATIONS, true)) {
            throw new InvalidArgumentException('Administrator sensitive mutation operation is invalid.');
        }

        if ($targetUserPublicId === null
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $targetUserPublicId) !== 1) {
            throw new InvalidArgumentException('Administrator sensitive mutation target public ID is invalid.');
        }

        $needsRole = in_array($operation, [
            self::ROLE_GRANT,
            self::ROLE_REVOKE,
        ], true);
        if ($needsRole !== ($roleCode !== null)
            || ($roleCode !== null && preg_match('/\A[a-z0-9_.-]{1,64}\z/', $roleCode) !== 1)) {
            throw new InvalidArgumentException('Administrator sensitive mutation role is invalid.');
        }

        $needsPermission = in_array($operation, [
            self::PERMISSION_ALLOW,
            self::PERMISSION_DENY,
            self::PERMISSION_INHERIT,
        ], true);
        if ($needsPermission !== ($permissionCode !== null)
            || ($permissionCode !== null && preg_match('/\A[a-z0-9_.-]{1,128}\z/', $permissionCode) !== 1)) {
            throw new InvalidArgumentException('Administrator sensitive mutation permission is invalid.');
        }
    }

    public function requiredPermission(): string
    {
        return match ($this->operation) {
            self::ROLE_GRANT,
            self::ROLE_REVOKE => 'access.roles.manage',
            self::PERMISSION_ALLOW,
            self::PERMISSION_DENY,
            self::PERMISSION_INHERIT => 'access.permissions.override',
            self::ADMINISTRATOR_SUSPEND,
            self::ADMINISTRATOR_REACTIVATE,
            self::ADMINISTRATOR_REVOKE,
            self::ADMINISTRATOR_ENABLE => 'admins.accounts.manage',
            default => throw new InvalidArgumentException('Administrator sensitive mutation operation is invalid.'),
        };
    }

    public function approvalAction(): string
    {
        return 'access.telegram.'.$this->operation;
    }

    public function bindingId(): string
    {
        return 'sha256:'.hash('sha256', json_encode($this->toPayload(), JSON_THROW_ON_ERROR));
    }

    public function safeSummary(): string
    {
        return implode(' ', array_values(array_filter([
            'operation='.$this->operation,
            'target='.$this->targetUserPublicId,
            $this->roleCode === null ? null : 'role='.$this->roleCode,
            $this->permissionCode === null ? null : 'permission='.$this->permissionCode,
        ], static fn (?string $value): bool => $value !== null)));
    }

    public function permissionEffect(): PermissionEffect
    {
        return match ($this->operation) {
            self::PERMISSION_ALLOW => PermissionEffect::Allow,
            self::PERMISSION_DENY => PermissionEffect::Deny,
            self::PERMISSION_INHERIT => PermissionEffect::Inherit,
            default => throw new InvalidArgumentException('Administrator sensitive mutation has no permission effect.'),
        };
    }

    /** @return array{operation:string,target_user_public_id:string,role_code:?string,permission_code:?string} */
    public function toPayload(): array
    {
        return [
            'operation' => $this->operation,
            'target_user_public_id' => $this->targetUserPublicId,
            'role_code' => $this->roleCode,
            'permission_code' => $this->permissionCode,
        ];
    }

    /** @param array<string,mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        if (array_keys($payload) !== ['operation', 'target_user_public_id', 'role_code', 'permission_code']
            || ! is_string($payload['operation'] ?? null)
            || ! is_string($payload['target_user_public_id'] ?? null)
            || (($payload['role_code'] ?? null) !== null && ! is_string($payload['role_code']))
            || (($payload['permission_code'] ?? null) !== null && ! is_string($payload['permission_code']))) {
            throw new InvalidArgumentException('Administrator sensitive mutation payload is invalid.');
        }

        return new self(
            $payload['operation'],
            $payload['target_user_public_id'],
            $payload['role_code'],
            $payload['permission_code'],
        );
    }
}
