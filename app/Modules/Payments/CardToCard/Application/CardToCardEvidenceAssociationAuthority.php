<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application;

use App\Modules\Payments\Domain\PaymentIntentState;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;
use stdClass;

final readonly class CardToCardEvidenceAssociationAuthority
{
    public function __construct(
        private CardToCardEvidenceRecoveryMutex $recoveryMutex,
        private Clock $clock,
    ) {}

    /** @return array{reservation:stdClass,intent:stdClass}|null */
    public function lockByPaymentIntentPublicId(Connection $connection, string $paymentIntentPublicId): ?array
    {
        $identity = $connection->table('c2c_amount_reservations as reservation')
            ->join('payment_intents as intent', 'intent.id', '=', 'reservation.payment_intent_id')
            ->where('intent.public_id', $paymentIntentPublicId)
            ->first([
                'reservation.id as reservation_id',
                'reservation.c2c_destination_account_id as destination_id',
            ]);
        if ($identity === null) {
            return null;
        }

        $this->lockDestination($connection, (int) $identity->destination_id);
        $authority = $this->lockByReservationId($connection, (int) $identity->reservation_id);
        if (! hash_equals((string) $authority['intent']->public_id, $paymentIntentPublicId)) {
            throw new RuntimeException('C2C evidence association intent identity changed unexpectedly.');
        }

        return $authority;
    }

    /** @return array{reservation:stdClass,intent:stdClass}|null */
    public function lockByReservationPublicId(Connection $connection, string $reservationPublicId): ?array
    {
        $identity = $connection->table('c2c_amount_reservations')
            ->where('public_id', $reservationPublicId)
            ->first(['id', 'c2c_destination_account_id']);
        if ($identity === null) {
            return null;
        }

        $this->lockDestination($connection, (int) $identity->c2c_destination_account_id);
        $authority = $this->lockByReservationId($connection, (int) $identity->id);
        if (! hash_equals((string) $authority['reservation']->public_id, $reservationPublicId)) {
            throw new RuntimeException('C2C evidence association reservation identity changed unexpectedly.');
        }

        return $authority;
    }

    /**
     * @return list<array{reservation:stdClass,intent:stdClass}>
     */
    public function lockMatchingBankEvidenceAuthorities(
        Connection $connection,
        int $destinationAccountId,
        int $amountIrr,
        DateTimeImmutable $occurredAt,
    ): array {
        $occurredAtValue = $this->databaseDateTime($occurredAt);

        /** @var list<stdClass> $reservations */
        $reservations = $connection->table('c2c_amount_reservations')
            ->where('c2c_destination_account_id', $destinationAccountId)
            ->where('payable_amount_irr', $amountIrr)
            ->where('reserved_at', '<=', $occurredAtValue)
            ->where('late_review_until', '>=', $occurredAtValue)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->all();

        $authorities = [];
        foreach ($reservations as $reservation) {
            $authorities[] = $this->lockIntentForReservation($connection, $reservation);
        }

        return $authorities;
    }

    /**
     * Restore only an evidence-free C2C late-review expiry that raced with evidence accepted before that expiry.
     * The database payment-intent guard independently verifies the recovery mutex, C2C reservation, terminal
     * history, no winning settlement/Order, no released/redeemed promotion, and matching bank/manual evidence for direct Submitted recovery.
     *
     * @param  array{reservation:stdClass,intent:stdClass}  $authority
     * @return array{reservation:stdClass,intent:stdClass,restored:bool}
     */
    public function restoreConcurrentEvidenceExpiry(
        Connection $connection,
        array $authority,
        DateTimeImmutable $evidenceAcceptedAt,
        PaymentIntentState $targetState,
        string $reasonCode,
        string $correlationId,
    ): array {
        if ($targetState !== PaymentIntentState::Submitted) {
            throw new DomainException('C2C evidence recovery target state is invalid.');
        }

        $reservation = $authority['reservation'];
        $intent = $authority['intent'];
        if ($intent->state !== PaymentIntentState::Expired->value) {
            return ['reservation' => $reservation, 'intent' => $intent, 'restored' => false];
        }

        $this->recoveryMutex->assertHeldByCurrentConnection($connection);
        $expiryHistory = $connection->table('payment_intent_state_histories')
            ->where('payment_intent_id', $intent->id)
            ->where('from_state', PaymentIntentState::AwaitingUserAction->value)
            ->where('to_state', PaymentIntentState::Expired->value)
            ->where('reason_code', 'c2c_late_review_expired')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first(['id', 'created_at']);
        if ($expiryHistory === null) {
            return ['reservation' => $reservation, 'intent' => $intent, 'restored' => false];
        }
        if ($evidenceAcceptedAt->setTimezone(new DateTimeZone('UTC')) > $this->storedDateTime((string) $expiryHistory->created_at)) {
            return ['reservation' => $reservation, 'intent' => $intent, 'restored' => false];
        }
        if (! $this->recoveryCommercialAuthorityStillOpen($connection, $intent)) {
            return ['reservation' => $reservation, 'intent' => $intent, 'restored' => false];
        }

        $timestamp = $this->timestamp();
        $updated = $connection->table('payment_intents')
            ->where('id', $intent->id)
            ->where('state', PaymentIntentState::Expired->value)
            ->whereNull('captured_at')
            ->update([
                'state' => $targetState->value,
                'updated_at' => $timestamp,
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('C2C concurrent evidence recovery changed concurrently.');
        }
        $connection->table('payment_intent_state_histories')->insert([
            'payment_intent_id' => (int) $intent->id,
            'from_state' => PaymentIntentState::Expired->value,
            'to_state' => $targetState->value,
            'reason_code' => $reasonCode,
            'correlation_id' => $correlationId,
            'created_at' => $timestamp,
        ]);

        $freshIntent = $connection->table('payment_intents')
            ->where('id', $intent->id)
            ->lockForUpdate()
            ->first([
                'id', 'public_id', 'purpose', 'user_id', 'payment_method_code', 'provider_code',
                'state', 'captured_at', 'source_quote_id', 'source_quote_public_id',
            ]);
        if ($freshIntent === null || $freshIntent->state !== $targetState->value) {
            throw new RuntimeException('C2C concurrent evidence recovery persistence failed.');
        }

        return ['reservation' => $reservation, 'intent' => $freshIntent, 'restored' => true];
    }

    private function recoveryCommercialAuthorityStillOpen(Connection $connection, stdClass $intent): bool
    {
        if ($intent->source_quote_id === null) {
            return false;
        }
        $quoteId = (int) $intent->source_quote_id;
        $promotionReservation = $connection->table('promotion_usage_reservations')
            ->where('quote_id', $quoteId)
            ->lockForUpdate()
            ->first(['id']);
        if ($promotionReservation !== null) {
            if ($connection->table('promotion_usage_releases')
                ->where('promotion_usage_reservation_id', $promotionReservation->id)
                ->lockForUpdate()
                ->first(['id']) !== null
                || $connection->table('promotion_usage_redemptions')
                    ->where('promotion_usage_reservation_id', $promotionReservation->id)
                    ->lockForUpdate()
                    ->first(['id']) !== null) {
                return false;
            }
        }

        $wonOrder = $connection->table('orders')
            ->where('source_quote_id', $quoteId)
            ->where(function ($query): void {
                $query->where('state', '<>', 'awaiting_payment')
                    ->orWhereNotNull('purchase_settlement_id')
                    ->orWhereNotNull('payment_intent_id');
            })
            ->lockForUpdate()
            ->first(['id']);
        if ($wonOrder !== null) {
            return false;
        }

        return $connection->table('purchase_settlements')
            ->where('source_quote_id', $quoteId)
            ->lockForUpdate()
            ->first(['id']) === null;
    }

    private function lockDestination(Connection $connection, int $destinationAccountId): void
    {
        $destination = $connection->table('c2c_destination_accounts')
            ->where('id', $destinationAccountId)
            ->lockForUpdate()
            ->first(['id']);
        if ($destination === null) {
            throw new RuntimeException('C2C evidence association destination is unavailable.');
        }
    }

    /** @return array{reservation:stdClass,intent:stdClass} */
    private function lockByReservationId(Connection $connection, int $reservationId): array
    {
        $reservation = $connection->table('c2c_amount_reservations')
            ->where('id', $reservationId)
            ->lockForUpdate()
            ->first();
        if ($reservation === null) {
            throw new RuntimeException('C2C evidence association reservation disappeared.');
        }

        return $this->lockIntentForReservation($connection, $reservation);
    }

    /** @return array{reservation:stdClass,intent:stdClass} */
    private function lockIntentForReservation(Connection $connection, stdClass $reservation): array
    {
        $intent = $connection->table('payment_intents')
            ->where('id', $reservation->payment_intent_id)
            ->lockForUpdate()
            ->first([
                'id', 'public_id', 'purpose', 'user_id', 'payment_method_code', 'provider_code',
                'state', 'captured_at', 'source_quote_id', 'source_quote_public_id',
            ]);
        if ($intent === null || (int) $intent->id !== (int) $reservation->payment_intent_id) {
            throw new RuntimeException('C2C evidence association payment intent is unavailable.');
        }
        if ($intent->purpose !== 'purchase'
            || $intent->payment_method_code !== 'card_to_card'
            || $intent->provider_code !== 'card_to_card') {
            throw new RuntimeException('C2C evidence association purchase identity is invalid.');
        }

        return ['reservation' => $reservation, 'intent' => $intent];
    }

    private function storedDateTime(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new RuntimeException('Stored C2C evidence recovery timestamp is invalid.');
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
