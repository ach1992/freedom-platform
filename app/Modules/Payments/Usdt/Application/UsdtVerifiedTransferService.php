<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\PurchaseSettlementService;
use App\Modules\Payments\Usdt\Application\Contracts\UsdtBlockchainVerificationEvidence;
use App\Shared\Application\Clock;
use App\Shared\Domain\Money;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class UsdtVerifiedTransferService
{
    public function __construct(
        private DatabaseManager $database,
        private PurchaseSettlementService $purchaseSettlements,
        private Clock $clock,
    ) {}

    /** @requirement USDT-003 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function recordAndSettle(
        string $submissionPublicId,
        string $providerCode,
        UsdtBlockchainVerificationEvidence $evidence,
        string $correlationId,
    ): UsdtProcessingReceipt {
        $this->assertIdentity($submissionPublicId, $providerCode, $correlationId);
        $this->validateSuccessfulEvidence($evidence);

        $this->persistVerifiedTransfer($submissionPublicId, $providerCode, $evidence);

        try {
            return $this->settlePersisted($submissionPublicId, $correlationId);
        } catch (Throwable $exception) {
            $this->recordFinding(
                $submissionPublicId,
                'verified_chain_local_not_captured',
                'critical',
                $providerCode,
                $evidence,
                $correlationId,
            );
            throw $exception;
        }
    }

    public function settlePersisted(string $submissionPublicId, string $correlationId): UsdtProcessingReceipt
    {
        if (! Str::isUlid($submissionPublicId)) {
            throw new DomainException('USDT submission public ID is invalid.');
        }
        $this->assertCorrelation($correlationId);

        $authority = $this->database->connection()->table('usdt_verified_transfers as transfer')
            ->join('usdt_txid_submissions as submission', 'submission.id', '=', 'transfer.usdt_txid_submission_id')
            ->join('usdt_payment_authorities as authority', 'authority.id', '=', 'transfer.usdt_payment_authority_id')
            ->join('usdt_chain_verification_events as event_row', 'event_row.id', '=', 'transfer.provider_event_row_id')
            ->join('payment_intents as intent', 'intent.id', '=', 'transfer.payment_intent_id')
            ->where('submission.public_id', $submissionPublicId)
            ->first([
                'transfer.id as transfer_id', 'transfer.public_id as transfer_public_id', 'transfer.provider_code',
                'transfer.txid', 'transfer.amount_base_units', 'transfer.confirmations', 'transfer.evidence_hash',
                'transfer.transaction_at', 'transfer.verified_at', 'transfer.purchase_settlement_id',
                'submission.id as submission_id', 'submission.state as submission_state',
                'authority.source_amount_irr', 'intent.public_id as intent_public_id', 'intent.state as intent_state',
                'event_row.provider_event_id as provider_event_id',
            ]);
        if ($authority === null) {
            throw new DomainException('USDT verified transfer does not exist.');
        }

        if ($authority->purchase_settlement_id !== null) {
            $settlementPublicId = $this->database->connection()->table('purchase_settlements')
                ->where('id', $authority->purchase_settlement_id)->value('public_id');
            if (! is_string($settlementPublicId) || $authority->submission_state !== 'captured' || $authority->intent_state !== 'captured') {
                throw new RuntimeException('USDT verified transfer settlement linkage is inconsistent.');
            }
            return new UsdtProcessingReceipt(
                $submissionPublicId,
                'captured',
                $this->reviewPublicId((int) $authority->submission_id),
                (string) $authority->transfer_public_id,
                $settlementPublicId,
                true,
            );
        }

        if ($authority->submission_state !== 'verified') {
            throw new RuntimeException('USDT verified transfer is not in a settleable local state.');
        }

        $transactionAt = $this->storedDateTime((string) $authority->transaction_at);
        $verifiedAt = $this->storedDateTime((string) $authority->verified_at);
        $commonTransactionId = hash('sha256', "BEP20\0".(string) $authority->txid);
        $commonEventId = hash('sha256', (string) $authority->provider_code."\0".(string) $authority->provider_event_id);
        $verifiedEvent = new VerifiedPaymentEvent(
            $commonEventId,
            strtolower((string) $authority->evidence_hash),
            new PaymentEvidence(
                ProviderOperationOutcome::Success,
                PaymentEvidenceAuthority::Authoritative,
                PaymentTransactionStatus::Settled,
                $commonTransactionId,
                $commonEventId,
                Money::irr((int) $authority->source_amount_irr),
                $transactionAt,
                $verifiedAt,
                strtolower((string) $authority->evidence_hash),
                ['network' => 'BEP20', 'txid' => (string) $authority->txid],
            ),
        );

        $settlement = $this->purchaseSettlements->capture(
            (string) $authority->intent_public_id,
            UsdtPaymentAuthorityService::METHOD_CODE,
            $verifiedEvent,
            $correlationId,
        );

        $this->database->connection()->transaction(function (Connection $connection) use ($authority, $settlement): void {
            $transfer = $connection->table('usdt_verified_transfers')->where('id', $authority->transfer_id)->lockForUpdate()->first();
            $submission = $connection->table('usdt_txid_submissions')->where('id', $authority->submission_id)->lockForUpdate()->first();
            if ($transfer === null || $submission === null) {
                throw new RuntimeException('USDT verified transfer linkage disappeared after settlement.');
            }
            if ($transfer->purchase_settlement_id !== null) {
                if ((int) $transfer->purchase_settlement_id !== $settlement->settlementId) {
                    throw new RuntimeException('USDT verified transfer is linked to another settlement.');
                }
                return;
            }
            $connection->table('usdt_verified_transfers')->where('id', $transfer->id)->update([
                'purchase_settlement_id' => $settlement->settlementId,
            ]);
            $updated = $connection->table('usdt_txid_submissions')->where('id', $submission->id)->where('state', 'verified')->update([
                'state' => 'captured',
            ]);
            if ($updated !== 1) {
                throw new RuntimeException('USDT submission capture state changed concurrently.');
            }
        }, 3);

        return new UsdtProcessingReceipt(
            $submissionPublicId,
            'captured',
            $this->reviewPublicId((int) $authority->submission_id),
            (string) $authority->transfer_public_id,
            $settlement->settlementPublicId,
            $settlement->replayed,
        );
    }

    private function persistVerifiedTransfer(string $submissionPublicId, string $providerCode, UsdtBlockchainVerificationEvidence $evidence): void
    {
        try {
            $this->database->connection()->transaction(function (Connection $connection) use ($submissionPublicId, $providerCode, $evidence): void {
                $authority = $connection->table('usdt_txid_submissions as submission')
                    ->join('usdt_payment_authorities as authority', 'authority.id', '=', 'submission.usdt_payment_authority_id')
                    ->join('payment_intents as intent', 'intent.id', '=', 'submission.payment_intent_id')
                    ->where('submission.public_id', $submissionPublicId)
                    ->lockForUpdate()
                    ->first([
                        'submission.id as submission_id', 'submission.state as submission_state', 'submission.txid',
                        'authority.id as authority_id', 'authority.payment_intent_id', 'authority.network', 'authority.chain_id',
                        'authority.token_contract', 'authority.destination_address', 'authority.expected_amount_base_units',
                        'authority.minimum_confirmations', 'authority.created_at as authority_created_at', 'authority.quote_expires_at',
                        'intent.state as intent_state', 'intent.captured_at',
                    ]);
                if ($authority === null) {
                    throw new DomainException('USDT TXID submission does not exist.');
                }
                $existing = $connection->table('usdt_verified_transfers')->where('usdt_txid_submission_id', $authority->submission_id)->lockForUpdate()->first();
                if ($existing !== null) {
                    if (! hash_equals((string) $existing->provider_code, $providerCode)
                        || ! hash_equals((string) $existing->txid, strtolower($evidence->txid))
                        || ! hash_equals(strtolower((string) $existing->evidence_hash), strtolower($evidence->evidenceHash))) {
                        throw new RuntimeException('USDT submission already has conflicting verified transfer authority.');
                    }
                    return;
                }
                if (! in_array($authority->submission_state, ['verifying', 'pending_manual_review'], true)
                    || $authority->captured_at !== null) {
                    throw new RuntimeException('USDT submission is not ready to accept verified transfer evidence.');
                }
                $this->assertEvidenceMatchesAuthority($authority, $evidence);

                $eventRow = $this->recordChainEvent($connection, (int) $authority->submission_id, $providerCode, $evidence);
                $transferId = (int) $connection->table('usdt_verified_transfers')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'usdt_payment_authority_id' => (int) $authority->authority_id,
                    'usdt_txid_submission_id' => (int) $authority->submission_id,
                    'provider_event_row_id' => (int) $eventRow->id,
                    'payment_intent_id' => (int) $authority->payment_intent_id,
                    'provider_code' => $providerCode,
                    'txid' => strtolower($evidence->txid),
                    'amount_base_units' => (int) $evidence->amountBaseUnits,
                    'confirmations' => (int) $evidence->confirmations,
                    'evidence_hash' => strtolower($evidence->evidenceHash),
                    'transaction_at' => $this->databaseDateTime($evidence->transactionAt ?? throw new DomainException('USDT successful evidence lacks transaction time.')),
                    'verified_at' => $this->databaseDateTime($evidence->observedAt),
                    'purchase_settlement_id' => null,
                    'created_at' => $this->timestamp(),
                ]);
                $updated = $connection->table('usdt_txid_submissions')->where('id', $authority->submission_id)
                    ->where('state', $authority->submission_state)->update(['state' => 'verified']);
                if ($updated !== 1) {
                    throw new RuntimeException('USDT verified submission state changed concurrently.');
                }
                if ($connection->table('usdt_verified_transfers')->where('id', $transferId)->doesntExist()) {
                    throw new RuntimeException('USDT verified transfer persistence failed.');
                }
            }, 3);
        } catch (QueryException $exception) {
            $conflict = $this->database->connection()->table('usdt_verified_transfers')
                ->where('txid', strtolower($evidence->txid))->orWhere('evidence_hash', strtolower($evidence->evidenceHash))->first(['usdt_txid_submission_id']);
            if ($conflict !== null) {
                throw new RuntimeException('USDT verified transfer evidence is already bound to another payment.', 0, $exception);
            }
            throw $exception;
        }
    }

    private function recordChainEvent(Connection $connection, int $submissionId, string $providerCode, UsdtBlockchainVerificationEvidence $evidence): object
    {
        $existing = $connection->table('usdt_chain_verification_events')
            ->where('provider_code', $providerCode)->where('provider_event_id', $evidence->providerEventId)->lockForUpdate()->first();
        if ($existing !== null) {
            if ((int) $existing->usdt_txid_submission_id !== $submissionId
                || ! hash_equals((string) $existing->txid, strtolower($evidence->txid))
                || ! hash_equals(strtolower((string) $existing->evidence_hash), strtolower($evidence->evidenceHash))) {
                throw new RuntimeException('USDT chain event replay conflicts with accepted evidence.');
            }
            return $existing;
        }
        $id = (int) $connection->table('usdt_chain_verification_events')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'usdt_txid_submission_id' => $submissionId,
            'provider_code' => $providerCode,
            'provider_event_id' => $evidence->providerEventId,
            'txid' => strtolower($evidence->txid),
            'outcome' => $evidence->outcome,
            'transaction_status' => $evidence->transactionStatus,
            'network' => $evidence->network,
            'chain_id' => $evidence->chainId,
            'token_contract' => $evidence->tokenContract === null ? null : strtolower($evidence->tokenContract),
            'destination_address' => $evidence->destinationAddress === null ? null : strtolower($evidence->destinationAddress),
            'amount_base_units' => $evidence->amountBaseUnits,
            'token_decimals' => $evidence->tokenDecimals,
            'confirmations' => $evidence->confirmations,
            'block_number' => $evidence->blockNumber,
            'transaction_at' => $evidence->transactionAt === null ? null : $this->databaseDateTime($evidence->transactionAt),
            'evidence_hash' => strtolower($evidence->evidenceHash),
            'observed_at' => $this->databaseDateTime($evidence->observedAt),
            'created_at' => $this->timestamp(),
        ]);
        $row = $connection->table('usdt_chain_verification_events')->where('id', $id)->first();
        if ($row === null) {
            throw new RuntimeException('USDT chain event persistence failed.');
        }
        return $row;
    }

    private function validateSuccessfulEvidence(UsdtBlockchainVerificationEvidence $evidence): void
    {
        if ($evidence->outcome !== 'success' || $evidence->transactionStatus !== 'success'
            || $evidence->network === null || $evidence->chainId === null || $evidence->tokenContract === null
            || $evidence->destinationAddress === null || $evidence->amountBaseUnits === null || $evidence->tokenDecimals === null
            || $evidence->confirmations === null || $evidence->transactionAt === null) {
            throw new DomainException('USDT verified transfer requires complete successful blockchain evidence.');
        }
        if (preg_match('/\A0x[a-fA-F0-9]{64}\z/', $evidence->txid) !== 1
            || preg_match('/\A[a-fA-F0-9]{64}\z/', $evidence->evidenceHash) !== 1
            || $evidence->providerEventId === '' || strlen($evidence->providerEventId) > 191) {
            throw new DomainException('USDT blockchain evidence identity is invalid.');
        }
    }

    private function assertEvidenceMatchesAuthority(object $authority, UsdtBlockchainVerificationEvidence $evidence): void
    {
        if (! hash_equals((string) $authority->txid, strtolower($evidence->txid))
            || $evidence->network !== $authority->network
            || $evidence->chainId !== (int) $authority->chain_id
            || ! hash_equals((string) $authority->token_contract, strtolower((string) $evidence->tokenContract))
            || ! hash_equals((string) $authority->destination_address, strtolower((string) $evidence->destinationAddress))
            || $evidence->amountBaseUnits !== (int) $authority->expected_amount_base_units
            || $evidence->tokenDecimals !== UsdtTokenAmount::DECIMALS
            || $evidence->confirmations < (int) $authority->minimum_confirmations) {
            throw new DomainException('USDT blockchain evidence does not exactly match the prepared payment authority.');
        }
        if ($authority->submission_state === 'verifying') {
            $transactionAt = $evidence->transactionAt ?? throw new DomainException('USDT blockchain evidence lacks transaction time.');
            if ($transactionAt < $this->storedDateTime((string) $authority->authority_created_at)
                || $transactionAt > $this->storedDateTime((string) $authority->quote_expires_at)) {
                throw new DomainException('USDT automatic verification cannot accept a transfer outside the locked quote window.');
            }
        }
    }

    private function recordFinding(string $submissionPublicId, string $type, string $severity, ?string $providerCode, ?UsdtBlockchainVerificationEvidence $evidence, string $correlationId): void
    {
        $submission = $this->database->connection()->table('usdt_txid_submissions')->where('public_id', $submissionPublicId)->first(['id','txid']);
        if ($submission === null) {
            return;
        }
        $key = hash('sha256', implode("\0", [$submissionPublicId, $type, $providerCode ?? '', $evidence?->providerEventId ?? '', $evidence?->evidenceHash ?? '']));
        $this->database->connection()->table('usdt_reconciliation_findings')->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'usdt_txid_submission_id' => (int) $submission->id,
            'finding_key' => $key,
            'finding_type' => $type,
            'severity' => $severity,
            'provider_code' => $providerCode,
            'txid' => (string) $submission->txid,
            'provider_event_id' => $evidence?->providerEventId,
            'evidence_hash' => $evidence === null ? null : strtolower($evidence->evidenceHash),
            'correlation_id' => strtolower($correlationId),
            'created_at' => $this->timestamp(),
        ]);
    }

    private function reviewPublicId(int $submissionId): ?string
    {
        $value = $this->database->connection()->table('usdt_manual_reviews')->where('usdt_txid_submission_id', $submissionId)->value('public_id');
        return is_string($value) ? $value : null;
    }

    private function assertIdentity(string $submissionPublicId, string $providerCode, string $correlationId): void
    {
        if (! Str::isUlid($submissionPublicId) || preg_match('/\A[a-zA-Z0-9:_.-]{2,64}\z/', $providerCode) !== 1) {
            throw new DomainException('USDT verified transfer identity is invalid.');
        }
        $this->assertCorrelation($correlationId);
    }

    private function assertCorrelation(string $value): void
    {
        if (preg_match('/\A[a-fA-F0-9]{64}\z/', $value) !== 1) {
            throw new DomainException('USDT correlation ID must be a SHA-256 hex digest.');
        }
    }

    private function storedDateTime(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new RuntimeException('Stored USDT timestamp is invalid.');
        }
        return $date;
    }

    private function databaseDateTime(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function timestamp(): string
    {
        return $this->databaseDateTime($this->clock->now());
    }
}
