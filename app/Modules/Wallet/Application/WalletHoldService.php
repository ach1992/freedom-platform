<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Modules\Wallet\Domain\WalletHoldStatus;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type WalletHoldRow object{
 *     id: int|string,
 *     hold_key: string,
 *     payload_hash: string,
 *     owner_user_id: int|string,
 *     ledger_account_id: int|string,
 *     amount_irr: int|string,
 *     source_type: string,
 *     source_id: string,
 *     status: string,
 *     expires_at: string,
 *     captured_ledger_transaction_id: int|string|null,
 *     captured_at: string|null,
 *     released_at: string|null,
 *     release_reason: string|null
 * }
 */
final readonly class WalletHoldService
{
    private const CAPTURE_TRANSACTION_TYPE = 'wallet_hold_capture';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private LedgerPostingService $ledger,
    ) {}

    /** @requirement WAL-002 DAT-002 DAT-003 DAT-004 QUA-001 */
    public function balance(int $ownerUserId, int $ledgerAccountId): WalletBalanceSnapshot
    {
        $this->assertPositiveId($ownerUserId, 'Wallet owner user ID');
        $this->assertPositiveId($ledgerAccountId, 'Wallet ledger account ID');

        return $this->database->connection()->transaction(function (Connection $connection) use ($ownerUserId, $ledgerAccountId): WalletBalanceSnapshot {
            $this->lockWalletAccount($connection, $ownerUserId, $ledgerAccountId);

            return $this->balanceLocked($connection, $ledgerAccountId);
        });
    }

    /** @requirement WAL-002 DAT-002 DAT-003 DAT-004 QUA-001 */
    public function place(
        string $holdKey,
        int $ownerUserId,
        int $ledgerAccountId,
        IrrMoney $amount,
        string $sourceType,
        string $sourceId,
        DateTimeImmutable $expiresAt,
    ): WalletHoldReceipt {
        $this->assertToken($holdKey, 'Wallet hold key', 8, 128);
        $this->assertPositiveId($ownerUserId, 'Wallet owner user ID');
        $this->assertPositiveId($ledgerAccountId, 'Wallet ledger account ID');
        if ($amount->isZero()) {
            throw new DomainException('Wallet hold amount must be positive.');
        }
        $this->assertToken($sourceType, 'Wallet hold source type', 3, 64);
        $this->assertPrintable($sourceId, 'Wallet hold source ID', 1, 191);

        $expiry = $expiresAt->setTimezone(new DateTimeZone('UTC'));
        if ($expiry <= $this->clock->now()) {
            throw new DomainException('Wallet hold expiry must be in the future.');
        }

        $payloadHash = $this->holdPayloadHash(
            $ownerUserId,
            $ledgerAccountId,
            $amount,
            $sourceType,
            $sourceId,
            $expiry,
        );

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $holdKey,
                $ownerUserId,
                $ledgerAccountId,
                $amount,
                $sourceType,
                $sourceId,
                $expiry,
                $payloadHash,
            ): WalletHoldReceipt {
                $existing = $this->lockedHoldByKey($connection, $holdKey);
                if ($existing !== null) {
                    return $this->receiptFromExisting($existing, $payloadHash, true);
                }

                $this->lockWalletAccount($connection, $ownerUserId, $ledgerAccountId);

                $existing = $this->lockedHoldByKey($connection, $holdKey);
                if ($existing !== null) {
                    return $this->receiptFromExisting($existing, $payloadHash, true);
                }

                $balance = $this->balanceLocked($connection, $ledgerAccountId);
                if ($amount->amount > $balance->availableBalance->amount) {
                    throw new DomainException('Wallet available balance is insufficient for this hold.');
                }

                $holdId = (int) $connection->table('wallet_holds')->insertGetId([
                    'hold_key' => $holdKey,
                    'payload_hash' => $payloadHash,
                    'owner_user_id' => $ownerUserId,
                    'ledger_account_id' => $ledgerAccountId,
                    'amount_irr' => $amount->amount,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'status' => WalletHoldStatus::Active->value,
                    'expires_at' => $expiry->format('Y-m-d H:i:s.u'),
                    'captured_ledger_transaction_id' => null,
                    'captured_at' => null,
                    'released_at' => null,
                    'release_reason' => null,
                    'created_at' => $this->timestamp(),
                ]);

                return new WalletHoldReceipt($holdId, WalletHoldStatus::Active, $amount, null, false);
            });
        } catch (QueryException $exception) {
            $replay = $this->replayAfterUniqueRace($holdKey, $payloadHash);
            if ($replay !== null) {
                return $replay;
            }

            throw $exception;
        }
    }

    /** @requirement WAL-002 DAT-002 DAT-003 DAT-004 QUA-001 */
    public function capture(string $holdKey, int $offsetAccountId, string $correlationId): WalletHoldReceipt
    {
        $this->assertToken($holdKey, 'Wallet hold key', 8, 128);
        $this->assertPositiveId($offsetAccountId, 'Wallet capture offset account ID');
        $this->assertToken($correlationId, 'Wallet hold correlation ID', 8, 64);

        return $this->database->connection()->transaction(function (Connection $connection) use ($holdKey, $offsetAccountId, $correlationId): WalletHoldReceipt {
            $hold = $this->lockedHoldByKey($connection, $holdKey);
            if ($hold === null) {
                throw new DomainException('Wallet hold does not exist.');
            }

            $status = $this->holdStatus($hold->status);
            if ($status === WalletHoldStatus::Captured) {
                $this->assertCapturedTransaction($connection, $hold, $offsetAccountId);

                return $this->receiptFromExisting($hold, null, true);
            }
            if ($status === WalletHoldStatus::Released) {
                throw new RuntimeException('Released wallet hold cannot be captured.');
            }

            $expiresAt = $this->databaseDateTime($hold->expires_at, 'Wallet hold expiry');
            if ($expiresAt <= $this->clock->now()) {
                throw new RuntimeException('Expired wallet hold must be released before further action.');
            }

            $walletAccountId = $this->positiveDatabaseInt($hold->ledger_account_id, 'Wallet hold account ID');
            if ($walletAccountId === $offsetAccountId) {
                throw new DomainException('Wallet capture offset account must differ from the held wallet account.');
            }
            $this->assertSystemOffsetAccount($connection, $offsetAccountId);

            $amount = IrrMoney::positive($this->positiveDatabaseInt($hold->amount_irr, 'Wallet hold amount'));
            $holdId = $this->positiveDatabaseInt($hold->id, 'Wallet hold ID');
            $ledgerReceipt = $this->ledger->post(
                $this->captureCommandKey($holdKey),
                self::CAPTURE_TRANSACTION_TYPE,
                $correlationId,
                [
                    new LedgerEntryDraft($walletAccountId, LedgerDirection::Debit, $amount),
                    new LedgerEntryDraft($offsetAccountId, LedgerDirection::Credit, $amount),
                ],
                'wallet_hold',
                (string) $holdId,
            );

            $updated = $connection->table('wallet_holds')
                ->where('id', $holdId)
                ->where('status', WalletHoldStatus::Active->value)
                ->update([
                    'status' => WalletHoldStatus::Captured->value,
                    'captured_ledger_transaction_id' => $ledgerReceipt->transactionId,
                    'captured_at' => $this->timestamp(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Wallet hold capture transition failed.');
            }

            return new WalletHoldReceipt(
                $holdId,
                WalletHoldStatus::Captured,
                $amount,
                $ledgerReceipt->transactionId,
                false,
            );
        });
    }

    /** @requirement WAL-002 DAT-002 DAT-003 DAT-004 QUA-001 */
    public function release(string $holdKey, string $reason): WalletHoldReceipt
    {
        $this->assertToken($holdKey, 'Wallet hold key', 8, 128);
        $this->assertPrintable($reason, 'Wallet hold release reason', 3, 191);

        return $this->database->connection()->transaction(function (Connection $connection) use ($holdKey, $reason): WalletHoldReceipt {
            $hold = $this->lockedHoldByKey($connection, $holdKey);
            if ($hold === null) {
                throw new DomainException('Wallet hold does not exist.');
            }

            $status = $this->holdStatus($hold->status);
            if ($status === WalletHoldStatus::Captured) {
                throw new RuntimeException('Captured wallet hold cannot be released.');
            }
            if ($status === WalletHoldStatus::Released) {
                if ($hold->release_reason === null || ! hash_equals($hold->release_reason, $reason)) {
                    throw new RuntimeException('Wallet hold release replay conflicts with the accepted reason.');
                }

                return $this->receiptFromExisting($hold, null, true);
            }

            $holdId = $this->positiveDatabaseInt($hold->id, 'Wallet hold ID');
            $accountId = $this->positiveDatabaseInt($hold->ledger_account_id, 'Wallet hold account ID');
            $this->lockAccountForTransition($connection, $accountId);

            $updated = $connection->table('wallet_holds')
                ->where('id', $holdId)
                ->where('status', WalletHoldStatus::Active->value)
                ->update([
                    'status' => WalletHoldStatus::Released->value,
                    'released_at' => $this->timestamp(),
                    'release_reason' => $reason,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Wallet hold release transition failed.');
            }

            return new WalletHoldReceipt(
                $holdId,
                WalletHoldStatus::Released,
                IrrMoney::positive($this->positiveDatabaseInt($hold->amount_irr, 'Wallet hold amount')),
                null,
                false,
            );
        });
    }

    private function balanceLocked(Connection $connection, int $ledgerAccountId): WalletBalanceSnapshot
    {
        $credit = $this->nonNegativeDatabaseInt(
            $connection->table('ledger_entries as entries')
                ->join('ledger_transactions as transactions', 'transactions.id', '=', 'entries.ledger_transaction_id')
                ->where('entries.ledger_account_id', $ledgerAccountId)
                ->where('entries.direction', LedgerDirection::Credit->value)
                ->whereNotNull('transactions.finalized_at')
                ->sum('entries.amount_irr'),
            'Wallet credit balance',
        );
        $debit = $this->nonNegativeDatabaseInt(
            $connection->table('ledger_entries as entries')
                ->join('ledger_transactions as transactions', 'transactions.id', '=', 'entries.ledger_transaction_id')
                ->where('entries.ledger_account_id', $ledgerAccountId)
                ->where('entries.direction', LedgerDirection::Debit->value)
                ->whereNotNull('transactions.finalized_at')
                ->sum('entries.amount_irr'),
            'Wallet debit balance',
        );
        if ($debit > $credit) {
            throw new RuntimeException('Wallet ledger-derived balance is negative and requires reconciliation.');
        }

        $ledgerBalance = IrrMoney::fromInt($credit - $debit);
        $activeHolds = IrrMoney::fromInt($this->nonNegativeDatabaseInt(
            $connection->table('wallet_holds')
                ->where('ledger_account_id', $ledgerAccountId)
                ->where('status', WalletHoldStatus::Active->value)
                ->sum('amount_irr'),
            'Wallet active holds',
        ));
        if ($activeHolds->amount > $ledgerBalance->amount) {
            throw new RuntimeException('Wallet active holds exceed ledger balance and require reconciliation.');
        }

        return new WalletBalanceSnapshot(
            $ledgerBalance,
            $activeHolds,
            $ledgerBalance->subtract($activeHolds),
        );
    }

    private function lockWalletAccount(Connection $connection, int $ownerUserId, int $ledgerAccountId): void
    {
        /** @var object{id: int|string, account_class: string, owner_user_id: int|string|null, wallet_bucket: string|null, currency: string, is_active: int|bool}|null $account */
        $account = $connection->table('ledger_accounts')
            ->where('id', $ledgerAccountId)
            ->lockForUpdate()
            ->first(['id', 'account_class', 'owner_user_id', 'wallet_bucket', 'currency', 'is_active']);
        if ($account === null) {
            throw new DomainException('Wallet ledger account does not exist.');
        }
        if ($account->account_class !== 'liability'
            || $account->owner_user_id === null
            || (int) $account->owner_user_id !== $ownerUserId
            || ! in_array($account->wallet_bucket, ['cash', 'promotional'], true)
            || $account->currency !== 'IRR'
        ) {
            throw new DomainException('Ledger account is not an IRR wallet bucket owned by this user.');
        }
        if (! (bool) $account->is_active) {
            throw new DomainException('Wallet ledger account is inactive.');
        }
    }

    private function assertSystemOffsetAccount(Connection $connection, int $offsetAccountId): void
    {
        /** @var object{id: int|string, owner_user_id: int|string|null, wallet_bucket: string|null, currency: string}|null $account */
        $account = $connection->table('ledger_accounts')
            ->where('id', $offsetAccountId)
            ->first(['id', 'owner_user_id', 'wallet_bucket', 'currency']);
        if ($account === null) {
            throw new DomainException('Wallet capture offset account does not exist.');
        }
        if ($account->owner_user_id !== null || $account->wallet_bucket !== null || $account->currency !== 'IRR') {
            throw new DomainException('Wallet capture offset account must be a system IRR account.');
        }
    }

    private function lockAccountForTransition(Connection $connection, int $accountId): void
    {
        $exists = $connection->table('ledger_accounts')
            ->where('id', $accountId)
            ->lockForUpdate()
            ->exists();
        if (! $exists) {
            throw new RuntimeException('Wallet hold account is unavailable.');
        }
    }

    /** @param WalletHoldRow $hold */
    private function assertCapturedTransaction(Connection $connection, object $hold, int $offsetAccountId): void
    {
        $transactionId = $this->positiveDatabaseInt($hold->captured_ledger_transaction_id, 'Wallet captured transaction ID');
        $holdId = $this->positiveDatabaseInt($hold->id, 'Wallet hold ID');
        $walletAccountId = $this->positiveDatabaseInt($hold->ledger_account_id, 'Wallet hold account ID');
        $amount = $this->positiveDatabaseInt($hold->amount_irr, 'Wallet hold amount');

        /** @var object{transaction_type: string, expected_total_irr: int|string, posted_debit_irr: int|string, posted_credit_irr: int|string, entry_count: int|string, source_type: string|null, source_id: string|null, finalized_at: string|null}|null $transaction */
        $transaction = $connection->table('ledger_transactions')
            ->where('id', $transactionId)
            ->first(['transaction_type', 'expected_total_irr', 'posted_debit_irr', 'posted_credit_irr', 'entry_count', 'source_type', 'source_id', 'finalized_at']);
        if ($transaction === null
            || $transaction->transaction_type !== self::CAPTURE_TRANSACTION_TYPE
            || $transaction->source_type !== 'wallet_hold'
            || $transaction->source_id !== (string) $holdId
            || $transaction->finalized_at === null
            || $this->positiveDatabaseInt($transaction->expected_total_irr, 'Wallet capture expected total') !== $amount
            || $this->positiveDatabaseInt($transaction->posted_debit_irr, 'Wallet capture debit total') !== $amount
            || $this->positiveDatabaseInt($transaction->posted_credit_irr, 'Wallet capture credit total') !== $amount
            || $this->positiveDatabaseInt($transaction->entry_count, 'Wallet capture entry count') !== 2
        ) {
            throw new RuntimeException('Wallet hold capture ledger integrity check failed.');
        }

        /** @var list<object{ledger_account_id: int|string, direction: string, amount_irr: int|string}> $entries */
        $entries = $connection->table('ledger_entries')
            ->where('ledger_transaction_id', $transactionId)
            ->orderBy('sequence')
            ->get(['ledger_account_id', 'direction', 'amount_irr'])
            ->all();
        if (count($entries) !== 2) {
            throw new RuntimeException('Wallet hold capture entry integrity check failed.');
        }

        $expected = [
            [$walletAccountId, LedgerDirection::Debit->value, $amount],
            [$offsetAccountId, LedgerDirection::Credit->value, $amount],
        ];
        $actual = array_map(fn (object $entry): array => [
            $this->positiveDatabaseInt($entry->ledger_account_id, 'Wallet capture entry account ID'),
            $entry->direction,
            $this->positiveDatabaseInt($entry->amount_irr, 'Wallet capture entry amount'),
        ], $entries);
        sort($expected);
        sort($actual);
        if ($actual !== $expected) {
            throw new RuntimeException('Wallet hold capture replay conflicts with the accepted ledger effect.');
        }
    }

    /** @return WalletHoldRow|null */
    private function lockedHoldByKey(Connection $connection, string $holdKey): ?object
    {
        /** @var WalletHoldRow|null $hold */
        $hold = $connection->table('wallet_holds')
            ->where('hold_key', $holdKey)
            ->lockForUpdate()
            ->first($this->holdColumns());

        return $hold;
    }

    private function replayAfterUniqueRace(string $holdKey, string $payloadHash): ?WalletHoldReceipt
    {
        /** @var WalletHoldRow|null $hold */
        $hold = $this->database->connection()->table('wallet_holds')
            ->where('hold_key', $holdKey)
            ->first($this->holdColumns());
        if ($hold === null) {
            return null;
        }

        return $this->receiptFromExisting($hold, $payloadHash, true);
    }

    /** @param WalletHoldRow $hold */
    private function receiptFromExisting(object $hold, ?string $payloadHash, bool $replayed): WalletHoldReceipt
    {
        if ($payloadHash !== null && ! hash_equals($hold->payload_hash, $payloadHash)) {
            throw new RuntimeException('Wallet hold key conflict.');
        }

        $status = $this->holdStatus($hold->status);
        $capturedTransactionId = null;
        if ($status === WalletHoldStatus::Captured) {
            $capturedTransactionId = $this->positiveDatabaseInt(
                $hold->captured_ledger_transaction_id,
                'Wallet captured transaction ID',
            );
        }

        return new WalletHoldReceipt(
            $this->positiveDatabaseInt($hold->id, 'Wallet hold ID'),
            $status,
            IrrMoney::positive($this->positiveDatabaseInt($hold->amount_irr, 'Wallet hold amount')),
            $capturedTransactionId,
            $replayed,
        );
    }

    /** @return list<string> */
    private function holdColumns(): array
    {
        return [
            'id',
            'hold_key',
            'payload_hash',
            'owner_user_id',
            'ledger_account_id',
            'amount_irr',
            'source_type',
            'source_id',
            'status',
            'expires_at',
            'captured_ledger_transaction_id',
            'captured_at',
            'released_at',
            'release_reason',
        ];
    }

    private function holdPayloadHash(
        int $ownerUserId,
        int $ledgerAccountId,
        IrrMoney $amount,
        string $sourceType,
        string $sourceId,
        DateTimeImmutable $expiresAt,
    ): string {
        return hash('sha256', json_encode([
            'owner_user_id' => $ownerUserId,
            'ledger_account_id' => $ledgerAccountId,
            'amount_irr' => $amount->amount,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s.u'),
        ], JSON_THROW_ON_ERROR));
    }

    private function captureCommandKey(string $holdKey): string
    {
        return 'wallet.hold.capture.'.hash('sha256', $holdKey);
    }

    private function holdStatus(mixed $value): WalletHoldStatus
    {
        if (! is_string($value)) {
            throw new RuntimeException('Wallet hold status is malformed.');
        }

        return WalletHoldStatus::tryFrom($value)
            ?? throw new RuntimeException('Wallet hold status is unknown.');
    }

    private function databaseDateTime(mixed $value, string $label): DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException($label.' is malformed.');
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable $throwable) {
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
