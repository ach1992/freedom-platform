<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application;

use App\Modules\Payments\Domain\PaymentIntentState;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

final readonly class CardToCardManualSubmissionService
{
    public function __construct(
        private DatabaseManager $database,
        private Encrypter $encrypter,
        private Clock $clock,
    ) {}

    /** @requirement C2C-003 C2C-004 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function submit(
        string $submissionKey,
        int $userId,
        string $reservationPublicId,
        int $claimedAmountIrr,
        DateTimeImmutable $claimedPaidAt,
        string $evidenceHash,
        ?string $senderCard = null,
        ?string $senderName = null,
        ?string $reference = null,
        ?string $privateReceiptReference = null,
        ?string $correlationId = null,
    ): CardToCardManualSubmissionReceipt {
        $this->assertToken($submissionKey, 'C2C manual submission key', 8, 128);
        if ($userId < 1 || ! Str::isUlid($reservationPublicId) || $claimedAmountIrr < 1) {
            throw new DomainException('C2C manual submission identity/amount is invalid.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/i', $evidenceHash) !== 1) {
            throw new DomainException('C2C manual submission evidence hash is invalid.');
        }
        $normalizedSenderCard = $this->normalizeOptionalCard($senderCard);
        $normalizedSenderName = $this->boundedOptional($senderName, 128, 'C2C sender name');
        $normalizedReference = $this->boundedOptional($reference, 191, 'C2C bank reference');
        $normalizedReceiptReference = $this->boundedOptional($privateReceiptReference, 191, 'C2C private receipt reference');
        if ($correlationId !== null) {
            $this->assertToken($correlationId, 'C2C manual submission correlation ID', 8, 64);
        }

        $paidAt = $claimedPaidAt->setTimezone(new DateTimeZone('UTC'));
        $senderHash = $normalizedSenderCard === null
            ? null
            : hash_hmac('sha256', $normalizedSenderCard, $this->lookupKey());

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $submissionKey,
            $userId,
            $reservationPublicId,
            $claimedAmountIrr,
            $paidAt,
            $evidenceHash,
            $senderHash,
            $normalizedSenderName,
            $normalizedReference,
            $normalizedReceiptReference,
            $correlationId,
        ): CardToCardManualSubmissionReceipt {
            $existing = $connection->table('c2c_manual_submissions')->where('submission_key', $submissionKey)->lockForUpdate()->first();
            if ($existing !== null) {
                return $this->replayOrConflict(
                    $connection,
                    $existing,
                    $userId,
                    $reservationPublicId,
                    $claimedAmountIrr,
                    $paidAt,
                    $evidenceHash,
                    $senderHash,
                    $normalizedSenderName,
                    $normalizedReference,
                    $normalizedReceiptReference,
                );
            }

            $reservation = $connection->table('c2c_amount_reservations as reservation')
                ->join('payment_intents as intent', 'intent.id', '=', 'reservation.payment_intent_id')
                ->where('reservation.public_id', $reservationPublicId)
                ->lockForUpdate()
                ->first([
                    'reservation.id', 'reservation.public_id', 'reservation.payment_intent_id',
                    'reservation.c2c_destination_account_id', 'reservation.payable_amount_irr',
                    'reservation.reserved_at', 'reservation.late_review_until',
                    'intent.public_id as intent_public_id', 'intent.user_id', 'intent.purpose',
                    'intent.payment_method_code', 'intent.provider_code', 'intent.state', 'intent.captured_at',
                ]);
            if ($reservation === null) {
                throw new DomainException('C2C manual submission reservation does not exist.');
            }
            if ((int) $reservation->user_id !== $userId
                || $reservation->purpose !== 'purchase'
                || $reservation->payment_method_code !== 'card_to_card'
                || $reservation->provider_code !== 'card_to_card'
                || $reservation->state !== PaymentIntentState::AwaitingUserAction->value
                || $reservation->captured_at !== null
                || (int) $reservation->payable_amount_irr !== $claimedAmountIrr) {
                throw new DomainException('C2C manual submission does not match the owned payable reservation.');
            }
            $reservedAt = $this->storedDateTime((string) $reservation->reserved_at);
            $lateReviewUntil = $this->storedDateTime((string) $reservation->late_review_until);
            if ($paidAt < $reservedAt || $paidAt > $lateReviewUntil) {
                throw new DomainException('C2C claimed payment time is outside the accepted review window.');
            }

            $submissionId = (int) $connection->table('c2c_manual_submissions')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'submission_key' => $submissionKey,
                'payment_intent_id' => (int) $reservation->payment_intent_id,
                'c2c_amount_reservation_id' => (int) $reservation->id,
                'c2c_destination_account_id' => (int) $reservation->c2c_destination_account_id,
                'submitted_by_user_id' => $userId,
                'claimed_amount_irr' => $claimedAmountIrr,
                'claimed_paid_at' => $this->databaseDateTime($paidAt),
                'sender_card_lookup_hash' => $senderHash,
                'encrypted_sender_name' => $normalizedSenderName === null ? null : $this->encrypter->encryptString($normalizedSenderName),
                'reference' => $normalizedReference,
                'private_receipt_reference' => $normalizedReceiptReference,
                'evidence_hash' => strtolower($evidenceHash),
                'created_at' => $this->timestamp(),
            ]);

            $updated = $connection->table('payment_intents')
                ->where('id', $reservation->payment_intent_id)
                ->where('state', PaymentIntentState::AwaitingUserAction->value)
                ->whereNull('captured_at')
                ->update([
                    'state' => PaymentIntentState::Submitted->value,
                    'updated_at' => $this->timestamp(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('C2C manual submission payment state changed concurrently.');
            }
            $connection->table('payment_intent_state_histories')->insert([
                'payment_intent_id' => (int) $reservation->payment_intent_id,
                'from_state' => PaymentIntentState::AwaitingUserAction->value,
                'to_state' => PaymentIntentState::Submitted->value,
                'reason_code' => 'c2c_manual_payment_submitted',
                'correlation_id' => $correlationId ?? hash('sha256', 'c2c-manual:'.$submissionKey),
                'created_at' => $this->timestamp(),
            ]);

            $stored = $connection->table('c2c_manual_submissions')->where('id', $submissionId)->first();
            if ($stored === null) {
                throw new RuntimeException('C2C manual submission persistence failed.');
            }

            return $this->receipt($connection, $stored, false);
        }, 3);
    }

    private function replayOrConflict(
        Connection $connection,
        stdClass $row,
        int $userId,
        string $reservationPublicId,
        int $claimedAmountIrr,
        DateTimeImmutable $paidAt,
        string $evidenceHash,
        ?string $senderHash,
        ?string $senderName,
        ?string $reference,
        ?string $privateReceiptReference,
    ): CardToCardManualSubmissionReceipt {
        $reservation = $connection->table('c2c_amount_reservations')->where('id', $row->c2c_amount_reservation_id)->first(['public_id']);
        if ($reservation === null) {
            throw new RuntimeException('Stored C2C manual submission reservation is unavailable.');
        }
        $storedSenderName = $row->encrypted_sender_name === null ? null : $this->encrypter->decryptString($row->encrypted_sender_name);
        if ((int) $row->submitted_by_user_id !== $userId
            || ! hash_equals((string) $reservation->public_id, $reservationPublicId)
            || (int) $row->claimed_amount_irr !== $claimedAmountIrr
            || $this->databaseDateTime($this->storedDateTime((string) $row->claimed_paid_at)) !== $this->databaseDateTime($paidAt)
            || ! hash_equals(strtolower((string) $row->evidence_hash), strtolower($evidenceHash))
            || (($row->sender_card_lookup_hash === null) !== ($senderHash === null))
            || ($senderHash !== null && ! hash_equals((string) $row->sender_card_lookup_hash, $senderHash))
            || $storedSenderName !== $senderName
            || $row->reference !== $reference
            || $row->private_receipt_reference !== $privateReceiptReference) {
            throw new RuntimeException('C2C manual submission key conflicts with accepted evidence.');
        }

        return $this->receipt($connection, $row, true);
    }

    private function receipt(Connection $connection, stdClass $row, bool $replayed): CardToCardManualSubmissionReceipt
    {
        $intent = $connection->table('payment_intents')->where('id', $row->payment_intent_id)->first(['public_id']);
        $reservation = $connection->table('c2c_amount_reservations')->where('id', $row->c2c_amount_reservation_id)->first(['public_id']);
        if ($intent === null || $reservation === null) {
            throw new RuntimeException('C2C manual submission linked authority is unavailable.');
        }

        return new CardToCardManualSubmissionReceipt(
            (int) $row->id,
            (string) $row->public_id,
            (string) $intent->public_id,
            (string) $reservation->public_id,
            (int) $row->claimed_amount_irr,
            $this->storedDateTime((string) $row->claimed_paid_at),
            $replayed,
        );
    }

    private function normalizeOptionalCard(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $value);
        if (! is_string($digits) || strlen($digits) < 4 || strlen($digits) > 24) {
            throw new DomainException('C2C sender card evidence is invalid.');
        }

        return $digits;
    }

    private function boundedOptional(?string $value, int $maximum, string $label): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maximum || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new DomainException($label.' is invalid.');
        }

        return $value;
    }

    private function lookupKey(): string
    {
        $key = config('payments.card_to_card.lookup_key');
        if (! is_string($key) || strlen($key) < 32) {
            throw new RuntimeException('Card-to-card lookup key is not configured securely.');
        }

        return $key;
    }

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum || preg_match('/\A[A-Za-z0-9:_.-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function storedDateTime(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new RuntimeException('Stored C2C manual submission timestamp is invalid.');
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
