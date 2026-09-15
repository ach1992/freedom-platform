<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use DomainException;
use Illuminate\Database\Connection;

/**
 * Acquires a complete ledger-account participant set in canonical numeric ID order.
 *
 * Caller-specific account validation remains with the owning application service.
 */
final class LedgerAccountLockSet
{
    /**
     * @param  list<int>  $accountIds
     * @return array<int, object{id:int|string,account_class:string,owner_user_id:int|string|null,wallet_bucket:string|null,currency:string,is_active:int|bool}>
     */
    public static function acquire(Connection $connection, array $accountIds): array
    {
        foreach ($accountIds as $accountId) {
            if ($accountId < 1) {
                throw new DomainException('Ledger account lock set contains an invalid account ID.');
            }
        }

        $accountIds = array_values(array_unique($accountIds));
        sort($accountIds, SORT_NUMERIC);

        /** @var list<object{id:int|string,account_class:string,owner_user_id:int|string|null,wallet_bucket:string|null,currency:string,is_active:int|bool}> $accounts */
        $accounts = $connection->table('ledger_accounts')
            ->whereIn('id', $accountIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'account_class', 'owner_user_id', 'wallet_bucket', 'currency', 'is_active'])
            ->all();

        $byId = [];
        foreach ($accounts as $account) {
            $byId[(int) $account->id] = $account;
        }

        return $byId;
    }

    private function __construct() {}
}
