<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Payments\Usdt\Application\Contracts\BlockchainTransactionVerificationProvider;
use App\Modules\Payments\Usdt\Application\Contracts\UsdtBlockchainVerificationEvidence;
use App\Modules\Payments\Usdt\Application\Contracts\UsdtBlockchainVerificationRequest;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class UsdtBlockchainVerificationService
{
    public function __construct(
        private DatabaseManager $database,
        private UsdtVerifiedTransferService $verifiedTransfers,
        private Clock $clock,
    ) {}

    /** @requirement USDT-003 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 SEC-002 INT-001 INT-002 QUA-004 */
    public function verify(
        string $submissionPublicId,
        BlockchainTransactionVerificationProvider $provider,
        string $correlationId,
    ): UsdtProcessingReceipt {
        if (! Str::isUlid($submissionPublicId)) {
            throw new DomainException('USDT submission public ID is invalid.');
        }
        $this->assertProviderCode($provider->code());
        $this->assertCorrelation($correlationId);

        $authority = $this->authority($submissionPublicId);
        if ($authority === null) {
            throw new DomainException('USDT TXID submission does not exist.');
        }
        if (in_array($authority->submission_state, ['captured', 'verified'], true)) {
            return $this->verifiedTransfers->settlePersisted($submissionPublicId, $correlationId);
        }
        if ($authority->submission_state === 'pending_manual_review') {
            return $this->receipt($authority, true);
        }
        if (! in_array($authority->submission_state, ['submitted', 'verifying', 'provider_unavailable'], true)) {
            throw new RuntimeException('USDT submission is not eligible for automatic blockchain verification.');
        }

        $this->beginVerification($authority, $correlationId);
        $authority = $this->authority($submissionPublicId) ?? throw new RuntimeException('USDT authority disappeared before provider lookup.');
        $request = new UsdtBlockchainVerificationRequest(
            (string) $authority->txid,
            (string) $authority->network,
            (int) $authority->chain_id,
            (string) $authority->token_contract,
            (string) $authority->destination_address,
            (int) $authority->expected_amount_base_units,
            UsdtTokenAmount::DECIMALS,
            (int) $authority->minimum_confirmations,
        );

        try {
            $evidence = $provider->lookup($request);
        } catch (Throwable $exception) {
            $this->markProviderUnavailable($authority, $provider->code(), $correlationId);
            throw new RuntimeException('USDT blockchain verification provider lookup failed.', 0, $exception);
        }

        $this->validateEvidenceEnvelope($evidence, (string) $authority->txid);

        if ($evidence->outcome === 'success' && $evidence->transactionStatus === 'success') {
            $reason = $this->mismatchReason($authority, $evidence);
            if ($reason === null) {
                return $this->verifiedTransfers->recordAndSettle($submissionPublicId, $provider->code(), $evidence, $correlationId);
            }
            $this->recordEvent($authority, $provider->code(), $evidence);
            if ($reason === 'insufficient_confirmations') {
                $this->recordFinding($authority, $reason, 'warning', $provider->code(), $evidence, $correlationId);
                return $this->receipt($this->authority($submissionPublicId) ?? $authority, false);
            }
            return $this->queueManualReview($authority, $reason, $provider->code(), $evidence, $correlationId);
        }

        $this->recordEvent($authority, $provider->code(), $evidence);
        if ($evidence->outcome === 'pending'
            || $evidence->outcome === 'uncertain'
            || $evidence->outcome === 'unavailable'
            || in_array($evidence->transactionStatus, ['pending', 'not_found', 'unknown'], true)) {
            $this->recordFinding($authority, 'chain_verification_pending_or_uncertain', 'warning', $provider->code(), $evidence, $correlationId);
            return $this->receipt($this->authority($submissionPublicId) ?? $authority, false);
        }

        return $this->queueManualReview(
            $authority,
            'chain_transaction_'.$evidence->transactionStatus,
            $provider->code(),
            $evidence,
            $correlationId,
        );
    }

    private function beginVerification(object $authority, string $correlationId): void
    {
        $this->database->connection()->transaction(function (Connection $connection) use ($authority, $correlationId): void {
            $submission = $connection->table('usdt_txid_submissions')->where('id', $authority->submission_id)->lockForUpdate()->first();
            $intent = $connection->table('payment_intents')->where('id', $authority->payment_intent_id)->lockForUpdate()->first();
            if ($submission === null || $intent === null) {
                throw new RuntimeException('USDT verification state disappeared.');
            }
            if ($submission->state === 'submitted') {
                $updated = $connection->table('usdt_txid_submissions')->where('id', $submission->id)->where('state', 'submitted')->update(['state' => 'verifying']);
                if ($updated !== 1) {
                    throw new RuntimeException('USDT submission verification transition changed concurrently.');
                }
            } elseif ($submission->state === 'provider_unavailable') {
                $updated = $connection->table('usdt_txid_submissions')->where('id', $submission->id)->where('state', 'provider_unavailable')->update(['state' => 'verifying']);
                if ($updated !== 1) {
                    throw new RuntimeException('USDT provider retry transition changed concurrently.');
                }
            } elseif ($submission->state !== 'verifying') {
                throw new RuntimeException('USDT submission state is not ready for provider lookup.');
            }

            if ($intent->state === PaymentIntentState::Submitted->value) {
                $this->transitionIntent($connection, (int) $intent->id, PaymentIntentState::Submitted, PaymentIntentState::Verifying, 'usdt_bep20_verification_started', $correlationId);
            } elseif ($intent->state !== PaymentIntentState::Verifying->value) {
                throw new RuntimeException('USDT payment intent state is not ready for provider verification.');
            }
        }, 3);
    }

    private function markProviderUnavailable(object $authority, string $providerCode, string $correlationId): void
    {
        $this->database->connection()->table('usdt_txid_submissions')->where('id', $authority->submission_id)->where('state', 'verifying')->update([
            'state' => 'provider_unavailable',
        ]);
        $this->recordFinding($authority, 'chain_provider_lookup_failed', 'warning', $providerCode, null, $correlationId);
    }

    private function queueManualReview(object $authority, string $reason, string $providerCode, ?UsdtBlockchainVerificationEvidence $evidence, string $correlationId): UsdtProcessingReceipt
    {
        $this->database->connection()->transaction(function (Connection $connection) use ($authority, $reason, $correlationId): void {
            $submission = $connection->table('usdt_txid_submissions')->where('id', $authority->submission_id)->lockForUpdate()->first();
            $intent = $connection->table('payment_intents')->where('id', $authority->payment_intent_id)->lockForUpdate()->first();
            if ($submission === null || $intent === null) {
                throw new RuntimeException('USDT manual-review authority disappeared.');
            }
            if ($submission->state !== 'pending_manual_review') {
                if (! in_array($submission->state, ['verifying', 'provider_unavailable'], true)) {
                    throw new RuntimeException('USDT submission cannot enter manual review from its current state.');
                }
                $updated = $connection->table('usdt_txid_submissions')->where('id', $submission->id)->where('state', $submission->state)->update(['state' => 'pending_manual_review']);
                if ($updated !== 1) {
                    throw new RuntimeException('USDT manual-review transition changed concurrently.');
                }
            }
            $now = $this->timestamp();
            $connection->table('usdt_manual_reviews')->insertOrIgnore([
                'public_id' => (string) Str::ulid(),
                'usdt_txid_submission_id' => (int) $submission->id,
                'reason_code' => substr($reason, 0, 64),
                'state' => 'pending',
                'decided_by_administrator_id' => null,
                'decision_reason' => null,
                'decided_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($intent->state === PaymentIntentState::Verifying->value) {
                $this->transitionIntent($connection, (int) $intent->id, PaymentIntentState::Verifying, PaymentIntentState::PendingManualReview, 'usdt_bep20_manual_review', $correlationId);
            } elseif ($intent->state !== PaymentIntentState::PendingManualReview->value) {
                throw new RuntimeException('USDT payment intent cannot enter manual review from its current state.');
            }
        }, 3);
        $this->recordFinding($authority, $reason, 'high', $providerCode, $evidence, $correlationId);

        return $this->receipt($this->authority((string) $authority->submission_public_id) ?? $authority, false);
    }

    private function mismatchReason(object $authority, UsdtBlockchainVerificationEvidence $evidence): ?string
    {
        if ($evidence->network !== $authority->network || $evidence->chainId !== (int) $authority->chain_id) {
            return 'wrong_network';
        }
        if ($evidence->tokenContract === null || ! hash_equals((string) $authority->token_contract, strtolower($evidence->tokenContract))) {
            return 'wrong_token_contract';
        }
        if ($evidence->destinationAddress === null || ! hash_equals((string) $authority->destination_address, strtolower($evidence->destinationAddress))) {
            return 'wrong_destination';
        }
        if ($evidence->tokenDecimals !== UsdtTokenAmount::DECIMALS) {
            return 'wrong_token_decimals';
        }
        if ($evidence->amountBaseUnits === null) {
            return 'missing_transfer_amount';
        }
        if ($evidence->amountBaseUnits < (int) $authority->expected_amount_base_units) {
            return 'underpaid';
        }
        if ($evidence->amountBaseUnits > (int) $authority->expected_amount_base_units) {
            return 'overpaid';
        }
        if ($evidence->confirmations === null || $evidence->confirmations < (int) $authority->minimum_confirmations) {
            return 'insufficient_confirmations';
        }
        if ($evidence->transactionAt === null) {
            return 'missing_transaction_time';
        }
        if ($evidence->transactionAt < $this->storedDateTime((string) $authority->authority_created_at)) {
            return 'transaction_predates_authority';
        }
        if ($evidence->transactionAt > $this->storedDateTime((string) $authority->quote_expires_at)) {
            return 'late_transaction';
        }

        return null;
    }

    private function recordEvent(object $authority, string $providerCode, UsdtBlockchainVerificationEvidence $evidence): void
    {
        $connection = $this->database->connection();
        $existing = $connection->table('usdt_chain_verification_events')
            ->where('provider_code', $providerCode)
            ->where(function ($query) use ($evidence): void {
                $query->where('provider_event_id', $evidence->providerEventId)
                    ->orWhere('evidence_hash', strtolower($evidence->evidenceHash));
            })
            ->first();
        if ($existing !== null) {
            $this->assertEventReplay($existing, $authority, $providerCode, $evidence);
            return;
        }
        try {
            $connection->table('usdt_chain_verification_events')->insert([
                'public_id' => (string) Str::ulid(),
                'usdt_txid_submission_id' => (int) $authority->submission_id,
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
        } catch (QueryException $exception) {
            $replay = $connection->table('usdt_chain_verification_events')
                ->where('provider_code', $providerCode)
                ->where(function ($query) use ($evidence): void {
                    $query->where('provider_event_id', $evidence->providerEventId)
                        ->orWhere('evidence_hash', strtolower($evidence->evidenceHash));
                })
                ->first();
            if ($replay === null) {
                throw $exception;
            }
            $this->assertEventReplay($replay, $authority, $providerCode, $evidence);
        }
    }

    private function assertEventReplay(object $row, object $authority, string $providerCode, UsdtBlockchainVerificationEvidence $evidence): void
    {
        if ((int) $row->usdt_txid_submission_id !== (int) $authority->submission_id
            || ! hash_equals((string) $row->provider_code, $providerCode)
            || ! hash_equals((string) $row->txid, strtolower($evidence->txid))
            || ! hash_equals(strtolower((string) $row->evidence_hash), strtolower($evidence->evidenceHash))) {
            throw new RuntimeException('USDT chain verification event replay conflicts with accepted evidence.');
        }
    }

    private function validateEvidenceEnvelope(UsdtBlockchainVerificationEvidence $evidence, string $expectedTxid): void
    {
        if (! in_array($evidence->outcome, ['success','pending','rejected','uncertain','unavailable'], true)
            || ! in_array($evidence->transactionStatus, ['success','pending','failed','reverted','not_found','unknown'], true)
            || ! hash_equals($expectedTxid, strtolower($evidence->txid))
            || preg_match('/\A0x[a-fA-F0-9]{64}\z/', $evidence->txid) !== 1
            || preg_match('/\A[a-fA-F0-9]{64}\z/', $evidence->evidenceHash) !== 1
            || $evidence->providerEventId === '' || strlen($evidence->providerEventId) > 191
            || preg_match('/[\x00-\x20\x7F]/', $evidence->providerEventId) === 1) {
            throw new DomainException('USDT blockchain verification evidence envelope is invalid.');
        }
        if ($evidence->network !== null && preg_match('/\A[A-Za-z0-9:_.-]{1,16}\z/', $evidence->network) !== 1) {
            throw new DomainException('USDT blockchain verification network evidence is invalid.');
        }
        foreach ([$evidence->tokenContract, $evidence->destinationAddress] as $address) {
            if ($address !== null && preg_match('/\A0x[a-fA-F0-9]{40}\z/', $address) !== 1) {
                throw new DomainException('USDT blockchain verification address evidence is invalid.');
            }
        }
        if (($evidence->chainId !== null && $evidence->chainId < 1)
            || ($evidence->amountBaseUnits !== null && $evidence->amountBaseUnits < 1)
            || ($evidence->tokenDecimals !== null && ($evidence->tokenDecimals < 0 || $evidence->tokenDecimals > 36))
            || ($evidence->confirmations !== null && $evidence->confirmations < 0)
            || ($evidence->blockNumber !== null && $evidence->blockNumber < 0)) {
            throw new DomainException('USDT blockchain verification numeric evidence is invalid.');
        }
    }

    private function authority(string $submissionPublicId): ?object
    {
        return $this->database->connection()->table('usdt_txid_submissions as submission')
            ->join('usdt_payment_authorities as authority', 'authority.id', '=', 'submission.usdt_payment_authority_id')
            ->join('payment_intents as intent', 'intent.id', '=', 'submission.payment_intent_id')
            ->leftJoin('usdt_verified_transfers as transfer', 'transfer.usdt_txid_submission_id', '=', 'submission.id')
            ->where('submission.public_id', $submissionPublicId)
            ->first([
                'submission.id as submission_id', 'submission.public_id as submission_public_id', 'submission.txid', 'submission.state as submission_state',
                'submission.payment_intent_id', 'authority.public_id as authority_public_id', 'authority.network', 'authority.chain_id',
                'authority.token_contract', 'authority.destination_address', 'authority.expected_amount_base_units', 'authority.minimum_confirmations',
                'authority.created_at as authority_created_at', 'authority.quote_expires_at', 'intent.state as intent_state', 'intent.captured_at',
                'transfer.public_id as transfer_public_id', 'transfer.purchase_settlement_id',
            ]);
    }

    private function receipt(object $authority, bool $replayed): UsdtProcessingReceipt
    {
        $review = $this->database->connection()->table('usdt_manual_reviews')->where('usdt_txid_submission_id', $authority->submission_id)->value('public_id');
        $settlement = $authority->purchase_settlement_id === null ? null : $this->database->connection()->table('purchase_settlements')->where('id', $authority->purchase_settlement_id)->value('public_id');
        return new UsdtProcessingReceipt(
            (string) $authority->submission_public_id,
            (string) $authority->submission_state,
            is_string($review) ? $review : null,
            $authority->transfer_public_id === null ? null : (string) $authority->transfer_public_id,
            is_string($settlement) ? $settlement : null,
            $replayed,
        );
    }

    private function recordFinding(object $authority, string $type, string $severity, ?string $providerCode, ?UsdtBlockchainVerificationEvidence $evidence, string $correlationId): void
    {
        $key = hash('sha256', implode("\0", [(string) $authority->submission_public_id, $type, $providerCode ?? '', $evidence?->providerEventId ?? '', $evidence?->evidenceHash ?? '']));
        $this->database->connection()->table('usdt_reconciliation_findings')->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'usdt_txid_submission_id' => (int) $authority->submission_id,
            'finding_key' => $key,
            'finding_type' => substr($type, 0, 64),
            'severity' => $severity,
            'provider_code' => $providerCode,
            'txid' => (string) $authority->txid,
            'provider_event_id' => $evidence?->providerEventId,
            'evidence_hash' => $evidence === null ? null : strtolower($evidence->evidenceHash),
            'correlation_id' => strtolower($correlationId),
            'created_at' => $this->timestamp(),
        ]);
    }

    private function transitionIntent(Connection $connection, int $intentId, PaymentIntentState $from, PaymentIntentState $to, string $reason, string $correlationId): void
    {
        $from->transitionTo($to);
        $updated = $connection->table('payment_intents')->where('id', $intentId)->where('state', $from->value)->update([
            'state' => $to->value,
            'updated_at' => $this->timestamp(),
        ]);
        if ($updated !== 1) {
            throw new RuntimeException('USDT payment intent state transition changed concurrently.');
        }
        $connection->table('payment_intent_state_histories')->insert([
            'payment_intent_id' => $intentId,
            'from_state' => $from->value,
            'to_state' => $to->value,
            'reason_code' => $reason,
            'correlation_id' => strtolower($correlationId),
            'created_at' => $this->timestamp(),
        ]);
    }

    private function assertProviderCode(string $value): void
    {
        if (preg_match('/\A[a-zA-Z0-9:_.-]{2,64}\z/', $value) !== 1) {
            throw new DomainException('USDT blockchain verification provider code is invalid.');
        }
    }

    private function assertCorrelation(string $value): void
    {
        if (preg_match('/\A[a-fA-F0-9]{64}\z/', $value) !== 1) {
            throw new DomainException('USDT verification correlation ID must be a SHA-256 hex digest.');
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
