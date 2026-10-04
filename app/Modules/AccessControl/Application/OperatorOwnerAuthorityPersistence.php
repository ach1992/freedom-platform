<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use App\Modules\Operations\Application\Contracts\OwnerAuthorityMutator;
use Illuminate\Database\Connection;
use RuntimeException;

final readonly class OperatorOwnerAuthorityPersistence implements OwnerAuthorityMutator
{
    public function currentOwnerUserId(Connection $connection): ?int
    {
        $owners = $this->owners($connection);

        if (count($owners) > 1) {
            throw new RuntimeException('Owner singleton invariant is violated.');
        }

        return $owners === [] ? null : (int) $owners[0]->user_id;
    }

    public function bootstrap(Connection $connection, int $targetUserId, string $timestamp): array
    {
        $owners = $this->owners($connection);

        if (count($owners) > 1) {
            throw new RuntimeException('Owner singleton invariant is violated.');
        }

        $target = $this->targetAdministrator($connection, $targetUserId, $timestamp);

        if ($owners !== []) {
            if ((int) $owners[0]->id !== $target['administrator_id']) {
                throw new RuntimeException('Installer Owner conflicts with the existing database Owner.');
            }

            return [
                'changed' => false,
                'administrator_id' => $target['administrator_id'],
                'user_id' => $targetUserId,
            ];
        }

        $connection->table('administrators')->where('id', $target['administrator_id'])->update([
            'is_owner' => true,
            'permission_version' => $target['permission_version'] + 1,
            'updated_at' => $timestamp,
        ]);

        $fingerprint = hash('sha256', 'installer-owner-bootstrap|'.$targetUserId);
        $this->audit(
            $connection,
            'system',
            'installer',
            'access.owner.bootstrap',
            $target['administrator_id'],
            ['owner_administrator_id' => null],
            [
                'owner_administrator_id' => $target['administrator_id'],
                'owner_user_id' => $targetUserId,
            ],
            'initial_install',
            'Initial Owner established by the secure installer.',
            'owner-bootstrap-'.substr($fingerprint, 0, 32),
            $fingerprint,
            $timestamp,
        );

        return [
            'changed' => true,
            'administrator_id' => $target['administrator_id'],
            'user_id' => $targetUserId,
        ];
    }

    public function recover(
        Connection $connection,
        int $targetUserId,
        ?int $expectedCurrentOwnerUserId,
        string $reason,
        string $timestamp,
    ): array {
        $owners = $this->owners($connection);

        if (count($owners) > 1) {
            throw new RuntimeException('Owner singleton invariant is violated.');
        }

        $previousAdministratorId = null;
        $currentOwnerUserId = null;

        if ($owners !== []) {
            $previousAdministratorId = (int) $owners[0]->id;
            $currentOwnerUserId = (int) $owners[0]->user_id;

            if ($expectedCurrentOwnerUserId === null) {
                throw new RuntimeException('Expected current Owner identity is required.');
            }

            if ($currentOwnerUserId !== $expectedCurrentOwnerUserId) {
                throw new RuntimeException('Expected current Owner identity does not match authoritative state.');
            }
        } elseif ($expectedCurrentOwnerUserId !== null) {
            throw new RuntimeException('Expected current Owner was supplied but no database Owner exists.');
        }

        $target = $this->targetAdministrator($connection, $targetUserId, $timestamp);

        if ($previousAdministratorId === $target['administrator_id']) {
            return [
                'changed' => false,
                'previous_administrator_id' => $previousAdministratorId,
                'administrator_id' => $target['administrator_id'],
                'user_id' => $targetUserId,
                'cancelled_transfer_count' => 0,
            ];
        }

        $cancelledTransferCount = $connection->table('owner_transfer_requests')
            ->where('state', 'pending')
            ->update([
                'active_current_owner_id' => null,
                'active_target_administrator_id' => null,
                'state' => 'cancelled',
                'cancelled_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

        if ($previousAdministratorId !== null) {
            $connection->table('administrators')->where('id', $previousAdministratorId)->update([
                'is_owner' => false,
                'permission_version' => ((int) $owners[0]->permission_version) + 1,
                'updated_at' => $timestamp,
            ]);
        }

        $connection->table('administrators')->where('id', $target['administrator_id'])->update([
            'is_owner' => true,
            'permission_version' => $target['permission_version'] + 1,
            'updated_at' => $timestamp,
        ]);

        $fingerprint = hash(
            'sha256',
            implode('|', [
                'operator-owner-recovery',
                (string) ($currentOwnerUserId ?? 0),
                (string) $targetUserId,
                (string) $target['administrator_id'],
                $reason,
                $timestamp,
            ]),
        );

        $this->audit(
            $connection,
            'operator',
            'local-artisan',
            'access.owner.recover',
            $target['administrator_id'],
            ['owner_administrator_id' => $previousAdministratorId],
            [
                'owner_administrator_id' => $target['administrator_id'],
                'owner_user_id' => $targetUserId,
                'cancelled_transfer_count' => $cancelledTransferCount,
            ],
            'owner_recovery',
            $reason,
            'owner-recovery-'.substr($fingerprint, 0, 32),
            $fingerprint,
            $timestamp,
        );

        return [
            'changed' => true,
            'previous_administrator_id' => $previousAdministratorId,
            'administrator_id' => $target['administrator_id'],
            'user_id' => $targetUserId,
            'cancelled_transfer_count' => $cancelledTransferCount,
        ];
    }

    /** @return array{administrator_id: int, permission_version: int} */
    private function targetAdministrator(Connection $connection, int $targetUserId, string $timestamp): array
    {
        $administrator = $connection->table('administrators')
            ->where('user_id', $targetUserId)
            ->lockForUpdate()
            ->first(['id', 'status', 'permission_version']);

        if ($administrator === null) {
            $administratorId = (int) $connection->table('administrators')->insertGetId([
                'user_id' => $targetUserId,
                'status' => 'active',
                'is_owner' => false,
                'permission_version' => 1,
                'last_authenticated_at' => null,
                'suspended_at' => null,
                'revoked_at' => null,
                'status_reason_code' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            return [
                'administrator_id' => $administratorId,
                'permission_version' => 1,
            ];
        }

        if ((string) $administrator->status !== 'active') {
            throw new RuntimeException('Target Owner administrator is not active.');
        }

        return [
            'administrator_id' => (int) $administrator->id,
            'permission_version' => (int) $administrator->permission_version,
        ];
    }

    /** @return list<object{id:int|string,user_id:int|string,permission_version:int|string}> */
    private function owners(Connection $connection): array
    {
        /** @var list<object{id:int|string,user_id:int|string,permission_version:int|string}> $owners */
        $owners = $connection->table('administrators')
            ->where('is_owner', true)
            ->lockForUpdate()
            ->get(['id', 'user_id', 'permission_version'])
            ->all();

        return $owners;
    }

    /**
     * @param  array<string,bool|int|string|null>  $before
     * @param  array<string,bool|int|string|null>  $after
     */
    private function audit(
        Connection $connection,
        string $actorType,
        string $actorId,
        string $action,
        int $targetAdministratorId,
        array $before,
        array $after,
        string $reasonCode,
        string $reason,
        string $correlationId,
        string $requestFingerprint,
        string $timestamp,
    ): void {
        $connection->table('audit_logs')->insert([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'target_type' => 'administrator',
            'target_id' => (string) $targetAdministratorId,
            'before_safe_data' => json_encode($before, JSON_THROW_ON_ERROR),
            'after_safe_data' => json_encode($after, JSON_THROW_ON_ERROR),
            'reason_code' => $reasonCode,
            'reason' => $reason,
            'correlation_id' => $correlationId,
            'request_fingerprint' => $requestFingerprint,
            'created_at' => $timestamp,
        ]);
    }
}
