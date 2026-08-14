<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

use App\Modules\Payments\Usdt\Application\Contracts\BlockchainTransactionVerificationProvider;
use App\Modules\Payments\Usdt\Application\Contracts\UsdtBlockchainVerificationEvidence;
use App\Modules\Payments\Usdt\Application\Contracts\UsdtBlockchainVerificationRequest;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class UsdtReconciliationService
{
    public function __construct(
        private DatabaseManager $database,
        private UsdtBlockchainVerificationService $verification,
        private UsdtVerifiedTransferService $verifiedTransfers,
        private Clock $clock,
    ) {}

    /** @requirement USDT-003 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 INT-001 INT-002 QUA-004 */
    public function reconcile(
        string $submissionPublicId,
        BlockchainTransactionVerificationProvider $provider,
        string $correlationId,
    ): UsdtProcessingReceipt {
        if (! Str::isUlid($submissionPublicId)) {
            throw new DomainException('USDT reconciliation submission public ID is invalid.');
        }
        if (preg_match('/\A[a-zA-Z0-9:_.-]{2,64}\z/', $provider->code()) !== 1
            || preg_match('/\A[a-fA-F0-9]{64}\z/', $correlationId) !== 1) {
            throw new DomainException('USDT reconciliation identity is invalid.');
        }

        $authority = $this->authority($submissionPublicId);
        if ($authority === null) {
            throw new DomainException('USDT reconciliation submission does not exist.');
        }

        if ($authority->transfer_id !== null && $authority->purchase_settlement_id === null) {
            return $this->verifiedTransfers->settlePersisted($submissionPublicId, $correlationId);
        }
        if (in_array($authority->submission_state, ['submitted','verifying','provider_unavailable'], true)) {
            return $this->verification->verify($submissionPublicId, $provider, $correlationId);
        }
        if ($authority->submission_state === 'pending_manual_review') {
            return $this->receipt($authority, true);
        }
        if ($authority->submission_state !== 'captured') {
            return $this->receipt($authority, true);
        }

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
            $this->recordFinding($authority, 'post_capture_provider_lookup_failed', 'high', $provider->code(), null, $correlationId);
            throw new RuntimeException('USDT post-capture reconciliation lookup failed.', 0, $exception);
        }

        $criticalType = $this->capturedMismatch($authority, $evidence);
        if ($criticalType !== null) {
            $this->recordFinding($authority, $criticalType, 'critical', $provider->code(), $evidence, $correlationId);
        }

        return $this->receipt($this->authority($submissionPublicId) ?? $authority, true);
    }

    private function capturedMismatch(object $authority, UsdtBlockchainVerificationEvidence $evidence): ?string
    {
        if (! hash_equals((string) $authority->txid, strtolower($evidence->txid))) {
            return 'post_capture_txid_mismatch';
        }
        if (in_array($evidence->transactionStatus, ['failed','reverted'], true)) {
            return 'post_capture_chain_reversal';
        }
        if ($evidence->outcome !== 'success' || $evidence->transactionStatus !== 'success') {
            return 'local_captured_chain_not_successful';
        }
        if ($evidence->network !== $authority->network
            || $evidence->chainId !== (int) $authority->chain_id
            || $evidence->tokenContract === null
            || ! hash_equals((string) $authority->token_contract, strtolower($evidence->tokenContract))
            || $evidence->destinationAddress === null
            || ! hash_equals((string) $authority->destination_address, strtolower($evidence->destinationAddress))
            || $evidence->amountBaseUnits !== (int) $authority->expected_amount_base_units
            || $evidence->tokenDecimals !== UsdtTokenAmount::DECIMALS) {
            return 'local_captured_chain_identity_mismatch';
        }
        return null;
    }

    private function authority(string $submissionPublicId): ?object
    {
        return $this->database->connection()->table('usdt_txid_submissions as submission')
            ->join('usdt_payment_authorities as authority', 'authority.id', '=', 'submission.usdt_payment_authority_id')
            ->leftJoin('usdt_verified_transfers as transfer', 'transfer.usdt_txid_submission_id', '=', 'submission.id')
            ->where('submission.public_id', $submissionPublicId)
            ->first([
                'submission.id as submission_id', 'submission.public_id as submission_public_id', 'submission.txid', 'submission.state as submission_state',
                'authority.network', 'authority.chain_id', 'authority.token_contract', 'authority.destination_address',
                'authority.expected_amount_base_units', 'authority.minimum_confirmations',
                'transfer.id as transfer_id', 'transfer.public_id as transfer_public_id', 'transfer.purchase_settlement_id',
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

    private function recordFinding(object $authority, string $type, string $severity, string $providerCode, ?UsdtBlockchainVerificationEvidence $evidence, string $correlationId): void
    {
        $key = hash('sha256', implode("\0", [(string) $authority->submission_public_id, $type, $providerCode, $evidence?->providerEventId ?? '', $evidence?->evidenceHash ?? '']));
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
            'created_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        ]);
    }
}
