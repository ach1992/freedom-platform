<?php

declare(strict_types=1);

namespace App\Modules\Payments\GiftCard\Application;

use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\PurchaseSettlementService;
use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderEvidence;
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

final readonly class GiftCardRedemptionService
{
    public function __construct(
        private DatabaseManager $database,
        private PurchaseSettlementService $purchaseSettlements,
        private Clock $clock,
    ) {}

    /** @requirement GFT-002 GFT-003 GFT-004 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function recordAndSettle(
        string $submissionPublicId,
        string $externalProviderCode,
        GiftCardProviderEvidence $evidence,
        string $correlationId,
    ): GiftCardProcessingReceipt {
        if (! Str::isUlid($submissionPublicId)) {
            throw new DomainException('Gift-card submission public ID is invalid.');
        }
        $this->assertToken($externalProviderCode, 'Gift-card provider code', 2, 64);
        $this->assertToken($correlationId, 'Gift-card redemption correlation ID', 8, 64);

        try {
            $this->validateRedeemEvidence($evidence);
        } catch (Throwable $exception) {
            if ($evidence->outcome === 'success' && $evidence->status === 'redeemed') {
                $this->recordFindingSafely(
                    $submissionPublicId,
                    'provider_redeemed_evidence_invalid',
                    'critical',
                    $externalProviderCode,
                    $evidence->providerEventId,
                    $evidence->providerTransactionId,
                    $evidence->evidenceHash,
                    $correlationId,
                );
            }
            throw $exception;
        }

        try {
            $this->persistRedemption($submissionPublicId, $externalProviderCode, $evidence);
        } catch (Throwable $exception) {
            $this->recordFindingSafely(
                $submissionPublicId,
                'provider_captured_redemption_persist_failed',
                'critical',
                $externalProviderCode,
                $evidence->providerEventId,
                $evidence->providerTransactionId,
                $evidence->evidenceHash,
                $correlationId,
            );
            throw $exception;
        }

        try {
            return $this->settlePersistedRedemption($submissionPublicId, $correlationId);
        } catch (Throwable $exception) {
            $this->recordFindingSafely(
                $submissionPublicId,
                'provider_captured_local_not_captured',
                'critical',
                $externalProviderCode,
                $evidence->providerEventId,
                $evidence->providerTransactionId,
                $evidence->evidenceHash,
                $correlationId,
            );
            throw $exception;
        }
    }

    public function settlePersistedRedemption(string $submissionPublicId, string $correlationId): GiftCardProcessingReceipt
    {
        if (! Str::isUlid($submissionPublicId)) {
            throw new DomainException('Gift-card submission public ID is invalid.');
        }
        $this->assertToken($correlationId, 'Gift-card settlement correlation ID', 8, 64);

        $connection = $this->database->connection();
        $authority = $connection->table('gift_card_redemptions as redemption')
            ->join('gift_card_submissions as submission', 'submission.id', '=', 'redemption.gift_card_submission_id')
            ->join('gift_card_provider_events as provider_event', 'provider_event.id', '=', 'redemption.provider_event_row_id')
            ->join('payment_intents as intent', 'intent.id', '=', 'redemption.payment_intent_id')
            ->where('submission.public_id', $submissionPublicId)
            ->first([
                'redemption.id as redemption_id', 'redemption.public_id as redemption_public_id',
                'redemption.provider_code as external_provider_code', 'redemption.provider_redemption_id',
                'redemption.amount_irr', 'redemption.currency', 'redemption.evidence_hash',
                'redemption.purchase_settlement_id', 'redemption.redeemed_at',
                'submission.id as submission_id', 'submission.state as submission_state',
                'provider_event.provider_event_id as external_provider_event_id',
                'intent.id as intent_id', 'intent.public_id as intent_public_id', 'intent.state as intent_state',
            ]);
        if ($authority === null) {
            throw new DomainException('Gift-card authoritative redemption does not exist.');
        }

        if ($authority->purchase_settlement_id !== null) {
            $settlement = $connection->table('purchase_settlements')
                ->where('id', $authority->purchase_settlement_id)
                ->first(['public_id']);
            if ($settlement === null || $authority->submission_state !== 'captured' || $authority->intent_state !== 'captured') {
                throw new RuntimeException('Gift-card redemption settlement linkage is inconsistent.');
            }

            return new GiftCardProcessingReceipt(
                $submissionPublicId,
                'captured',
                null,
                (string) $authority->redemption_public_id,
                (string) $settlement->public_id,
                true,
            );
        }

        if ($authority->submission_state !== 'redeeming') {
            throw new RuntimeException('Gift-card redemption is not in a settleable local state.');
        }

        $redeemedAt = $this->storedDateTime((string) $authority->redeemed_at);
        $commonProviderEventId = hash('sha256', (string) $authority->external_provider_code."\0".(string) $authority->external_provider_event_id);
        $commonProviderTransactionId = hash('sha256', (string) $authority->external_provider_code."\0".(string) $authority->provider_redemption_id);
        $verifiedEvent = new VerifiedPaymentEvent(
            $commonProviderEventId,
            strtolower((string) $authority->evidence_hash),
            new PaymentEvidence(
                ProviderOperationOutcome::Success,
                PaymentEvidenceAuthority::Authoritative,
                PaymentTransactionStatus::Settled,
                $commonProviderTransactionId,
                $commonProviderEventId,
                Money::irr((int) $authority->amount_irr),
                $redeemedAt,
                $redeemedAt,
                strtolower((string) $authority->evidence_hash),
                [
                    'gift_card_provider' => (string) $authority->external_provider_code,
                    'gift_card_submission' => $submissionPublicId,
                ],
            ),
        );

        $settlement = $this->purchaseSettlements->capture(
            (string) $authority->intent_public_id,
            'gift_card',
            $verifiedEvent,
            $correlationId,
        );

        $this->database->connection()->transaction(function (Connection $connection) use ($authority, $settlement): void {
            $redemption = $connection->table('gift_card_redemptions')->where('id', $authority->redemption_id)->lockForUpdate()->first();
            $submission = $connection->table('gift_card_submissions')->where('id', $authority->submission_id)->lockForUpdate()->first();
            if ($redemption === null || $submission === null) {
                throw new RuntimeException('Gift-card redemption linkage disappeared during settlement.');
            }
            if ($redemption->purchase_settlement_id !== null) {
                if ((int) $redemption->purchase_settlement_id !== $settlement->settlementId) {
                    throw new RuntimeException('Gift-card redemption is linked to another settlement.');
                }

                return;
            }
            $connection->table('gift_card_redemptions')->where('id', $redemption->id)->update([
                'purchase_settlement_id' => $settlement->settlementId,
            ]);
            $updated = $connection->table('gift_card_submissions')
                ->where('id', $submission->id)
                ->where('state', 'redeeming')
                ->update(['state' => 'captured']);
            if ($updated !== 1) {
                throw new RuntimeException('Gift-card submission capture state changed concurrently.');
            }
        }, 3);

        return new GiftCardProcessingReceipt(
            $submissionPublicId,
            'captured',
            null,
            (string) $authority->redemption_public_id,
            $settlement->settlementPublicId,
            $settlement->replayed,
        );
    }

    private function persistRedemption(string $submissionPublicId, string $externalProviderCode, GiftCardProviderEvidence $evidence): void
    {
        try {
            $this->database->connection()->transaction(function (Connection $connection) use ($submissionPublicId, $externalProviderCode, $evidence): void {
                $submission = $connection->table('gift_card_submissions as submission')
                    ->join('gift_card_types as type', 'type.id', '=', 'submission.gift_card_type_id')
                    ->join('payment_intents as intent', 'intent.id', '=', 'submission.payment_intent_id')
                    ->where('submission.public_id', $submissionPublicId)
                    ->lockForUpdate()
                    ->first([
                        'submission.id', 'submission.payment_intent_id', 'submission.claimed_face_value',
                        'submission.claimed_currency', 'submission.claimed_brand', 'submission.claimed_region',
                        'submission.state', 'submission.submitted_at',
                        'type.provider_code', 'type.face_currency', 'type.brand', 'type.region',
                        'intent.amount_irr', 'intent.currency', 'intent.provider_code as intent_provider_code',
                    ]);
                if ($submission === null) {
                    throw new DomainException('Gift-card submission does not exist.');
                }
                if ($submission->provider_code !== $externalProviderCode || $submission->intent_provider_code !== 'gift_card') {
                    throw new RuntimeException('Gift-card redemption provider authority is inconsistent.');
                }

                $existingRedemption = $connection->table('gift_card_redemptions')
                    ->where('gift_card_submission_id', $submission->id)
                    ->lockForUpdate()
                    ->first();
                if ($existingRedemption !== null) {
                    if ($existingRedemption->provider_code !== $externalProviderCode
                        || ! hash_equals((string) $existingRedemption->provider_redemption_id, (string) $evidence->providerTransactionId)
                        || ! hash_equals(strtolower((string) $existingRedemption->evidence_hash), strtolower($evidence->evidenceHash))
                        || (int) $existingRedemption->amount_irr !== (int) $evidence->faceValue
                        || $existingRedemption->currency !== $evidence->currency) {
                        throw new RuntimeException('Gift-card submission already has conflicting redemption authority.');
                    }

                    return;
                }

                if ($submission->state !== 'redeeming') {
                    throw new RuntimeException('Gift-card redemption state authority is inconsistent.');
                }
                $this->assertEvidenceMatchesSubmission($submission, $evidence);

                $providerEvent = $this->recordProviderEvent($connection, (int) $submission->id, $externalProviderCode, $evidence);
                $connection->table('gift_card_redemptions')->insert([
                    'public_id' => (string) Str::ulid(),
                    'gift_card_submission_id' => (int) $submission->id,
                    'provider_event_row_id' => (int) $providerEvent->id,
                    'payment_intent_id' => (int) $submission->payment_intent_id,
                    'provider_code' => $externalProviderCode,
                    'provider_redemption_id' => (string) $evidence->providerTransactionId,
                    'amount_irr' => (int) $evidence->faceValue,
                    'currency' => 'IRR',
                    'evidence_hash' => strtolower($evidence->evidenceHash),
                    'purchase_settlement_id' => null,
                    'redeemed_at' => $this->databaseDateTime($evidence->occurredAt),
                    'created_at' => $this->timestamp(),
                ]);
            }, 3);
        } catch (QueryException $exception) {
            $connection = $this->database->connection();
            $transactionConflict = $connection->table('gift_card_redemptions')
                ->where('provider_code', $externalProviderCode)
                ->where('provider_redemption_id', $evidence->providerTransactionId)
                ->first(['gift_card_submission_id']);
            if ($transactionConflict !== null) {
                throw new RuntimeException('Gift-card provider redemption is already bound to another purchase.', 0, $exception);
            }
            $evidenceConflict = $connection->table('gift_card_redemptions')
                ->where('provider_code', $externalProviderCode)
                ->where('evidence_hash', strtolower($evidence->evidenceHash))
                ->first(['gift_card_submission_id']);
            if ($evidenceConflict !== null) {
                throw new RuntimeException('Gift-card provider redemption evidence is already bound to another purchase.', 0, $exception);
            }

            throw $exception;
        }
    }

    private function recordProviderEvent(Connection $connection, int $submissionId, string $providerCode, GiftCardProviderEvidence $evidence): object
    {
        $existing = $connection->table('gift_card_provider_events')
            ->where('provider_code', $providerCode)
            ->where('provider_event_id', $evidence->providerEventId)
            ->lockForUpdate()
            ->first();
        if ($existing !== null) {
            if ((int) $existing->gift_card_submission_id !== $submissionId
                || $existing->operation !== $evidence->operation
                || $existing->outcome !== $evidence->outcome
                || $existing->provider_status !== $evidence->status
                || $existing->provider_transaction_id !== $evidence->providerTransactionId
                || (int) $existing->face_value !== (int) $evidence->faceValue
                || $existing->currency !== $evidence->currency
                || $existing->brand !== $evidence->brand
                || $existing->region !== $evidence->region
                || ! hash_equals(strtolower((string) $existing->evidence_hash), strtolower($evidence->evidenceHash))) {
                throw new RuntimeException('Gift-card provider event replay conflicts with accepted evidence.');
            }

            return $existing;
        }

        $id = (int) $connection->table('gift_card_provider_events')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'gift_card_submission_id' => $submissionId,
            'provider_code' => $providerCode,
            'provider_event_id' => $evidence->providerEventId,
            'operation' => $evidence->operation,
            'outcome' => $evidence->outcome,
            'provider_status' => $evidence->status,
            'provider_transaction_id' => $evidence->providerTransactionId,
            'face_value' => $evidence->faceValue,
            'currency' => $evidence->currency,
            'brand' => $evidence->brand,
            'region' => $evidence->region,
            'evidence_hash' => strtolower($evidence->evidenceHash),
            'occurred_at' => $this->databaseDateTime($evidence->occurredAt),
            'created_at' => $this->timestamp(),
        ]);
        $row = $connection->table('gift_card_provider_events')->where('id', $id)->first();
        if ($row === null) {
            throw new RuntimeException('Gift-card provider event persistence failed.');
        }

        return $row;
    }

    private function validateRedeemEvidence(GiftCardProviderEvidence $evidence): void
    {
        if ($evidence->operation !== 'redeem'
            || $evidence->outcome !== 'success'
            || $evidence->status !== 'redeemed'
            || $evidence->providerTransactionId === null
            || $evidence->faceValue === null
            || $evidence->currency === null
            || $evidence->brand === null) {
            throw new DomainException('Gift-card settlement requires authoritative redeemed provider evidence.');
        }
        $this->assertPrintable($evidence->providerEventId, 'Gift-card provider event ID', 1, 191);
        $this->assertPrintable($evidence->providerTransactionId, 'Gift-card provider redemption ID', 1, 191);
        $this->assertSha256($evidence->evidenceHash, 'Gift-card provider evidence hash');
        if ($evidence->faceValue < 1 || $evidence->currency !== 'IRR') {
            throw new DomainException('Gift-card redeemed value must be positive IRR for purchase settlement.');
        }
    }

    private function assertEvidenceMatchesSubmission(object $submission, GiftCardProviderEvidence $evidence): void
    {
        if ((int) $submission->claimed_face_value !== (int) $evidence->faceValue
            || (int) $submission->amount_irr !== (int) $evidence->faceValue
            || $submission->claimed_currency !== $evidence->currency
            || $submission->currency !== $evidence->currency
            || $submission->face_currency !== $evidence->currency
            || $submission->claimed_brand !== $evidence->brand
            || $submission->brand !== $evidence->brand
            || (($submission->claimed_region === null) !== ($evidence->region === null))
            || ($evidence->region !== null && ! hash_equals((string) $submission->claimed_region, $evidence->region))
            || (($submission->region === null) !== ($evidence->region === null))
            || ($evidence->region !== null && ! hash_equals((string) $submission->region, $evidence->region))
            || $evidence->occurredAt->setTimezone(new DateTimeZone('UTC')) < $this->storedDateTime((string) $submission->submitted_at)) {
            throw new DomainException('Gift-card redeemed evidence does not exactly match the accepted post-submission purchase/type claim.');
        }
    }

    private function recordFindingSafely(
        string $submissionPublicId,
        string $findingType,
        string $severity,
        ?string $providerCode,
        ?string $providerEventId,
        ?string $providerTransactionId,
        ?string $evidenceHash,
        string $correlationId,
    ): void {
        try {
            $this->recordFinding(
                $submissionPublicId,
                $findingType,
                $severity,
                $providerCode,
                $providerEventId,
                $providerTransactionId,
                $evidenceHash,
                $correlationId,
            );
        } catch (Throwable) {
            // Preserve the original provider/local failure. Outer incident handling can still report it.
        }
    }

    private function recordFinding(
        string $submissionPublicId,
        string $findingType,
        string $severity,
        ?string $providerCode,
        ?string $providerEventId,
        ?string $providerTransactionId,
        ?string $evidenceHash,
        string $correlationId,
    ): void {
        $connection = $this->database->connection();
        $submissionId = $connection->table('gift_card_submissions')->where('public_id', $submissionPublicId)->value('id');
        if ($submissionId === null) {
            return;
        }
        $findingKey = hash('sha256', implode("\0", [
            $submissionPublicId,
            $findingType,
            $providerCode ?? '',
            $providerEventId ?? '',
            $providerTransactionId ?? '',
            $evidenceHash ?? '',
        ]));
        $connection->table('gift_card_reconciliation_findings')->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'gift_card_submission_id' => (int) $submissionId,
            'finding_key' => $findingKey,
            'finding_type' => $findingType,
            'severity' => $severity,
            'provider_code' => $providerCode,
            'provider_event_id' => $providerEventId,
            'provider_transaction_id' => $providerTransactionId,
            'evidence_hash' => $evidenceHash === null ? null : strtolower($evidenceHash),
            'correlation_id' => $correlationId,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function storedDateTime(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new RuntimeException('Stored gift-card timestamp is invalid.');
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

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum || preg_match('/\A[A-Za-z0-9:_.-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertPrintable(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum || preg_match('/\A[\x21-\x7E]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertSha256(string $value, string $label): void
    {
        if (preg_match('/\A[a-fA-F0-9]{64}\z/', $value) !== 1) {
            throw new DomainException($label.' must be a SHA-256 hex digest.');
        }
    }
}
