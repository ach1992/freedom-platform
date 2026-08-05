<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use App\Modules\AccessControl\Domain\OwnerTransferState;
use App\Shared\Application\Clock;
use DateInterval;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-type AdministratorRow object{
 *     id: int|string,
 *     status: string,
 *     is_owner: int|bool,
 *     permission_version: int|string,
 *     last_authenticated_at: ?string
 * }
 * @phpstan-type OwnerTransferRow object{
 *     id: string,
 *     current_owner_administrator_id: int|string,
 *     target_administrator_id: int|string,
 *     request_fingerprint: string,
 *     signed_intent_hash: string,
 *     state: string,
 *     correlation_id: string,
 *     current_owner_permission_version: int|string,
 *     target_permission_version: int|string,
 *     expires_at: string,
 *     accepted_by_administrator_id: int|string|null,
 *     accepted_at: ?string,
 *     cancelled_at: ?string,
 *     consumed_at: ?string
 * }
 */
final readonly class OwnerTransferService
{
    private const TRANSFER_PERMISSION = 'admins.transfer_ownership';

    private const MINIMUM_TTL_SECONDS = 60;

    private const MAXIMUM_TTL_SECONDS = 900;

    private string $intentKey;

    public function __construct(
        private DatabaseManager $database,
        private AdministratorPermissionAuthorizer $authorizer,
        private AccessMutationAudit $audit,
        private Clock $clock,
        string $intentKey,
        private int $maximumReauthenticationAgeSeconds = 300,
    ) {
        $this->intentKey = $this->normalizeIntentKey($intentKey);

        if ($maximumReauthenticationAgeSeconds < 60 || $maximumReauthenticationAgeSeconds > 3600) {
            throw new RuntimeException('Owner transfer reauthentication policy is invalid.');
        }
    }

    /** @requirement ACL-003 ADM-002 SEC-002 QUA-001 */
    public function request(
        int $targetAdministratorId,
        int $ttlSeconds,
        AccessChangeContext $context,
    ): AccessMutationReceipt {
        if ($targetAdministratorId < 1) {
            throw new RuntimeException('Owner transfer target is invalid.');
        }
        if ($ttlSeconds < self::MINIMUM_TTL_SECONDS || $ttlSeconds > self::MAXIMUM_TTL_SECONDS) {
            throw new RuntimeException('Owner transfer lifetime is invalid.');
        }
        $context->requireReason();
        $action = 'access.owner_transfer.request';

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $action,
            $targetAdministratorId,
            $ttlSeconds,
            $context,
        ): AccessMutationReceipt {
            $existing = $this->audit->existing(
                $action,
                $targetAdministratorId,
                $context->requestFingerprint,
                true,
            );
            if ($existing !== null) {
                $this->assertReceiptValue($existing, 'current_owner_administrator_id', $context->actorAdministratorId);
                $this->assertReceiptValue($existing, 'target_administrator_id', $targetAdministratorId);

                return $existing;
            }

            $owner = $this->currentOwner($connection);
            $ownerId = (int) $owner->id;

            if ($ownerId !== $context->actorAdministratorId) {
                throw new AuthorizationException('Only the current Owner can request ownership transfer.');
            }

            $this->assertRecentAuthentication($owner);
            $this->authorizer->authorize($ownerId, self::TRANSFER_PERMISSION);

            $target = $this->administrator($connection, $targetAdministratorId, true);
            if ($target->status !== 'active' || (bool) $target->is_owner || $targetAdministratorId === $ownerId) {
                throw new RuntimeException('Owner transfer target is not eligible.');
            }

            $active = $this->activeTransfer($connection, $ownerId, $targetAdministratorId);
            if ($active !== null) {
                if (! $this->isExpired($active->expires_at)) {
                    throw new RuntimeException('An active Owner transfer already exists.');
                }

                $this->expireTransfer($connection, $active, $context);
            }

            $transferId = (string) Str::ulid();
            $now = $this->clock->now();
            $timestamp = $now->format('Y-m-d H:i:s.u');
            $expiresAt = $now->add(new DateInterval('PT'.$ttlSeconds.'S'))->format('Y-m-d H:i:s.u');
            $ownerVersion = (int) $owner->permission_version;
            $targetVersion = (int) $target->permission_version;
            $intentHash = $this->intentHash(
                $transferId,
                $ownerId,
                $targetAdministratorId,
                $context->requestFingerprint,
                $context->correlationId,
                $ownerVersion,
                $targetVersion,
                $expiresAt,
            );

            $connection->table('owner_transfer_requests')->insert([
                'id' => $transferId,
                'current_owner_administrator_id' => $ownerId,
                'target_administrator_id' => $targetAdministratorId,
                'active_current_owner_id' => $ownerId,
                'active_target_administrator_id' => $targetAdministratorId,
                'request_fingerprint' => $context->requestFingerprint,
                'signed_intent_hash' => $intentHash,
                'state' => OwnerTransferState::Pending->value,
                'reason_code' => $context->reasonCode,
                'reason' => $context->reason,
                'correlation_id' => $context->correlationId,
                'current_owner_permission_version' => $ownerVersion,
                'target_permission_version' => $targetVersion,
                'expires_at' => $expiresAt,
                'accepted_by_administrator_id' => null,
                'accepted_at' => null,
                'cancelled_at' => null,
                'consumed_at' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            return $this->audit->record(
                $connection,
                $action,
                $targetAdministratorId,
                $context,
                [
                    'state' => null,
                    'current_owner_administrator_id' => $ownerId,
                    'target_administrator_id' => $targetAdministratorId,
                ],
                [
                    'transfer_id' => $transferId,
                    'state' => OwnerTransferState::Pending->value,
                    'current_owner_administrator_id' => $ownerId,
                    'target_administrator_id' => $targetAdministratorId,
                    'expires_at' => $expiresAt,
                ],
            );
        });
    }

    /** @requirement ACL-003 ADM-002 SEC-002 QUA-001 */
    public function accept(string $transferId, AccessChangeContext $context): AccessMutationReceipt
    {
        $this->assertTransferId($transferId);
        $context->requireReason();
        $action = 'access.owner_transfer.accept';

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $transferId,
            $context,
            $action,
        ): AccessMutationReceipt {
            $transfer = $this->transfer($connection, $transferId, true);
            $ownerId = (int) $transfer->current_owner_administrator_id;
            $targetId = (int) $transfer->target_administrator_id;

            if ($context->actorAdministratorId !== $targetId) {
                throw new AuthorizationException('Only the receiving administrator can accept ownership transfer.');
            }

            $existing = $this->audit->existing(
                $action,
                $targetId,
                $context->requestFingerprint,
                true,
            );
            if ($existing !== null) {
                $this->assertReceiptValue($existing, 'transfer_id', $transferId);

                return $existing;
            }

            $state = $this->state($transfer->state);
            if ($state !== OwnerTransferState::Pending) {
                throw new RuntimeException('Owner transfer is already terminal.');
            }

            if ($this->isExpired($transfer->expires_at)) {
                $this->releaseTransfer($connection, $transferId, OwnerTransferState::Expired, null);

                return $this->audit->record(
                    $connection,
                    $action,
                    $targetId,
                    $context,
                    $this->safeState($transfer),
                    [
                        'transfer_id' => $transferId,
                        'state' => OwnerTransferState::Expired->value,
                        'current_owner_administrator_id' => $ownerId,
                        'target_administrator_id' => $targetId,
                    ],
                );
            }

            $owner = $this->administrator($connection, $ownerId, true);
            $target = $this->administrator($connection, $targetId, true);
            $singleton = $this->currentOwner($connection);

            if ((int) $singleton->id !== $ownerId
                || $owner->status !== 'active'
                || ! (bool) $owner->is_owner
                || $target->status !== 'active'
                || (bool) $target->is_owner) {
                throw new RuntimeException('Owner transfer participants are no longer eligible.');
            }

            $this->assertRecentAuthentication($target);

            if ((int) $owner->permission_version !== (int) $transfer->current_owner_permission_version
                || (int) $target->permission_version !== (int) $transfer->target_permission_version) {
                throw new RuntimeException('Owner transfer intent is stale.');
            }

            $expectedIntent = $this->intentHash(
                $transferId,
                $ownerId,
                $targetId,
                $transfer->request_fingerprint,
                $transfer->correlation_id,
                (int) $transfer->current_owner_permission_version,
                (int) $transfer->target_permission_version,
                $transfer->expires_at,
            );
            if (! hash_equals($transfer->signed_intent_hash, $expectedIntent)) {
                throw new RuntimeException('Owner transfer intent integrity check failed.');
            }

            $now = $this->timestamp();
            $ownerVersion = (int) $owner->permission_version + 1;
            $targetVersion = (int) $target->permission_version + 1;

            $connection->table('administrators')->where('id', $ownerId)->update([
                'is_owner' => false,
                'permission_version' => $ownerVersion,
                'updated_at' => $now,
            ]);
            $connection->table('administrators')->where('id', $targetId)->update([
                'is_owner' => true,
                'permission_version' => $targetVersion,
                'updated_at' => $now,
            ]);
            $connection->table('owner_transfer_requests')->where('id', $transferId)->update([
                'active_current_owner_id' => null,
                'active_target_administrator_id' => null,
                'state' => OwnerTransferState::Accepted->value,
                'accepted_by_administrator_id' => $targetId,
                'accepted_at' => $now,
                'consumed_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->audit->record(
                $connection,
                $action,
                $targetId,
                $context,
                $this->safeState($transfer),
                [
                    'transfer_id' => $transferId,
                    'state' => OwnerTransferState::Accepted->value,
                    'current_owner_administrator_id' => $ownerId,
                    'target_administrator_id' => $targetId,
                    'previous_owner_is_owner' => false,
                    'receiving_owner_is_owner' => true,
                    'previous_owner_permission_version' => $ownerVersion,
                    'receiving_owner_permission_version' => $targetVersion,
                ],
            );
        });
    }

    /** @requirement ACL-003 ADM-002 SEC-002 QUA-001 */
    public function cancel(string $transferId, AccessChangeContext $context): AccessMutationReceipt
    {
        $this->assertTransferId($transferId);
        $context->requireReason();
        $action = 'access.owner_transfer.cancel';

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $transferId,
            $context,
            $action,
        ): AccessMutationReceipt {
            $transfer = $this->transfer($connection, $transferId, true);
            $ownerId = (int) $transfer->current_owner_administrator_id;
            $targetId = (int) $transfer->target_administrator_id;

            if ($context->actorAdministratorId !== $ownerId) {
                throw new AuthorizationException('Only the current Owner can cancel ownership transfer.');
            }

            $existing = $this->audit->existing(
                $action,
                $targetId,
                $context->requestFingerprint,
                true,
            );
            if ($existing !== null) {
                $this->assertReceiptValue($existing, 'transfer_id', $transferId);

                return $existing;
            }

            if ($this->state($transfer->state) !== OwnerTransferState::Pending) {
                throw new RuntimeException('Owner transfer is already terminal.');
            }

            $owner = $this->currentOwner($connection);
            if ((int) $owner->id !== $ownerId) {
                throw new AuthorizationException('Only the current Owner can cancel ownership transfer.');
            }
            $this->assertRecentAuthentication($owner);
            $this->authorizer->authorize($ownerId, self::TRANSFER_PERMISSION);

            $next = $this->isExpired($transfer->expires_at)
                ? OwnerTransferState::Expired
                : OwnerTransferState::Cancelled;
            $now = $this->timestamp();
            $this->releaseTransfer(
                $connection,
                $transferId,
                $next,
                $next === OwnerTransferState::Cancelled ? $now : null,
            );

            return $this->audit->record(
                $connection,
                $action,
                $targetId,
                $context,
                $this->safeState($transfer),
                [
                    'transfer_id' => $transferId,
                    'state' => $next->value,
                    'current_owner_administrator_id' => $ownerId,
                    'target_administrator_id' => $targetId,
                ],
            );
        });
    }

    /** @return AdministratorRow */
    private function currentOwner(Connection $connection): object
    {
        /** @var list<AdministratorRow> $owners */
        $owners = $connection->table('administrators')
            ->where('is_owner', true)
            ->lockForUpdate()
            ->get(['id', 'status', 'is_owner', 'permission_version', 'last_authenticated_at'])
            ->all();

        if (count($owners) !== 1) {
            throw new RuntimeException('Owner singleton invariant is violated.');
        }

        return $owners[0];
    }

    /** @return AdministratorRow */
    private function administrator(Connection $connection, int $administratorId, bool $lock): object
    {
        $query = $connection->table('administrators')->where('id', $administratorId);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var AdministratorRow|null $administrator */
        $administrator = $query->first([
            'id',
            'status',
            'is_owner',
            'permission_version',
            'last_authenticated_at',
        ]);

        if ($administrator === null) {
            throw new RuntimeException('Administrator does not exist.');
        }

        return $administrator;
    }

    /** @return OwnerTransferRow|null */
    private function activeTransfer(
        Connection $connection,
        int $ownerId,
        int $targetAdministratorId,
    ): ?object {
        /** @var OwnerTransferRow|null $transfer */
        $transfer = $connection->table('owner_transfer_requests')
            ->where('state', OwnerTransferState::Pending->value)
            ->where(function (Builder $query) use ($ownerId, $targetAdministratorId): void {
                $query->where('active_current_owner_id', $ownerId)
                    ->orWhere('active_target_administrator_id', $targetAdministratorId);
            })
            ->lockForUpdate()
            ->first($this->transferColumns());

        return $transfer;
    }

    /** @return OwnerTransferRow */
    private function transfer(Connection $connection, string $transferId, bool $lock): object
    {
        $query = $connection->table('owner_transfer_requests')->where('id', $transferId);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var OwnerTransferRow|null $transfer */
        $transfer = $query->first($this->transferColumns());
        if ($transfer === null) {
            throw new RuntimeException('Owner transfer does not exist.');
        }

        return $transfer;
    }

    /** @return list<string> */
    private function transferColumns(): array
    {
        return [
            'id',
            'current_owner_administrator_id',
            'target_administrator_id',
            'request_fingerprint',
            'signed_intent_hash',
            'state',
            'correlation_id',
            'current_owner_permission_version',
            'target_permission_version',
            'expires_at',
            'accepted_by_administrator_id',
            'accepted_at',
            'cancelled_at',
            'consumed_at',
        ];
    }

    /** @param OwnerTransferRow $transfer */
    private function expireTransfer(
        Connection $connection,
        object $transfer,
        AccessChangeContext $context,
    ): void {
        $transferId = (string) $transfer->id;
        $targetId = (int) $transfer->target_administrator_id;
        $this->releaseTransfer($connection, $transferId, OwnerTransferState::Expired, null);
        $this->audit->record(
            $connection,
            'access.owner_transfer.expire',
            $targetId,
            $context,
            $this->safeState($transfer),
            [
                'transfer_id' => $transferId,
                'state' => OwnerTransferState::Expired->value,
                'current_owner_administrator_id' => (int) $transfer->current_owner_administrator_id,
                'target_administrator_id' => $targetId,
            ],
        );
    }

    private function releaseTransfer(
        Connection $connection,
        string $transferId,
        OwnerTransferState $state,
        ?string $cancelledAt,
    ): void {
        $connection->table('owner_transfer_requests')->where('id', $transferId)->update([
            'active_current_owner_id' => null,
            'active_target_administrator_id' => null,
            'state' => $state->value,
            'cancelled_at' => $cancelledAt,
            'updated_at' => $this->timestamp(),
        ]);
    }

    /** @param AdministratorRow $administrator */
    private function assertRecentAuthentication(object $administrator): void
    {
        if ($administrator->status !== 'active' || ! is_string($administrator->last_authenticated_at)) {
            throw new AuthorizationException('Recent administrator authentication is required.');
        }

        $authenticatedAt = strtotime($administrator->last_authenticated_at);
        $now = $this->clock->now()->getTimestamp();
        if ($authenticatedAt === false
            || $authenticatedAt > $now + 30
            || $now - $authenticatedAt > $this->maximumReauthenticationAgeSeconds) {
            throw new AuthorizationException('Recent administrator authentication is required.');
        }
    }

    private function intentHash(
        string $transferId,
        int $ownerId,
        int $targetId,
        string $requestFingerprint,
        string $correlationId,
        int $ownerPermissionVersion,
        int $targetPermissionVersion,
        string $expiresAt,
    ): string {
        $intent = json_encode([
            'transfer_id' => $transferId,
            'current_owner_administrator_id' => $ownerId,
            'target_administrator_id' => $targetId,
            'request_fingerprint' => $requestFingerprint,
            'correlation_id' => $correlationId,
            'current_owner_permission_version' => $ownerPermissionVersion,
            'target_permission_version' => $targetPermissionVersion,
            'expires_at' => $expiresAt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return hash_hmac('sha256', $intent, $this->intentKey);
    }

    private function normalizeIntentKey(string $key): string
    {
        $normalized = str_starts_with($key, 'base64:')
            ? base64_decode(substr($key, 7), true)
            : $key;

        if (! is_string($normalized) || strlen($normalized) < 32) {
            throw new RuntimeException('Owner transfer intent key is invalid.');
        }

        return $normalized;
    }

    /** @param OwnerTransferRow $transfer */
    private function safeState(object $transfer): array
    {
        return [
            'transfer_id' => (string) $transfer->id,
            'state' => $this->state($transfer->state)->value,
            'current_owner_administrator_id' => (int) $transfer->current_owner_administrator_id,
            'target_administrator_id' => (int) $transfer->target_administrator_id,
        ];
    }

    private function assertReceiptValue(
        AccessMutationReceipt $receipt,
        string $key,
        int|string $expected,
    ): void {
        if (($receipt->after[$key] ?? null) !== $expected) {
            throw new RuntimeException('Owner transfer fingerprint conflict.');
        }
    }

    private function state(string $state): OwnerTransferState
    {
        return OwnerTransferState::tryFrom($state)
            ?? throw new RuntimeException('Owner transfer state is invalid.');
    }

    private function isExpired(string $expiresAt): bool
    {
        $timestamp = strtotime($expiresAt);

        return $timestamp === false || $this->clock->now()->getTimestamp() >= $timestamp;
    }

    private function assertTransferId(string $transferId): void
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $transferId) !== 1) {
            throw new RuntimeException('Owner transfer ID is invalid.');
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
