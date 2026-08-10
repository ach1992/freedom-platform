<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Identity\Application\Contracts\PhoneLookupHasher;
use App\Modules\Identity\Domain\IdentityItemType;
use App\Modules\Identity\Domain\IdentityItemValue;
use App\Modules\Identity\Domain\VerificationStatus;
use App\Shared\Application\Clock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class IdentityItemService
{
    private const REVIEW_PERMISSION = 'identity.verifications.manage';

    /** @var list<IdentityItemType> */
    private array $requiredTypes;

    /**
     * @param  list<IdentityItemType|string>  $requiredTypes
     */
    public function __construct(
        private DatabaseManager $database,
        private StringEncrypter $encrypter,
        private PhoneLookupHasher $hasher,
        private AdministratorPermissionAuthorizer $authorizer,
        private IdentityMutationAudit $audit,
        private Clock $clock,
        private int $hashKeyVersion = 1,
        array $requiredTypes = [IdentityItemType::NationalId, IdentityItemType::FullName],
    ) {
        if ($hashKeyVersion < 1) {
            throw new RuntimeException('Identity item hash key version is invalid.');
        }

        $normalized = [];
        foreach ($requiredTypes as $type) {
            $resolved = $type instanceof IdentityItemType ? $type : IdentityItemType::tryFrom($type);
            if ($resolved === null || in_array($resolved, $normalized, true)) {
                throw new RuntimeException('Required identity item policy is invalid.');
            }
            $normalized[] = $resolved;
        }
        if ($normalized === []) {
            throw new RuntimeException('At least one required identity item is necessary.');
        }
        $this->requiredTypes = $normalized;
    }

    /** @requirement USR-001 SEC-003 DAT-003 QUA-001 */
    public function submit(
        int $userId,
        IdentityItemType $type,
        string $value,
        bool $ownershipCheckRequired,
        IdentityChangeContext $context,
    ): IdentityMutationReceipt {
        if ($context->requireUser() !== $userId || $userId < 1) {
            throw new AuthorizationException('Identity item submission failed.');
        }

        $identityValue = IdentityItemValue::fromInput($type, $value);
        $lookupHash = $this->hasher->hashOpaque($type->value."\0".$identityValue->canonical);
        $targetId = $this->targetId($userId, $type);
        $action = 'identity.item.submit';
        $existingAudit = $this->audit->existing($action, $targetId, $context->requestFingerprint);
        if ($existingAudit !== null) {
            return $existingAudit;
        }

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $userId,
                $type,
                $identityValue,
                $lookupHash,
                $ownershipCheckRequired,
                $context,
                $targetId,
                $action,
            ): IdentityMutationReceipt {
                $existingAudit = $this->audit->existing($action, $targetId, $context->requestFingerprint, true);
                if ($existingAudit !== null) {
                    return $existingAudit;
                }

                /** @var object{account_status: string}|null $user */
                $user = $connection->table('users')->where('id', $userId)->lockForUpdate()->first(['account_status']);
                if ($user === null || $user->account_status === 'deleted') {
                    throw new RuntimeException('An existing non-deleted account is required.');
                }

                $item = $this->item($connection, $userId, $type, true);
                $this->assertLookupAvailable($connection, $type, $lookupHash, $item?->id);
                $before = $item === null ? [] : $this->safeState($item);
                $expectedOwnership = $ownershipCheckRequired ? 'pending' : 'not_required';

                if ($item !== null
                    && $item->lookupHash === $lookupHash
                    && in_array($item->state, [VerificationStatus::Pending, VerificationStatus::Verified], true)
                    && $item->ownershipCheckRequired === $ownershipCheckRequired) {
                    return $this->audit->record($connection, $action, $targetId, $context, $before, $before);
                }

                $version = $item === null ? 1 : $item->version + 1;
                $now = $this->timestamp();
                $attributes = [
                    'encrypted_value' => $this->encrypter->encryptString($identityValue->canonical),
                    'lookup_hash' => $lookupHash,
                    'active_lookup_hash' => $type->isGloballyUnique() ? $lookupHash : null,
                    'hash_key_version' => $this->hashKeyVersion,
                    'masked_value' => $identityValue->masked,
                    'state' => VerificationStatus::Pending->value,
                    'ownership_check_required' => $ownershipCheckRequired,
                    'ownership_check_status' => $expectedOwnership,
                    'version' => $version,
                    'verified_by_administrator_id' => null,
                    'rejected_by_administrator_id' => null,
                    'decision_reason_code' => null,
                    'decision_reason' => null,
                    'submitted_at' => $now,
                    'verified_at' => null,
                    'rejected_at' => null,
                    'updated_at' => $now,
                ];

                if ($item === null) {
                    $itemId = (int) $connection->table('identity_items')->insertGetId([
                        'user_id' => $userId,
                        'type' => $type->value,
                        ...$attributes,
                        'created_at' => $now,
                    ]);
                } else {
                    $itemId = $item->id;
                    $connection->table('identity_items')->where('id', $itemId)->update($attributes);
                }

                $this->history(
                    $connection,
                    $itemId,
                    $version,
                    $item?->state,
                    VerificationStatus::Pending,
                    $identityValue->masked,
                    $expectedOwnership,
                    $context,
                );
                $this->updateAggregate($connection, $userId, $now);
                $after = [
                    'item_id' => $itemId,
                    'user_id' => $userId,
                    'type' => $type->value,
                    'masked_value' => $identityValue->masked,
                    'state' => VerificationStatus::Pending->value,
                    'ownership_check_status' => $expectedOwnership,
                    'version' => $version,
                ];

                return $this->audit->record($connection, $action, $targetId, $context, $before, $after);
            });
        } catch (QueryException $exception) {
            $existingAudit = $this->audit->existing($action, $targetId, $context->requestFingerprint);
            if ($existingAudit !== null) {
                return $existingAudit;
            }
            $owner = $this->database->connection()->table('identity_items')
                ->where('active_lookup_hash', $lookupHash)
                ->value('user_id');
            if (is_numeric($owner) && (int) $owner !== $userId) {
                throw new RuntimeException('Identity value is already assigned.', previous: $exception);
            }

            throw $exception;
        }
    }

    /** @requirement USR-001 ACL-002 SEC-003 QUA-001 */
    public function recordOwnershipCheck(
        int $userId,
        IdentityItemType $type,
        bool $matched,
        IdentityChangeContext $context,
    ): IdentityMutationReceipt {
        $administratorId = $context->requireAdministrator();
        $context->requireReason();
        $this->authorizer->authorize($administratorId, self::REVIEW_PERMISSION);
        $action = 'identity.item.ownership_check';

        return $this->reviewTransaction(
            $action,
            $userId,
            $type,
            $administratorId,
            $context,
            function (Connection $connection, IdentityItemRecord $item) use (
                $matched,
                $administratorId,
                $context,
            ): array {
                if (! $item->ownershipCheckRequired || $item->state !== VerificationStatus::Pending) {
                    throw new RuntimeException('Identity ownership check is not pending.');
                }

                $status = $matched ? 'matched' : 'mismatched';
                $state = $matched ? VerificationStatus::Pending : VerificationStatus::Rejected;
                $version = $item->version + 1;
                $now = $this->timestamp();
                $connection->table('identity_items')->where('id', $item->id)->update([
                    'state' => $state->value,
                    'ownership_check_status' => $status,
                    'version' => $version,
                    'rejected_by_administrator_id' => $matched ? null : $administratorId,
                    'decision_reason_code' => $matched ? null : $context->reasonCode,
                    'decision_reason' => $matched ? null : $context->reason,
                    'rejected_at' => $matched ? null : $now,
                    'updated_at' => $now,
                ]);
                $this->history(
                    $connection,
                    $item->id,
                    $version,
                    $item->state,
                    $state,
                    $item->maskedValue,
                    $status,
                    $context,
                );
                $this->updateAggregate($connection, $item->userId, $now);

                return [
                    'item_id' => $item->id,
                    'user_id' => $item->userId,
                    'type' => $item->type->value,
                    'masked_value' => $item->maskedValue,
                    'state' => $state->value,
                    'ownership_check_status' => $status,
                    'version' => $version,
                ];
            },
        );
    }

    /** @requirement USR-001 ACL-002 SEC-003 QUA-001 */
    public function verify(
        int $userId,
        IdentityItemType $type,
        IdentityChangeContext $context,
    ): IdentityMutationReceipt {
        $administratorId = $context->requireAdministrator();
        $context->requireReason();
        $this->authorizer->authorize($administratorId, self::REVIEW_PERMISSION);

        return $this->reviewTransaction(
            'identity.item.verify',
            $userId,
            $type,
            $administratorId,
            $context,
            function (Connection $connection, IdentityItemRecord $item) use ($administratorId, $context): array {
                if ($item->state !== VerificationStatus::Pending) {
                    throw new RuntimeException('Only a pending identity item can be verified.');
                }
                if ($item->ownershipCheckRequired && $item->ownershipCheckStatus !== 'matched') {
                    throw new RuntimeException('Identity ownership check must match before verification.');
                }

                $version = $item->version + 1;
                $now = $this->timestamp();
                $connection->table('identity_items')->where('id', $item->id)->update([
                    'state' => VerificationStatus::Verified->value,
                    'version' => $version,
                    'verified_by_administrator_id' => $administratorId,
                    'rejected_by_administrator_id' => null,
                    'decision_reason_code' => $context->reasonCode,
                    'decision_reason' => $context->reason,
                    'verified_at' => $now,
                    'rejected_at' => null,
                    'updated_at' => $now,
                ]);
                $this->history(
                    $connection,
                    $item->id,
                    $version,
                    $item->state,
                    VerificationStatus::Verified,
                    $item->maskedValue,
                    $item->ownershipCheckStatus,
                    $context,
                );
                $this->updateAggregate($connection, $item->userId, $now);

                return [
                    'item_id' => $item->id,
                    'user_id' => $item->userId,
                    'type' => $item->type->value,
                    'masked_value' => $item->maskedValue,
                    'state' => VerificationStatus::Verified->value,
                    'ownership_check_status' => $item->ownershipCheckStatus,
                    'version' => $version,
                ];
            },
        );
    }

    /** @requirement USR-001 ACL-002 SEC-003 QUA-001 */
    public function reject(
        int $userId,
        IdentityItemType $type,
        IdentityChangeContext $context,
    ): IdentityMutationReceipt {
        $administratorId = $context->requireAdministrator();
        $context->requireReason();
        $this->authorizer->authorize($administratorId, self::REVIEW_PERMISSION);

        return $this->reviewTransaction(
            'identity.item.reject',
            $userId,
            $type,
            $administratorId,
            $context,
            function (Connection $connection, IdentityItemRecord $item) use ($administratorId, $context): array {
                if ($item->state !== VerificationStatus::Pending) {
                    throw new RuntimeException('Only a pending identity item can be rejected.');
                }

                $version = $item->version + 1;
                $now = $this->timestamp();
                $connection->table('identity_items')->where('id', $item->id)->update([
                    'state' => VerificationStatus::Rejected->value,
                    'version' => $version,
                    'verified_by_administrator_id' => null,
                    'rejected_by_administrator_id' => $administratorId,
                    'decision_reason_code' => $context->reasonCode,
                    'decision_reason' => $context->reason,
                    'verified_at' => null,
                    'rejected_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->history(
                    $connection,
                    $item->id,
                    $version,
                    $item->state,
                    VerificationStatus::Rejected,
                    $item->maskedValue,
                    $item->ownershipCheckStatus,
                    $context,
                );
                $this->updateAggregate($connection, $item->userId, $now);

                return [
                    'item_id' => $item->id,
                    'user_id' => $item->userId,
                    'type' => $item->type->value,
                    'masked_value' => $item->maskedValue,
                    'state' => VerificationStatus::Rejected->value,
                    'ownership_check_status' => $item->ownershipCheckStatus,
                    'version' => $version,
                ];
            },
        );
    }

    /** @return list<array{type: string, masked_value: string, state: string, ownership_check_status: string, version: int}> */
    public function summaryForUser(int $userId): array
    {
        if ($userId < 1) {
            throw new RuntimeException('Identity summary user is invalid.');
        }

        /** @var iterable<int, object{type: string, masked_value: string, state: string, ownership_check_status: string, version: int|string}> $rows */
        $rows = $this->database->connection()->table('identity_items')
            ->where('user_id', $userId)
            ->orderBy('type')
            ->get(['type', 'masked_value', 'state', 'ownership_check_status', 'version']);

        $summary = [];
        foreach ($rows as $row) {
            $summary[] = [
                'type' => $row->type,
                'masked_value' => $row->masked_value,
                'state' => $row->state,
                'ownership_check_status' => $row->ownership_check_status,
                'version' => (int) $row->version,
            ];
        }

        return $summary;
    }

    /**
     * @param  callable(Connection, IdentityItemRecord): array<string, bool|int|string|null>  $mutation
     */
    private function reviewTransaction(
        string $action,
        int $userId,
        IdentityItemType $type,
        int $administratorId,
        IdentityChangeContext $context,
        callable $mutation,
    ): IdentityMutationReceipt {
        if ($userId < 1) {
            throw new RuntimeException('Identity item user is invalid.');
        }
        $targetId = $this->targetId($userId, $type);
        $existingAudit = $this->audit->existing($action, $targetId, $context->requestFingerprint);
        if ($existingAudit !== null) {
            return $existingAudit;
        }

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $action,
            $userId,
            $type,
            $administratorId,
            $context,
            $mutation,
            $targetId,
        ): IdentityMutationReceipt {
            $this->assertActiveAdministrator($connection, $administratorId);
            $this->authorizer->authorize($administratorId, self::REVIEW_PERMISSION);

            $existingAudit = $this->audit->existing($action, $targetId, $context->requestFingerprint, true);
            if ($existingAudit !== null) {
                return $existingAudit;
            }

            $item = $this->item($connection, $userId, $type, true);
            if ($item === null) {
                throw new RuntimeException('Identity item does not exist.');
            }
            $before = $this->safeState($item);
            $after = $mutation($connection, $item);

            return $this->audit->record($connection, $action, $targetId, $context, $before, $after);
        });
    }

    private function assertActiveAdministrator(Connection $connection, int $administratorId): void
    {
        $active = $connection->table('administrators')
            ->where('id', $administratorId)
            ->where('status', 'active')
            ->lockForUpdate()
            ->exists();

        if (! $active) {
            throw new AuthorizationException('Administrator authorization failed.');
        }
    }

    private function assertLookupAvailable(
        Connection $connection,
        IdentityItemType $type,
        string $lookupHash,
        ?int $currentItemId,
    ): void {
        if (! $type->isGloballyUnique()) {
            return;
        }

        $query = $connection->table('identity_items')
            ->where('active_lookup_hash', $lookupHash);
        if ($currentItemId !== null) {
            $query->where('id', '<>', $currentItemId);
        }

        if ($query->lockForUpdate()->exists()) {
            throw new RuntimeException('Identity value is already assigned.');
        }
    }

    private function item(
        Connection $connection,
        int $userId,
        IdentityItemType $type,
        bool $lock,
    ): ?IdentityItemRecord {
        $query = $connection->table('identity_items')
            ->where('user_id', $userId)
            ->where('type', $type->value);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var object{id: int|string, user_id: int|string, type: string, lookup_hash: string, masked_value: string, state: string, ownership_check_required: int|bool, ownership_check_status: string, version: int|string}|null $row */
        $row = $query->first([
            'id',
            'user_id',
            'type',
            'lookup_hash',
            'masked_value',
            'state',
            'ownership_check_required',
            'ownership_check_status',
            'version',
        ]);

        return $row === null ? null : IdentityItemRecord::fromRow($row);
    }

    private function ensureProfile(Connection $connection, int $userId, string $now): void
    {
        $tierId = $connection->table('customer_tiers')->where('code', 'new')->value('id');
        $connection->table('customer_profiles')->insertOrIgnore([
            'user_id' => $userId,
            'current_tier_id' => is_numeric($tierId) ? (int) $tierId : null,
            'tier_locked' => false,
            'phone_verification_status' => VerificationStatus::Unverified->value,
            'identity_verification_status' => VerificationStatus::Unverified->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function updateAggregate(Connection $connection, int $userId, string $now): void
    {
        $this->ensureProfile($connection, $userId, $now);
        /** @var array<string, string> $states */
        $states = $connection->table('identity_items')
            ->where('user_id', $userId)
            ->whereIn('type', array_map(
                static fn (IdentityItemType $type): string => $type->value,
                $this->requiredTypes,
            ))
            ->pluck('state', 'type')
            ->all();

        $requiredStates = [];
        foreach ($this->requiredTypes as $type) {
            $requiredStates[] = $states[$type->value] ?? VerificationStatus::Unverified->value;
        }

        $aggregate = match (true) {
            in_array(VerificationStatus::Rejected->value, $requiredStates, true) => VerificationStatus::Rejected,
            in_array(VerificationStatus::Pending->value, $requiredStates, true) => VerificationStatus::Pending,
            in_array(VerificationStatus::Unverified->value, $requiredStates, true) => VerificationStatus::Unverified,
            default => VerificationStatus::Verified,
        };
        $connection->table('customer_profiles')->where('user_id', $userId)->update([
            'identity_verification_status' => $aggregate->value,
            'updated_at' => $now,
        ]);
    }

    private function history(
        Connection $connection,
        int $itemId,
        int $version,
        ?VerificationStatus $from,
        VerificationStatus $to,
        string $maskedValue,
        string $ownershipCheckStatus,
        IdentityChangeContext $context,
    ): void {
        $connection->table('identity_item_histories')->insert([
            'identity_item_id' => $itemId,
            'version' => $version,
            'from_state' => $from?->value,
            'to_state' => $to->value,
            'masked_value' => $maskedValue,
            'ownership_check_status' => $ownershipCheckStatus,
            'actor_user_id' => $context->actorUserId,
            'actor_administrator_id' => $context->actorAdministratorId,
            'reason_code' => $context->reasonCode,
            'reason' => $context->reason,
            'correlation_id' => $context->correlationId,
            'created_at' => $this->timestamp(),
        ]);
    }

    /** @return array<string, bool|int|string|null> */
    private function safeState(IdentityItemRecord $item): array
    {
        return [
            'item_id' => $item->id,
            'user_id' => $item->userId,
            'type' => $item->type->value,
            'masked_value' => $item->maskedValue,
            'state' => $item->state->value,
            'ownership_check_status' => $item->ownershipCheckStatus,
            'version' => $item->version,
        ];
    }

    private function targetId(int $userId, IdentityItemType $type): string
    {
        return $userId.':'.$type->value;
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
