<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Modules\Wallet\Domain\RefundDestination;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * @phpstan-type RefundRow object{
 *     id: int|string,
 *     refund_key: string,
 *     payload_hash: string,
 *     source_ledger_transaction_id: int|string,
 *     source_refundable_total_irr: int|string,
 *     default_destination: string,
 *     destination: string,
 *     destination_overridden: int|bool,
 *     amount_irr: int|string,
 *     requested_by_administrator_id: int|string,
 *     manual_external_reference: string|null,
 *     manual_external_evidence_reference: string|null,
 *     ledger_transaction_id: int|string
 * }
 * @phpstan-type SourceEntryRow object{
 *     entry_id: int|string,
 *     ledger_account_id: int|string,
 *     direction: string,
 *     amount_irr: int|string,
 *     account_class: string,
 *     owner_user_id: int|string|null,
 *     wallet_bucket: string|null,
 *     currency: string,
 *     is_active: int|bool
 * }
 */
final readonly class WalletRefundService
{
    private const APPROVE_PERMISSION = 'refunds.approve';

    private const OVERRIDE_DESTINATION_PERMISSION = 'refunds.override_destination';

    private const LEDGER_TRANSACTION_TYPE = 'refund_reversal';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private AdministratorPermissionAuthorizer $authorizer,
        private LedgerPostingService $ledger,
    ) {}

    /**
     * @param  list<RefundEntryAllocation>  $allocations
     *
     * @requirement WAL-004 DAT-002 DAT-003 DAT-004 ACL-001 ACL-002 SEC-002 QUA-001
     */
    public function refund(
        string $refundKey,
        int $sourceLedgerTransactionId,
        RefundDestination $destination,
        array $allocations,
        AccessChangeContext $context,
        ?string $manualExternalReference = null,
        ?string $manualExternalEvidenceReference = null,
    ): WalletRefundReceipt {
        $this->assertToken($refundKey, 'Refund key', 8, 128);
        if ($sourceLedgerTransactionId < 1) {
            throw new DomainException('Refund source ledger transaction ID must be positive.');
        }
        if (count($allocations) < 2 || count($allocations) > 65535) {
            throw new DomainException('Refund requires between 2 and 65535 source allocations.');
        }

        $reason = $context->requireReason();
        $this->authorizer->authorize($context->actorAdministratorId, self::APPROVE_PERMISSION);
        [$manualExternalReference, $manualExternalEvidenceReference] = $this->normalizeManualEvidence(
            $destination,
            $manualExternalReference,
            $manualExternalEvidenceReference,
        );
        $allocationMap = $this->allocationMap($allocations);
        $payloadHash = $this->payloadHash(
            $sourceLedgerTransactionId,
            $destination,
            $allocationMap,
            $context->actorAdministratorId,
            $context->reasonCode,
            $reason,
            $manualExternalReference,
            $manualExternalEvidenceReference,
        );

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $refundKey,
                $sourceLedgerTransactionId,
                $destination,
                $allocationMap,
                $context,
                $reason,
                $manualExternalReference,
                $manualExternalEvidenceReference,
                $payloadHash,
            ): WalletRefundReceipt {
                /** @var object{id: int|string, finalized_at: string|null}|null $source */
                $source = $connection->table('ledger_transactions')
                    ->where('id', $sourceLedgerTransactionId)
                    ->lockForUpdate()
                    ->first(['id', 'finalized_at']);
                if ($source === null) {
                    throw new DomainException('Refund source ledger transaction does not exist.');
                }
                if ($source->finalized_at === null) {
                    throw new DomainException('Refund source ledger transaction is not finalized.');
                }

                /** @var object{refundable_total_irr: int|string, default_destination: string}|null $refundability */
                $refundability = $connection->table('ledger_refundability')
                    ->where('ledger_transaction_id', $sourceLedgerTransactionId)
                    ->lockForUpdate()
                    ->first(['refundable_total_irr', 'default_destination']);
                if ($refundability === null) {
                    throw new DomainException('Refund source ledger transaction is not declared refundable.');
                }

                $refundableTotal = $this->positiveDatabaseInt(
                    $refundability->refundable_total_irr,
                    'Refund source refundable total',
                );
                $defaultDestination = RefundDestination::tryFrom($refundability->default_destination);
                if ($defaultDestination === null) {
                    throw new RuntimeException('Refund source destination snapshot is invalid.');
                }

                $existing = $this->lockedRefundByKey($connection, $refundKey);
                if ($existing !== null) {
                    return $this->receiptFromExisting($connection, $existing, $payloadHash);
                }

                $destinationOverridden = $destination !== $defaultDestination;
                if ($destinationOverridden) {
                    $this->authorizer->authorize(
                        $context->actorAdministratorId,
                        self::OVERRIDE_DESTINATION_PERMISSION,
                    );
                }

                $sourceEntries = $this->sourceEntries(
                    $connection,
                    $sourceLedgerTransactionId,
                    array_keys($allocationMap),
                );
                [$refundAmount, $reversalEntries] = $this->validateAndBuildReversal(
                    $connection,
                    $sourceEntries,
                    $allocationMap,
                    $destination,
                );

                $alreadyRefunded = $this->nonNegativeDatabaseInt(
                    $connection->table('refunds')
                        ->where('source_ledger_transaction_id', $sourceLedgerTransactionId)
                        ->sum('amount_irr'),
                    'Previously refunded source amount',
                );
                if ($alreadyRefunded > $refundableTotal
                    || $refundAmount->amount > $refundableTotal - $alreadyRefunded) {
                    throw new DomainException('Refund would exceed the source refundable amount.');
                }

                $ledgerReceipt = $this->ledger->post(
                    $this->ledgerCommandKey($refundKey),
                    self::LEDGER_TRANSACTION_TYPE,
                    $context->correlationId,
                    $reversalEntries,
                    'refund',
                    $refundKey,
                );

                $createdAt = $this->timestamp();
                $refundId = (int) $connection->table('refunds')->insertGetId([
                    'refund_key' => $refundKey,
                    'payload_hash' => $payloadHash,
                    'source_ledger_transaction_id' => $sourceLedgerTransactionId,
                    'source_refundable_total_irr' => $refundableTotal,
                    'default_destination' => $defaultDestination->value,
                    'destination' => $destination->value,
                    'destination_overridden' => $destinationOverridden,
                    'amount_irr' => $refundAmount->amount,
                    'requested_by_administrator_id' => $context->actorAdministratorId,
                    'reason_code' => $context->reasonCode,
                    'reason' => $reason,
                    'correlation_id' => $context->correlationId,
                    'request_fingerprint' => $context->requestFingerprint,
                    'manual_external_reference' => $manualExternalReference,
                    'manual_external_evidence_reference' => $manualExternalEvidenceReference,
                    'ledger_transaction_id' => $ledgerReceipt->transactionId,
                    'created_at' => $createdAt,
                ]);

                foreach ($allocationMap as $sourceEntryId => $amount) {
                    $connection->table('refund_allocations')->insert([
                        'refund_id' => $refundId,
                        'source_ledger_entry_id' => $sourceEntryId,
                        'amount_irr' => $amount,
                        'created_at' => $createdAt,
                    ]);
                }

                $this->recordAudit(
                    $connection,
                    $refundId,
                    $sourceLedgerTransactionId,
                    $refundAmount,
                    $defaultDestination,
                    $destination,
                    $destinationOverridden,
                    count($allocationMap),
                    $ledgerReceipt->transactionId,
                    $manualExternalReference !== null,
                    $manualExternalEvidenceReference !== null,
                    $context,
                    $reason,
                );

                return new WalletRefundReceipt(
                    $refundId,
                    $sourceLedgerTransactionId,
                    $defaultDestination,
                    $destination,
                    $refundAmount,
                    $ledgerReceipt->transactionId,
                    $destinationOverridden,
                    false,
                );
            });
        } catch (QueryException $exception) {
            $existing = $this->refundByKey($this->database->connection(), $refundKey);
            if ($existing !== null) {
                return $this->receiptFromExisting(
                    $this->database->connection(),
                    $existing,
                    $payloadHash,
                );
            }

            throw $exception;
        }
    }

    /**
     * @param  list<RefundEntryAllocation>  $allocations
     * @return array<int, int>
     */
    private function allocationMap(array $allocations): array
    {
        $map = [];
        foreach ($allocations as $allocation) {
            if (isset($map[$allocation->sourceLedgerEntryId])) {
                throw new DomainException('Refund source allocation entry is duplicated.');
            }
            $map[$allocation->sourceLedgerEntryId] = $allocation->amount->amount;
        }
        ksort($map, SORT_NUMERIC);

        return $map;
    }

    /**
     * @param  array<int, int>  $allocationMap
     */
    private function payloadHash(
        int $sourceLedgerTransactionId,
        RefundDestination $destination,
        array $allocationMap,
        int $actorAdministratorId,
        string $reasonCode,
        string $reason,
        ?string $manualExternalReference,
        ?string $manualExternalEvidenceReference,
    ): string {
        $allocations = [];
        foreach ($allocationMap as $sourceEntryId => $amount) {
            $allocations[] = [
                'source_ledger_entry_id' => $sourceEntryId,
                'amount_irr' => $amount,
            ];
        }

        return hash('sha256', json_encode([
            'source_ledger_transaction_id' => $sourceLedgerTransactionId,
            'destination' => $destination->value,
            'allocations' => $allocations,
            'actor_administrator_id' => $actorAdministratorId,
            'reason_code' => $reasonCode,
            'reason' => $reason,
            'manual_external_reference' => $manualExternalReference,
            'manual_external_evidence_reference' => $manualExternalEvidenceReference,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<int>  $entryIds
     * @return list<SourceEntryRow>
     */
    private function sourceEntries(Connection $connection, int $sourceLedgerTransactionId, array $entryIds): array
    {
        /** @var list<SourceEntryRow> $rows */
        $rows = $connection->table('ledger_entries as entries')
            ->join('ledger_accounts as accounts', 'accounts.id', '=', 'entries.ledger_account_id')
            ->where('entries.ledger_transaction_id', $sourceLedgerTransactionId)
            ->whereIn('entries.id', $entryIds)
            ->orderBy('entries.id')
            ->get([
                'entries.id as entry_id',
                'entries.ledger_account_id',
                'entries.direction',
                'entries.amount_irr',
                'accounts.account_class',
                'accounts.owner_user_id',
                'accounts.wallet_bucket',
                'accounts.currency',
                'accounts.is_active',
            ])
            ->all();

        if (count($rows) !== count($entryIds)) {
            throw new DomainException('Refund source allocation does not belong to the source ledger transaction.');
        }

        return $rows;
    }

    /**
     * @param  list<SourceEntryRow>  $sourceEntries
     * @param  array<int, int>  $allocationMap
     * @return array{0: IrrMoney, 1: list<LedgerEntryDraft>}
     */
    private function validateAndBuildReversal(
        Connection $connection,
        array $sourceEntries,
        array $allocationMap,
        RefundDestination $destination,
    ): array {
        $sourceDebits = IrrMoney::zero();
        $sourceCredits = IrrMoney::zero();
        $reversalEntries = [];
        $destinationEntryCount = 0;

        foreach ($sourceEntries as $sourceEntry) {
            $sourceEntryId = $this->positiveDatabaseInt($sourceEntry->entry_id, 'Refund source ledger entry ID');
            $sourceAmount = $this->positiveDatabaseInt($sourceEntry->amount_irr, 'Refund source ledger entry amount');
            $allocationAmount = $allocationMap[$sourceEntryId] ?? null;
            if ($allocationAmount === null || $allocationAmount < 1 || $allocationAmount > $sourceAmount) {
                throw new DomainException('Refund source allocation amount is invalid.');
            }

            $alreadyAllocated = $this->nonNegativeDatabaseInt(
                $connection->table('refund_allocations')
                    ->where('source_ledger_entry_id', $sourceEntryId)
                    ->sum('amount_irr'),
                'Previously allocated refund source amount',
            );
            if ($alreadyAllocated > $sourceAmount || $allocationAmount > $sourceAmount - $alreadyAllocated) {
                throw new DomainException('Refund source entry would be over-refunded.');
            }

            if ($sourceEntry->currency !== 'IRR') {
                throw new DomainException('Refund source ledger account currency is not IRR.');
            }
            if (! (bool) $sourceEntry->is_active) {
                throw new DomainException('Refund source ledger account is inactive.');
            }

            $direction = LedgerDirection::tryFrom($sourceEntry->direction);
            if ($direction === null) {
                throw new RuntimeException('Refund source ledger direction is invalid.');
            }
            $amount = IrrMoney::positive($allocationAmount);
            $isWallet = $sourceEntry->owner_user_id !== null && $sourceEntry->wallet_bucket !== null;

            if ($direction === LedgerDirection::Debit) {
                ++$destinationEntryCount;
                $sourceDebits = $sourceDebits->add($amount);

                if ($destination === RefundDestination::Wallet) {
                    if (! $isWallet || $sourceEntry->account_class !== 'liability') {
                        throw new DomainException('Wallet refund must return value to original wallet debit entries only.');
                    }
                } elseif ($isWallet || $sourceEntry->account_class !== 'asset') {
                    throw new DomainException('Manual external refund must return value to original external asset debit entries only.');
                }

                $reversalEntries[] = new LedgerEntryDraft(
                    $this->positiveDatabaseInt($sourceEntry->ledger_account_id, 'Refund destination ledger account ID'),
                    LedgerDirection::Credit,
                    $amount,
                );
            } else {
                $sourceCredits = $sourceCredits->add($amount);
                if ($isWallet) {
                    throw new DomainException('Refund reversal cannot debit a wallet credit entry.');
                }

                $reversalEntries[] = new LedgerEntryDraft(
                    $this->positiveDatabaseInt($sourceEntry->ledger_account_id, 'Refund offset ledger account ID'),
                    LedgerDirection::Debit,
                    $amount,
                );
            }
        }

        if ($destinationEntryCount < 1 || $sourceDebits->isZero() || ! $sourceDebits->equals($sourceCredits)) {
            throw new DomainException('Refund source allocations must form a positive balanced reversal.');
        }

        return [$sourceDebits, $reversalEntries];
    }

    /** @return array{0: string|null, 1: string|null} */
    private function normalizeManualEvidence(
        RefundDestination $destination,
        ?string $manualExternalReference,
        ?string $manualExternalEvidenceReference,
    ): array {
        if ($destination === RefundDestination::Wallet) {
            if ($manualExternalReference !== null || $manualExternalEvidenceReference !== null) {
                throw new DomainException('Wallet refund cannot include manual external evidence fields.');
            }

            return [null, null];
        }

        if ($manualExternalReference === null || $manualExternalEvidenceReference === null) {
            throw new DomainException('Manual external refund requires payment reference and evidence reference.');
        }
        $manualExternalReference = trim($manualExternalReference);
        $manualExternalEvidenceReference = trim($manualExternalEvidenceReference);
        $this->assertPrintable($manualExternalReference, 'Manual external refund reference', 3, 191);
        $this->assertPrintable($manualExternalEvidenceReference, 'Manual external refund evidence reference', 3, 191);

        return [$manualExternalReference, $manualExternalEvidenceReference];
    }

    /** @return RefundRow|null */
    private function lockedRefundByKey(Connection $connection, string $refundKey): ?object
    {
        return $this->refundByKey($connection, $refundKey, true);
    }

    /** @return RefundRow|null */
    private function refundByKey(Connection $connection, string $refundKey, bool $lock = false): ?object
    {
        $query = $connection->table('refunds')->where('refund_key', $refundKey);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var RefundRow|null $row */
        $row = $query->first([
            'id',
            'refund_key',
            'payload_hash',
            'source_ledger_transaction_id',
            'source_refundable_total_irr',
            'default_destination',
            'destination',
            'destination_overridden',
            'amount_irr',
            'requested_by_administrator_id',
            'manual_external_reference',
            'manual_external_evidence_reference',
            'ledger_transaction_id',
        ]);

        return $row;
    }

    /** @param RefundRow $row */
    private function receiptFromExisting(Connection $connection, object $row, string $payloadHash): WalletRefundReceipt
    {
        if (! hash_equals($row->payload_hash, $payloadHash)) {
            throw new RuntimeException('Refund key conflict.');
        }

        $refundId = $this->positiveDatabaseInt($row->id, 'Refund ID');
        $sourceLedgerTransactionId = $this->positiveDatabaseInt(
            $row->source_ledger_transaction_id,
            'Refund source ledger transaction ID',
        );
        $sourceRefundableTotal = $this->positiveDatabaseInt(
            $row->source_refundable_total_irr,
            'Refund source refundable total',
        );
        $amount = IrrMoney::positive($this->positiveDatabaseInt($row->amount_irr, 'Refund amount'));
        if ($amount->amount > $sourceRefundableTotal) {
            throw new RuntimeException('Stored refund exceeds its source refundable snapshot.');
        }

        $defaultDestination = RefundDestination::tryFrom($row->default_destination);
        $destination = RefundDestination::tryFrom($row->destination);
        if ($defaultDestination === null || $destination === null) {
            throw new RuntimeException('Stored refund destination is invalid.');
        }
        $overridden = (bool) $row->destination_overridden;
        if ($overridden !== ($defaultDestination !== $destination)) {
            throw new RuntimeException('Stored refund destination override state is invalid.');
        }
        if ($destination === RefundDestination::Wallet
            && ($row->manual_external_reference !== null || $row->manual_external_evidence_reference !== null)) {
            throw new RuntimeException('Stored wallet refund contains external evidence fields.');
        }
        if ($destination === RefundDestination::ManualExternal
            && ($row->manual_external_reference === null || $row->manual_external_evidence_reference === null)) {
            throw new RuntimeException('Stored manual external refund is missing required evidence fields.');
        }

        $ledgerTransactionId = $this->positiveDatabaseInt(
            $row->ledger_transaction_id,
            'Refund ledger transaction ID',
        );
        /** @var object{transaction_type: string, source_type: string|null, source_id: string|null, expected_total_irr: int|string, posted_debit_irr: int|string, posted_credit_irr: int|string, entry_count: int|string, finalized_at: string|null}|null $ledgerTransaction */
        $ledgerTransaction = $connection->table('ledger_transactions')
            ->where('id', $ledgerTransactionId)
            ->first([
                'transaction_type',
                'source_type',
                'source_id',
                'expected_total_irr',
                'posted_debit_irr',
                'posted_credit_irr',
                'entry_count',
                'finalized_at',
            ]);
        if ($ledgerTransaction === null
            || $ledgerTransaction->transaction_type !== self::LEDGER_TRANSACTION_TYPE
            || $ledgerTransaction->source_type !== 'refund'
            || $ledgerTransaction->source_id !== $row->refund_key
            || $ledgerTransaction->finalized_at === null
            || (int) $ledgerTransaction->expected_total_irr !== $amount->amount
            || (int) $ledgerTransaction->posted_debit_irr !== $amount->amount
            || (int) $ledgerTransaction->posted_credit_irr !== $amount->amount
            || (int) $ledgerTransaction->entry_count < 2) {
            throw new RuntimeException('Stored refund ledger effect failed integrity verification.');
        }

        /** @var object{source_debits: int|string|null, source_credits: int|string|null, allocation_count: int|string}|null $allocationTotals */
        $allocationTotals = $connection->table('refund_allocations as allocations')
            ->join('ledger_entries as entries', 'entries.id', '=', 'allocations.source_ledger_entry_id')
            ->where('allocations.refund_id', $refundId)
            ->selectRaw("SUM(CASE WHEN entries.direction = 'debit' THEN allocations.amount_irr ELSE 0 END) AS source_debits")
            ->selectRaw("SUM(CASE WHEN entries.direction = 'credit' THEN allocations.amount_irr ELSE 0 END) AS source_credits")
            ->selectRaw('COUNT(*) AS allocation_count')
            ->first();
        if ($allocationTotals === null
            || (int) $allocationTotals->source_debits !== $amount->amount
            || (int) $allocationTotals->source_credits !== $amount->amount
            || (int) $allocationTotals->allocation_count < 2) {
            throw new RuntimeException('Stored refund allocations failed integrity verification.');
        }

        return new WalletRefundReceipt(
            $refundId,
            $sourceLedgerTransactionId,
            $defaultDestination,
            $destination,
            $amount,
            $ledgerTransactionId,
            $overridden,
            true,
        );
    }

    private function recordAudit(
        Connection $connection,
        int $refundId,
        int $sourceLedgerTransactionId,
        IrrMoney $amount,
        RefundDestination $defaultDestination,
        RefundDestination $destination,
        bool $destinationOverridden,
        int $allocationCount,
        int $ledgerTransactionId,
        bool $manualReferencePresent,
        bool $manualEvidencePresent,
        AccessChangeContext $context,
        string $reason,
    ): void {
        $connection->table('audit_logs')->insert([
            'actor_type' => 'administrator',
            'actor_id' => (string) $context->actorAdministratorId,
            'action' => 'wallet.refund.complete',
            'target_type' => 'refund',
            'target_id' => (string) $refundId,
            'before_safe_data' => null,
            'after_safe_data' => json_encode([
                'source_ledger_transaction_id' => $sourceLedgerTransactionId,
                'amount_irr' => $amount->amount,
                'default_destination' => $defaultDestination->value,
                'destination' => $destination->value,
                'destination_overridden' => $destinationOverridden,
                'allocation_count' => $allocationCount,
                'ledger_transaction_id' => $ledgerTransactionId,
                'manual_reference_present' => $manualReferencePresent,
                'manual_evidence_present' => $manualEvidencePresent,
            ], JSON_THROW_ON_ERROR),
            'reason_code' => $context->reasonCode,
            'reason' => $reason,
            'correlation_id' => $context->correlationId,
            'request_fingerprint' => $context->requestFingerprint,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function ledgerCommandKey(string $refundKey): string
    {
        return 'ledger.refund.'.hash('sha256', $refundKey);
    }

    private function positiveDatabaseInt(int|string $value, string $label): int
    {
        if (is_string($value) && preg_match('/\A[0-9]+\z/', $value) !== 1) {
            throw new RuntimeException($label.' is invalid.');
        }
        $integer = (int) $value;
        if ($integer < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }

    private function nonNegativeDatabaseInt(int|float|string|null $value, string $label): int
    {
        if ($value === null) {
            return 0;
        }
        if (is_float($value)) {
            if (! is_finite($value) || floor($value) !== $value) {
                throw new RuntimeException($label.' is invalid.');
            }
        } elseif (is_string($value) && preg_match('/\A[0-9]+\z/', $value) !== 1) {
            throw new RuntimeException($label.' is invalid.');
        }
        $integer = (int) $value;
        if ($integer < 0) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
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
