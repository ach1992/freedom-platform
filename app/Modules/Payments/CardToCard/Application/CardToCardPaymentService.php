<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application;

use App\Modules\Payments\Application\Contracts\PurchasePromotionUsageAuthority;
use App\Modules\Payments\Application\PurchasePaymentIntentReceipt;
use App\Modules\Payments\Application\PurchasePaymentIntentService;
use App\Modules\Payments\CardToCard\Application\Contracts\CardToCardAdjustmentGenerator;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use Throwable;

final readonly class CardToCardPaymentService
{
    private const PAYMENT_METHOD_CODE = 'card_to_card';

    public function __construct(
        private DatabaseManager $database,
        private PurchasePaymentIntentService $purchaseIntents,
        private PurchasePromotionUsageAuthority $promotionUsage,
        private CardToCardAdjustmentGenerator $adjustments,
        private Clock $clock,
    ) {}

    /** @requirement C2C-001 C2C-002 C2C-004 PAY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function create(
        string $creationKey,
        int $userId,
        string $quotePublicId,
        string $eligibilityDecisionPublicId,
        string $correlationId,
    ): CardToCardPaymentReceipt {
        if ($userId < 1) {
            throw new DomainException('Card-to-card payment user ID must be positive.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $creationKey,
            $userId,
            $quotePublicId,
            $eligibilityDecisionPublicId,
            $correlationId,
        ): CardToCardPaymentReceipt {
            $this->expireReservations($connection);

            $intent = $this->purchaseIntents->create(
                $creationKey,
                $userId,
                $quotePublicId,
                $eligibilityDecisionPublicId,
                self::PAYMENT_METHOD_CODE,
                $correlationId,
            );
            $intentId = $connection->table('payment_intents')
                ->where('public_id', $intent->intentPublicId)
                ->lockForUpdate()
                ->value('id');
            if ($intentId === null) {
                throw new RuntimeException('Card-to-card payment intent authority is unavailable.');
            }
            $intentId = $this->positiveInt($intentId, 'Card-to-card payment intent ID');

            $this->promotionUsage->reserveForQuote(
                $this->promotionReservationKey($quotePublicId),
                $userId,
                $quotePublicId,
            );

            $existing = $connection->table('c2c_amount_reservations')
                ->where('payment_intent_id', $intentId)
                ->first();
            if ($existing !== null) {
                return $this->receipt($connection, $intent, $existing, true);
            }

            $baseAmountIrr = $intent->amount->amount();
            if ($baseAmountIrr < 1) {
                throw new RuntimeException('Card-to-card base amount must be positive integer IRR.');
            }

            /** @var list<stdClass> $destinations */
            $destinations = $connection->table('c2c_destination_accounts')
                ->where('state', 'active')
                ->orderBy('priority')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->all();
            if ($destinations === []) {
                throw new DomainException('No active card-to-card destination is available.');
            }

            foreach ($destinations as $destination) {
                if (! $this->destinationHasDailyCapacity($connection, $destination, $baseAmountIrr)) {
                    continue;
                }
                $reservation = $this->reserveOnDestination($connection, $intentId, $baseAmountIrr, $destination);
                if ($reservation !== null) {
                    return $this->receipt($connection, $intent, $reservation, false);
                }
            }

            throw new DomainException('No unique card-to-card payable amount is currently available.');
        }, 3);
    }

    /** @requirement C2C-002 C2C-004 DAT-003 DAT-004 QUA-004 */
    public function expireDue(): int
    {
        return $this->database->connection()->transaction(
            fn (Connection $connection): int => $this->expireReservations($connection),
            3,
        );
    }

    /** @requirement C2C-002 C2C-004 PAY-002 PRO-001 DAT-003 DAT-004 QUA-001 QUA-004 */
    public function expireAbandonedIntentsDue(int $limit = 100): CardToCardPurchaseMaintenanceResult
    {
        if ($limit < 1 || $limit > 500) {
            throw new DomainException('Card-to-card purchase maintenance limit must be between 1 and 500.');
        }

        $this->expireDue();
        $now = $this->databaseDateTime($this->clock->now());
        /** @var list<object{intent_public_id:string}> $rows */
        $rows = $this->database->connection()->table('c2c_amount_reservations as reservation')
            ->join('payment_intents as intent', 'intent.id', '=', 'reservation.payment_intent_id')
            ->where('reservation.late_review_until', '<=', $now)
            ->where('intent.purpose', 'purchase')
            ->where('intent.payment_method_code', self::PAYMENT_METHOD_CODE)
            ->where('intent.provider_code', self::PAYMENT_METHOD_CODE)
            ->where('intent.state', PaymentIntentState::AwaitingUserAction->value)
            ->whereNull('intent.captured_at')
            ->orderBy('intent.id')
            ->limit($limit)
            ->get(['intent.public_id as intent_public_id'])
            ->all();

        $expired = 0;
        $failures = 0;
        foreach ($rows as $row) {
            try {
                if ($this->expireAbandonedIntent((string) $row->intent_public_id)) {
                    $expired++;
                }
            } catch (Throwable) {
                $failures++;
            }
        }

        return new CardToCardPurchaseMaintenanceResult(count($rows), $expired, $failures);
    }

    private function expireAbandonedIntent(string $paymentIntentPublicId): bool
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($paymentIntentPublicId): bool {
            $intent = $connection->table('payment_intents')
                ->where('public_id', $paymentIntentPublicId)
                ->lockForUpdate()
                ->first(['id', 'public_id', 'purpose', 'payment_method_code', 'provider_code', 'state', 'captured_at']);
            if ($intent === null) {
                return false;
            }
            if ($intent->purpose !== 'purchase'
                || $intent->payment_method_code !== self::PAYMENT_METHOD_CODE
                || $intent->provider_code !== self::PAYMENT_METHOD_CODE
                || $intent->state !== PaymentIntentState::AwaitingUserAction->value
                || $intent->captured_at !== null) {
                return false;
            }

            $reservation = $connection->table('c2c_amount_reservations')
                ->where('payment_intent_id', $intent->id)
                ->lockForUpdate()
                ->first();
            if ($reservation === null) {
                throw new RuntimeException('Card-to-card purchase maintenance reservation is unavailable.');
            }
            $now = $this->databaseDateTime($this->clock->now());
            if ((string) $reservation->late_review_until > $now) {
                return false;
            }
            if ($connection->table('c2c_manual_submissions')->where('payment_intent_id', $intent->id)->exists()
                || $connection->table('c2c_transaction_matches')->where('payment_intent_id', $intent->id)->exists()) {
                return false;
            }
            $bankEvidence = $connection->table('c2c_bank_transactions')
                ->where('c2c_destination_account_id', $reservation->c2c_destination_account_id)
                ->where('amount_irr', $reservation->payable_amount_irr)
                ->whereIn('status', ['pending', 'settled'])
                ->where('occurred_at', '>=', $reservation->reserved_at)
                ->where('occurred_at', '<=', $reservation->late_review_until)
                ->lockForUpdate()
                ->exists();
            if ($bankEvidence) {
                return false;
            }

            if ((int) ($reservation->active_lock ?? 0) === 1) {
                $released = $connection->table('c2c_amount_reservations')
                    ->where('id', $reservation->id)
                    ->where('active_lock', 1)
                    ->update([
                        'active_lock' => null,
                        'released_at' => $now,
                        'release_reason' => 'expired',
                    ]);
                if ($released !== 1) {
                    throw new RuntimeException('Card-to-card amount reservation expiry changed concurrently.');
                }
            }

            PaymentIntentState::AwaitingUserAction->transitionTo(PaymentIntentState::Expired);
            $updated = $connection->table('payment_intents')
                ->where('id', $intent->id)
                ->where('state', PaymentIntentState::AwaitingUserAction->value)
                ->whereNull('captured_at')
                ->update([
                    'state' => PaymentIntentState::Expired->value,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Card-to-card abandoned payment intent changed concurrently.');
            }
            $connection->table('payment_intent_state_histories')->insert([
                'payment_intent_id' => $intent->id,
                'from_state' => PaymentIntentState::AwaitingUserAction->value,
                'to_state' => PaymentIntentState::Expired->value,
                'reason_code' => 'c2c_late_review_expired',
                'correlation_id' => 'c2c-maintenance:'.$paymentIntentPublicId,
                'created_at' => $now,
            ]);

            return true;
        }, 3);
    }

    private function promotionReservationKey(string $quotePublicId): string
    {
        return 'purchase-promotion-reservation:'.$quotePublicId;
    }

    private function reserveOnDestination(Connection $connection, int $intentId, int $baseAmountIrr, stdClass $destination): ?stdClass
    {
        $minimum = (bool) $destination->adjustment_enabled ? (int) $destination->adjustment_min_irr : 0;
        $maximum = (bool) $destination->adjustment_enabled ? (int) $destination->adjustment_max_irr : 0;
        if ($minimum < 0 || $maximum < $minimum) {
            throw new RuntimeException('Stored card-to-card adjustment policy is invalid.');
        }
        $span = ($maximum - $minimum) + 1;
        $start = $this->adjustments->generate($minimum, $maximum);
        $attempts = min($span, 10000);
        $reservedAt = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $expiresAt = $reservedAt->modify('+'.((int) $destination->reservation_minutes).' minutes');
        $lateReviewUntil = $reservedAt->modify('+'.((int) $destination->late_review_minutes).' minutes');

        for ($offset = 0; $offset < $attempts; $offset++) {
            $adjustment = $minimum + (($start - $minimum + $offset) % $span);
            $payable = $baseAmountIrr + $adjustment;
            if ($payable < $baseAmountIrr) {
                throw new RuntimeException('Card-to-card payable amount overflowed integer authority.');
            }
            try {
                $reservationId = (int) $connection->table('c2c_amount_reservations')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'payment_intent_id' => $intentId,
                    'c2c_destination_account_id' => $this->positiveInt($destination->id, 'Card-to-card destination ID'),
                    'base_amount_irr' => $baseAmountIrr,
                    'adjustment_amount_irr' => $adjustment,
                    'payable_amount_irr' => $payable,
                    'active_lock' => 1,
                    'reserved_at' => $this->databaseDateTime($reservedAt),
                    'expires_at' => $this->databaseDateTime($expiresAt),
                    'late_review_until' => $this->databaseDateTime($lateReviewUntil),
                    'released_at' => null,
                    'release_reason' => null,
                    'created_at' => $this->databaseDateTime($reservedAt),
                ]);
                $row = $connection->table('c2c_amount_reservations')->where('id', $reservationId)->first();
                if ($row === null) {
                    throw new RuntimeException('Card-to-card amount reservation disappeared after creation.');
                }

                return $row;
            } catch (QueryException $exception) {
                $collision = $connection->table('c2c_amount_reservations')
                    ->where('c2c_destination_account_id', $destination->id)
                    ->where('payable_amount_irr', $payable)
                    ->where('active_lock', 1)
                    ->exists();
                if ($collision) {
                    continue;
                }
                throw $exception;
            }
        }

        return null;
    }

    private function destinationHasDailyCapacity(Connection $connection, stdClass $destination, int $baseAmountIrr): bool
    {
        if ($destination->daily_limit_irr === null) {
            return true;
        }
        $limit = $this->positiveInt($destination->daily_limit_irr, 'Card-to-card destination daily limit');
        $day = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
        $reserved = (int) $connection->table('c2c_amount_reservations')
            ->where('c2c_destination_account_id', $destination->id)
            ->whereDate('reserved_at', $day)
            ->sum('payable_amount_irr');

        return $reserved <= $limit && $baseAmountIrr <= ($limit - $reserved);
    }

    private function expireReservations(Connection $connection): int
    {
        $now = $this->databaseDateTime($this->clock->now());

        return $connection->table('c2c_amount_reservations')
            ->where('active_lock', 1)
            ->where('expires_at', '<=', $now)
            ->update([
                'active_lock' => null,
                'released_at' => $now,
                'release_reason' => 'expired',
            ]);
    }

    private function receipt(Connection $connection, PurchasePaymentIntentReceipt $intent, stdClass $reservation, bool $replayed): CardToCardPaymentReceipt
    {
        $destination = $connection->table('c2c_destination_accounts')
            ->where('id', $reservation->c2c_destination_account_id)
            ->first(['public_id', 'code', 'masked_card_number']);
        if ($destination === null) {
            throw new RuntimeException('Card-to-card destination authority is unavailable.');
        }

        return new CardToCardPaymentReceipt(
            $intent,
            $this->positiveInt($reservation->id, 'Card-to-card reservation ID'),
            $reservation->public_id,
            $this->positiveInt($reservation->base_amount_irr, 'Card-to-card base amount'),
            (int) $reservation->adjustment_amount_irr,
            $this->positiveInt($reservation->payable_amount_irr, 'Card-to-card payable amount'),
            $destination->public_id,
            $destination->code,
            $destination->masked_card_number,
            $this->storedDateTime($reservation->expires_at),
            $this->storedDateTime($reservation->late_review_until),
            $replayed,
        );
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($integer === false) {
            throw new RuntimeException($label.' must be a positive integer.');
        }

        return $integer;
    }

    private function storedDateTime(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new RuntimeException('Stored card-to-card timestamp is invalid.');
        }

        return $date;
    }

    private function databaseDateTime(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
