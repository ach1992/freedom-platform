<?php

declare(strict_types=1);

namespace App\Modules\Payments\GiftCard\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderEvidence;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class GiftCardReviewDecisionService
{
    private const PERMISSION = 'access.sensitive_actions.approve';

    public function __construct(
        private DatabaseManager $database,
        private AdministratorPermissionAuthorizer $authorizer,
        private GiftCardRedemptionService $redemptions,
        private Clock $clock,
    ) {}

    /** @requirement GFT-003 GFT-004 ACL-002 PAY-002 PAY-003 SEC-002 DAT-003 QUA-004 */
    public function approveRedeemed(
        string $reviewPublicId,
        int $administratorId,
        string $reason,
        string $externalProviderCode,
        GiftCardProviderEvidence $redeemEvidence,
        string $correlationId,
    ): GiftCardProcessingReceipt {
        $this->assertInputs($reviewPublicId, $administratorId, $reason, $correlationId);
        $this->assertToken($externalProviderCode, 'Gift-card review provider code', 2, 64);
        $this->authorizer->authorize($administratorId, self::PERMISSION);

        $submissionPublicId = $this->database->connection()->transaction(function (Connection $connection) use (
            $reviewPublicId,
            $administratorId,
            $reason,
            $externalProviderCode,
            $redeemEvidence,
        ): string {
            $authority = $connection->table('gift_card_reviews as review')
                ->join('gift_card_submissions as submission', 'submission.id', '=', 'review.gift_card_submission_id')
                ->join('gift_card_types as type', 'type.id', '=', 'submission.gift_card_type_id')
                ->join('payment_intents as intent', 'intent.id', '=', 'submission.payment_intent_id')
                ->where('review.public_id', $reviewPublicId)
                ->lockForUpdate()
                ->first([
                    'review.id as review_id', 'review.state as review_state', 'review.decided_by_administrator_id',
                    'submission.id as submission_id', 'submission.public_id as submission_public_id', 'submission.state as submission_state',
                    'submission.claimed_face_value', 'submission.claimed_currency', 'submission.claimed_brand', 'submission.claimed_region',
                    'submission.submitted_at',
                    'type.provider_code', 'type.face_currency', 'type.brand', 'type.region',
                    'intent.id as payment_intent_id', 'intent.amount_irr', 'intent.currency', 'intent.state as intent_state',
                ]);
            if ($authority === null) {
                throw new DomainException('Gift-card review does not exist.');
            }
            $this->assertRedeemedEvidenceMatchesAuthority($authority, $externalProviderCode, $redeemEvidence);

            if ($authority->review_state === 'approved') {
                if ((int) $authority->decided_by_administrator_id !== $administratorId) {
                    throw new RuntimeException('Gift-card review was already approved by a different administrator.');
                }
                return (string) $authority->submission_public_id;
            }
            if ($authority->review_state !== 'pending' || $authority->submission_state !== 'pending_manual_review') {
                throw new RuntimeException('Gift-card review was already decided or submission state changed.');
            }
            if ($authority->intent_state !== PaymentIntentState::PendingManualReview->value) {
                throw new RuntimeException('Gift-card payment intent is not pending manual review.');
            }

            $now = $this->timestamp();
            $updated = $connection->table('gift_card_reviews')
                ->where('id', $authority->review_id)
                ->where('state', 'pending')
                ->update([
                    'state' => 'approved',
                    'decided_by_administrator_id' => $administratorId,
                    'decision_reason' => trim($reason),
                    'decided_at' => $now,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Gift-card review decision changed concurrently.');
            }
            $submissionUpdated = $connection->table('gift_card_submissions')
                ->where('id', $authority->submission_id)
                ->where('state', 'pending_manual_review')
                ->update(['state' => 'redeeming']);
            if ($submissionUpdated !== 1) {
                throw new RuntimeException('Gift-card submission review state changed concurrently.');
            }

            return (string) $authority->submission_public_id;
        }, 3);

        return $this->redemptions->recordAndSettle($submissionPublicId, $externalProviderCode, $redeemEvidence, $correlationId);
    }

    public function reject(
        string $reviewPublicId,
        int $administratorId,
        string $reason,
        string $correlationId,
    ): GiftCardProcessingReceipt {
        $this->assertInputs($reviewPublicId, $administratorId, $reason, $correlationId);
        $this->authorizer->authorize($administratorId, self::PERMISSION);

        return $this->database->connection()->transaction(function (Connection $connection) use ($reviewPublicId, $administratorId, $reason, $correlationId): GiftCardProcessingReceipt {
            $authority = $connection->table('gift_card_reviews as review')
                ->join('gift_card_submissions as submission', 'submission.id', '=', 'review.gift_card_submission_id')
                ->join('payment_intents as intent', 'intent.id', '=', 'submission.payment_intent_id')
                ->where('review.public_id', $reviewPublicId)
                ->lockForUpdate()
                ->first([
                    'review.id as review_id', 'review.state as review_state', 'review.public_id as review_public_id',
                    'submission.id as submission_id', 'submission.public_id as submission_public_id', 'submission.state as submission_state',
                    'intent.id as payment_intent_id', 'intent.state as intent_state',
                ]);
            if ($authority === null) {
                throw new DomainException('Gift-card review does not exist.');
            }
            if ($authority->review_state === 'rejected') {
                return new GiftCardProcessingReceipt(
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
                throw new RuntimeException('Gift-card review cannot be rejected from its current state.');
            }

            $now = $this->timestamp();
            $connection->table('gift_card_reviews')->where('id', $authority->review_id)->where('state', 'pending')->update([
                'state' => 'rejected',
                'decided_by_administrator_id' => $administratorId,
                'decision_reason' => trim($reason),
                'decided_at' => $now,
                'updated_at' => $now,
            ]);
            $connection->table('gift_card_submissions')->where('id', $authority->submission_id)->where('state', 'pending_manual_review')->update([
                'state' => 'rejected',
            ]);
            $this->transitionIntent(
                $connection,
                (int) $authority->payment_intent_id,
                PaymentIntentState::PendingManualReview,
                PaymentIntentState::Failed,
                'gift_card_manual_review_rejected',
                $correlationId,
            );

            return new GiftCardProcessingReceipt(
                (string) $authority->submission_public_id,
                'rejected',
                (string) $authority->review_public_id,
                null,
                null,
                false,
            );
        }, 3);
    }

    private function assertRedeemedEvidenceMatchesAuthority(object $authority, string $providerCode, GiftCardProviderEvidence $evidence): void
    {
        if ($evidence->operation !== 'redeem'
            || $evidence->outcome !== 'success'
            || $evidence->status !== 'redeemed'
            || $evidence->providerTransactionId === null
            || $evidence->faceValue === null
            || $evidence->faceValue < 1
            || $evidence->currency !== 'IRR'
            || $evidence->brand === null
            || $authority->provider_code !== $providerCode
            || (int) $authority->claimed_face_value !== $evidence->faceValue
            || (int) $authority->amount_irr !== $evidence->faceValue
            || $authority->claimed_currency !== $evidence->currency
            || $authority->currency !== $evidence->currency
            || $authority->face_currency !== $evidence->currency
            || $authority->claimed_brand !== $evidence->brand
            || $authority->brand !== $evidence->brand
            || (($authority->claimed_region === null) !== ($evidence->region === null))
            || ($evidence->region !== null && ! hash_equals((string) $authority->claimed_region, $evidence->region))
            || (($authority->region === null) !== ($evidence->region === null))
            || ($evidence->region !== null && ! hash_equals((string) $authority->region, $evidence->region))
            || $evidence->occurredAt->setTimezone(new DateTimeZone('UTC')) < $this->storedDateTime((string) $authority->submitted_at)) {
            throw new DomainException('Gift-card manual approval requires exact authoritative post-submission redeemed evidence.');
        }
        $this->assertPrintable($evidence->providerEventId, 'Gift-card manual approval provider event ID', 1, 191);
        $this->assertPrintable($evidence->providerTransactionId, 'Gift-card manual approval provider redemption ID', 1, 191);
        if (preg_match('/\A[a-fA-F0-9]{64}\z/', $evidence->evidenceHash) !== 1) {
            throw new DomainException('Gift-card manual approval evidence hash is invalid.');
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
            throw new RuntimeException('Gift-card payment intent review transition changed concurrently.');
        }
        $connection->table('payment_intent_state_histories')->insert([
            'payment_intent_id' => $intentId,
            'from_state' => $from->value,
            'to_state' => $to->value,
            'reason_code' => $reason,
            'correlation_id' => $correlationId,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function assertInputs(string $reviewPublicId, int $administratorId, string $reason, string $correlationId): void
    {
        if (! Str::isUlid($reviewPublicId) || $administratorId < 1) {
            throw new DomainException('Gift-card review identity is invalid.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 191 || preg_match('/[\x00-\x1F\x7F]/', $reason) === 1) {
            throw new DomainException('Gift-card review reason is required and bounded.');
        }
        $this->assertToken($correlationId, 'Gift-card review correlation ID', 8, 64);
    }

    private function assertPrintable(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum || preg_match('/\A[\x21-\x7E]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function storedDateTime(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new RuntimeException('Stored gift-card review timestamp is invalid.');
        }

        return $date;
    }

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum || preg_match('/\A[A-Za-z0-9:_.-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}