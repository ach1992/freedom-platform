<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\Wallet\Domain\LedgerDirection;
use App\Modules\Wallet\Domain\WalletHoldStatus;
use App\Modules\Wallet\Domain\WalletReconciliationStatus;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class WalletReconciliationService
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private WalletHoldService $holds,
    ) {}

    /** @requirement WAL-002 DAT-002 DAT-003 DAT-004 QUA-001 */
    public function reconcile(int $ownerUserId, int $ledgerAccountId): WalletReconciliationResult
    {
        if ($ownerUserId < 1) {
            throw new DomainException('Wallet owner user ID must be positive.');
        }
        if ($ledgerAccountId < 1) {
            throw new DomainException('Wallet ledger account ID must be positive.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use ($ownerUserId, $ledgerAccountId): WalletReconciliationResult {
            // WalletHoldService::balance() performs the authoritative account validation,
            // wallet-account lock and ledger/hold derivation. This nested transaction runs
            // on the same connection; the lock remains held until this outer transaction commits.
            $balance = $this->holds->balance($ownerUserId, $ledgerAccountId);

            $lastFinalizedLedgerEntryId = $this->nullablePositiveDatabaseInt(
                $connection->table('ledger_entries as entries')
                    ->join('ledger_transactions as transactions', 'transactions.id', '=', 'entries.ledger_transaction_id')
                    ->where('entries.ledger_account_id', $ledgerAccountId)
                    ->whereNotNull('transactions.finalized_at')
                    ->max('entries.id'),
                'Last finalized wallet ledger entry ID',
            );
            $lastActiveHoldId = $this->nullablePositiveDatabaseInt(
                $connection->table('wallet_holds')
                    ->where('ledger_account_id', $ledgerAccountId)
                    ->where('status', WalletHoldStatus::Active->value)
                    ->max('id'),
                'Last active wallet hold ID',
            );

            $sourceFingerprint = hash('sha256', json_encode([
                'ledger_account_id' => $ledgerAccountId,
                'ledger_balance_irr' => $balance->ledgerBalance->amount,
                'active_holds_irr' => $balance->activeHolds->amount,
                'available_balance_irr' => $balance->availableBalance->amount,
                'last_finalized_ledger_entry_id' => $lastFinalizedLedgerEntryId,
                'last_active_hold_id' => $lastActiveHoldId,
            ], JSON_THROW_ON_ERROR));

            /** @var object{id: int|string, source_fingerprint: string}|null $latest */
            $latest = $connection->table('wallet_balance_snapshots')
                ->where('ledger_account_id', $ledgerAccountId)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first(['id', 'source_fingerprint']);

            $comparedSnapshotId = null;
            $status = WalletReconciliationStatus::Initial;
            if ($latest !== null) {
                $comparedSnapshotId = $this->positiveDatabaseInt($latest->id, 'Compared wallet snapshot ID');
                if (strlen($latest->source_fingerprint) !== 64) {
                    throw new RuntimeException('Latest wallet snapshot fingerprint is malformed.');
                }
                $status = hash_equals($latest->source_fingerprint, $sourceFingerprint)
                    ? WalletReconciliationStatus::Matched
                    : WalletReconciliationStatus::Refreshed;
            }

            $timestamp = $this->clock->now()->format('Y-m-d H:i:s.u');
            $snapshotId = (int) $connection->table('wallet_balance_snapshots')->insertGetId([
                'ledger_account_id' => $ledgerAccountId,
                'ledger_balance_irr' => $balance->ledgerBalance->amount,
                'active_holds_irr' => $balance->activeHolds->amount,
                'available_balance_irr' => $balance->availableBalance->amount,
                'last_finalized_ledger_entry_id' => $lastFinalizedLedgerEntryId,
                'last_active_hold_id' => $lastActiveHoldId,
                'source_fingerprint' => $sourceFingerprint,
                'comparison_status' => $status->value,
                'compared_snapshot_id' => $comparedSnapshotId,
                'calculated_at' => $timestamp,
                'created_at' => $timestamp,
            ]);

            return new WalletReconciliationResult(
                $snapshotId,
                $status,
                $balance,
                $comparedSnapshotId,
                $sourceFingerprint,
            );
        });
    }

    private function nullablePositiveDatabaseInt(mixed $value, string $label): ?int
    {
        if ($value === null) {
            return null;
        }

        return $this->positiveDatabaseInt($value, $label);
    }

    private function positiveDatabaseInt(mixed $value, string $label): int
    {
        if (is_int($value)) {
            if ($value < 1) {
                throw new RuntimeException($label.' must be positive.');
            }

            return $value;
        }
        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            throw new RuntimeException($label.' is malformed.');
        }

        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)
        ) {
            throw new RuntimeException($label.' exceeds the supported integer range.');
        }

        return (int) $value;
    }
}
