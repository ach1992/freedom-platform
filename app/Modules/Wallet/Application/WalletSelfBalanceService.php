<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Read-only self-service Wallet projection. It never provisions accounts or
 * posts ledger/hold state; missing wallet buckets are represented as zero.
 */
final readonly class WalletSelfBalanceService
{
    public function __construct(
        private DatabaseManager $database,
        private WalletHoldService $holds,
    ) {}

    /** @requirement WAL-002 USR-001 SEC-003 DAT-003 */
    public function forSelf(int $userId, int $actorUserId): WalletSelfBalanceSummary
    {
        if ($userId < 1 || $actorUserId !== $userId) {
            throw new AuthorizationException('Wallet self balance access denied.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use ($userId): WalletSelfBalanceSummary {
            /** @var object{id:int|string,account_status:string}|null $user */
            $user = $connection->table('users')
                ->where('id', $userId)
                ->first(['id', 'account_status']);
            if ($user === null || $user->account_status === 'deleted') {
                throw new RuntimeException('Wallet self balance is unavailable.');
            }

            /** @var iterable<int, object{id:int|string,account_class:string,owner_user_id:int|string|null,wallet_bucket:string|null,currency:string,is_active:int|bool}> $rows */
            $rows = $connection->table('ledger_accounts')
                ->where('owner_user_id', $userId)
                ->whereIn('wallet_bucket', ['cash', 'promotional'])
                ->orderBy('wallet_bucket')
                ->lockForUpdate()
                ->get(['id', 'account_class', 'owner_user_id', 'wallet_bucket', 'currency', 'is_active']);

            $accounts = [];
            foreach ($rows as $row) {
                $bucket = $this->validatedBucket($row, $userId);
                if (isset($accounts[$bucket])) {
                    throw new RuntimeException('Wallet self balance contains duplicate wallet buckets.');
                }
                $accounts[$bucket] = $this->positiveDatabaseInt($row->id, 'Wallet self balance account ID');
            }

            [$cashExists, $cashLedger, $cashHolds, $cashAvailable] = $this->balanceForBucket(
                $connection,
                $userId,
                $accounts['cash'] ?? null,
            );
            [$promotionalExists, $promotionalLedger, $promotionalHolds, $promotionalAvailable] = $this->balanceForBucket(
                $connection,
                $userId,
                $accounts['promotional'] ?? null,
            );

            return new WalletSelfBalanceSummary(
                $cashExists,
                $cashLedger,
                $cashHolds,
                $cashAvailable,
                $promotionalExists,
                $promotionalLedger,
                $promotionalHolds,
                $promotionalAvailable,
            );
        });
    }

    /** @return array{0:bool,1:int,2:int,3:int} */
    private function balanceForBucket(Connection $connection, int $userId, ?int $accountId): array
    {
        if ($accountId === null) {
            return [false, 0, 0, 0];
        }

        $balance = $this->holds->balanceOnLockedAccount($connection, $userId, $accountId);

        return [
            true,
            $balance->ledgerBalance->amount,
            $balance->activeHolds->amount,
            $balance->availableBalance->amount,
        ];
    }

    /** @param object{account_class:string,owner_user_id:int|string|null,wallet_bucket:string|null,currency:string,is_active:int|bool} $row */
    private function validatedBucket(object $row, int $userId): string
    {
        if ($row->account_class !== 'liability'
            || $row->owner_user_id === null
            || (int) $row->owner_user_id !== $userId
            || ! in_array($row->wallet_bucket, ['cash', 'promotional'], true)
            || $row->currency !== 'IRR'
            || ! (bool) $row->is_active
        ) {
            throw new RuntimeException('Wallet self balance account invariant is violated.');
        }

        return $row->wallet_bucket;
    }

    private function positiveDatabaseInt(mixed $value, string $label): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($validated === false) {
            throw new RuntimeException($label.' must be a positive integer.');
        }

        return $validated;
    }
}
