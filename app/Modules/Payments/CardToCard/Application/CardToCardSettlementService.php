<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application;

use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\PurchaseSettlementService;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Shared\Application\Clock;
use App\Shared\Domain\Money;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

final readonly class CardToCardSettlementService
{
    private const PROVIDER_CODE = 'card_to_card';

    public function __construct(
        private DatabaseManager $database,
        private PurchaseSettlementService $settlements,
        private Clock $clock,
    ) {}

    /** @requirement C2C-004 C2C-005 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 QUA-001 QUA-004 */
    public function capture(string $matchPublicId, string $correlationId): CardToCardSettlementReceipt
    {
        if (! Str::isUlid($matchPublicId)) {
            throw new DomainException('C2C match public ID is invalid.');
        }
        $this->assertToken($correlationId, 'C2C settlement correlation ID', 8, 64);

        return $this->database->connection()->transaction(function (Connection $connection) use ($matchPublicId, $correlationId): CardToCardSettlementReceipt {
            $match = $connection->table('c2c_transaction_matches')
                ->where('public_id', $matchPublicId)
                ->lockForUpdate()
                ->first();
            if ($match === null) {
                throw new DomainException('C2C transaction match does not exist.');
            }

            $transaction = $connection->table('c2c_bank_transactions')
                ->where('id', $match->c2c_bank_transaction_id)
                ->lockForUpdate()
                ->first();
            $reservation = $connection->table('c2c_amount_reservations')
                ->where('id', $match->c2c_amount_reservation_id)
                ->lockForUpdate()
                ->first();
            $intent = $connection->table('payment_intents')
                ->where('id', $match->payment_intent_id)
                ->lockForUpdate()
                ->first();
            if ($transaction === null || $reservation === null || $intent === null) {
                throw new RuntimeException('C2C settlement authority is incomplete.');
            }

            if ($match->state === 'captured') {
                return $this->replay($connection, $match, $transaction, $reservation, $intent);
            }
            if ($match->state !== 'matched' || $match->purchase_settlement_id !== null || $match->captured_at !== null) {
                throw new RuntimeException('C2C match is not ready for settlement.');
            }
            if ($transaction->status !== 'settled'
                || (int) $transaction->amount_irr !== (int) $reservation->payable_amount_irr
                || $transaction->currency !== 'IRR'
                || (int) $reservation->payment_intent_id !== (int) $intent->id
                || (int) $match->payment_intent_id !== (int) $intent->id
                || $intent->purpose !== 'purchase'
                || $intent->provider_code !== self::PROVIDER_CODE
                || $intent->payment_method_code !== self::PROVIDER_CODE
                || $intent->currency !== 'IRR'
                || (int) $intent->amount_irr !== (int) $reservation->base_amount_irr) {
                throw new RuntimeException('C2C match financial identity is inconsistent.');
            }

            $settledEvent = $connection->table('c2c_bank_transaction_events')
                ->where('c2c_bank_transaction_id', $transaction->id)
                ->where('status', 'settled')
                ->orderByDesc('id')
                ->first();
            if ($settledEvent === null) {
                throw new RuntimeException('C2C settled transaction has no immutable settled event authority.');
            }

            $current = PaymentIntentState::tryFrom((string) $intent->state)
                ?? throw new RuntimeException('Stored C2C payment intent state is invalid.');
            if ($current === PaymentIntentState::AwaitingUserAction) {
                $this->transitionIntent(
                    $connection,
                    (int) $intent->id,
                    PaymentIntentState::AwaitingUserAction,
                    PaymentIntentState::Submitted,
                    'c2c_payment_matched',
                    $correlationId,
                );
                $current = PaymentIntentState::Submitted;
            }
            if ($current === PaymentIntentState::Submitted) {
                $this->transitionIntent(
                    $connection,
                    (int) $intent->id,
                    PaymentIntentState::Submitted,
                    PaymentIntentState::Verifying,
                    'c2c_payment_verifying',
                    $correlationId,
                );
                $current = PaymentIntentState::Verifying;
            }
            if (! in_array($current, [PaymentIntentState::Verifying, PaymentIntentState::PendingManualReview, PaymentIntentState::Authorized], true)) {
                throw new RuntimeException('C2C payment intent is not ready for authoritative settlement.');
            }

            $providerTransactionId = hash('sha256', $transaction->provider_code."\0".$transaction->provider_transaction_id);
            $providerEventId = hash('sha256', $transaction->provider_code."\0".$settledEvent->provider_event_id);
            $occurredAt = $this->storedDateTime((string) $transaction->occurred_at, 'C2C transaction occurred-at');
            $settledAt = $this->storedDateTime((string) $settledEvent->observed_at, 'C2C settled-event observed-at');
            $payloadHash = strtolower((string) $settledEvent->evidence_payload_hash);
            if (preg_match('/\A[0-9a-f]{64}\z/', $payloadHash) !== 1) {
                throw new RuntimeException('C2C settled evidence hash is invalid.');
            }

            $event = new VerifiedPaymentEvent(
                $providerEventId,
                $payloadHash,
                new PaymentEvidence(
                    ProviderOperationOutcome::Success,
                    PaymentEvidenceAuthority::Authoritative,
                    PaymentTransactionStatus::Settled,
                    $providerTransactionId,
                    $providerEventId,
                    Money::irr((int) $reservation->payable_amount_irr),
                    $occurredAt,
                    $settledAt,
                    $payloadHash,
                    [
                        'bank_provider_code' => (string) $transaction->provider_code,
                        'c2c_bank_transaction_public_id' => (string) $transaction->public_id,
                        'c2c_match_public_id' => (string) $match->public_id,
                        'match_mode' => (string) $match->match_mode,
                    ],
                ),
            );

            $settlement = $this->settlements->capture(
                (string) $intent->public_id,
                self::PROVIDER_CODE,
                $event,
                $correlationId,
            );

            $capturedAt = $this->timestamp();
            $updated = $connection->table('c2c_transaction_matches')
                ->where('id', $match->id)
                ->where('state', 'matched')
                ->whereNull('purchase_settlement_id')
                ->update([
                    'state' => 'captured',
                    'purchase_settlement_id' => $settlement->settlementId,
                    'captured_at' => $capturedAt,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('C2C match settlement changed concurrently.');
            }

            if ((int) ($reservation->active_lock ?? 0) === 1) {
                $released = $connection->table('c2c_amount_reservations')
                    ->where('id', $reservation->id)
                    ->where('active_lock', 1)
                    ->update([
                        'active_lock' => null,
                        'released_at' => $capturedAt,
                        'release_reason' => 'matched',
                    ]);
                if ($released !== 1) {
                    throw new RuntimeException('C2C amount reservation release changed concurrently.');
                }
            }

            return new CardToCardSettlementReceipt(
                (int) $match->id,
                (string) $match->public_id,
                (string) $transaction->public_id,
                (string) $intent->public_id,
                $settlement->settlementId,
                $settlement->settlementPublicId,
                (int) $reservation->base_amount_irr,
                (int) $reservation->adjustment_amount_irr,
                (int) $reservation->payable_amount_irr,
                false,
            );
        }, 3);
    }

    private function replay(
        Connection $connection,
        stdClass $match,
        stdClass $transaction,
        stdClass $reservation,
        stdClass $intent,
    ): CardToCardSettlementReceipt {
        if ($match->purchase_settlement_id === null || $match->captured_at === null) {
            throw new RuntimeException('Captured C2C match is missing settlement authority.');
        }
        $settlement = $connection->table('purchase_settlements')
            ->where('id', $match->purchase_settlement_id)
            ->first(['id', 'public_id', 'payment_intent_id', 'provider_code', 'provider_transaction_id', 'amount_irr', 'currency']);
        if ($settlement === null
            || (int) $settlement->payment_intent_id !== (int) $intent->id
            || $settlement->provider_code !== self::PROVIDER_CODE
            || ! hash_equals(
                (string) $settlement->provider_transaction_id,
                hash('sha256', $transaction->provider_code."\0".$transaction->provider_transaction_id),
            )
            || (int) $settlement->amount_irr !== (int) $reservation->payable_amount_irr
            || $settlement->currency !== 'IRR'
            || $intent->state !== PaymentIntentState::Captured->value
            || $intent->captured_at === null) {
            throw new RuntimeException('Stored C2C settlement integrity check failed.');
        }

        return new CardToCardSettlementReceipt(
            (int) $match->id,
            (string) $match->public_id,
            (string) $transaction->public_id,
            (string) $intent->public_id,
            (int) $settlement->id,
            (string) $settlement->public_id,
            (int) $reservation->base_amount_irr,
            (int) $reservation->adjustment_amount_irr,
            (int) $reservation->payable_amount_irr,
            true,
        );
    }

    private function transitionIntent(
        Connection $connection,
        int $intentId,
        PaymentIntentState $from,
        PaymentIntentState $to,
        string $reasonCode,
        string $correlationId,
    ): void {
        $from->transitionTo($to);
        $updated = $connection->table('payment_intents')
            ->where('id', $intentId)
            ->where('state', $from->value)
            ->whereNull('captured_at')
            ->update([
                'state' => $to->value,
                'updated_at' => $this->timestamp(),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('C2C payment intent state transition changed concurrently.');
        }
        $connection->table('payment_intent_state_histories')->insert([
            'payment_intent_id' => $intentId,
            'from_state' => $from->value,
            'to_state' => $to->value,
            'reason_code' => $reasonCode,
            'correlation_id' => $correlationId,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function storedDateTime(string $value, string $label): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception $exception) {
            throw new RuntimeException($label.' is invalid.', previous: $exception);
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
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
