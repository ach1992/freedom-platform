<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Modules\Wallet\Domain\WalletHoldStatus;
use App\Modules\Wallet\Domain\WalletTransferStatus;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * @phpstan-type WalletTransferRow object{
 *     id: int|string,
 *     transfer_key: string,
 *     payload_hash: string,
 *     sender_user_id: int|string,
 *     recipient_user_id: int|string,
 *     recipient_public_id: string,
 *     sender_wallet_account_id: int|string,
 *     recipient_wallet_account_id: int|string,
 *     wallet_bucket: string,
 *     amount_irr: int|string,
 *     fee_irr: int|string,
 *     total_debit_irr: int|string,
 *     policy_minimum_irr: int|string,
 *     policy_maximum_irr: int|string,
 *     policy_daily_limit_irr: int|string,
 *     policy_fixed_fee_irr: int|string,
 *     policy_fee_basis_points: int|string,
 *     fee_account_code: string|null,
 *     policy_business_date: string,
 *     wallet_hold_id: int|string,
 *     status: string,
 *     confirmation_key: string|null,
 *     ledger_transaction_id: int|string|null,
 *     confirmation_expires_at: string,
 *     confirmed_at: string|null,
 *     completed_at: string|null,
 *     cancelled_at: string|null,
 *     cancel_reason: string|null
 * }
 * @phpstan-type TransferPolicy array{
 *     minimum: int,
 *     maximum: int,
 *     daily_limit: int,
 *     fixed_fee: int,
 *     fee_basis_points: int,
 *     confirmation_ttl_seconds: int,
 *     fee_account_code: string|null
 * }
 */
final readonly class WalletTransferService
{
    private const TRANSFER_TYPE = 'wallet_transfer';

    private const EXPIRED_REASON = 'transfer confirmation expired';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private WalletHoldService $holds,
        private LedgerPostingService $ledger,
    ) {}

    /** @requirement WAL-003 DAT-002 DAT-003 DAT-004 QUA-001 */
    public function prepare(
        string $transferKey,
        int $senderUserId,
        string $recipientPublicId,
        string $walletBucket,
        IrrMoney $amount,
    ): WalletTransferReceipt {
        $this->assertToken($transferKey, 'Wallet transfer key', 8, 128);
        $this->assertPositiveId($senderUserId, 'Wallet transfer sender user ID');
        if ($amount->isZero()) {
            throw new DomainException('Wallet transfer amount must be positive.');
        }

        $recipientPublicId = $this->canonicalPublicId($recipientPublicId);
        $payloadHash = $this->requestHash($senderUserId, $recipientPublicId, $walletBucket, $amount);

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $transferKey,
                $senderUserId,
                $recipientPublicId,
                $walletBucket,
                $amount,
                $payloadHash,
            ): WalletTransferReceipt {
                $existing = $this->lockedTransferByKey($connection, $transferKey);
                if ($existing !== null) {
                    return $this->receiptFromExisting($existing, $payloadHash, true);
                }

                $policy = $this->transferPolicy($walletBucket, $amount);

                /** @var object{id: int|string}|null $recipientCandidate */
                $recipientCandidate = $connection->table('users')
                    ->where('public_id', $recipientPublicId)
                    ->first(['id']);
                if ($recipientCandidate === null) {
                    throw new DomainException('Wallet transfer recipient does not exist.');
                }
                $recipientUserId = $this->positiveDatabaseInt($recipientCandidate->id, 'Wallet transfer recipient user ID');
                if ($recipientUserId === $senderUserId) {
                    throw new DomainException('Wallet transfer sender and recipient must differ.');
                }

                $users = $this->lockAndValidateUsers($connection, $senderUserId, $recipientUserId, $recipientPublicId);
                unset($users);

                [$senderWalletAccountId, $recipientWalletAccountId] = $this->lockTransferWalletAccounts(
                    $connection,
                    $senderUserId,
                    $recipientUserId,
                    $walletBucket,
                );

                $businessDate = $this->businessDate();
                $usedToday = $this->nonNegativeDatabaseInt(
                    $connection->table('wallet_transfers')
                        ->where('sender_user_id', $senderUserId)
                        ->where('policy_business_date', $businessDate)
                        ->whereIn('status', [
                            WalletTransferStatus::PendingConfirmation->value,
                            WalletTransferStatus::Completed->value,
                        ])
                        ->sum('amount_irr'),
                    'Wallet transfer daily usage',
                );
                if ($amount->amount > $policy['daily_limit'] - $usedToday) {
                    throw new DomainException('Wallet transfer daily limit would be exceeded.');
                }

                $fee = IrrMoney::fromInt($this->feeAmount($amount->amount, $policy));
                $totalDebit = $amount->add($fee);
                $feeAccountCode = null;
                if (! $fee->isZero()) {
                    $feeAccountCode = $policy['fee_account_code'];
                    if ($feeAccountCode === null) {
                        throw new RuntimeException('Wallet transfer fee account is not configured.');
                    }
                    $this->resolveFeeAccountId($connection, $feeAccountCode, true);
                }

                $confirmationExpiresAt = $this->clock->now()->modify(sprintf('+%d seconds', $policy['confirmation_ttl_seconds']));
                $hold = $this->holds->place(
                    $this->holdKey($transferKey),
                    $senderUserId,
                    $senderWalletAccountId,
                    $totalDebit,
                    self::TRANSFER_TYPE,
                    $transferKey,
                    $confirmationExpiresAt,
                );

                $transferId = (int) $connection->table('wallet_transfers')->insertGetId([
                    'transfer_key' => $transferKey,
                    'payload_hash' => $payloadHash,
                    'sender_user_id' => $senderUserId,
                    'recipient_user_id' => $recipientUserId,
                    'recipient_public_id' => $recipientPublicId,
                    'sender_wallet_account_id' => $senderWalletAccountId,
                    'recipient_wallet_account_id' => $recipientWalletAccountId,
                    'wallet_bucket' => $walletBucket,
                    'amount_irr' => $amount->amount,
                    'fee_irr' => $fee->amount,
                    'total_debit_irr' => $totalDebit->amount,
                    'policy_minimum_irr' => $policy['minimum'],
                    'policy_maximum_irr' => $policy['maximum'],
                    'policy_daily_limit_irr' => $policy['daily_limit'],
                    'policy_fixed_fee_irr' => $policy['fixed_fee'],
                    'policy_fee_basis_points' => $policy['fee_basis_points'],
                    'fee_account_code' => $feeAccountCode,
                    'policy_business_date' => $businessDate,
                    'wallet_hold_id' => $hold->holdId,
                    'status' => WalletTransferStatus::PendingConfirmation->value,
                    'confirmation_key' => null,
                    'ledger_transaction_id' => null,
                    'confirmation_expires_at' => $confirmationExpiresAt->format('Y-m-d H:i:s.u'),
                    'confirmed_at' => null,
                    'completed_at' => null,
                    'cancelled_at' => null,
                    'cancel_reason' => null,
                    'created_at' => $this->timestamp(),
                ]);

                return new WalletTransferReceipt(
                    $transferId,
                    WalletTransferStatus::PendingConfirmation,
                    $recipientUserId,
                    $recipientPublicId,
                    $walletBucket,
                    $amount,
                    $fee,
                    $totalDebit,
                    $hold->holdId,
                    null,
                    false,
                );
            });
        } catch (QueryException $exception) {
            $replay = $this->replayAfterUniqueRace($transferKey, $payloadHash);
            if ($replay !== null) {
                return $replay;
            }

            throw $exception;
        }
    }

    /** @requirement WAL-003 DAT-002 DAT-003 DAT-004 QUA-001 */
    public function confirm(string $transferKey, string $confirmationKey, string $correlationId): WalletTransferReceipt
    {
        $this->assertToken($transferKey, 'Wallet transfer key', 8, 128);
        $this->assertToken($confirmationKey, 'Wallet transfer confirmation key', 8, 128);
        $this->assertToken($correlationId, 'Wallet transfer correlation ID', 8, 64);

        return $this->database->connection()->transaction(function (Connection $connection) use ($transferKey, $confirmationKey, $correlationId): WalletTransferReceipt {
            $transfer = $this->lockedTransferByKey($connection, $transferKey);
            if ($transfer === null) {
                throw new DomainException('Wallet transfer does not exist.');
            }

            $status = $this->transferStatus($transfer->status);
            if ($status === WalletTransferStatus::Completed) {
                if ($transfer->confirmation_key === null || ! hash_equals($transfer->confirmation_key, $confirmationKey)) {
                    throw new RuntimeException('Wallet transfer confirmation replay conflicts with the accepted confirmation.');
                }
                $this->verifyCompletedLedgerEffect($connection, $transfer);

                return $this->receiptFromExisting($transfer, null, true);
            }
            if ($status === WalletTransferStatus::Cancelled) {
                throw new RuntimeException('Cancelled wallet transfer cannot be confirmed.');
            }

            $now = $this->clock->now();
            if ($this->databaseDateTime($transfer->confirmation_expires_at, 'Wallet transfer confirmation expiry') <= $now) {
                return $this->cancelLocked($connection, $transfer, self::EXPIRED_REASON, true);
            }

            $this->assertCurrentPolicyAllows($connection, $transfer);
            $senderUserId = $this->positiveDatabaseInt($transfer->sender_user_id, 'Wallet transfer sender user ID');
            $recipientUserId = $this->positiveDatabaseInt($transfer->recipient_user_id, 'Wallet transfer recipient user ID');
            $this->lockAndValidateUsers($connection, $senderUserId, $recipientUserId, $transfer->recipient_public_id);
            $this->lockAndValidateStoredWalletAccounts($connection, $transfer);

            $feeAccountId = null;
            $fee = IrrMoney::fromInt($this->nonNegativeDatabaseInt($transfer->fee_irr, 'Wallet transfer fee'));
            if (! $fee->isZero()) {
                if ($transfer->fee_account_code === null) {
                    throw new RuntimeException('Wallet transfer fee account snapshot is missing.');
                }
                $feeAccountId = $this->resolveFeeAccountId($connection, $transfer->fee_account_code, true);
            }

            $hold = $this->lockedTransferHold($connection, $transfer);
            if ($this->databaseDateTime($hold->expires_at, 'Wallet transfer hold expiry') <= $now) {
                return $this->cancelLocked($connection, $transfer, self::EXPIRED_REASON, true);
            }

            $senderWalletId = $this->positiveDatabaseInt($transfer->sender_wallet_account_id, 'Wallet transfer sender wallet account ID');
            $recipientWalletId = $this->positiveDatabaseInt($transfer->recipient_wallet_account_id, 'Wallet transfer recipient wallet account ID');
            $amount = IrrMoney::positive($this->positiveDatabaseInt($transfer->amount_irr, 'Wallet transfer amount'));
            $totalDebit = IrrMoney::positive($this->positiveDatabaseInt($transfer->total_debit_irr, 'Wallet transfer total debit'));
            $transferId = $this->positiveDatabaseInt($transfer->id, 'Wallet transfer ID');

            $entries = [
                new LedgerEntryDraft($senderWalletId, LedgerDirection::Debit, $totalDebit),
                new LedgerEntryDraft($recipientWalletId, LedgerDirection::Credit, $amount),
            ];
            if ($feeAccountId !== null) {
                $entries[] = new LedgerEntryDraft($feeAccountId, LedgerDirection::Credit, $fee);
            }

            $ledgerReceipt = $this->ledger->post(
                $this->ledgerCommandKey($transferKey),
                self::TRANSFER_TYPE,
                $correlationId,
                $entries,
                self::TRANSFER_TYPE,
                (string) $transferId,
            );

            $holdId = $this->positiveDatabaseInt($transfer->wallet_hold_id, 'Wallet transfer hold ID');
            $holdUpdated = $connection->table('wallet_holds')
                ->where('id', $holdId)
                ->where('status', WalletHoldStatus::Active->value)
                ->update([
                    'status' => WalletHoldStatus::Captured->value,
                    'captured_ledger_transaction_id' => $ledgerReceipt->transactionId,
                    'captured_at' => $this->timestamp(),
                ]);
            if ($holdUpdated !== 1) {
                throw new RuntimeException('Wallet transfer hold capture transition failed.');
            }

            $timestamp = $this->timestamp();
            $updated = $connection->table('wallet_transfers')
                ->where('id', $transferId)
                ->where('status', WalletTransferStatus::PendingConfirmation->value)
                ->update([
                    'status' => WalletTransferStatus::Completed->value,
                    'confirmation_key' => $confirmationKey,
                    'ledger_transaction_id' => $ledgerReceipt->transactionId,
                    'confirmed_at' => $timestamp,
                    'completed_at' => $timestamp,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Wallet transfer completion transition failed.');
            }

            return new WalletTransferReceipt(
                $transferId,
                WalletTransferStatus::Completed,
                $recipientUserId,
                $transfer->recipient_public_id,
                $transfer->wallet_bucket,
                $amount,
                $fee,
                $totalDebit,
                $holdId,
                $ledgerReceipt->transactionId,
                false,
            );
        });
    }

    /** @requirement WAL-003 DAT-004 QUA-001 */
    public function cancel(string $transferKey, string $reason): WalletTransferReceipt
    {
        $this->assertToken($transferKey, 'Wallet transfer key', 8, 128);
        $this->assertPrintable($reason, 'Wallet transfer cancellation reason', 3, 191);

        return $this->database->connection()->transaction(function (Connection $connection) use ($transferKey, $reason): WalletTransferReceipt {
            $transfer = $this->lockedTransferByKey($connection, $transferKey);
            if ($transfer === null) {
                throw new DomainException('Wallet transfer does not exist.');
            }

            $status = $this->transferStatus($transfer->status);
            if ($status === WalletTransferStatus::Completed) {
                throw new RuntimeException('Completed wallet transfer cannot be cancelled.');
            }
            if ($status === WalletTransferStatus::Cancelled) {
                if ($transfer->cancel_reason === null || ! hash_equals($transfer->cancel_reason, $reason)) {
                    throw new RuntimeException('Wallet transfer cancellation replay conflicts with the accepted reason.');
                }

                return $this->receiptFromExisting($transfer, null, true);
            }

            return $this->cancelLocked($connection, $transfer, $reason, false);
        });
    }

    /** @param WalletTransferRow $transfer */
    private function cancelLocked(Connection $connection, object $transfer, string $reason, bool $systemExpiry): WalletTransferReceipt
    {
        $hold = $this->lockedTransferHold($connection, $transfer);
        $holdStatus = $this->holdStatus($hold->status);
        if ($holdStatus === WalletHoldStatus::Captured) {
            throw new RuntimeException('Captured wallet transfer hold cannot be cancelled.');
        }
        if ($holdStatus === WalletHoldStatus::Active) {
            $released = $this->holds->release($hold->hold_key, $reason);
            if ($released->holdId !== $this->positiveDatabaseInt($transfer->wallet_hold_id, 'Wallet transfer hold ID')) {
                throw new RuntimeException('Wallet transfer cancellation released a different hold.');
            }
        } elseif ($holdStatus === WalletHoldStatus::Released) {
            if ($hold->release_reason === null || ! hash_equals($hold->release_reason, $reason)) {
                throw new RuntimeException('Wallet transfer hold release conflicts with transfer cancellation.');
            }
        } else {
            throw new RuntimeException('Wallet transfer hold state is invalid.');
        }

        $transferId = $this->positiveDatabaseInt($transfer->id, 'Wallet transfer ID');
        $updated = $connection->table('wallet_transfers')
            ->where('id', $transferId)
            ->where('status', WalletTransferStatus::PendingConfirmation->value)
            ->update([
                'status' => WalletTransferStatus::Cancelled->value,
                'cancelled_at' => $this->timestamp(),
                'cancel_reason' => $reason,
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Wallet transfer cancellation transition failed.');
        }

        return new WalletTransferReceipt(
            $transferId,
            WalletTransferStatus::Cancelled,
            $this->positiveDatabaseInt($transfer->recipient_user_id, 'Wallet transfer recipient user ID'),
            $transfer->recipient_public_id,
            $transfer->wallet_bucket,
            IrrMoney::positive($this->positiveDatabaseInt($transfer->amount_irr, 'Wallet transfer amount')),
            IrrMoney::fromInt($this->nonNegativeDatabaseInt($transfer->fee_irr, 'Wallet transfer fee')),
            IrrMoney::positive($this->positiveDatabaseInt($transfer->total_debit_irr, 'Wallet transfer total debit')),
            $this->positiveDatabaseInt($transfer->wallet_hold_id, 'Wallet transfer hold ID'),
            null,
            $systemExpiry,
        );
    }

    /** @param WalletTransferRow $transfer */
    private function assertCurrentPolicyAllows(Connection $connection, object $transfer): void
    {
        $amount = IrrMoney::positive($this->positiveDatabaseInt($transfer->amount_irr, 'Wallet transfer amount'));
        $policy = $this->transferPolicy($transfer->wallet_bucket, $amount);
        $currentDailyUsage = $this->nonNegativeDatabaseInt(
            $connection->table('wallet_transfers')
                ->where('sender_user_id', $this->positiveDatabaseInt($transfer->sender_user_id, 'Wallet transfer sender user ID'))
                ->where('policy_business_date', $transfer->policy_business_date)
                ->whereIn('status', [
                    WalletTransferStatus::PendingConfirmation->value,
                    WalletTransferStatus::Completed->value,
                ])
                ->sum('amount_irr'),
            'Wallet transfer current daily usage',
        );
        if ($currentDailyUsage > $policy['daily_limit']) {
            throw new DomainException('Wallet transfer current daily limit is exceeded.');
        }
    }

    /**
     * @return array{0: object{id: int|string, public_id: string, account_type: string, account_status: string}, 1: object{id: int|string, public_id: string, account_type: string, account_status: string}}
     */
    private function lockAndValidateUsers(Connection $connection, int $senderUserId, int $recipientUserId, string $recipientPublicId): array
    {
        $ids = [$senderUserId, $recipientUserId];
        sort($ids, SORT_NUMERIC);
        /** @var list<object{id: int|string, public_id: string, account_type: string, account_status: string}> $users */
        $users = $connection->table('users')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'public_id', 'account_type', 'account_status'])
            ->all();
        if (count($users) !== 2) {
            throw new DomainException('Wallet transfer user is unavailable.');
        }

        $byId = [];
        foreach ($users as $user) {
            $userId = $this->positiveDatabaseInt($user->id, 'Wallet transfer user ID');
            if ($user->account_type !== 'customer' || $user->account_status !== 'active') {
                throw new DomainException('Wallet transfer users must be active customers.');
            }
            $byId[$userId] = $user;
        }
        if (! isset($byId[$senderUserId], $byId[$recipientUserId])) {
            throw new RuntimeException('Wallet transfer locked user set is inconsistent.');
        }
        if (! hash_equals($this->canonicalPublicId($byId[$recipientUserId]->public_id), $recipientPublicId)) {
            throw new RuntimeException('Wallet transfer recipient identity changed after preparation.');
        }

        return [$byId[$senderUserId], $byId[$recipientUserId]];
    }

    /** @return array{0: int, 1: int} */
    private function lockTransferWalletAccounts(
        Connection $connection,
        int $senderUserId,
        int $recipientUserId,
        string $walletBucket,
    ): array {
        /** @var list<object{id: int|string, account_class: string, owner_user_id: int|string|null, wallet_bucket: string|null, currency: string, is_active: int|bool}> $accounts */
        $accounts = $connection->table('ledger_accounts')
            ->whereIn('owner_user_id', [$senderUserId, $recipientUserId])
            ->where('wallet_bucket', $walletBucket)
            ->where('currency', 'IRR')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'account_class', 'owner_user_id', 'wallet_bucket', 'currency', 'is_active'])
            ->all();
        if (count($accounts) !== 2) {
            throw new DomainException('Wallet transfer requires both sender and recipient wallet buckets.');
        }

        $byOwner = [];
        foreach ($accounts as $account) {
            if ($account->account_class !== 'liability'
                || $account->owner_user_id === null
                || $account->wallet_bucket !== $walletBucket
                || $account->currency !== 'IRR'
                || ! (bool) $account->is_active
            ) {
                throw new DomainException('Wallet transfer wallet account is invalid or inactive.');
            }
            $byOwner[(int) $account->owner_user_id] = $this->positiveDatabaseInt($account->id, 'Wallet transfer wallet account ID');
        }
        if (! isset($byOwner[$senderUserId], $byOwner[$recipientUserId])) {
            throw new RuntimeException('Wallet transfer wallet mapping is inconsistent.');
        }

        return [$byOwner[$senderUserId], $byOwner[$recipientUserId]];
    }

    /** @param WalletTransferRow $transfer */
    private function lockAndValidateStoredWalletAccounts(Connection $connection, object $transfer): void
    {
        $senderAccountId = $this->positiveDatabaseInt($transfer->sender_wallet_account_id, 'Wallet transfer sender wallet account ID');
        $recipientAccountId = $this->positiveDatabaseInt($transfer->recipient_wallet_account_id, 'Wallet transfer recipient wallet account ID');
        $ids = [$senderAccountId, $recipientAccountId];
        sort($ids, SORT_NUMERIC);

        /** @var list<object{id: int|string, account_class: string, owner_user_id: int|string|null, wallet_bucket: string|null, currency: string, is_active: int|bool}> $accounts */
        $accounts = $connection->table('ledger_accounts')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'account_class', 'owner_user_id', 'wallet_bucket', 'currency', 'is_active'])
            ->all();
        if (count($accounts) !== 2) {
            throw new DomainException('Wallet transfer wallet account is unavailable.');
        }

        $expectedOwners = [
            $senderAccountId => $this->positiveDatabaseInt($transfer->sender_user_id, 'Wallet transfer sender user ID'),
            $recipientAccountId => $this->positiveDatabaseInt($transfer->recipient_user_id, 'Wallet transfer recipient user ID'),
        ];
        foreach ($accounts as $account) {
            $accountId = $this->positiveDatabaseInt($account->id, 'Wallet transfer wallet account ID');
            if ($account->account_class !== 'liability'
                || $account->owner_user_id === null
                || (int) $account->owner_user_id !== $expectedOwners[$accountId]
                || $account->wallet_bucket !== $transfer->wallet_bucket
                || $account->currency !== 'IRR'
                || ! (bool) $account->is_active
            ) {
                throw new DomainException('Wallet transfer wallet account changed or became inactive.');
            }
        }
    }

    private function resolveFeeAccountId(Connection $connection, string $code, bool $requireActive): int
    {
        /** @var object{id: int|string, owner_user_id: int|string|null, wallet_bucket: string|null, currency: string, is_active: int|bool}|null $account */
        $account = $connection->table('ledger_accounts')
            ->where('code', $code)
            ->first(['id', 'owner_user_id', 'wallet_bucket', 'currency', 'is_active']);
        if ($account === null
            || $account->owner_user_id !== null
            || $account->wallet_bucket !== null
            || $account->currency !== 'IRR'
            || ($requireActive && ! (bool) $account->is_active)
        ) {
            throw new DomainException('Wallet transfer fee account is invalid or inactive.');
        }

        return $this->positiveDatabaseInt($account->id, 'Wallet transfer fee account ID');
    }

    /**
     * @param WalletTransferRow $transfer
     * @return object{id: int|string, hold_key: string, ledger_account_id: int|string, amount_irr: int|string, source_type: string, source_id: string, status: string, expires_at: string, captured_ledger_transaction_id: int|string|null, release_reason: string|null}
     */
    private function lockedTransferHold(Connection $connection, object $transfer): object
    {
        /** @var object{id: int|string, hold_key: string, ledger_account_id: int|string, amount_irr: int|string, source_type: string, source_id: string, status: string, expires_at: string, captured_ledger_transaction_id: int|string|null, release_reason: string|null}|null $hold */
        $hold = $connection->table('wallet_holds')
            ->where('id', $this->positiveDatabaseInt($transfer->wallet_hold_id, 'Wallet transfer hold ID'))
            ->lockForUpdate()
            ->first(['id', 'hold_key', 'ledger_account_id', 'amount_irr', 'source_type', 'source_id', 'status', 'expires_at', 'captured_ledger_transaction_id', 'release_reason']);
        if ($hold === null
            || $hold->source_type !== self::TRANSFER_TYPE
            || ! hash_equals($hold->source_id, $transfer->transfer_key)
            || $this->positiveDatabaseInt($hold->ledger_account_id, 'Wallet transfer hold wallet ID') !== $this->positiveDatabaseInt($transfer->sender_wallet_account_id, 'Wallet transfer sender wallet account ID')
            || $this->positiveDatabaseInt($hold->amount_irr, 'Wallet transfer hold amount') !== $this->positiveDatabaseInt($transfer->total_debit_irr, 'Wallet transfer total debit')
            || ! hash_equals($hold->hold_key, $this->holdKey($transfer->transfer_key))
        ) {
            throw new RuntimeException('Wallet transfer hold integrity check failed.');
        }

        return $hold;
    }

    /** @param WalletTransferRow $transfer */
    private function verifyCompletedLedgerEffect(Connection $connection, object $transfer): void
    {
        $transactionId = $this->positiveDatabaseInt($transfer->ledger_transaction_id, 'Wallet transfer ledger transaction ID');
        $transferId = $this->positiveDatabaseInt($transfer->id, 'Wallet transfer ID');
        $totalDebit = $this->positiveDatabaseInt($transfer->total_debit_irr, 'Wallet transfer total debit');
        $amount = $this->positiveDatabaseInt($transfer->amount_irr, 'Wallet transfer amount');
        $fee = $this->nonNegativeDatabaseInt($transfer->fee_irr, 'Wallet transfer fee');

        /** @var object{transaction_type: string, expected_total_irr: int|string, posted_debit_irr: int|string, posted_credit_irr: int|string, entry_count: int|string, source_type: string|null, source_id: string|null, finalized_at: string|null}|null $transaction */
        $transaction = $connection->table('ledger_transactions')
            ->where('id', $transactionId)
            ->first(['transaction_type', 'expected_total_irr', 'posted_debit_irr', 'posted_credit_irr', 'entry_count', 'source_type', 'source_id', 'finalized_at']);
        $expectedCount = $fee > 0 ? 3 : 2;
        if ($transaction === null
            || $transaction->transaction_type !== self::TRANSFER_TYPE
            || $transaction->source_type !== self::TRANSFER_TYPE
            || $transaction->source_id !== (string) $transferId
            || $transaction->finalized_at === null
            || $this->positiveDatabaseInt($transaction->expected_total_irr, 'Wallet transfer ledger expected total') !== $totalDebit
            || $this->positiveDatabaseInt($transaction->posted_debit_irr, 'Wallet transfer ledger debit total') !== $totalDebit
            || $this->positiveDatabaseInt($transaction->posted_credit_irr, 'Wallet transfer ledger credit total') !== $totalDebit
            || $this->positiveDatabaseInt($transaction->entry_count, 'Wallet transfer ledger entry count') !== $expectedCount
        ) {
            throw new RuntimeException('Wallet transfer ledger integrity check failed.');
        }

        /** @var list<object{ledger_account_id: int|string, direction: string, amount_irr: int|string}> $entries */
        $entries = $connection->table('ledger_entries')
            ->where('ledger_transaction_id', $transactionId)
            ->get(['ledger_account_id', 'direction', 'amount_irr'])
            ->all();
        if (count($entries) !== $expectedCount) {
            throw new RuntimeException('Wallet transfer ledger entry integrity check failed.');
        }

        $expected = [
            [
                $this->positiveDatabaseInt($transfer->sender_wallet_account_id, 'Wallet transfer sender wallet account ID'),
                LedgerDirection::Debit->value,
                $totalDebit,
            ],
            [
                $this->positiveDatabaseInt($transfer->recipient_wallet_account_id, 'Wallet transfer recipient wallet account ID'),
                LedgerDirection::Credit->value,
                $amount,
            ],
        ];
        if ($fee > 0) {
            if ($transfer->fee_account_code === null) {
                throw new RuntimeException('Wallet transfer fee account snapshot is missing.');
            }
            $expected[] = [
                $this->resolveFeeAccountId($connection, $transfer->fee_account_code, false),
                LedgerDirection::Credit->value,
                $fee,
            ];
        }

        $actual = array_map(fn (object $entry): array => [
            $this->positiveDatabaseInt($entry->ledger_account_id, 'Wallet transfer ledger entry account ID'),
            $entry->direction,
            $this->positiveDatabaseInt($entry->amount_irr, 'Wallet transfer ledger entry amount'),
        ], $entries);
        sort($expected);
        sort($actual);
        if ($expected !== $actual) {
            throw new RuntimeException('Wallet transfer ledger entry set conflicts with the accepted effect.');
        }
    }

    /** @return TransferPolicy */
    private function transferPolicy(string $walletBucket, IrrMoney $amount): array
    {
        if (config('wallet.transfers.enabled', false) !== true) {
            throw new DomainException('Wallet transfers are disabled.');
        }

        $allowed = config('wallet.transfers.allowed_buckets', []);
        if (! is_array($allowed) || $allowed === []) {
            throw new RuntimeException('Wallet transfer allowed-bucket policy is invalid.');
        }
        $allowedBuckets = [];
        foreach ($allowed as $bucket) {
            if (! is_string($bucket) || ! in_array($bucket, ['cash', 'promotional'], true)) {
                throw new RuntimeException('Wallet transfer allowed-bucket policy is invalid.');
            }
            $allowedBuckets[] = $bucket;
        }
        if (! in_array($walletBucket, array_values(array_unique($allowedBuckets)), true)) {
            throw new DomainException('Wallet transfer bucket is not allowed.');
        }

        $minimum = $this->configPositiveInt('wallet.transfers.minimum_irr');
        $maximum = $this->configPositiveInt('wallet.transfers.maximum_irr');
        $dailyLimit = $this->configPositiveInt('wallet.transfers.daily_limit_irr');
        $fixedFee = $this->configNonNegativeInt('wallet.transfers.fixed_fee_irr');
        $feeBasisPoints = $this->configNonNegativeInt('wallet.transfers.fee_basis_points');
        $ttl = $this->configPositiveInt('wallet.transfers.confirmation_ttl_seconds');
        if ($maximum < $minimum || $dailyLimit < $maximum || $feeBasisPoints > 10000 || $ttl > 86400) {
            throw new RuntimeException('Wallet transfer policy is invalid.');
        }
        if ($amount->amount < $minimum || $amount->amount > $maximum) {
            throw new DomainException('Wallet transfer amount is outside the configured limits.');
        }

        $feeAccountCode = config('wallet.transfers.fee_account_code');
        if ($feeAccountCode !== null) {
            if (! is_string($feeAccountCode)) {
                throw new RuntimeException('Wallet transfer fee account policy is invalid.');
            }
            $this->assertToken($feeAccountCode, 'Wallet transfer fee account code', 3, 128);
        }

        return [
            'minimum' => $minimum,
            'maximum' => $maximum,
            'daily_limit' => $dailyLimit,
            'fixed_fee' => $fixedFee,
            'fee_basis_points' => $feeBasisPoints,
            'confirmation_ttl_seconds' => $ttl,
            'fee_account_code' => $feeAccountCode,
        ];
    }

    /** @param TransferPolicy $policy */
    private function feeAmount(int $amount, array $policy): int
    {
        $basisPoints = $policy['fee_basis_points'];
        $whole = intdiv($amount, 10000);
        if ($basisPoints > 0 && $whole > intdiv(PHP_INT_MAX, $basisPoints)) {
            throw new DomainException('Wallet transfer fee exceeds the supported integer range.');
        }
        $percentage = $whole * $basisPoints;
        $remainderProduct = ($amount % 10000) * $basisPoints;
        if ($remainderProduct > 0) {
            $percentage = $this->safeAdd($percentage, intdiv($remainderProduct + 9999, 10000));
        }

        return $this->safeAdd($policy['fixed_fee'], $percentage);
    }

    private function safeAdd(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $left > PHP_INT_MAX - $right) {
            throw new DomainException('Wallet transfer amount exceeds the supported integer range.');
        }

        return $left + $right;
    }

    private function requestHash(int $senderUserId, string $recipientPublicId, string $walletBucket, IrrMoney $amount): string
    {
        return hash('sha256', json_encode([
            'sender_user_id' => $senderUserId,
            'recipient_public_id' => $recipientPublicId,
            'wallet_bucket' => $walletBucket,
            'amount_irr' => $amount->amount,
        ], JSON_THROW_ON_ERROR));
    }

    private function businessDate(): string
    {
        $timezone = config('business.display_timezone');
        if (! is_string($timezone) || trim($timezone) === '') {
            throw new RuntimeException('Business timezone is invalid.');
        }

        try {
            return $this->clock->now()->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
        } catch (\Throwable $throwable) {
            throw new RuntimeException('Business timezone is invalid.', previous: $throwable);
        }
    }

    private function holdKey(string $transferKey): string
    {
        return 'wallet.transfer.hold.'.hash('sha256', $transferKey);
    }

    private function ledgerCommandKey(string $transferKey): string
    {
        return 'wallet.transfer.post.'.hash('sha256', $transferKey);
    }

    /** @return WalletTransferRow|null */
    private function lockedTransferByKey(Connection $connection, string $transferKey): ?object
    {
        /** @var WalletTransferRow|null $transfer */
        $transfer = $connection->table('wallet_transfers')
            ->where('transfer_key', $transferKey)
            ->lockForUpdate()
            ->first($this->transferColumns());

        return $transfer;
    }

    private function replayAfterUniqueRace(string $transferKey, string $payloadHash): ?WalletTransferReceipt
    {
        /** @var WalletTransferRow|null $transfer */
        $transfer = $this->database->connection()->table('wallet_transfers')
            ->where('transfer_key', $transferKey)
            ->first($this->transferColumns());
        if ($transfer === null) {
            return null;
        }

        return $this->receiptFromExisting($transfer, $payloadHash, true);
    }

    /** @param WalletTransferRow $transfer */
    private function receiptFromExisting(object $transfer, ?string $payloadHash, bool $replayed): WalletTransferReceipt
    {
        if ($payloadHash !== null && ! hash_equals($transfer->payload_hash, $payloadHash)) {
            throw new RuntimeException('Wallet transfer key conflict.');
        }

        $ledgerTransactionId = null;
        if ($transfer->ledger_transaction_id !== null) {
            $ledgerTransactionId = $this->positiveDatabaseInt($transfer->ledger_transaction_id, 'Wallet transfer ledger transaction ID');
        }

        return new WalletTransferReceipt(
            $this->positiveDatabaseInt($transfer->id, 'Wallet transfer ID'),
            $this->transferStatus($transfer->status),
            $this->positiveDatabaseInt($transfer->recipient_user_id, 'Wallet transfer recipient user ID'),
            $transfer->recipient_public_id,
            $transfer->wallet_bucket,
            IrrMoney::positive($this->positiveDatabaseInt($transfer->amount_irr, 'Wallet transfer amount')),
            IrrMoney::fromInt($this->nonNegativeDatabaseInt($transfer->fee_irr, 'Wallet transfer fee')),
            IrrMoney::positive($this->positiveDatabaseInt($transfer->total_debit_irr, 'Wallet transfer total debit')),
            $this->positiveDatabaseInt($transfer->wallet_hold_id, 'Wallet transfer hold ID'),
            $ledgerTransactionId,
            $replayed,
        );
    }

    /** @return list<string> */
    private function transferColumns(): array
    {
        return [
            'id', 'transfer_key', 'payload_hash', 'sender_user_id', 'recipient_user_id', 'recipient_public_id',
            'sender_wallet_account_id', 'recipient_wallet_account_id', 'wallet_bucket', 'amount_irr', 'fee_irr',
            'total_debit_irr', 'policy_minimum_irr', 'policy_maximum_irr', 'policy_daily_limit_irr',
            'policy_fixed_fee_irr', 'policy_fee_basis_points', 'fee_account_code', 'policy_business_date',
            'wallet_hold_id', 'status', 'confirmation_key', 'ledger_transaction_id', 'confirmation_expires_at',
            'confirmed_at', 'completed_at', 'cancelled_at', 'cancel_reason',
        ];
    }

    private function transferStatus(mixed $value): WalletTransferStatus
    {
        if (! is_string($value)) {
            throw new RuntimeException('Wallet transfer status is malformed.');
        }

        return WalletTransferStatus::tryFrom($value)
            ?? throw new RuntimeException('Wallet transfer status is unknown.');
    }

    private function holdStatus(mixed $value): WalletHoldStatus
    {
        if (! is_string($value)) {
            throw new RuntimeException('Wallet transfer hold status is malformed.');
        }

        return WalletHoldStatus::tryFrom($value)
            ?? throw new RuntimeException('Wallet transfer hold status is unknown.');
    }

    private function canonicalPublicId(string $value): string
    {
        $canonical = strtoupper(trim($value));
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $canonical) !== 1) {
            throw new DomainException('Wallet transfer recipient public ID is invalid.');
        }

        return $canonical;
    }

    private function configPositiveInt(string $key): int
    {
        $value = $this->configNonNegativeInt($key);
        if ($value < 1) {
            throw new RuntimeException('Wallet transfer policy is invalid.');
        }

        return $value;
    }

    private function configNonNegativeInt(string $key): int
    {
        $value = config($key);
        if (is_int($value)) {
            if ($value < 0) {
                throw new RuntimeException('Wallet transfer policy is invalid.');
            }

            return $value;
        }
        if (! is_string($value) || preg_match('/\A[0-9]+\z/', $value) !== 1) {
            throw new RuntimeException('Wallet transfer policy is invalid.');
        }

        return $this->nonNegativeDatabaseInt($value, 'Wallet transfer policy value');
    }

    private function databaseDateTime(mixed $value, string $label): DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException($label.' is malformed.');
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Throwable $throwable) {
            throw new RuntimeException($label.' is malformed.', previous: $throwable);
        }
    }

    private function nonNegativeDatabaseInt(mixed $value, string $label): int
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw new RuntimeException($label.' is negative.');
            }

            return $value;
        }
        if (! is_string($value) || preg_match('/\A[0-9]+\z/', $value) !== 1) {
            throw new RuntimeException($label.' is malformed.');
        }

        $canonical = ltrim($value, '0');
        $canonical = $canonical === '' ? '0' : $canonical;
        $maximum = (string) PHP_INT_MAX;
        if (strlen($canonical) > strlen($maximum)
            || (strlen($canonical) === strlen($maximum) && strcmp($canonical, $maximum) > 0)
        ) {
            throw new RuntimeException($label.' exceeds the supported integer range.');
        }

        return (int) $canonical;
    }

    private function positiveDatabaseInt(mixed $value, string $label): int
    {
        $integer = $this->nonNegativeDatabaseInt($value, $label);
        if ($integer < 1) {
            throw new RuntimeException($label.' must be positive.');
        }

        return $integer;
    }

    private function assertPositiveId(int $value, string $label): void
    {
        if ($value < 1) {
            throw new DomainException($label.' must be positive.');
        }
    }

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum || preg_match('/\A[A-Za-z0-9._:-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertPrintable(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum || $value !== trim($value) || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
