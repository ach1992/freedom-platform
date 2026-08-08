<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Modules\Wallet\Domain\RefundDestination;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use RuntimeException;

final readonly class LedgerPostingService
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    /**
     * @param  list<LedgerEntryDraft>  $entries
     *
     * @requirement WAL-002 WAL-004 DAT-002 DAT-003 DAT-004 QUA-001
     */
    public function post(
        string $commandKey,
        string $transactionType,
        string $correlationId,
        array $entries,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?LedgerRefundabilitySnapshot $refundability = null,
    ): LedgerPostingReceipt {
        $this->assertToken($commandKey, 'Ledger command key', 8, 128);
        $this->assertToken($transactionType, 'Ledger transaction type', 3, 64);
        $this->assertToken($correlationId, 'Ledger correlation ID', 8, 64);
        $this->assertSource($sourceType, $sourceId);
        [$debit, $credit] = $this->balancedTotals($entries);
        if ($refundability !== null && $refundability->refundableTotal->amount > $debit->amount) {
            throw new DomainException('Refundable ledger amount cannot exceed the captured total.');
        }
        $payloadHash = $this->payloadHash($transactionType, $sourceType, $sourceId, $entries, $refundability);

        try {
            return $this->database->connection()->transaction(
                fn (Connection $connection): LedgerPostingReceipt => $this->postLocked(
                    $connection,
                    $commandKey,
                    $payloadHash,
                    $transactionType,
                    $correlationId,
                    $sourceType,
                    $sourceId,
                    $entries,
                    $debit,
                    $credit,
                    $refundability,
                ),
            );
        } catch (QueryException $exception) {
            $replay = $this->replayAfterUniqueRace($commandKey, $payloadHash, $refundability);
            if ($replay !== null) {
                return $replay;
            }

            throw $exception;
        }
    }

    /**
     * @param  list<LedgerEntryDraft>  $entries
     */
    private function postLocked(
        Connection $connection,
        string $commandKey,
        string $payloadHash,
        string $transactionType,
        string $correlationId,
        ?string $sourceType,
        ?string $sourceId,
        array $entries,
        IrrMoney $debit,
        IrrMoney $credit,
        ?LedgerRefundabilitySnapshot $refundability,
    ): LedgerPostingReceipt {
        /** @var object{id: int|string, payload_hash: string, expected_total_irr: int|string, posted_debit_irr: int|string, posted_credit_irr: int|string, entry_count: int|string, finalized_at: string|null}|null $existing */
        $existing = $connection->table('ledger_transactions')
            ->where('command_key', $commandKey)
            ->lockForUpdate()
            ->first(['id', 'payload_hash', 'expected_total_irr', 'posted_debit_irr', 'posted_credit_irr', 'entry_count', 'finalized_at']);
        if ($existing !== null) {
            $receipt = $this->receiptFromExisting($existing, $payloadHash);
            $this->assertStoredRefundability($connection, $receipt->transactionId, $refundability);

            return $receipt;
        }

        $accountIds = array_values(array_unique(array_map(
            static fn (LedgerEntryDraft $entry): int => $entry->accountId,
            $entries,
        )));
        sort($accountIds, SORT_NUMERIC);
        /** @var Collection<int, object{id: int|string, currency: string, is_active: int|bool, owner_user_id: int|string|null, wallet_bucket: string|null}> $accounts */
        $accounts = $connection->table('ledger_accounts')
            ->whereIn('id', $accountIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'currency', 'is_active', 'owner_user_id', 'wallet_bucket']);
        if ($accounts->count() !== count($accountIds)) {
            throw new DomainException('Ledger account does not exist.');
        }
        foreach ($accounts as $account) {
            if ($account->currency !== 'IRR') {
                throw new DomainException('Ledger account currency is not IRR.');
            }
            if (! (bool) $account->is_active) {
                throw new DomainException('Ledger account is inactive.');
            }
        }
        if ($refundability !== null) {
            /** @var list<object{id: int|string, currency: string, is_active: int|bool, owner_user_id: int|string|null, wallet_bucket: string|null}> $refundabilityAccounts */
            $refundabilityAccounts = $accounts->values()->all();
            $this->assertRefundabilityCompatibility($refundability, $entries, $refundabilityAccounts);
        }

        $createdAt = $this->timestamp();
        $transactionId = (int) $connection->table('ledger_transactions')->insertGetId([
            'command_key' => $commandKey,
            'payload_hash' => $payloadHash,
            'transaction_type' => $transactionType,
            'expected_total_irr' => $debit->amount,
            'posted_debit_irr' => 0,
            'posted_credit_irr' => 0,
            'entry_count' => 0,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'correlation_id' => $correlationId,
            'finalized_at' => null,
            'created_at' => $createdAt,
        ]);

        foreach ($entries as $index => $entry) {
            $connection->table('ledger_entries')->insert([
                'ledger_transaction_id' => $transactionId,
                'ledger_account_id' => $entry->accountId,
                'sequence' => $index + 1,
                'direction' => $entry->direction->value,
                'amount_irr' => $entry->amount->amount,
                'created_at' => $createdAt,
            ]);
        }

        if ($refundability !== null) {
            $connection->table('ledger_refundability')->insert([
                'ledger_transaction_id' => $transactionId,
                'refundable_total_irr' => $refundability->refundableTotal->amount,
                'default_destination' => $refundability->defaultDestination->value,
                'created_at' => $this->timestamp(),
            ]);
        }

        $updated = $connection->table('ledger_transactions')
            ->where('id', $transactionId)
            ->whereNull('finalized_at')
            ->update(['finalized_at' => $this->timestamp()]);
        if ($updated !== 1) {
            throw new RuntimeException('Ledger transaction finalization failed.');
        }

        if (! $debit->equals($credit)) {
            throw new RuntimeException('Ledger balance changed during posting.');
        }

        return new LedgerPostingReceipt($transactionId, $debit, count($entries), false);
    }

    /**
     * @param  list<LedgerEntryDraft>  $entries
     * @return array{0: IrrMoney, 1: IrrMoney}
     */
    private function balancedTotals(array $entries): array
    {
        if (count($entries) < 2 || count($entries) > 65535) {
            throw new DomainException('Ledger transaction requires between 2 and 65535 entries.');
        }

        $debit = IrrMoney::zero();
        $credit = IrrMoney::zero();
        foreach ($entries as $entry) {
            if ($entry->direction === LedgerDirection::Debit) {
                $debit = $debit->add($entry->amount);
            } else {
                $credit = $credit->add($entry->amount);
            }
        }

        if ($debit->isZero() || ! $debit->equals($credit)) {
            throw new DomainException('Ledger transaction must be balanced.');
        }

        return [$debit, $credit];
    }

    /**
     * @param  list<LedgerEntryDraft>  $entries
     */
    private function payloadHash(
        string $transactionType,
        ?string $sourceType,
        ?string $sourceId,
        array $entries,
        ?LedgerRefundabilitySnapshot $refundability,
    ): string {
        $canonicalEntries = array_map(
            static fn (LedgerEntryDraft $entry): array => [
                'account_id' => $entry->accountId,
                'direction' => $entry->direction->value,
                'amount_irr' => $entry->amount->amount,
            ],
            $entries,
        );
        usort($canonicalEntries, static function (array $left, array $right): int {
            return [$left['account_id'], $left['direction'], $left['amount_irr']]
                <=> [$right['account_id'], $right['direction'], $right['amount_irr']];
        });

        $canonical = [
            'transaction_type' => $transactionType,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'entries' => $canonicalEntries,
        ];

        if ($refundability !== null) {
            $canonical['refundability'] = [
                'refundable_total_irr' => $refundability->refundableTotal->amount,
                'default_destination' => $refundability->defaultDestination->value,
            ];
        }

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    private function replayAfterUniqueRace(
        string $commandKey,
        string $payloadHash,
        ?LedgerRefundabilitySnapshot $refundability,
    ): ?LedgerPostingReceipt {
        /** @var object{id: int|string, payload_hash: string, expected_total_irr: int|string, posted_debit_irr: int|string, posted_credit_irr: int|string, entry_count: int|string, finalized_at: string|null}|null $existing */
        $existing = $this->database->connection()->table('ledger_transactions')
            ->where('command_key', $commandKey)
            ->first(['id', 'payload_hash', 'expected_total_irr', 'posted_debit_irr', 'posted_credit_irr', 'entry_count', 'finalized_at']);
        if ($existing === null) {
            return null;
        }

        $receipt = $this->receiptFromExisting($existing, $payloadHash);
        $this->assertStoredRefundability($this->database->connection(), $receipt->transactionId, $refundability);

        return $receipt;
    }

    /**
     * @param  object{id: int|string, payload_hash: string, expected_total_irr: int|string, posted_debit_irr: int|string, posted_credit_irr: int|string, entry_count: int|string, finalized_at: string|null}  $existing
     */
    private function receiptFromExisting(object $existing, string $payloadHash): LedgerPostingReceipt
    {
        if (! hash_equals($existing->payload_hash, $payloadHash)) {
            throw new RuntimeException('Ledger command key conflict.');
        }
        if ($existing->finalized_at === null) {
            throw new RuntimeException('Ledger command is incomplete and requires reconciliation.');
        }

        $expected = (int) $existing->expected_total_irr;
        $debit = (int) $existing->posted_debit_irr;
        $credit = (int) $existing->posted_credit_irr;
        $entryCount = (int) $existing->entry_count;
        if ($expected < 1 || $debit !== $expected || $credit !== $expected || $entryCount < 2) {
            throw new RuntimeException('Ledger transaction integrity check failed.');
        }

        return new LedgerPostingReceipt((int) $existing->id, IrrMoney::positive($expected), $entryCount, true);
    }

    /**
     * @param  list<LedgerEntryDraft>  $entries
     * @param  list<object{id: int|string, currency: string, is_active: int|bool, owner_user_id: int|string|null, wallet_bucket: string|null}>  $accounts
     */
    private function assertRefundabilityCompatibility(
        LedgerRefundabilitySnapshot $refundability,
        array $entries,
        array $accounts,
    ): void {
        /** @var array<int, object{id: int|string, currency: string, is_active: int|bool, owner_user_id: int|string|null, wallet_bucket: string|null}> $accountById */
        $accountById = [];
        foreach ($accounts as $account) {
            $accountById[(int) $account->id] = $account;
        }

        $walletDebitSeen = false;
        $externalDebitSeen = false;
        foreach ($entries as $entry) {
            if ($entry->direction !== LedgerDirection::Debit) {
                continue;
            }
            $account = $accountById[$entry->accountId] ?? null;
            if ($account === null) {
                throw new RuntimeException('Refundability account mapping is incomplete.');
            }
            if ($account->owner_user_id !== null && $account->wallet_bucket !== null) {
                $walletDebitSeen = true;
            } else {
                $externalDebitSeen = true;
            }
        }

        if ($refundability->defaultDestination === RefundDestination::Wallet && ! $walletDebitSeen) {
            throw new DomainException('Wallet-default refundability requires an original wallet debit.');
        }
        if ($refundability->defaultDestination === RefundDestination::ManualExternal && ! $externalDebitSeen) {
            throw new DomainException('Manual-external refundability requires an original non-wallet debit.');
        }
    }

    private function assertStoredRefundability(
        Connection $connection,
        int $transactionId,
        ?LedgerRefundabilitySnapshot $refundability,
    ): void {
        /** @var object{refundable_total_irr: int|string, default_destination: string}|null $stored */
        $stored = $connection->table('ledger_refundability')
            ->where('ledger_transaction_id', $transactionId)
            ->first(['refundable_total_irr', 'default_destination']);

        if ($refundability === null) {
            if ($stored !== null) {
                throw new RuntimeException('Ledger refundability replay conflicts with the accepted transaction.');
            }

            return;
        }

        if ($stored === null
            || (int) $stored->refundable_total_irr !== $refundability->refundableTotal->amount
            || ! hash_equals($stored->default_destination, $refundability->defaultDestination->value)) {
            throw new RuntimeException('Ledger refundability replay conflicts with the accepted transaction.');
        }
    }

    private function assertSource(?string $sourceType, ?string $sourceId): void
    {
        if (($sourceType === null) !== ($sourceId === null)) {
            throw new DomainException('Ledger source type and ID must be supplied together.');
        }
        if ($sourceType !== null && $sourceId !== null) {
            $this->assertToken($sourceType, 'Ledger source type', 3, 64);
            $this->assertPrintable($sourceId, 'Ledger source ID', 1, 191);
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
