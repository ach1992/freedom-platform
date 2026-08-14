<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application;

use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class CardToCardMatchingService
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    /** @requirement C2C-004 C2C-005 PAY-002 DAT-002 DAT-003 DAT-004 QUA-001 QUA-004 */
    public function match(string $bankTransactionPublicId, string $correlationId): CardToCardMatchReceipt
    {
        if (! Str::isUlid($bankTransactionPublicId)) {
            throw new DomainException('C2C bank transaction public ID is invalid.');
        }
        $this->assertToken($correlationId, 'C2C match correlation ID', 8, 64);

        return $this->database->connection()->transaction(function (Connection $connection) use ($bankTransactionPublicId, $correlationId): CardToCardMatchReceipt {
            $transaction = $connection->table('c2c_bank_transactions')
                ->where('public_id', $bankTransactionPublicId)
                ->lockForUpdate()
                ->first();
            if ($transaction === null) {
                throw new DomainException('C2C bank transaction does not exist.');
            }

            $existingMatch = $connection->table('c2c_transaction_matches')
                ->where('c2c_bank_transaction_id', $transaction->id)
                ->first();
            if ($existingMatch !== null) {
                return $this->matchReceipt($connection, $transaction, $existingMatch, true);
            }
            $existingReview = $connection->table('c2c_match_reviews')
                ->where('c2c_bank_transaction_id', $transaction->id)
                ->first();
            if ($existingReview !== null) {
                return $this->reviewReceipt($transaction, $existingReview, true);
            }

            if ($transaction->status !== 'settled') {
                return $this->createReview($connection, $transaction, 'transaction_not_settled', 0, $correlationId);
            }

            /** @var list<object> $candidates */
            $candidates = $connection->table('c2c_amount_reservations as reservation')
                ->join('payment_intents as intent', 'intent.id', '=', 'reservation.payment_intent_id')
                ->where('reservation.c2c_destination_account_id', $transaction->c2c_destination_account_id)
                ->where('reservation.payable_amount_irr', $transaction->amount_irr)
                ->where('reservation.reserved_at', '<=', $transaction->occurred_at)
                ->where('reservation.late_review_until', '>=', $transaction->occurred_at)
                ->where('intent.purpose', 'purchase')
                ->where('intent.payment_method_code', 'card_to_card')
                ->where('intent.provider_code', 'card_to_card')
                ->whereIn('intent.state', ['awaiting_user_action', 'submitted'])
                ->whereNull('intent.captured_at')
                ->orderBy('reservation.id')
                ->lockForUpdate()
                ->get([
                    'reservation.id', 'reservation.public_id', 'reservation.payment_intent_id',
                    'reservation.expires_at', 'reservation.active_lock', 'reservation.released_at', 'reservation.release_reason',
                ])
                ->all();

            $onTime = array_values(array_filter(
                $candidates,
                static fn (object $candidate): bool => $transaction->occurred_at <= $candidate->expires_at,
            ));
            if (count($onTime) === 1) {
                return $this->createMatch($connection, $transaction, $onTime[0], 'automatic', $correlationId);
            }
            if (count($onTime) > 1) {
                return $this->createReview($connection, $transaction, 'ambiguous', count($onTime), $correlationId);
            }
            if ($candidates !== []) {
                return $this->createReview($connection, $transaction, 'late', count($candidates), $correlationId);
            }

            return $this->createReview($connection, $transaction, 'no_candidate', 0, $correlationId);
        }, 3);
    }

    /** @requirement C2C-004 C2C-005 DAT-002 DAT-003 DAT-004 QUA-004 */
    public function acceptReview(
        string $reviewPublicId,
        string $reservationPublicId,
        int $administratorId,
        string $reason,
        string $correlationId,
    ): CardToCardMatchReceipt {
        if (! Str::isUlid($reviewPublicId) || ! Str::isUlid($reservationPublicId)) {
            throw new DomainException('C2C review/reservation public ID is invalid.');
        }
        if ($administratorId < 1) {
            throw new DomainException('C2C review administrator ID must be positive.');
        }
        $this->assertReason($reason);
        $this->assertToken($correlationId, 'C2C review correlation ID', 8, 64);

        return $this->database->connection()->transaction(function (Connection $connection) use ($reviewPublicId, $reservationPublicId, $administratorId, $reason, $correlationId): CardToCardMatchReceipt {
            $review = $connection->table('c2c_match_reviews')->where('public_id', $reviewPublicId)->lockForUpdate()->first();
            if ($review === null) {
                throw new DomainException('C2C match review does not exist.');
            }
            $transaction = $connection->table('c2c_bank_transactions')->where('id', $review->c2c_bank_transaction_id)->lockForUpdate()->first();
            if ($transaction === null) {
                throw new RuntimeException('C2C review bank transaction is unavailable.');
            }
            $existingMatch = $connection->table('c2c_transaction_matches')->where('c2c_bank_transaction_id', $transaction->id)->first();
            if ($review->state !== 'pending') {
                if ($review->state === 'accepted' && $existingMatch !== null
                    && (int) $review->selected_c2c_amount_reservation_id === (int) $existingMatch->c2c_amount_reservation_id) {
                    return $this->matchReceipt($connection, $transaction, $existingMatch, true);
                }
                throw new RuntimeException('C2C match review was already decided differently.');
            }
            if ($existingMatch !== null) {
                throw new RuntimeException('C2C bank transaction is already matched.');
            }
            if ($transaction->status !== 'settled') {
                throw new DomainException('Only a settled C2C bank transaction can be manually accepted.');
            }

            $reservation = $connection->table('c2c_amount_reservations as reservation')
                ->join('payment_intents as intent', 'intent.id', '=', 'reservation.payment_intent_id')
                ->where('reservation.public_id', $reservationPublicId)
                ->where('reservation.c2c_destination_account_id', $transaction->c2c_destination_account_id)
                ->where('reservation.payable_amount_irr', $transaction->amount_irr)
                ->where('reservation.reserved_at', '<=', $transaction->occurred_at)
                ->where('reservation.late_review_until', '>=', $transaction->occurred_at)
                ->where('intent.purpose', 'purchase')
                ->where('intent.payment_method_code', 'card_to_card')
                ->where('intent.provider_code', 'card_to_card')
                ->whereIn('intent.state', ['awaiting_user_action', 'submitted'])
                ->whereNull('intent.captured_at')
                ->lockForUpdate()
                ->first(['reservation.*']);
            if ($reservation === null) {
                throw new DomainException('Selected C2C reservation is not a valid review candidate.');
            }

            $now = $this->timestamp();
            $updated = $connection->table('c2c_match_reviews')
                ->where('id', $review->id)
                ->where('state', 'pending')
                ->update([
                    'state' => 'accepted',
                    'selected_c2c_amount_reservation_id' => $reservation->id,
                    'decided_by_administrator_id' => $administratorId,
                    'decision_reason' => trim($reason),
                    'decided_at' => $now,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('C2C review decision changed concurrently.');
            }

            return $this->createMatch($connection, $transaction, $reservation, 'manual', $correlationId);
        }, 3);
    }

    /** @requirement C2C-004 C2C-005 DAT-003 DAT-004 QUA-004 */
    public function rejectReview(string $reviewPublicId, int $administratorId, string $reason): CardToCardMatchReceipt
    {
        if (! Str::isUlid($reviewPublicId) || $administratorId < 1) {
            throw new DomainException('C2C review identity is invalid.');
        }
        $this->assertReason($reason);

        return $this->database->connection()->transaction(function (Connection $connection) use ($reviewPublicId, $administratorId, $reason): CardToCardMatchReceipt {
            $review = $connection->table('c2c_match_reviews')->where('public_id', $reviewPublicId)->lockForUpdate()->first();
            if ($review === null) {
                throw new DomainException('C2C match review does not exist.');
            }
            $transaction = $connection->table('c2c_bank_transactions')->where('id', $review->c2c_bank_transaction_id)->first();
            if ($transaction === null) {
                throw new RuntimeException('C2C review bank transaction is unavailable.');
            }
            if ($review->state === 'rejected') {
                return $this->reviewReceipt($transaction, $review, true);
            }
            if ($review->state !== 'pending') {
                throw new RuntimeException('C2C match review was already decided differently.');
            }
            $now = $this->timestamp();
            $connection->table('c2c_match_reviews')->where('id', $review->id)->where('state', 'pending')->update([
                'state' => 'rejected',
                'decided_by_administrator_id' => $administratorId,
                'decision_reason' => trim($reason),
                'decided_at' => $now,
                'updated_at' => $now,
            ]);
            $fresh = $connection->table('c2c_match_reviews')->where('id', $review->id)->first();
            if ($fresh === null) {
                throw new RuntimeException('C2C review disappeared after rejection.');
            }

            return $this->reviewReceipt($transaction, $fresh, false);
        }, 3);
    }

    private function createMatch(Connection $connection, object $transaction, object $reservation, string $mode, string $correlationId): CardToCardMatchReceipt
    {
        $matchId = (int) $connection->table('c2c_transaction_matches')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'c2c_bank_transaction_id' => (int) $transaction->id,
            'c2c_amount_reservation_id' => (int) $reservation->id,
            'payment_intent_id' => (int) $reservation->payment_intent_id,
            'match_mode' => $mode,
            'state' => 'matched',
            'purchase_settlement_id' => null,
            'matched_at' => $this->timestamp(),
            'captured_at' => null,
            'correlation_id' => $correlationId,
            'created_at' => $this->timestamp(),
        ]);
        $match = $connection->table('c2c_transaction_matches')->where('id', $matchId)->first();
        if ($match === null) {
            throw new RuntimeException('C2C transaction match persistence failed.');
        }

        return $this->matchReceipt($connection, $transaction, $match, false);
    }

    private function createReview(Connection $connection, object $transaction, string $reason, int $candidateCount, string $correlationId): CardToCardMatchReceipt
    {
        $now = $this->timestamp();
        $reviewId = (int) $connection->table('c2c_match_reviews')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'c2c_bank_transaction_id' => (int) $transaction->id,
            'reason_code' => $reason,
            'candidate_count' => min($candidateCount, 65535),
            'state' => 'pending',
            'selected_c2c_amount_reservation_id' => null,
            'decided_by_administrator_id' => null,
            'decision_reason' => null,
            'decided_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $review = $connection->table('c2c_match_reviews')->where('id', $reviewId)->first();
        if ($review === null) {
            throw new RuntimeException('C2C match review persistence failed.');
        }

        return $this->reviewReceipt($transaction, $review, false);
    }

    private function matchReceipt(Connection $connection, object $transaction, object $match, bool $replayed): CardToCardMatchReceipt
    {
        $reservation = $connection->table('c2c_amount_reservations')->where('id', $match->c2c_amount_reservation_id)->first(['id', 'public_id']);
        if ($reservation === null) {
            throw new RuntimeException('C2C matched reservation is unavailable.');
        }

        return new CardToCardMatchReceipt(
            (int) $transaction->id,
            $transaction->public_id,
            (int) $match->id,
            $match->public_id,
            (int) $reservation->id,
            $reservation->public_id,
            null,
            null,
            $match->state,
            1,
            $replayed,
        );
    }

    private function reviewReceipt(object $transaction, object $review, bool $replayed): CardToCardMatchReceipt
    {
        return new CardToCardMatchReceipt(
            (int) $transaction->id,
            $transaction->public_id,
            null,
            null,
            null,
            null,
            (int) $review->id,
            $review->public_id,
            'review_'.$review->state.':'.$review->reason_code,
            (int) $review->candidate_count,
            $replayed,
        );
    }

    private function assertReason(string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 191) {
            throw new DomainException('C2C review decision reason is required and bounded.');
        }
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
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
