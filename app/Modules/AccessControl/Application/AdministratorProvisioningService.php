<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use App\Shared\Application\Clock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class AdministratorProvisioningService
{
    private const MANAGE_PERMISSION = 'admins.accounts.manage';

    public function __construct(
        private DatabaseManager $database,
        private AdministratorPermissionAuthorizer $authorizer,
        private Clock $clock,
    ) {}

    /** @requirement ADM-002 ACL-001 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function enableUser(string $userPublicId, AccessChangeContext $context): AccessMutationReceipt
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $userPublicId) !== 1) {
            throw new RuntimeException('Administrator enable target public ID is invalid.');
        }
        $context->requireReason();

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $userPublicId,
                $context,
            ): AccessMutationReceipt {
                $this->authorizeActor($connection, $context->actorAdministratorId);

                $existing = $this->existing($connection, $context->requestFingerprint, $userPublicId, true);
                if ($existing !== null) {
                    return $existing;
                }

                $user = $connection->table('users')
                    ->where('public_id', $userPublicId)
                    ->where('account_status', '<>', 'deleted')
                    ->lockForUpdate()
                    ->first(['id']);
                if ($user === null) {
                    throw new RuntimeException('Administrator enable target user does not exist.');
                }

                $userId = $this->positiveInt($user->id ?? null, 'Administrator enable target user ID');
                if ($connection->table('administrators')->where('user_id', $userId)->exists()) {
                    throw new RuntimeException('Administrator already exists for this user.');
                }

                $now = $this->timestamp();
                $administratorId = (int) $connection->table('administrators')->insertGetId([
                    'user_id' => $userId,
                    'status' => 'active',
                    'is_owner' => false,
                    'permission_version' => 1,
                    'last_authenticated_at' => null,
                    'suspended_at' => null,
                    'revoked_at' => null,
                    'status_reason_code' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if ($administratorId < 1) {
                    throw new RuntimeException('Administrator enable identity is invalid.');
                }

                $before = [];
                $after = [
                    'user_public_id' => $userPublicId,
                    'status' => 'active',
                    'is_owner' => false,
                    'permission_version' => 1,
                ];
                $connection->table('audit_logs')->insert([
                    'actor_type' => 'administrator',
                    'actor_id' => (string) $context->actorAdministratorId,
                    'action' => 'access.administrator.enabled',
                    'target_type' => 'administrator',
                    'target_id' => (string) $administratorId,
                    'before_safe_data' => json_encode($before, JSON_THROW_ON_ERROR),
                    'after_safe_data' => json_encode($after, JSON_THROW_ON_ERROR),
                    'reason_code' => $context->reasonCode,
                    'reason' => $context->reason,
                    'correlation_id' => $context->correlationId,
                    'request_fingerprint' => $context->requestFingerprint,
                    'created_at' => $now,
                ]);

                return new AccessMutationReceipt(
                    'access.administrator.enabled',
                    $administratorId,
                    $before,
                    $after,
                    true,
                );
            });
        } catch (QueryException $exception) {
            $existing = $this->existing(
                $this->database->connection(),
                $context->requestFingerprint,
                $userPublicId,
            );
            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function authorizeActor(Connection $connection, int $administratorId): void
    {
        $actor = $connection->table('administrators')
            ->where('id', $administratorId)
            ->lockForUpdate()
            ->first(['status']);
        if ($actor === null || $actor->status !== 'active') {
            throw new AuthorizationException('Administrator authorization failed.');
        }

        $this->authorizer->authorize($administratorId, self::MANAGE_PERMISSION);
    }

    private function existing(
        Connection $connection,
        string $fingerprint,
        string $userPublicId,
        bool $lock = false,
    ): ?AccessMutationReceipt {
        $query = $connection->table('audit_logs')
            ->where('action', 'access.administrator.enabled')
            ->where('request_fingerprint', $fingerprint);
        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first(['target_type', 'target_id', 'before_safe_data', 'after_safe_data']);
        if ($row === null) {
            return null;
        }
        if ($row->target_type !== 'administrator'
            || ! is_string($row->target_id)
            || preg_match('/\A[1-9][0-9]*\z/', $row->target_id) !== 1) {
            throw new RuntimeException('Administrator enable fingerprint conflict.');
        }

        $administratorId = (int) $row->target_id;
        $storedPublicId = $connection->table('administrators as administrator')
            ->join('users as user', 'user.id', '=', 'administrator.user_id')
            ->where('administrator.id', $administratorId)
            ->value('user.public_id');
        if ($storedPublicId !== $userPublicId) {
            throw new RuntimeException('Administrator enable fingerprint conflict.');
        }

        $before = $this->decode($row->before_safe_data ?? null);
        $after = $this->decode($row->after_safe_data ?? null);

        return new AccessMutationReceipt(
            'access.administrator.enabled',
            $administratorId,
            $before,
            $after,
            $before !== $after,
            true,
        );
    }

    /** @return array<string, bool|int|string|null> */
    private function decode(mixed $encoded): array
    {
        if ($encoded === null) {
            return [];
        }
        if (! is_string($encoded)) {
            throw new RuntimeException('Stored administrator enable audit state is invalid.');
        }

        $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Stored administrator enable audit state is invalid.');
        }

        $safe = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key)
                || (! is_bool($value) && ! is_int($value) && ! is_string($value) && $value !== null)) {
                throw new RuntimeException('Stored administrator enable audit state is invalid.');
            }
            $safe[$key] = $value;
        }

        return $safe;
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
