<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application;

use Illuminate\Database\Connection;
use RuntimeException;
use stdClass;

final readonly class CardToCardEvidenceAssociationAuthority
{
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
}
