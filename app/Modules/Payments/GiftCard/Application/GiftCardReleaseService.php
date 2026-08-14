<?php

declare(strict_types=1);

namespace App\Modules\Payments\GiftCard\Application;

use App\Modules\Payments\Domain\PaymentIntentState;
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

final readonly class GiftCardReleaseService
{
    public function __construct(
        private DatabaseManager $database,
        private Encrypter $encrypter,
        private Clock $clock,
    ) {}

    /** @requirement GFT-003 GFT-004 PAY-002 DAT-002 DAT-003 DAT-004 INT-001 INT-002 QUA-004 */
    public function release(
        string $submissionPublicId,
        GiftCardVerificationProvider $provider,
        string $correlationId,
    ): GiftCardProcessingReceipt {
        if (! Str::isUlid($submissionPublicId)) {
            throw new DomainException('Gift-card release submission public ID is invalid.');
        }
        $this->assertToken($provider->code(), 'Gift-card release provider code', 2, 64);
        $this->assertToken($correlationId, 'Gift-card release correlation ID', 8, 64);
        if (! $provider->capabilities()->release) {
            throw new DomainException('Gift-card provider does not support reservation release.');
        }

        $prepared = $this->prepare($submissionPublicId, $provider->code());
        if ($prepared instanceof GiftCardProcessingReceipt) {
            return $prepared;
        }

        try {
            $evidence = $provider->release($prepared);
        } catch (Throwable $exception) {
            $this->recordFinding(
                $submissionPublicId,
                'release_call_uncertain',
                'critical',
                $provider->code(),
                null,
                $correlationId,
            );
            throw new RuntimeException('Gift-card reservation release outcome is uncertain; reconcile before retry.', 0, $exception);
        }

        return $this->finalize($submissionPublicId, $provider->code(), $evidence, $correlationId);
    }

    /** @return GiftCardProviderRequest|GiftCardProcessingReceipt */
    private function prepare(string $submissionPublicId, string $providerCode): GiftCardProviderRequest|GiftCardProcessingReceipt
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($submissionPublicId, $providerCode): GiftCardProviderRequest|GiftCardProcessingReceipt {
            $authority = $this->authority($connection, $submissionPublicId, true);
            if ($authority === null) {
                throw new DomainException('Gift-card release submission does not exist.');
            }
            if ($authority->provider_code !== $providerCode) {
                throw new DomainException('Gift-card release provider does not match immutable type authority.');
            }
            if ($authority->state === 'released') {
                return $this->receipt($connection, $authority, true);
            }
            if (! in_array($authority->state, ['reserved', 'rejected'], true)) {
                throw new RuntimeException('Gift-card submission is not in a releasable state.');
            }
            if ($connection->table('gift_card_redemptions')->where('gift_card_submission_id', $authority->submission_id)->exists()) {
                throw new RuntimeException('Redeemed gift-card submission cannot be released.');
            }
            $reserveCount = $connection->table('gift_card_provider_events')
                ->where('gift_card_submission_id', $authority->submission_id)
                ->where('provider_code', $providerCode)
                ->where('operation', 'reserve')
                ->where('outcome', 'success')
                ->where('provider_status', 'reserved')
                ->count();
            if ($reserveCount !== 1) {
                throw new RuntimeException('Gift-card release requires one authoritative provider reservation.');
            }

            return $this->request($authority);
        }, 3);
    }

    private function finalize(
        string $submissionPublicId,
        string $providerCode,
        GiftCardProviderEvidence $evidence,
        string $correlationId,
    ): GiftCardProcessingReceipt {
        return $this->database->connection()->transaction(function (Connection $connection) use (
            $submissionPublicId,
            $providerCode,
            $evidence,
            $correlationId,
        ): GiftCardProcessingReceipt {
            $authority = $this->authority($connection, $submissionPublicId, true);
            if ($authority === null) {
                throw new DomainException('Gift-card release submission does not exist.');
            }
            if ($authority->state === 'released') {
                return $this->receipt($connection, $authority, true);
            }
            if (! in_array($authority->state, ['reserved', 'rejected'], true)) {
                throw new RuntimeException('Gift-card release state changed concurrently.');
            }
            $this->validateEvidence($authority, $providerCode, $evidence);
            $this->recordProviderEvent($connection, $authority, $providerCode, $evidence);

            if ($evidence->outcome !== 'success'
                || ! in_array($evidence->status, ['released', 'canceled', 'cancelled'], true)) {
                $this->recordFindingInConnection(
                    $connection,
                    $authority,
                    'release_not_confirmed',
                    'critical',
                    $providerCode,
                    $evidence,
                    $correlationId,
                );

                return $this->receipt($connection, $authority, false);
            }

            $updated = $connection->table('gift_card_submissions')
                ->where('id', $authority->submission_id)
                ->where('state', $authority->state)
                ->update(['state' => 'released']);
            if ($updated !== 1) {
                throw new RuntimeException('Gift-card release state changed concurrently.');
            }
            $intentState = PaymentIntentState::from((string) $authority->intent_state);
            if ($intentState === PaymentIntentState::Verifying) {
                $this->transitionIntent(
                    $connection,
                    (int) $authority->payment_intent_id,
                    PaymentIntentState::Verifying,
                    PaymentIntentState::Failed,
                    'gift_card_reservation_released',
                    $correlationId,
                );
            } elseif ($intentState !== PaymentIntentState::Failed) {
                throw new RuntimeException('Gift-card released reservation has inconsistent payment intent state.');
            }

            $fresh = $this->authority($connection, $submissionPublicId);
            if ($fresh === null) {
                throw new RuntimeException('Gift-card submission disappeared after release.');
            }

            return $this->receipt($connection, $fresh, false);
        }, 3);
    }

    private function authority(Connection $connection, string $submissionPublicId, bool $lock = false): ?object
    {
        $query = $connection->table('gift_card_submissions as submission')
            ->join('gift_card_types as type', 'type.id', '=', 'submission.gift_card_type_id')
            ->join('payment_intents as intent', 'intent.id', '=', 'submission.payment_intent_id')
            ->where('submission.public_id', $submissionPublicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first([
            'submission.id as submission_id', 'submission.public_id as submission_public_id',
            'submission.encrypted_code', 'submission.private_image_reference', 'submission.state',
            'submission.claimed_face_value', 'submission.claimed_currency', 'submission.claimed_brand', 'submission.claimed_region',
            'type.type_code', 'type.provider_code',
            'intent.id as payment_intent_id', 'intent.state as intent_state',
        ]);
    }

    private function request(object $authority): GiftCardProviderRequest
    {
        $code = $authority->encrypted_code === null ? null : $this->encrypter->decryptString((string) $authority->encrypted_code);

        return new GiftCardProviderRequest(
            hash('sha256', 'gift-card:'.$authority->submission_public_id.':release'),
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

    private function validateEvidence(object $authority, string $providerCode, GiftCardProviderEvidence $evidence): void
    {
        if ($authority->provider_code !== $providerCode
            || $evidence->operation !== 'release'
            || ! in_array($evidence->outcome, ['success', 'pending', 'rejected', 'uncertain', 'unavailable'], true)) {
            throw new DomainException('Gift-card release evidence is invalid.');
        }
        $this->assertPrintable($evidence->providerEventId, 'Gift-card release provider event ID', 1, 191);
        $this->assertSha256($evidence->evidenceHash, 'Gift-card release evidence hash');
        if ($evidence->providerTransactionId !== null) {
            $this->assertPrintable($evidence->providerTransactionId, 'Gift-card release provider transaction ID', 1, 191);
        }
        if ($evidence->faceValue !== null && $evidence->faceValue !== (int) $authority->claimed_face_value) {
            throw new DomainException('Gift-card release face value conflicts with submitted authority.');
        }
        if ($evidence->currency !== null && $evidence->currency !== $authority->claimed_currency) {
            throw new DomainException('Gift-card release currency conflicts with submitted authority.');
        }
        if ($evidence->brand !== null && $evidence->brand !== $authority->claimed_brand) {
            throw new DomainException('Gift-card release brand conflicts with submitted authority.');
        }
        if ($evidence->region !== null && $evidence->region !== $authority->claimed_region) {
            throw new DomainException('Gift-card release region conflicts with submitted authority.');
        }
    }

    private function recordProviderEvent(Connection $connection, object $authority, string $providerCode, GiftCardProviderEvidence $evidence): void
    {
        $existing = $connection->table('gift_card_provider_events')
            ->where('provider_code', $providerCode)
            ->where('provider_event_id', $evidence->providerEventId)
            ->lockForUpdate()
            ->first();
        if ($existing !== null) {
            if ((int) $existing->gift_card_submission_id !== (int) $authority->submission_id
                || $existing->operation !== 'release'
                || $existing->outcome !== $evidence->outcome
                || $existing->provider_status !== $evidence->status
                || $existing->provider_transaction_id !== $evidence->providerTransactionId
                || ! hash_equals(strtolower((string) $existing->evidence_hash), strtolower($evidence->evidenceHash))) {
                throw new RuntimeException('Gift-card release event replay conflicts with accepted evidence.');
            }

            return;
        }

        $connection->table('gift_card_provider_events')->insert([
            'public_id' => (string) Str::ulid(),
            'gift_card_submission_id' => $authority->submission_id,
            'provider_code' => $providerCode,
            'provider_event_id' => $evidence->providerEventId,
            'operation' => 'release',
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

    private function recordFinding(
        string $submissionPublicId,
        string $findingType,
        string $severity,
        string $providerCode,
        ?GiftCardProviderEvidence $evidence,
        string $correlationId,
    ): void {
        $connection = $this->database->connection();
        $authority = $this->authority($connection, $submissionPublicId);
        if ($authority === null) {
            return;
        }
        $this->recordFindingInConnection(
            $connection,
            $authority,
            $findingType,
            $severity,
            $providerCode,
            $evidence,
            $correlationId,
        );
    }

    private function recordFindingInConnection(
        Connection $connection,
        object $authority,
        string $findingType,
        string $severity,
        string $providerCode,
        ?GiftCardProviderEvidence $evidence,
        string $correlationId,
    ): void {
        $findingKey = hash('sha256', implode("\0", [
            (string) $authority->submission_public_id,
            $findingType,
            $providerCode,
            $evidence?->providerEventId ?? '',
            $evidence?->providerTransactionId ?? '',
            $evidence?->evidenceHash ?? '',
        ]));
        $connection->table('gift_card_reconciliation_findings')->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'gift_card_submission_id' => $authority->submission_id,
            'finding_key' => $findingKey,
            'finding_type' => substr($findingType, 0, 64),
            'severity' => $severity,
            'provider_code' => $providerCode,
            'provider_event_id' => $evidence?->providerEventId,
            'provider_transaction_id' => $evidence?->providerTransactionId,
            'evidence_hash' => $evidence === null ? null : strtolower($evidence->evidenceHash),
            'correlation_id' => $correlationId,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function receipt(Connection $connection, object $authority, bool $replayed): GiftCardProcessingReceipt
    {
        $reviewPublicId = $connection->table('gift_card_reviews')
            ->where('gift_card_submission_id', $authority->submission_id)
            ->value('public_id');

        return new GiftCardProcessingReceipt(
            (string) $authority->submission_public_id,
            (string) $authority->state,
            $reviewPublicId === null ? null : (string) $reviewPublicId,
            null,
            null,
            $replayed,
        );
    }

    private function transitionIntent(
        Connection $connection,
        int $intentId,
        PaymentIntentState $from,
        PaymentIntentState $to,
        string $reason,
        string $correlationId,
    ): void {
        $from->transitionTo($to);
        $updated = $connection->table('payment_intents')
            ->where('id', $intentId)
            ->where('state', $from->value)
            ->update([
                'state' => $to->value,
                'updated_at' => $this->timestamp(),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Gift-card release payment state changed concurrently.');
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

    private function databaseDateTime(\DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
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