<?php

declare(strict_types=1);

namespace App\Modules\Payments\GiftCard\Application;

use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderEvidence;
use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderRequest;
use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardVerificationProvider;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class GiftCardReconciliationService
{
    public function __construct(
        private DatabaseManager $database,
        private Encrypter $encrypter,
        private GiftCardRedemptionService $redemptions,
        private Clock $clock,
    ) {}

    /** @requirement GFT-003 GFT-004 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 INT-001 INT-002 QUA-004 */
    public function reconcile(
        string $submissionPublicId,
        GiftCardVerificationProvider $provider,
        string $correlationId,
    ): GiftCardProcessingReceipt {
        if (! Str::isUlid($submissionPublicId)) {
            throw new DomainException('Gift-card reconciliation submission public ID is invalid.');
        }
        $this->assertToken($provider->code(), 'Gift-card reconciliation provider code', 2, 64);
        $this->assertToken($correlationId, 'Gift-card reconciliation correlation ID', 8, 64);

        $authority = $this->authority($submissionPublicId);
        if ($authority === null) {
            throw new DomainException('Gift-card reconciliation submission does not exist.');
        }
        if ($authority->provider_code !== $provider->code()) {
            throw new DomainException('Gift-card reconciliation provider does not match type authority.');
        }

        if ($authority->redemption_id !== null && $authority->purchase_settlement_id === null) {
            return $this->redemptions->settlePersistedRedemption($submissionPublicId, $correlationId);
        }

        if (! $provider->capabilities()->status) {
            $this->recordFinding($authority, 'status_lookup_unsupported', $authority->state === 'captured' ? 'high' : 'warning', null, $correlationId);
            return $this->receipt($authority, true);
        }

        $request = $this->statusRequest($authority);
        try {
            $evidence = $provider->status($request);
        } catch (Throwable $exception) {
            $this->recordFinding($authority, 'status_lookup_failed', $authority->state === 'captured' ? 'high' : 'warning', null, $correlationId);
            throw new RuntimeException('Gift-card provider status reconciliation failed.', 0, $exception);
        }
        $this->validateStatusEvidence($evidence);
        $this->recordStatusEvent($authority, $evidence);

        if ($authority->state === 'captured') {
            if (in_array($evidence->status, ['reversed', 'canceled', 'cancelled'], true)) {
                $this->recordFinding($authority, 'post_capture_provider_reversal', 'critical', $evidence, $correlationId);
            } elseif (in_array($evidence->outcome, ['unavailable', 'uncertain'], true)
                || in_array($evidence->status, ['missing', 'not_found', 'unknown'], true)) {
                $this->recordFinding($authority, 'local_captured_provider_missing', 'high', $evidence, $correlationId);
            } elseif ($evidence->status !== 'redeemed') {
                $this->recordFinding($authority, 'local_captured_provider_state_mismatch', 'critical', $evidence, $correlationId);
            }

            return $this->receipt($this->authority($submissionPublicId) ?? $authority, true);
        }

        if ($evidence->outcome === 'success' && $evidence->status === 'redeemed') {
            if ($authority->state !== 'redeeming') {
                $this->recordFinding($authority, 'provider_captured_unexpected_local_state', 'critical', $evidence, $correlationId);
                return $this->receipt($this->authority($submissionPublicId) ?? $authority, false);
            }
            $redeemEvidence = new GiftCardProviderEvidence(
                'redeem',
                'success',
                'redeemed',
                $evidence->providerEventId,
                $evidence->providerTransactionId,
                $evidence->faceValue,
                $evidence->currency,
                $evidence->brand,
                $evidence->region,
                $evidence->occurredAt,
                $evidence->evidenceHash,
                $evidence->safeEvidence,
            );

            return $this->redemptions->recordAndSettle(
                $submissionPublicId,
                $provider->code(),
                $redeemEvidence,
                $correlationId,
            );
        }

        if ($authority->state === 'reserving' && $evidence->outcome === 'success' && $evidence->status === 'reserved') {
            if (! $this->matchesClaim($authority, $evidence)) {
                $this->recordFinding($authority, 'reserve_status_identity_mismatch', 'high', $evidence, $correlationId);
            } else {
                $this->database->connection()->table('gift_card_submissions')
                    ->where('id', $authority->submission_id)
                    ->where('state', 'reserving')
                    ->update(['state' => 'reserved']);
            }
            return $this->receipt($this->authority($submissionPublicId) ?? $authority, false);
        }

        if ($authority->state === 'validating' && $evidence->outcome === 'success' && $evidence->status === 'valid') {
            if (! $this->matchesClaim($authority, $evidence)) {
                $this->recordFinding($authority, 'validation_status_identity_mismatch', 'high', $evidence, $correlationId);
            } else {
                $this->database->connection()->table('gift_card_submissions')
                    ->where('id', $authority->submission_id)
                    ->where('state', 'validating')
                    ->update(['state' => 'valid_unreserved']);
            }
            return $this->receipt($this->authority($submissionPublicId) ?? $authority, false);
        }

        if (in_array($evidence->status, ['reversed', 'canceled', 'cancelled'], true)) {
            $this->recordFinding($authority, 'provider_reversal_before_capture', 'high', $evidence, $correlationId);
        } elseif (in_array($evidence->outcome, ['unavailable', 'uncertain'], true)) {
            $this->recordFinding($authority, 'provider_status_uncertain', 'warning', $evidence, $correlationId);
        } else {
            $this->recordFinding($authority, 'provider_local_state_mismatch', 'high', $evidence, $correlationId);
        }

        return $this->receipt($this->authority($submissionPublicId) ?? $authority, false);
    }

    private function authority(string $submissionPublicId): ?object
    {
        return $this->database->connection()->table('gift_card_submissions as submission')
            ->join('gift_card_types as type', 'type.id', '=', 'submission.gift_card_type_id')
            ->join('payment_intents as intent', 'intent.id', '=', 'submission.payment_intent_id')
            ->leftJoin('gift_card_redemptions as redemption', 'redemption.gift_card_submission_id', '=', 'submission.id')
            ->where('submission.public_id', $submissionPublicId)
            ->first([
                'submission.id as submission_id', 'submission.public_id as submission_public_id',
                'submission.encrypted_code', 'submission.private_image_reference', 'submission.state',
                'submission.claimed_face_value', 'submission.claimed_currency', 'submission.claimed_brand', 'submission.claimed_region',
                'type.type_code', 'type.provider_code',
                'intent.public_id as intent_public_id', 'intent.state as intent_state',
                'redemption.id as redemption_id', 'redemption.public_id as redemption_public_id',
                'redemption.provider_redemption_id', 'redemption.purchase_settlement_id',
            ]);
    }

    private function statusRequest(object $authority): GiftCardProviderRequest
    {
        $code = $authority->encrypted_code === null ? null : $this->encrypter->decryptString((string) $authority->encrypted_code);

        return new GiftCardProviderRequest(
            hash('sha256', 'gift-card:'.$authority->submission_public_id.':status'),
            (string) $authority->submission_public_id,
            (string) $authority->type_code,
            (string) $authority->claimed_brand,
            $authority->claimed_region === null ? null : (string) $authority->claimed_region,
            (string) $authority->claimed_currency,
            (int) $authority->claimed_face_value,
            $code,
            $authority->private_image_reference === null ? null : (string) $authority->private_image_reference,
        );
    }

    private function validateStatusEvidence(GiftCardProviderEvidence $evidence): void
    {
        if ($evidence->operation !== 'status'
            || ! in_array($evidence->outcome, ['success', 'pending', 'rejected', 'uncertain', 'unavailable'], true)) {
            throw new DomainException('Gift-card provider status evidence is invalid.');
        }
        $this->assertPrintable($evidence->providerEventId, 'Gift-card status event ID', 1, 191);
        $this->assertSha256($evidence->evidenceHash, 'Gift-card status evidence hash');
        if ($evidence->providerTransactionId !== null) {
            $this->assertPrintable($evidence->providerTransactionId, 'Gift-card status transaction ID', 1, 191);
        }
    }

    private function recordStatusEvent(object $authority, GiftCardProviderEvidence $evidence): void
    {
        $connection = $this->database->connection();
        $existing = $connection->table('gift_card_provider_events')
            ->where('provider_code', $authority->provider_code)
            ->where('provider_event_id', $evidence->providerEventId)
            ->first();
        if ($existing !== null) {
            if ((int) $existing->gift_card_submission_id !== (int) $authority->submission_id
                || $existing->operation !== 'status'
                || $existing->outcome !== $evidence->outcome
                || $existing->provider_status !== $evidence->status
                || $existing->provider_transaction_id !== $evidence->providerTransactionId
                || ! hash_equals(strtolower((string) $existing->evidence_hash), strtolower($evidence->evidenceHash))) {
                throw new RuntimeException('Gift-card status event replay conflicts with accepted evidence.');
            }
            return;
        }

        $connection->table('gift_card_provider_events')->insert([
            'public_id' => (string) Str::ulid(),
            'gift_card_submission_id' => $authority->submission_id,
            'provider_code' => $authority->provider_code,
            'provider_event_id' => $evidence->providerEventId,
            'operation' => 'status',
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
    }

    private function matchesClaim(object $authority, GiftCardProviderEvidence $evidence): bool
    {
        return $evidence->faceValue !== null
            && $evidence->currency !== null
            && $evidence->brand !== null
            && (int) $authority->claimed_face_value === $evidence->faceValue
            && $authority->claimed_currency === $evidence->currency
            && $authority->claimed_brand === $evidence->brand
            && (($authority->claimed_region === null) === ($evidence->region === null))
            && ($evidence->region === null || hash_equals((string) $authority->claimed_region, $evidence->region));
    }

    private function recordFinding(
        object $authority,
        string $type,
        string $severity,
        ?GiftCardProviderEvidence $evidence,
        string $correlationId,
    ): void {
        $providerEventId = $evidence?->providerEventId;
        $providerTransactionId = $evidence?->providerTransactionId;
        $evidenceHash = $evidence?->evidenceHash;
        $key = hash('sha256', implode("\0", [
            (string) $authority->submission_public_id,
            $type,
            (string) $authority->provider_code,
            $providerEventId ?? '',
            $providerTransactionId ?? '',
            $evidenceHash ?? '',
        ]));
        $this->database->connection()->table('gift_card_reconciliation_findings')->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'gift_card_submission_id' => $authority->submission_id,
            'finding_key' => $key,
            'finding_type' => substr($type, 0, 64),
            'severity' => $severity,
            'provider_code' => $authority->provider_code,
            'provider_event_id' => $providerEventId,
            'provider_transaction_id' => $providerTransactionId,
            'evidence_hash' => $evidenceHash === null ? null : strtolower($evidenceHash),
            'correlation_id' => $correlationId,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function receipt(object $authority, bool $replayed): GiftCardProcessingReceipt
    {
        $settlementPublicId = null;
        if ($authority->purchase_settlement_id !== null) {
            $settlementPublicId = $this->database->connection()->table('purchase_settlements')
                ->where('id', $authority->purchase_settlement_id)
                ->value('public_id');
        }
        $reviewPublicId = $this->database->connection()->table('gift_card_reviews')
            ->where('gift_card_submission_id', $authority->submission_id)
            ->value('public_id');

        return new GiftCardProcessingReceipt(
            (string) $authority->submission_public_id,
            (string) $authority->state,
            $reviewPublicId === null ? null : (string) $reviewPublicId,
            $authority->redemption_public_id === null ? null : (string) $authority->redemption_public_id,
            $settlementPublicId === null ? null : (string) $settlementPublicId,
            $replayed,
        );
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

    private function databaseDateTime(\DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function timestamp(): string
    {
        return $this->databaseDateTime($this->clock->now());
    }
}