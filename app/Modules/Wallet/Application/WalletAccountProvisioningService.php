<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class WalletAccountProvisioningService
{
    private const PROMOTIONAL_BUCKET = 'promotional';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    /** @requirement WAL-002 DAT-002 DAT-003 QUA-001 */
    public function ensurePromotionalForUser(int $userId): int
    {
        if ($userId < 1) {
            throw new DomainException('Wallet account owner user ID must be positive.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use ($userId): int {
            $lockedUserId = $connection->table('users')
                ->where('id', $userId)
                ->lockForUpdate()
                ->value('id');
            if ($lockedUserId === null) {
                throw new DomainException('Wallet account owner does not exist.');
            }

            /** @var object{id:int|string,code:string,account_class:string,owner_user_id:int|string|null,wallet_bucket:string|null,currency:string,is_active:int|bool}|null $existing */
            $existing = $connection->table('ledger_accounts')
                ->where('owner_user_id', $userId)
                ->where('wallet_bucket', self::PROMOTIONAL_BUCKET)
                ->first(['id', 'code', 'account_class', 'owner_user_id', 'wallet_bucket', 'currency', 'is_active']);
            if ($existing !== null) {
                return $this->validatedAccountId($existing, $userId);
            }

            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $accountId = (int) $connection->table('ledger_accounts')->insertGetId([
                'code' => 'wallet.user.'.$userId.'.promotional',
                'account_class' => 'liability',
                'owner_user_id' => $userId,
                'wallet_bucket' => self::PROMOTIONAL_BUCKET,
                'currency' => 'IRR',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            /** @var object{id:int|string,code:string,account_class:string,owner_user_id:int|string|null,wallet_bucket:string|null,currency:string,is_active:int|bool}|null $created */
            $created = $connection->table('ledger_accounts')->where('id', $accountId)->first([
                'id', 'code', 'account_class', 'owner_user_id', 'wallet_bucket', 'currency', 'is_active',
            ]);
            if ($created === null) {
                throw new RuntimeException('Promotional wallet account provisioning failed.');
            }

            return $this->validatedAccountId($created, $userId);
        }, 3);
    }

    /** @param object{id:int|string,code:string,account_class:string,owner_user_id:int|string|null,wallet_bucket:string|null,currency:string,is_active:int|bool} $account */
    private function validatedAccountId(object $account, int $userId): int
    {
        if ($account->account_class !== 'liability'
            || $account->owner_user_id === null
            || (int) $account->owner_user_id !== $userId
            || $account->wallet_bucket !== self::PROMOTIONAL_BUCKET
            || $account->currency !== 'IRR'
            || ! (bool) $account->is_active) {
            throw new DomainException('Promotional wallet account authority is unavailable or invalid.');
        }

        $accountId = filter_var($account->id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($accountId === false) {
            throw new RuntimeException('Promotional wallet account ID must be a positive integer.');
        }

        return $accountId;
    }
}
