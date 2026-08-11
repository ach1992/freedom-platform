<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

final readonly class LedgerAccountLockService
{
    /**
     * Acquire the complete ledger-account lock set in ascending account-ID order.
     *
     * @param  list<int>  $accountIds
     * @return array<int, object{id:int|string,code:string,account_class:string,owner_user_id:int|string|null,wallet_bucket:string|null,currency:string,is_active:int|bool}>
     */
    public function lock(Connection $connection, array $accountIds): array
    {
        if ($accountIds === []) {
            throw new DomainException('Ledger account lock set must not be empty.');
        }

        foreach ($accountIds as $accountId) {
            if ($accountId < 1) {
                throw new DomainException('Ledger account lock ID must be positive.');
            }
        }

        $orderedIds = array_values(array_unique($accountIds));
        sort($orderedIds, SORT_NUMERIC);

        /** @var list<object{id:int|string,code:string,account_class:string,owner_user_id:int|string|null,wallet_bucket:string|null,currency:string,is_active:int|bool}> $accounts */
        $accounts = $connection->table('ledger_accounts')
            ->whereIn('id', $orderedIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'code', 'account_class', 'owner_user_id', 'wallet_bucket', 'currency', 'is_active'])
            ->all();

        $byId = [];
        foreach ($accounts as $account) {
            $accountId = $this->positiveDatabaseInt($account->id);
            $byId[$accountId] = $account;
        }

        return $byId;
    }

    private function positiveDatabaseInt(mixed $value): int
    {
        if (is_int($value)) {
            if ($value < 1) {
                throw new RuntimeException('Locked ledger account ID must be positive.');
            }

            return $value;
        }
        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            throw new RuntimeException('Locked ledger account ID is malformed.');
        }

        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)
        ) {
            throw new RuntimeException('Locked ledger account ID exceeds the supported integer range.');
        }

        return (int) $value;
    }
}
