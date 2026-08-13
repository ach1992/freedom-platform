<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

use App\Modules\AccessControl\Application\CurrentOwnerAuthorizer;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * @phpstan-type IdentityRow object{id:int|string,user_id:int|string,token:string}
 * @phpstan-type RelationshipRow object{
 *     id:int|string,
 *     referred_user_id:int|string,
 *     inviter_user_id:int|string,
 *     inviter_referral_identity_id:int|string,
 *     locked_purchase_settlement_id:int|string|null,
 *     locked_at:?string
 * }
 */
final readonly class ReferralAttributionService
{
    public function __construct(
        private DatabaseManager $database,
        private CurrentOwnerAuthorizer $ownerAuthorizer,
        private Clock $clock,
    ) {}

    /** @requirement REF-001 ONB-002 DAT-002 DAT-003 DAT-004 */
    public function identityForUser(int $userId): string
    {
        if ($userId < 1) {
            throw new RuntimeException('Referral identity user is invalid.');
        }

        $token = $this->database->connection()
            ->table('referral_identities')
            ->where('user_id', $userId)
            ->value('token');

        if (! is_string($token) || preg_match('/\A[0-9a-f]{32}\z/', $token) !== 1) {
            throw new RuntimeException('Referral identity invariant is violated.');
        }

        return $token;
    }

    /** @requirement REF-001 ONB-002 DAT-002 DAT-003 DAT-004 */
    public function bind(int $referredUserId, string $inviterToken): ReferralAttributionReceipt
    {
        $this->assertUserId($referredUserId);
        $token = $this->normalizeToken($inviterToken);

        return $this->database->connection()->transaction(function (Connection $connection) use ($referredUserId, $token): ReferralAttributionReceipt {
            $identity = $this->identityByToken($connection, $token);
            $inviterUserId = (int) $identity->user_id;
            if ($referredUserId === $inviterUserId) {
                throw new DomainException('Self-referral is not allowed.');
            }

            $this->lockUsers($connection, [$referredUserId, $inviterUserId]);
            $existing = $this->relationshipForUser($connection, $referredUserId, true);
            if ($existing !== null) {
                if ((int) $existing->inviter_user_id === $inviterUserId
                    && (int) $existing->inviter_referral_identity_id === (int) $identity->id) {
                    return $this->receipt($existing, $token, true);
                }

                throw new DomainException('Referral inviter is already bound.');
            }

            if ($this->hasSuccessfulPurchase($connection, $referredUserId)) {
                throw new DomainException('Referral inviter cannot be bound after a successful purchase.');
            }

            $timestamp = $this->timestamp();
            $relationshipId = (int) $connection->table('referral_relationships')->insertGetId([
                'referred_user_id' => $referredUserId,
                'inviter_user_id' => $inviterUserId,
                'inviter_referral_identity_id' => (int) $identity->id,
                'locked_purchase_settlement_id' => null,
                'bound_at' => $timestamp,
                'locked_at' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $connection->table('referral_attribution_events')->insert([
                'relationship_id' => $relationshipId,
                'event_type' => 'bound',
                'inviter_user_id' => $inviterUserId,
                'actor_administrator_id' => null,
                'purchase_settlement_id' => null,
                'occurred_at' => $timestamp,
            ]);

            return new ReferralAttributionReceipt(
                $relationshipId,
                $referredUserId,
                $inviterUserId,
                $token,
                false,
                false,
            );
        });
    }

    /** @requirement REF-001 ACL-002 SEC-002 DAT-002 DAT-003 DAT-004 */
    public function correctByOwner(
        int $administratorId,
        int $referredUserId,
        string $inviterToken,
    ): ReferralAttributionReceipt {
        $this->assertUserId($referredUserId);
        $token = $this->normalizeToken($inviterToken);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $administratorId,
            $referredUserId,
            $token,
        ): ReferralAttributionReceipt {
            $this->ownerAuthorizer->authorize($administratorId);
            $identity = $this->identityByToken($connection, $token);
            $inviterUserId = (int) $identity->user_id;
            if ($referredUserId === $inviterUserId) {
                throw new DomainException('Self-referral is not allowed.');
            }

            $this->lockUsers($connection, [$referredUserId, $inviterUserId]);
            $relationship = $this->relationshipForUser($connection, $referredUserId, true);
            if ($relationship === null) {
                throw new DomainException('Referral relationship does not exist.');
            }

            if ($relationship->locked_purchase_settlement_id !== null
                || $relationship->locked_at !== null
                || $this->hasSuccessfulPurchase($connection, $referredUserId)) {
                throw new DomainException('Referral inviter is locked after successful purchase.');
            }

            if ((int) $relationship->inviter_user_id === $inviterUserId
                && (int) $relationship->inviter_referral_identity_id === (int) $identity->id) {
                return $this->receipt($relationship, $token, true);
            }

            $timestamp = $this->timestamp();
            $connection->table('referral_relationships')
                ->where('id', (int) $relationship->id)
                ->update([
                    'inviter_user_id' => $inviterUserId,
                    'inviter_referral_identity_id' => (int) $identity->id,
                    'updated_at' => $timestamp,
                ]);

            $connection->table('referral_attribution_events')->insert([
                'relationship_id' => (int) $relationship->id,
                'event_type' => 'corrected',
                'inviter_user_id' => $inviterUserId,
                'actor_administrator_id' => $administratorId,
                'purchase_settlement_id' => null,
                'occurred_at' => $timestamp,
            ]);

            return new ReferralAttributionReceipt(
                (int) $relationship->id,
                $referredUserId,
                $inviterUserId,
                $token,
                false,
                false,
            );
        });
    }

    private function assertUserId(int $userId): void
    {
        if ($userId < 1) {
            throw new RuntimeException('Referral user is invalid.');
        }
    }

    private function normalizeToken(string $token): string
    {
        $normalized = strtolower(trim($token));
        if (preg_match('/\A[0-9a-f]{32}\z/', $normalized) !== 1) {
            throw new DomainException('Referral identity token is invalid.');
        }

        return $normalized;
    }

    /** @return IdentityRow */
    private function identityByToken(Connection $connection, string $token): object
    {
        /** @var IdentityRow|null $identity */
        $identity = $connection->table('referral_identities')
            ->where('token', $token)
            ->first(['id', 'user_id', 'token']);

        if ($identity === null) {
            throw new DomainException('Referral identity does not exist.');
        }

        return $identity;
    }

    /** @param list<int> $userIds */
    private function lockUsers(Connection $connection, array $userIds): void
    {
        $ids = array_values(array_unique($userIds));
        sort($ids, SORT_NUMERIC);

        $locked = $connection->table('users')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->all();

        if (count($locked) !== count($ids)) {
            throw new RuntimeException('Referral participant does not exist.');
        }
    }

    /** @return RelationshipRow|null */
    private function relationshipForUser(Connection $connection, int $referredUserId, bool $lock): ?object
    {
        $query = $connection->table('referral_relationships')
            ->where('referred_user_id', $referredUserId);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var RelationshipRow|null $relationship */
        $relationship = $query->first([
            'id',
            'referred_user_id',
            'inviter_user_id',
            'inviter_referral_identity_id',
            'locked_purchase_settlement_id',
            'locked_at',
        ]);

        return $relationship;
    }

    private function hasSuccessfulPurchase(Connection $connection, int $userId): bool
    {
        return $connection->table('purchase_settlements')
            ->where('user_id', $userId)
            ->exists();
    }

    /** @param RelationshipRow $relationship */
    private function receipt(object $relationship, string $token, bool $replayed): ReferralAttributionReceipt
    {
        return new ReferralAttributionReceipt(
            (int) $relationship->id,
            (int) $relationship->referred_user_id,
            (int) $relationship->inviter_user_id,
            $token,
            $relationship->locked_purchase_settlement_id !== null,
            $replayed,
        );
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
