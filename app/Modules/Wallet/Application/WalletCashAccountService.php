<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class WalletCashAccountService
{
    public function __construct(
        private DatabaseManager $database,
        private WalletHoldService $holds,
    ) {}

    /** @requirement WAL-002 SEC-002 DAT-002 DAT-003 */
    public function forSelf(int $userId, int $actorUserId): WalletCashAccountReceipt
    {
        if ($userId < 1 || $actorUserId !== $userId) {
            throw new AuthorizationException('Wallet cash account self access denied.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use ($userId): WalletCashAccountReceipt {
            /** @var object{id:int|string,account_status:string}|null $user */
            $user = $connection->table('users')->where('id', $userId)->lockForUpdate()->first(['id', 'account_status']);
            if ($user === null || $user->account_status !== 'active') {
                throw new DomainException('Wallet cash account requires an active user.');
            }

            /** @var list<object{id:int|string,account_class:string,owner_user_id:int|string|null,wallet_bucket:string|null,currency:string,is_active:int|bool}> $accounts */
            $accounts = $connection->table('ledger_accounts')
                ->where('owner_user_id', $userId)
                ->where('wallet_bucket', 'cash')
                ->lockForUpdate()
                ->get(['id', 'account_class', 'owner_user_id', 'wallet_bucket', 'currency', 'is_active'])
                ->all();
            if (count($accounts) !== 1) {
                throw new DomainException('Wallet cash account is unavailable.');
            }
            $account = $accounts[0];
            if ($account->account_class !== 'liability'
                || $account->owner_user_id === null
                || (int) $account->owner_user_id !== $userId
                || $account->wallet_bucket !== 'cash'
                || $account->currency !== 'IRR'
                || ! (bool) $account->is_active) {
                throw new DomainException('Wallet cash account authority is invalid.');
            }
            $accountId = filter_var($account->id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($accountId === false) {
                throw new RuntimeException('Wallet cash account ID is invalid.');
            }
            $balance = $this->holds->balanceOnLockedAccount($connection, $userId, (int) $accountId);

            return new WalletCashAccountReceipt(
                (int) $accountId,
                $balance->ledgerBalance->amount,
                $balance->activeHolds->amount,
                $balance->availableBalance->amount,
            );
        }, 3);
    }
}
