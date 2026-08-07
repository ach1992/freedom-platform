<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
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
     * @requirement WAL-002 DAT-002 DAT-003 DAT-004 QUA-001
     */
    public function post(
        string $commandKey,
        string $transactionType,
        string $correlationId,
        array $entries,
        ?string $sourceType = null,
        ?string $sourceId = null,
    ): LedgerPostingReceipt {
        $this->assertToken($commandKey, 'Ledger command key', 8, 128);
        $this->assertToken($transactionType, 'Ledger transaction type', 3, 64);
        $this->assertToken($correlationId, 'Ledger correlation ID', 8, 64);
        $this->assertSource($sourceType, $sourceId);
        [$debit, $credit] = $this->balancedTotals($entries);
        $payloadHash = $this->payloadHash($transactionType, $sourceType, $sourceId, $entries);

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
                ),
            );
        } catch (QueryException $exception) {
            $replay = $this->replayAfterUniqueRace($commandKey, $payloadHash);
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
    ): LedgerPostingReceipt {
        /** @var object{id: int|string, payload_hash: string, expected_total_irr: int|string, posted_debit_irr: int|string, posted_credit_irr: int|string, entry_count: int|string, finalized_at: string|null}|null $existing */
        $existing = $connection->table('ledger_transactions')
            ->where('command_key', $commandKey)
            ->lockForUpdate()
            ->first(['id', 'payload_hash', 'expected_total_irr', 'posted_debit_irr', 'posted_credit_irr', 'entry_count', 'finalized_at']);
        if ($existing !== null) {
            return $this->receiptFromExisting($existing, $payloadHash);
        }

        $accountIds = array_values(array_unique(array_map(
            static fn (LedgerEntryDraft $entry): int => $entry->accountId,
            $entries,
        )));
        sort($accountIds, SORT_NUMERIC);
        /** @var Collection<int, object{id: int|string, currency: string, is_active: int|bool}> $accounts */
        $accounts = $connection->table('ledger_accounts')
            ->whereIn('id', $accountIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'currency', 'is_active']);
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
            if (! $entry instanceof LedgerEntryDraft) {
                throw new DomainException('Ledger entry draft is invalid.');
            }
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
    private function payloadHash(string $transactionType, ?string $sourceType, ?string $sourceId, array $entries): string
    {
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

        return hash('sha256', json_encode([
            'transaction_type' => $transactionType,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'entries' => $canonicalEntries,
        ], JSON_THROW_ON_ERROR));
    }

    private function replayAfterUniqueRace(string $commandKey, string $payloadHash): ?LedgerPostingReceipt
    {
        /** @var object{id: int|string, payload_hash: string, expected_total_irr: int|string, posted_debit_irr: int|string, posted_credit_irr: int|string, entry_count: int|string, finalized_at: string|null}|null $existing */
        $existing = $this->database->connection()->table('ledger_transactions')
            ->where('command_key', $commandKey)
            ->first(['id', 'payload_hash', 'expected_total_irr', 'posted_debit_irr', 'posted_credit_irr', 'entry_count', 'finalized_at']);
        if ($existing === null) {
            return null;
        }

        return $this->receiptFromExisting($existing, $payloadHash);
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
