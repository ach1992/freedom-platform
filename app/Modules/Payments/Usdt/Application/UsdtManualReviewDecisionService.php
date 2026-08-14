<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Payments\Usdt\Application\Contracts\UsdtBlockchainVerificationEvidence;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class UsdtManualReviewDecisionService
{
    private const PERMISSION = 'access.sensitive_actions.approve';

    public function __construct(
        private DatabaseManager $database,
        private AdministratorPermissionAuthorizer $authorizer,
        private UsdtVerifiedTransferService $verifiedTransfers,
        private Clock $clock,
    ) {}

    /** @requirement USDT-003 ACL-002 PAY-002 PAY-003 DAT-003 SEC-002 QUA-004 */
    public function approveVerified(
        string $reviewPublicId,
        int $administratorId,
        string $reason,
        string $providerCode,
        UsdtBlockchainVerificationEvidence $evidence,
        string $correlationId,
    ): UsdtProcessingReceipt {
        $this->assertInputs($reviewPublicId, $administratorId, $reason, $correlationId);
        $this->assertProviderCode($providerCode);
        $this->authorizer->authorize($administratorId, self::PERMISSION);

        $submissionPublicId = $this->database->connection()->transaction(function (Connection $connection) use (
            $reviewPublicId,
            $administratorId,
            $reason,
            $evidence,
        ): string {
            $authority = $connection->table('usdt_manual_reviews as review')
                ->join('usdt_txid_submissions as submission', 'submission.id', '=', 'review.usdt_txid_submission_id')
                ->join('usdt_payment_authorities as authority', 'authority.id', '=', 'submission.usdt_payment_authority_id')
                ->join('payment_intents as intent', 'intent.id', '=', 'submission.payment_intent_id')
                ->where('review.public_id', $reviewPublicId)
                ->lockForUpdate()
                ->first([
                    'review.id as review_id', 'review.state as review_state', 'review.decided_by_administrator_id',
                    'submission.id as submission_id', 'submission.public_id as submission_public_id', 'submission.state as submission_state', 'submission.txid',
                    'authority.network', 'authority.chain_id', 'authority.token_contract', 'authority.destination_address',
                    'authority.expected_amount_base_units', 'authority.minimum_confirmations', 'authority.created_at as authority_created_at',
                    'intent.state as intent_state', 'intent.captured_at',
                ]);
            if ($authority === null) {
                throw new DomainException('USDT manual review does not exist.');
            }
            $this->assertExactManualEvidence($authority, $evidence);

            if ($authority->review_state === 'approved') {
                if ((int) $authority->decided_by_administrator_id !== $administratorId) {
                    throw new RuntimeException('USDT manual review was already approved by a different administrator.');
                }
                return (string) $authority->submission_public_id;
            }
            if ($authority->review_state !== 'pending'
                || $authority->submission_state !== 'pending_manual_review'
                || $authority->intent_state !== PaymentIntentState::PendingManualReview->value
                || $authority->captured_at !== null) {
                throw new RuntimeException('USDT manual review is no longer approvable.');
            }

            $now = $this->timestamp();
            $updated = $connection->table('usdt_manual_reviews')->where('id', $authority->review_id)->where('state', 'pending')->update([
                'state' => 'approved',
                'decided_by_administrator_id' => $administratorId,
                'decision_reason' => trim($reason),
                'decided_at' => $now,
                'updated_at' => $now,
            ]);
            if ($updated !== 1) {
                throw new RuntimeException('USDT manual review approval changed concurrently.');
            }

            return (string) $authority->submission_public_id;
        }, 3);

        return $this->verifiedTransfers->recordAndSettle($submissionPublicId, $providerCode, $evidence, $correlationId);
    }

    public function reject(
        string $reviewPublicId,
        int $administratorId,
        string $reason,
        string $correlationId,
    ): UsdtProcessingReceipt {
        $this->assertInputs($reviewPublicId, $administratorId, $reason, $correlationId);
        $this->authorizer->authorize($administratorId, self::PERMISSION);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $reviewPublicId,
            $administratorId,
            $reason,
            $correlationId,
        ): UsdtProcessingReceipt {
            $authority = $connection->table('usdt_manual_reviews as review')
                ->join('usdt_txid_submissions as submission', 'submission.id', '=', 'review.usdt_txid_submission_id')
                ->join('payment_intents as intent', 'intent.id', '=', 'submission.payment_intent_id')
                ->where('review.public_id', $reviewPublicId)
                ->lockForUpdate()
                ->first([
                    'review.id as review_id', 'review.state as review_state', 'review.public_id as review_public_id',
                    'submission.id as submission_id', 'submission.public_id as submission_public_id', 'submission.state as submission_state',
                    'intent.id as intent_id', 'intent.state as intent_state',
                ]);
            if ($authority === null) {
                throw new DomainException('USDT manual review does not exist.');
            }
            if ($authority->review_state === 'rejected') {
                return new UsdtProcessingReceipt(
                    (string) $authority->submission_public_id,
                    (string) $authority->submission_state,
                    (string) $authority->review_public_id,
                    null,
                    null,
                    true,
                );
            }
            if ($authority->review_state !== 'pending'
                || $authority->submission_state !== 'pending_manual_review'
                || $authority->intent_state !== PaymentIntentState::PendingManualReview->value) {
                throw new RuntimeException('USDT manual review cannot be rejected from its current state.');
            }

            $now = $this->timestamp();
            $connection->table('usdt_manual_reviews')->where('id', $authority->review_id)->where('state', 'pending')->update([
                'state' => 'rejected',
                'decided_by_administrator_id' => $administratorId,
                'decision_reason' => trim($reason),
                'decided_at' => $now,
                'updated_at' => $now,
            ]);
            $updated = $connection->table('usdt_txid_submissions')->where('id', $authority->submission_id)->where('state', 'pending_manual_review')->update([
                'state' => 'rejected',
            ]);
            if ($updated !== 1) {
                throw new RuntimeException('USDT rejected submission state changed concurrently.');
            }
            $this->transitionIntent(
                $connection,
                (int) $authority->intent_id,
                PaymentIntentState::PendingManualReview,
                PaymentIntentState::Failed,
                'usdt_bep20_manual_review_rejected',
                $correlationId,
            );

            return new UsdtProcessingReceipt(
                (string) $authority->submission_public_id,
                'rejected',
                (string) $authority->review_public_id,
                null,
                null,
                false,
            );
        }, 3);
    }

    private function assertExactManualEvidence(object $authority, UsdtBlockchainVerificationEvidence $evidence): void
    {
        if ($evidence->outcome !== 'success'
            || $evidence->transactionStatus !== 'success'
            || ! hash_equals((string) $authority->txid, strtolower($evidence->txid))
            || $evidence->network !== $authority->network
            || $evidence->chainId !== (int) $authority->chain_id
            || $evidence->tokenContract === null
            || ! hash_equals((string) $authority->token_contract, strtolower($evidence->tokenContract))
            || $evidence->destinationAddress === null
            || ! hash_equals((string) $authority->destination_address, strtolower($evidence->destinationAddress))
            || $evidence->amountBaseUnits !== (int) $authority->expected_amount_base_units
            || $evidence->tokenDecimals !== UsdtTokenAmount::DECIMALS
            || $evidence->confirmations === null
            || $evidence->confirmations < (int) $authority->minimum_confirmations
            || $evidence->transactionAt === null
            || $evidence->transactionAt < $this->storedDateTime((string) $authority->authority_created_at)) {
            throw new DomainException('USDT manual approval requires exact authoritative BEP20 transfer evidence.');
        }
        if ($evidence->providerEventId === '' || strlen($evidence->providerEventId) > 191
            || preg_match('/\A[a-fA-F0-9]{64}\z/', $evidence->evidenceHash) !== 1) {
            throw new DomainException('USDT manual approval evidence identity is invalid.');
        }
    }

    private function transitionIntent(Connection $connection, int $intentId, PaymentIntentState $from, PaymentIntentState $to, string $reason, string $correlationId): void
    {
        $from->transitionTo($to);
        $updated = $connection->table('payment_intents')->where('id', $intentId)->where('state', $from->value)->update([
            'state' => $to->value,
            'updated_at' => $this->timestamp(),
        ]);
        if ($updated !== 1) {
            throw new RuntimeException('USDT payment intent review transition changed concurrently.');
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

    private function assertInputs(string $reviewPublicId, int $administratorId, string $reason, string $correlationId): void
    {
        if (! Str::isUlid($reviewPublicId) || $administratorId < 1) {
            throw new DomainException('USDT manual review identity is invalid.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 191 || preg_match('/[\x00-\x1F\x7F]/', $reason) === 1) {
            throw new DomainException('USDT manual review reason is required and bounded.');
        }
        $this->assertCorrelation($correlationId);
    }

    private function assertProviderCode(string $value): void
    {
        if (preg_match('/\A[a-zA-Z0-9:_.-]{2,64}\z/', $value) !== 1) {
            throw new DomainException('USDT manual review provider code is invalid.');
        }
    }

    private function assertCorrelation(string $value): void
    {
        if (preg_match('/\A[a-fA-F0-9]{64}\z/', $value) !== 1) {
            throw new DomainException('USDT manual review correlation ID must be a SHA-256 hex digest.');
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

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
