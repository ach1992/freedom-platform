<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Modules\Wallet\Domain\WalletSystemAccountCode;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class ReferralRewardWalletService
{
    private const WALLET_BUCKET = 'promotional';

    private const RELEASE_TRANSACTION_TYPE = 'referral_reward_release';

    private const REVERSAL_TRANSACTION_TYPE = 'referral_reward_reversal';

    public function __construct(
        private DatabaseManager $database,
        private LedgerPostingService $ledger,
    ) {}

    /** @requirement REF-001 WAL-002 DAT-002 DAT-003 DAT-004 QUA-001 */
    public function release(
        string $commandKey,
        int $recipientUserId,
        string $rewardPublicId,
        IrrMoney $amount,
        string $correlationId,
    ): LedgerPostingReceipt {
        $this->validateRequest($commandKey, $recipientUserId, $rewardPublicId, $amount, $correlationId);
        [$walletAccountId, $expenseAccountId] = $this->resolveAccounts($recipientUserId);

        return $this->ledger->post(
            $commandKey,
            self::RELEASE_TRANSACTION_TYPE,
            $correlationId,
            [
                new LedgerEntryDraft($expenseAccountId, LedgerDirection::Debit, $amount),
                new LedgerEntryDraft($walletAccountId, LedgerDirection::Credit, $amount),
            ],
            'referral_reward',
            $rewardPublicId,
        );
    }

    /** @requirement REF-001 WAL-002 DAT-002 DAT-003 DAT-004 QUA-001 */
    public function reverse(
        string $commandKey,
        int $recipientUserId,
        string $rewardPublicId,
        int $releaseLedgerTransactionId,
        IrrMoney $amount,
        string $correlationId,
    ): LedgerPostingReceipt {
        $this->validateRequest($commandKey, $recipientUserId, $rewardPublicId, $amount, $correlationId);
        if ($releaseLedgerTransactionId < 1) {
            throw new DomainException('Referral reward release ledger transaction ID must be positive.');
        }

        [$walletAccountId, $expenseAccountId] = $this->resolveAccounts($recipientUserId);
        $this->verifyReleaseEffect(
            $releaseLedgerTransactionId,
            $rewardPublicId,
            $amount,
            $walletAccountId,
            $expenseAccountId,
        );

        return $this->ledger->post(
            $commandKey,
            self::REVERSAL_TRANSACTION_TYPE,
            $correlationId,
            [
                new LedgerEntryDraft($walletAccountId, LedgerDirection::Debit, $amount),
                new LedgerEntryDraft($expenseAccountId, LedgerDirection::Credit, $amount),
            ],
            'referral_reward_reversal',
            $rewardPublicId,
        );
    }

    private function validateRequest(
        string $commandKey,
        int $recipientUserId,
        string $rewardPublicId,
        IrrMoney $amount,
        string $correlationId,
    ): void {
        $this->assertToken($commandKey, 'Referral reward ledger command key', 8, 128);
        if ($recipientUserId < 1) {
            throw new DomainException('Referral reward recipient user ID must be positive.');
        }
        if (! Str::isUlid($rewardPublicId)) {
            throw new DomainException('Referral reward public ID is invalid.');
        }
        if ($amount->isZero()) {
            throw new DomainException('Referral reward wallet amount must be positive.');
        }
        $this->assertToken($correlationId, 'Referral reward ledger correlation ID', 8, 64);
    }

    /** @return array{0:int,1:int} */
    private function resolveAccounts(int $recipientUserId): array
    {
        $connection = $this->database->connection();
        /** @var object{id:int|string,account_class:string,owner_user_id:int|string|null,wallet_bucket:string|null,currency:string,is_active:int|bool}|null $wallet */
        $wallet = $connection->table('ledger_accounts')
            ->where('owner_user_id', $recipientUserId)
            ->where('wallet_bucket', self::WALLET_BUCKET)
            ->first(['id', 'account_class', 'owner_user_id', 'wallet_bucket', 'currency', 'is_active']);
        if ($wallet === null
            || $wallet->account_class !== 'liability'
            || $wallet->owner_user_id === null
            || (int) $wallet->owner_user_id !== $recipientUserId
            || $wallet->wallet_bucket !== self::WALLET_BUCKET
            || $wallet->currency !== 'IRR'
            || ! (bool) $wallet->is_active) {
            throw new DomainException('Referral reward requires an active promotional wallet account.');
        }

        /** @var object{id:int|string,account_class:string,owner_user_id:int|string|null,wallet_bucket:string|null,currency:string,is_active:int|bool}|null $expense */
        $expense = $connection->table('ledger_accounts')
            ->where('code', WalletSystemAccountCode::REFERRAL_REWARD_EXPENSE)
            ->first(['id', 'account_class', 'owner_user_id', 'wallet_bucket', 'currency', 'is_active']);
        if ($expense === null
            || $expense->account_class !== 'expense'
            || $expense->owner_user_id !== null
            || $expense->wallet_bucket !== null
            || $expense->currency !== 'IRR'
            || ! (bool) $expense->is_active) {
            throw new RuntimeException('Referral reward expense ledger account is unavailable or invalid.');
        }

        return [
            $this->positiveInt($wallet->id, 'Referral reward promotional wallet account ID'),
            $this->positiveInt($expense->id, 'Referral reward expense ledger account ID'),
        ];
    }

    private function verifyReleaseEffect(
        int $transactionId,
        string $rewardPublicId,
        IrrMoney $amount,
        int $walletAccountId,
        int $expenseAccountId,
    ): void {
        $connection = $this->database->connection();
        /** @var object{transaction_type:string,expected_total_irr:int|string,posted_debit_irr:int|string,posted_credit_irr:int|string,entry_count:int|string,source_type:string|null,source_id:string|null,finalized_at:string|null}|null $transaction */
        $transaction = $connection->table('ledger_transactions')
            ->where('id', $transactionId)
            ->first([
                'transaction_type', 'expected_total_irr', 'posted_debit_irr', 'posted_credit_irr',
                'entry_count', 'source_type', 'source_id', 'finalized_at',
            ]);
        if ($transaction === null
            || $transaction->transaction_type !== self::RELEASE_TRANSACTION_TYPE
            || $transaction->source_type !== 'referral_reward'
            || ! hash_equals((string) $transaction->source_id, $rewardPublicId)
            || $transaction->finalized_at === null
            || $this->positiveInt($transaction->expected_total_irr, 'Referral reward ledger expected total') !== $amount->amount
            || $this->positiveInt($transaction->posted_debit_irr, 'Referral reward ledger debit total') !== $amount->amount
            || $this->positiveInt($transaction->posted_credit_irr, 'Referral reward ledger credit total') !== $amount->amount
            || $this->positiveInt($transaction->entry_count, 'Referral reward ledger entry count') !== 2) {
            throw new RuntimeException('Referral reward release ledger authority is invalid.');
        }

        /** @var list<object{ledger_account_id:int|string,direction:string,amount_irr:int|string}> $rows */
        $rows = $connection->table('ledger_entries')
            ->where('ledger_transaction_id', $transactionId)
            ->get(['ledger_account_id', 'direction', 'amount_irr'])
            ->all();
        $actual = array_map(
            fn (object $row): array => [
                $this->positiveInt($row->ledger_account_id, 'Referral reward ledger entry account ID'),
                $row->direction,
                $this->positiveInt($row->amount_irr, 'Referral reward ledger entry amount'),
            ],
            $rows,
        );
        $expected = [
            [$expenseAccountId, LedgerDirection::Debit->value, $amount->amount],
            [$walletAccountId, LedgerDirection::Credit->value, $amount->amount],
        ];
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException('Referral reward release ledger entries conflict with accepted authority.');
        }
    }

    private function assertToken(string $value, string $label, int $min, int $max): void
    {
        $length = strlen($value);
        if ($length < $min || $length > $max || preg_match('/\A[A-Za-z0-9:_.-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($integer === false) {
            throw new RuntimeException($label.' must be a positive integer.');
        }

        return $integer;
    }
}
