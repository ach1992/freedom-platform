<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Domain\PaymentIntentState;
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

final readonly class PurchaseRefundService
{
    private const PURPOSE = 'purchase';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    /** @requirement PAY-002 PAY-003 WAL-004 DAT-002 DAT-003 QUA-004 */
    public function record(
        string $refundKey,
        string $purchaseSettlementPublicId,
        string $providerCode,
        VerifiedPaymentEvent $event,
        string $correlationId,
    ): PurchaseRefundReceipt {
        $this->assertToken($refundKey, 'Purchase refund key', 8, 128);
        $this->assertUlid($purchaseSettlementPublicId, 'Purchase settlement public ID');
        $this->assertToken($providerCode, 'Payment provider code', 2, 64);
        $this->assertToken($correlationId, 'Purchase refund correlation ID', 8, 64);
        $this->validateRefundEvent($event);

        $payloadHash = $this->payloadHash($purchaseSettlementPublicId, $providerCode, $event);

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $refundKey,
                $purchaseSettlementPublicId,
                $providerCode,
                $event,
                $correlationId,
                $payloadHash,
            ): PurchaseRefundReceipt {
                $settlement = $this->settlementByPublicId($connection, $purchaseSettlementPublicId, true);
                if ($settlement === null) {
                    throw new DomainException('Authoritative purchase settlement does not exist.');
                }

                $intentId = $this->positiveInt($settlement->payment_intent_id, 'Payment intent ID');
                $intent = $this->intentById($connection, $intentId, true);
                if ($intent === null) {
                    throw new RuntimeException('Purchase settlement payment intent is unavailable.');
                }
                $this->assertSettlementIntentAuthority($settlement, $intent, $providerCode, $event->evidence);

                $existing = $this->refundByKey($connection, $refundKey, true);
                if ($existing !== null) {
                    return $this->replayReceipt(
                        $existing,
                        $payloadHash,
                        $purchaseSettlementPublicId,
                        $intent->public_id,
                    );
                }

                $state = $this->intentState($intent->state);
                if (! in_array($state, [PaymentIntentState::Captured, PaymentIntentState::PartiallyRefunded], true)) {
                    throw new DomainException('Purchase payment intent is not refundable from its current state.');
                }

                $settlementAmount = $this->positiveInt($settlement->amount_irr, 'Purchase settlement amount');
                $refundAmount = $event->evidence->amount->amount();
                $alreadyRefunded = $this->nonNegativeInt(
                    $connection->table('purchase_refunds')
                        ->where('purchase_settlement_id', $this->positiveInt($settlement->id, 'Purchase settlement ID'))
                        ->sum('amount_irr'),
                    'Previously refunded purchase amount',
                );
                if ($alreadyRefunded > $settlementAmount || $refundAmount > $settlementAmount - $alreadyRefunded) {
                    throw new DomainException('Purchase refund would exceed authoritative captured amount.');
                }

                $cumulative = $alreadyRefunded + $refundAmount;
                $resultingState = $cumulative === $settlementAmount
                    ? PaymentIntentState::Refunded
                    : PaymentIntentState::PartiallyRefunded;
                $providerEvent = $this->recordProviderEvent($connection, $intentId, $providerCode, $event);
                $publicId = (string) Str::ulid();
                $refundId = (int) $connection->table('purchase_refunds')->insertGetId([
                    'public_id' => $publicId,
                    'refund_key' => $refundKey,
                    'payload_hash' => $payloadHash,
                    'purchase_settlement_id' => $this->positiveInt($settlement->id, 'Purchase settlement ID'),
                    'payment_intent_id' => $intentId,
                    'provider_event_row_id' => $this->positiveInt($providerEvent->id, 'Provider refund event row ID'),
                    'user_id' => $this->positiveInt($settlement->user_id, 'Purchase settlement user ID'),
                    'provider_code' => $providerCode,
                    'provider_refund_id' => $event->evidence->providerTransactionId,
                    'evidence_payload_hash' => strtolower($event->evidence->payloadHash),
                    'amount_irr' => $refundAmount,
                    'cumulative_refunded_irr' => $cumulative,
                    'currency' => $event->evidence->amount->currency(),
                    'resulting_payment_state' => $resultingState->value,
                    'refunded_at' => $this->databaseDateTime($event->evidence->occurredAt),
                    'correlation_id' => $correlationId,
                    'created_at' => $this->timestamp(),
                ]);

                $this->transitionIntent(
                    $connection,
                    $intentId,
                    $state,
                    PaymentIntentState::RefundPending,
                    'authoritative_purchase_refund_received',
                    $correlationId,
                    $refundId,
                );
                $this->transitionIntent(
                    $connection,
                    $intentId,
                    PaymentIntentState::RefundPending,
                    $resultingState,
                    $resultingState === PaymentIntentState::Refunded
                        ? 'purchase_refund_completed_full'
                        : 'purchase_refund_completed_partial',
                    $correlationId,
                    $refundId,
                );

                return new PurchaseRefundReceipt(
                    $refundId,
                    $publicId,
                    $refundKey,
                    $purchaseSettlementPublicId,
                    $intent->public_id,
                    $event->evidence->providerTransactionId,
                    Money::irr($refundAmount),
                    Money::irr($cumulative),
                    $resultingState,
                    $event->evidence->occurredAt,
                    false,
                );
            });
        } catch (QueryException $exception) {
            $connection = $this->database->connection();
            $existing = $this->refundByKey($connection, $refundKey);
            if ($existing !== null) {
                $settlement = $this->settlementById($connection, $this->positiveInt($existing->purchase_settlement_id, 'Purchase settlement ID'));
                $intent = $this->intentById($connection, $this->positiveInt($existing->payment_intent_id, 'Payment intent ID'));
                if ($settlement !== null && $intent !== null) {
                    return $this->replayReceipt($existing, $payloadHash, $settlement->public_id, $intent->public_id);
                }
            }

            $providerConflict = $this->refundByProviderRefundId(
                $connection,
                $providerCode,
                $event->evidence->providerTransactionId,
            );
            if ($providerConflict !== null) {
                throw new RuntimeException('Authoritative provider refund is already bound to another purchase refund key.', 0, $exception);
            }

            throw $exception;
        }
    }

    private function validateRefundEvent(VerifiedPaymentEvent $event): void
    {
        $this->assertPrintableIdentifier($event->providerEventId, 'Payment provider refund event ID', 1, 191);
        $this->assertSha256($event->payloadHash, 'Payment provider refund event payload hash');
        $this->assertPrintableIdentifier($event->evidence->providerTransactionId, 'Payment provider refund transaction ID', 1, 191);
        $this->assertSha256($event->evidence->payloadHash, 'Payment refund evidence payload hash');
        if ($event->evidence->providerEventId !== null
            && ! hash_equals($event->providerEventId, $event->evidence->providerEventId)) {
            throw new DomainException('Verified provider refund event and evidence event IDs do not match.');
        }
        if ($event->evidence->outcome !== ProviderOperationOutcome::Success
            || $event->evidence->authority !== PaymentEvidenceAuthority::Authoritative
            || ! in_array($event->evidence->status, [PaymentTransactionStatus::Refunded, PaymentTransactionStatus::Reversed], true)) {
            throw new DomainException('Only authoritative successful refund evidence can change purchase refund state.');
        }
        if ($event->evidence->amount->currency() !== 'IRR' || $event->evidence->amount->amount() < 1) {
            throw new DomainException('Purchase refund amount must be positive integer IRR.');
        }
        $this->normalizeSafeEvidence($event->evidence->safeEvidence);
    }

    private function settlementByPublicId(Connection $connection, string $publicId, bool $lock = false): ?object
    {
        $query = $connection->table('purchase_settlements')->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first(['id', 'public_id', 'payment_intent_id', 'user_id', 'provider_code', 'amount_irr', 'currency', 'settled_at']);
    }

    private function settlementById(Connection $connection, int $id): ?object
    {
        return $connection->table('purchase_settlements')->where('id', $id)->first([
            'id', 'public_id', 'payment_intent_id', 'user_id', 'provider_code', 'amount_irr', 'currency', 'settled_at',
        ]);
    }

    private function intentById(Connection $connection, int $id, bool $lock = false): ?object
    {
        $query = $connection->table('payment_intents')->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first([
            'id', 'public_id', 'purpose', 'user_id', 'provider_code', 'amount_irr', 'currency', 'state',
            'captured_at', 'latest_purchase_refund_id',
        ]);
    }

    private function assertSettlementIntentAuthority(
        object $settlement,
        object $intent,
        string $providerCode,
        PaymentEvidence $evidence,
    ): void {
        if ($intent->purpose !== self::PURPOSE
            || (int) $intent->id !== (int) $settlement->payment_intent_id
            || (int) $intent->user_id !== (int) $settlement->user_id
            || ! hash_equals($intent->provider_code, $settlement->provider_code)
            || ! hash_equals($providerCode, $settlement->provider_code)
            || (int) $intent->amount_irr !== (int) $settlement->amount_irr
            || $intent->currency !== $settlement->currency
            || $evidence->amount->currency() !== $settlement->currency
            || $intent->captured_at === null) {
            throw new RuntimeException('Purchase refund settlement authority is inconsistent.');
        }
        if ($evidence->occurredAt->setTimezone(new DateTimeZone('UTC')) < $this->storedDateTime($settlement->settled_at)) {
            throw new DomainException('Purchase refund evidence cannot predate authoritative settlement.');
        }
    }

    private function refundByKey(Connection $connection, string $refundKey, bool $lock = false): ?object
    {
        $query = $connection->table('purchase_refunds')->where('refund_key', $refundKey);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first([
            'id', 'public_id', 'refund_key', 'payload_hash', 'purchase_settlement_id', 'payment_intent_id',
            'provider_event_row_id', 'user_id', 'provider_code', 'provider_refund_id', 'evidence_payload_hash',
            'amount_irr', 'cumulative_refunded_irr', 'currency', 'resulting_payment_state', 'refunded_at',
        ]);
    }

    private function refundByProviderRefundId(Connection $connection, string $providerCode, string $providerRefundId): ?object
    {
        return $connection->table('purchase_refunds')
            ->where('provider_code', $providerCode)
            ->where('provider_refund_id', $providerRefundId)
            ->first([
                'id', 'public_id', 'refund_key', 'payload_hash', 'purchase_settlement_id', 'payment_intent_id',
                'provider_event_row_id', 'user_id', 'provider_code', 'provider_refund_id', 'evidence_payload_hash',
                'amount_irr', 'cumulative_refunded_irr', 'currency', 'resulting_payment_state', 'refunded_at',
            ]);
    }

    private function recordProviderEvent(
        Connection $connection,
        int $intentId,
        string $providerCode,
        VerifiedPaymentEvent $event,
    ): object {
        $existing = $connection->table('payment_provider_events')
            ->where('provider_code', $providerCode)
            ->where('provider_event_id', $event->providerEventId)
            ->lockForUpdate()
            ->first([
                'id', 'payment_intent_id', 'provider_code', 'provider_event_id', 'event_payload_hash',
                'provider_transaction_id', 'evidence_payload_hash', 'evidence_authority', 'transaction_status',
                'amount_irr', 'currency', 'occurred_at', 'settled_at',
            ]);
        if ($existing !== null) {
            $this->assertProviderEventMatches($existing, $intentId, $event);

            return $existing;
        }

        $evidence = $event->evidence;
        $eventId = (int) $connection->table('payment_provider_events')->insertGetId([
            'payment_intent_id' => $intentId,
            'provider_code' => $providerCode,
            'provider_event_id' => $event->providerEventId,
            'event_payload_hash' => strtolower($event->payloadHash),
            'provider_transaction_id' => $evidence->providerTransactionId,
            'evidence_payload_hash' => strtolower($evidence->payloadHash),
            'evidence_authority' => $evidence->authority->value,
            'transaction_status' => $evidence->status->value,
            'amount_irr' => $evidence->amount->amount(),
            'currency' => $evidence->amount->currency(),
            'occurred_at' => $this->databaseDateTime($evidence->occurredAt),
            'settled_at' => $evidence->settledAt === null ? null : $this->databaseDateTime($evidence->settledAt),
            'safe_evidence' => json_encode($this->normalizeSafeEvidence($evidence->safeEvidence), JSON_THROW_ON_ERROR),
            'created_at' => $this->timestamp(),
        ]);

        $row = $connection->table('payment_provider_events')->where('id', $eventId)->first([
            'id', 'payment_intent_id', 'provider_code', 'provider_event_id', 'event_payload_hash',
            'provider_transaction_id', 'evidence_payload_hash', 'evidence_authority', 'transaction_status',
            'amount_irr', 'currency', 'occurred_at', 'settled_at',
        ]);
        if ($row === null) {
            throw new RuntimeException('Payment provider refund event persistence failed.');
        }

        return $row;
    }

    private function assertProviderEventMatches(object $row, int $intentId, VerifiedPaymentEvent $event): void
    {
        $evidence = $event->evidence;
        $expectedSettledAt = $evidence->settledAt === null ? null : $this->databaseDateTime($evidence->settledAt);
        if ((int) $row->payment_intent_id !== $intentId
            || ! hash_equals($row->provider_event_id, $event->providerEventId)
            || ! hash_equals(strtolower($row->event_payload_hash), strtolower($event->payloadHash))
            || ! hash_equals($row->provider_transaction_id, $evidence->providerTransactionId)
            || ! hash_equals(strtolower($row->evidence_payload_hash), strtolower($evidence->payloadHash))
            || $row->evidence_authority !== $evidence->authority->value
            || $row->transaction_status !== $evidence->status->value
            || (int) $row->amount_irr !== $evidence->amount->amount()
            || $row->currency !== $evidence->amount->currency()
            || $this->databaseDateTime($this->storedDateTime($row->occurred_at)) !== $this->databaseDateTime($evidence->occurredAt)
            || (($row->settled_at === null) !== ($expectedSettledAt === null))
            || ($row->settled_at !== null && $this->databaseDateTime($this->storedDateTime($row->settled_at)) !== $expectedSettledAt)) {
            throw new RuntimeException('Payment provider refund event replay conflicts with the accepted event.');
        }
    }

    private function replayReceipt(
        object $row,
        string $payloadHash,
        string $purchaseSettlementPublicId,
        string $paymentIntentPublicId,
    ): PurchaseRefundReceipt {
        if (! hash_equals(strtolower($row->payload_hash), $payloadHash)) {
            throw new RuntimeException('Purchase refund key conflict.');
        }

        $state = $this->intentState($row->resulting_payment_state);
        if (! in_array($state, [PaymentIntentState::PartiallyRefunded, PaymentIntentState::Refunded], true)) {
            throw new RuntimeException('Stored purchase refund resulting state is invalid.');
        }

        return new PurchaseRefundReceipt(
            $this->positiveInt($row->id, 'Purchase refund ID'),
            $row->public_id,
            $row->refund_key,
            $purchaseSettlementPublicId,
            $paymentIntentPublicId,
            $row->provider_refund_id,
            Money::irr($this->positiveInt($row->amount_irr, 'Purchase refund amount')),
            Money::irr($this->positiveInt($row->cumulative_refunded_irr, 'Purchase cumulative refund amount')),
            $state,
            $this->storedDateTime($row->refunded_at),
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
        int $refundId,
    ): void {
        $from->transitionTo($to);
        $updated = $connection->table('payment_intents')
            ->where('id', $intentId)
            ->where('state', $from->value)
            ->update([
                'state' => $to->value,
                'latest_purchase_refund_id' => $refundId,
                'updated_at' => $this->timestamp(),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Purchase refund payment state changed concurrently.');
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

    private function payloadHash(string $purchaseSettlementPublicId, string $providerCode, VerifiedPaymentEvent $event): string
    {
        $evidence = $event->evidence;

        return hash('sha256', json_encode([
            'purchase_settlement_public_id' => $purchaseSettlementPublicId,
            'provider_code' => $providerCode,
            'provider_event_id' => $event->providerEventId,
            'event_payload_hash' => strtolower($event->payloadHash),
            'provider_refund_id' => $evidence->providerTransactionId,
            'evidence_payload_hash' => strtolower($evidence->payloadHash),
            'evidence_authority' => $evidence->authority->value,
            'transaction_status' => $evidence->status->value,
            'amount_irr' => $evidence->amount->amount(),
            'currency' => $evidence->amount->currency(),
            'occurred_at' => $this->databaseDateTime($evidence->occurredAt),
            'settled_at' => $evidence->settledAt === null ? null : $this->databaseDateTime($evidence->settledAt),
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, scalar|null> $safeEvidence @return array<string, scalar|null> */
    private function normalizeSafeEvidence(array $safeEvidence): array
    {
        if (count($safeEvidence) > 32) {
            throw new DomainException('Payment refund safe evidence contains too many fields.');
        }

        $normalized = [];
        foreach ($safeEvidence as $key => $value) {
            if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $key) !== 1) {
                throw new DomainException('Payment refund safe evidence key is invalid.');
            }
            if (is_string($value) && strlen($value) > 191) {
                throw new DomainException('Payment refund safe evidence value is too long.');
            }
            $normalized[$key] = $value;
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private function intentState(string $value): PaymentIntentState
    {
        return PaymentIntentState::tryFrom($value)
            ?? throw new RuntimeException('Stored payment intent state is invalid.');
    }

    private function assertUlid(string $value, string $label): void
    {
        if (! Str::isUlid($value)) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertToken(string $value, string $label, int $min, int $max): void
    {
        $length = strlen($value);
        if ($length < $min || $length > $max || preg_match('/\A[A-Za-z0-9:_.-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertPrintableIdentifier(string $value, string $label, int $min, int $max): void
    {
        $length = strlen($value);
        if ($length < $min || $length > $max || preg_match('/\A[\x21-\x7E]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertSha256(string $value, string $label): void
    {
        if (preg_match('/\A[0-9a-fA-F]{64}\z/', $value) !== 1) {
            throw new DomainException($label.' must be a SHA-256 hex digest.');
        }
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($integer === false) {
            throw new RuntimeException($label.' must be a positive integer.');
        }

        return $integer;
    }

    private function nonNegativeInt(mixed $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($integer === false) {
            throw new RuntimeException($label.' must be a non-negative integer.');
        }

        return $integer;
    }

    private function storedDateTime(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new RuntimeException('Stored payment refund timestamp is invalid.');
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
