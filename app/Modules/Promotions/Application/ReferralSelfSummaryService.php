<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Read-only self projection of referral identity/attribution state. The user
 * insert trigger owns identity creation; this service performs no bind,
 * correction, lock or referral mutation and exposes no internal durable IDs.
 */
final readonly class ReferralSelfSummaryService
{
    public function __construct(private DatabaseManager $database) {}

    /** @requirement REF-001 USR-001 SEC-003 DAT-003 */
    public function forSelf(int $userId, int $actorUserId): ReferralSelfSummary
    {
        if ($userId < 1 || $actorUserId !== $userId) {
            throw new AuthorizationException('Referral self summary access denied.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use ($userId): ReferralSelfSummary {
            $user = $connection->table('users')->where('id', $userId)->first(['id', 'account_status']);
            if ($user === null || (string) $user->account_status === 'deleted') {
                throw new RuntimeException('Referral self summary is unavailable.');
            }

            $token = $connection->table('referral_identities')->where('user_id', $userId)->value('token');
            if (! is_string($token) || preg_match('/\A[0-9a-f]{32}\z/', $token) !== 1) {
                throw new RuntimeException('Referral self identity invariant is violated.');
            }

            /** @var object{inviter_user_id:int|string,inviter_referral_identity_id:int|string,bound_at:string,locked_purchase_settlement_id:int|string|null,locked_at:?string}|null $relationship */
            $relationship = $connection->table('referral_relationships')
                ->where('referred_user_id', $userId)
                ->first([
                    'inviter_user_id',
                    'inviter_referral_identity_id',
                    'bound_at',
                    'locked_purchase_settlement_id',
                    'locked_at',
                ]);
            if ($relationship === null) {
                return new ReferralSelfSummary($token, false, false, null, null);
            }

            $validInviterIdentity = $connection->table('referral_identities')
                ->where('id', $relationship->inviter_referral_identity_id)
                ->where('user_id', $relationship->inviter_user_id)
                ->exists();
            if (! $validInviterIdentity) {
                throw new RuntimeException('Referral self inviter identity invariant is violated.');
            }

            $hasLockId = $relationship->locked_purchase_settlement_id !== null;
            $hasLockedAt = $relationship->locked_at !== null;
            if ($hasLockId !== $hasLockedAt) {
                throw new RuntimeException('Referral self lock invariant is violated.');
            }
            if (! is_string($relationship->bound_at) || $relationship->bound_at === '') {
                throw new RuntimeException('Referral self bound timestamp is invalid.');
            }
            if ($relationship->locked_at !== null && (! is_string($relationship->locked_at) || $relationship->locked_at === '')) {
                throw new RuntimeException('Referral self lock timestamp is invalid.');
            }

            return new ReferralSelfSummary(
                $token,
                true,
                $hasLockId,
                $relationship->bound_at,
                $relationship->locked_at,
            );
        });
    }
}
