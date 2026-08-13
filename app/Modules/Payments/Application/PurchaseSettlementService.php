<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Application\Contracts\PaymentEvidence;
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

/**
 * @phpstan-type PurchaseIntentRow object{
 *     id:int|string,
 *     public_id:string,
 *     purpose:string,
 *     user_id:int|string,
 *     wallet_account_id:int|string|null,
 *     source_quote_id:int|string|null,
 *     source_quote_public_id:string|null,
 *     provider_code:string,
 *     amount_irr:int|string,
 *     currency:string,
 *     state:string,
 *     captured_at:string|null
 * }
 * @phpstan-type ProviderEventRow object{
 *     id:int|string,
 *     payment_intent_id:int|string,
 *     provider_code:string,
 *     provider_event_id:string,
 *     event_payload_hash:string,
 *     provider_transaction_id:string,
 *     evidence_payload_hash:string,
 *     evidence_authority:string,
 *     transaction_status:string,
 *     amount_irr:int|string,
 *     currency:string
 * }
 * @phpstan-type ProviderTransactionRow object{
 *     id:int|string,
 *     payment_intent_id:int|string,
 *     provider_event_row_id:int|string,
 *     provider_code:string,
 *     provider_transaction_id:string,
 *     evidence_payload_hash:string,
 *     transaction_status:string,
 *     amount_irr:int|string,
 *     currency:string,
 *     settled_at:string
 * }
 * @phpstan-type PurchaseSettlementRow object{
 *     id:int|string,
 *     public_id:string,
 *     payment_intent_id:int|string,
 *     provider_transaction_row_id:int|string,
 *     user_id:int|string,
 *     source_quote_id:int|string,
 *     source_quote_public_id:string,
 *     provider_code:string,
 *     provider_transaction_id:string,
 *     evidence_payload_hash:string,
 *     amount_irr:int|string,
 *     currency:string,
 *     settled_at:string
 * }
 */
final readonly class PurchaseSettlementService
{
    private const PURPOSE = 'purchase';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    /** @requirement PAY-002 PAY-003 PAY-004 PAY-005 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function capture(
        string $intentPublicId,
        string $providerCode,
        VerifiedPaymentEvent $event,
        string $correlationId,
    ): PurchaseSettlementReceipt {
        $this->assertUlid($intentPublicId, 'Payment intent public ID');
        $this->assertToken($providerCode, 'Payment provider code', 2, 64);
        $this->assertToken($correlationId, 'Payment capture correlation ID', 8, 64);
        $this->validateCaptureEvent($event);

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $intentPublicId,
                $providerCode,
                $event,
                $correlationId,
            ): PurchaseSettlementReceipt {
                $intent = $this->intentByPublicId($connection, $intentPublicId, true);
                if ($intent === null) {
                    throw new DomainException('Payment intent does not exist.');
                }
                $this->assertCaptureMatchesIntent($intent, $providerCode, $event->evidence);
                $intentId = $this->positiveDatabaseInt($intent->id, 'Payment intent ID');

                $providerEvent = $this->recordProviderEvent($connection, $intentId, $providerCode, $event);
                $existingSettlement = $this->settlementByIntent($connection, $intentId, true);
                if ($existingSettlement !== null) {
                    return $this->replaySettlement(
                        $connection,
                        $intent,
                        $existingSettlement,
                        $providerCode,
                        $event,
                    );
                }

                $state = $this->intentState($intent->state);
                if (! in_array($state, [
                    PaymentIntentState::Submitted,
                    PaymentIntentState::Verifying,
                    PaymentIntentState::PendingManualReview,
                    PaymentIntentState::Authorized,
                ], true)) {
                    throw new RuntimeException('Purchase payment intent is not ready for authoritative capture.');
                }

                $providerTransaction = $this->recordProviderTransaction(
                    $connection,
                    $intentId,
                    $providerEvent,
                    $providerCode,
                    $event->evidence,
                );
                $sourceQuoteId = $this->positiveDatabaseInt($intent->source_quote_id, 'Purchase source Quote ID');
                $sourceQuotePublicId = $this->requiredString($intent->source_quote_public_id, 'Purchase source Quote public ID');
                $userId = $this->positiveDatabaseInt($intent->user_id, 'Payment intent user ID');
                $settlementPublicId = (string) Str::ulid();
                $settlementId = (int) $connection->table('purchase_settlements')->insertGetId([
                    'public_id' => $settlementPublicId,
                    'payment_intent_id' => $intentId,
                    'provider_transaction_row_id' => $this->positiveDatabaseInt($providerTransaction->id, 'Provider transaction row ID'),
                    'user_id' => $userId,
                    'source_quote_id' => $sourceQuoteId,
                    'source_quote_public_id' => $sourceQuotePublicId,
                    'provider_code' => $providerCode,
                    'provider_transaction_id' => $event->evidence->providerTransactionId,
                    'evidence_payload_hash' => strtolower($event->evidence->payloadHash),
                    'amount_irr' => $event->evidence->amount->amount(),
                    'currency' => $event->evidence->amount->currency(),
                    'settled_at' => $this->databaseDateTime($event->evidence->settledAt ?? throw new RuntimeException('Payment settlement timestamp is missing.')),
                    'created_at' => $this->timestamp(),
                ]);

                $this->advanceToCaptured($connection, $intentId, $state, $correlationId);
                $connection->table('payment_attempts')->insert([
                    'payment_intent_id' => $intentId,
                    'attempt_key' => hash('sha256', $providerCode."\0".$event->providerEventId),
                    'provider_code' => $providerCode,
                    'state' => PaymentIntentState::Captured->value,
                    'created_at' => $this->timestamp(),
                ]);
                $this->recordCaptureAudit(
                    $connection,
                    $intentPublicId,
                    $sourceQuotePublicId,
                    $providerCode,
                    $event,
                    $settlementId,
                    $correlationId,
                );

                return new PurchaseSettlementReceipt(
                    $settlementId,
                    $settlementPublicId,
                    $intentPublicId,
                    PaymentIntentState::Captured,
                    $userId,
                    $sourceQuotePublicId,
                    $providerCode,
                    $event->providerEventId,
                    $event->evidence->providerTransactionId,
                    Money::irr($event->evidence->amount->amount()),
                    $event->evidence->settledAt ?? throw new RuntimeException('Payment settlement timestamp is missing.'),
                    false,
                );
            });
        } catch (QueryException $exception) {
            $replay = $this->captureReplayAfterUniqueRace($intentPublicId, $providerCode, $event);
            if ($replay !== null) {
                return $replay;
            }

            throw $exception;
        }
    }

    /** @return PurchaseIntentRow|null */
    private function intentByPublicId(Connection $connection, string $publicId, bool $lock = false): ?object
    {
        $query = $connection->table('payment_intents')->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var PurchaseIntentRow|null $row */
        $row = $query->first([
            'id', 'public_id', 'purpose', 'user_id', 'wallet_account_id', 'source_quote_id',
            'source_quote_public_id', 'provider_code', 'amount_irr', 'currency', 'state', 'captured_at',
        ]);

        return $row;
    }

    private function validateCaptureEvent(VerifiedPaymentEvent $event): void
    {
        $this->assertPrintableIdentifier($event->providerEventId, 'Payment provider event ID', 1, 191);
        $this->assertSha256($event->payloadHash, 'Payment provider event payload hash');
        $this->assertPrintableIdentifier($event->evidence->providerTransactionId, 'Payment provider transaction ID', 1, 191);
        $this->assertSha256($event->evidence->payloadHash, 'Payment evidence payload hash');
        if ($event->evidence->providerEventId !== null
            && ! hash_equals($event->providerEventId, $event->evidence->providerEventId)) {
            throw new DomainException('Verified provider event and payment evidence event IDs do not match.');
        }
        if (! $event->evidence->authorizesCapture()) {
            throw new DomainException('Non-authoritative payment evidence cannot capture a purchase.');
        }
        if ($event->evidence->settledAt === null) {
            throw new DomainException('Authoritative settled payment evidence requires a settlement timestamp.');
        }
        if ($event->evidence->amount->currency() !== 'IRR' || $event->evidence->amount->amount() < 1) {
            throw new DomainException('Payment capture amount must be positive integer IRR.');
        }
        $this->normalizeSafeEvidence($event->evidence->safeEvidence);
    }

    /** @param PurchaseIntentRow $intent */
    private function assertCaptureMatchesIntent(object $intent, string $providerCode, PaymentEvidence $evidence): void
    {
        if ($intent->purpose !== self::PURPOSE || $intent->wallet_account_id !== null) {
            throw new RuntimeException('Payment intent purpose is not purchase settlement.');
        }
        if (! hash_equals($intent->provider_code, $providerCode)) {
            throw new RuntimeException('Payment provider does not match the immutable intent.');
        }
        if ($intent->currency !== $evidence->amount->currency()
            || (int) $intent->amount_irr !== $evidence->amount->amount()) {
            throw new RuntimeException('Authoritative provider evidence amount does not match the payment intent.');
        }
        $this->positiveDatabaseInt($intent->source_quote_id, 'Purchase source Quote ID');
        $this->requiredString($intent->source_quote_public_id, 'Purchase source Quote public ID');
    }

    /** @return ProviderEventRow */
    private function recordProviderEvent(
        Connection $connection,
        int $intentId,
        string $providerCode,
        VerifiedPaymentEvent $event,
    ): object {
        /** @var ProviderEventRow|null $existing */
        $existing = $connection->table('payment_provider_events')
            ->where('provider_code', $providerCode)
            ->where('provider_event_id', $event->providerEventId)
            ->lockForUpdate()
            ->first([
                'id', 'payment_intent_id', 'provider_code', 'provider_event_id', 'event_payload_hash',
                'provider_transaction_id', 'evidence_payload_hash', 'evidence_authority',
                'transaction_status', 'amount_irr', 'currency',
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
            'settled_at' => $this->databaseDateTime($evidence->settledAt ?? throw new RuntimeException('Payment settlement timestamp is missing.')),
            'safe_evidence' => json_encode($this->normalizeSafeEvidence($evidence->safeEvidence), JSON_THROW_ON_ERROR),
            'created_at' => $this->timestamp(),
        ]);

        /** @var ProviderEventRow|null $row */
        $row = $connection->table('payment_provider_events')->where('id', $eventId)->first([
            'id', 'payment_intent_id', 'provider_code', 'provider_event_id', 'event_payload_hash',
            'provider_transaction_id', 'evidence_payload_hash', 'evidence_authority',
            'transaction_status', 'amount_irr', 'currency',
        ]);
        if ($row === null) {
            throw new RuntimeException('Payment provider event persistence failed.');
        }

        return $row;
    }

    /** @param ProviderEventRow $row */
    private function assertProviderEventMatches(object $row, int $intentId, VerifiedPaymentEvent $event): void
    {
        $evidence = $event->evidence;
        if ((int) $row->payment_intent_id !== $intentId
            || ! hash_equals($row->provider_event_id, $event->providerEventId)
            || ! hash_equals(strtolower($row->event_payload_hash), strtolower($event->payloadHash))
            || ! hash_equals($row->provider_transaction_id, $evidence->providerTransactionId)
            || ! hash_equals(strtolower($row->evidence_payload_hash), strtolower($evidence->payloadHash))
            || $row->evidence_authority !== $evidence->authority->value
            || $row->transaction_status !== $evidence->status->value
            || (int) $row->amount_irr !== $evidence->amount->amount()
            || $row->currency !== $evidence->amount->currency()) {
            throw new RuntimeException('Payment provider event replay conflicts with the accepted event.');
        }
    }

    /**
     * @param  ProviderEventRow  $providerEvent
     * @return ProviderTransactionRow
     */
    private function recordProviderTransaction(
        Connection $connection,
        int $intentId,
        object $providerEvent,
        string $providerCode,
        PaymentEvidence $evidence,
    ): object {
        /** @var ProviderTransactionRow|null $existing */
        $existing = $connection->table('payment_provider_transactions')
            ->where('provider_code', $providerCode)
            ->where('provider_transaction_id', $evidence->providerTransactionId)
            ->lockForUpdate()
            ->first([
                'id', 'payment_intent_id', 'provider_event_row_id', 'provider_code', 'provider_transaction_id',
                'evidence_payload_hash', 'transaction_status', 'amount_irr', 'currency', 'settled_at',
            ]);
        if ($existing !== null) {
            if ((int) $existing->payment_intent_id !== $intentId
                || ! hash_equals(strtolower($existing->evidence_payload_hash), strtolower($evidence->payloadHash))
                || $existing->transaction_status !== $evidence->status->value
                || (int) $existing->amount_irr !== $evidence->amount->amount()
                || $existing->currency !== $evidence->amount->currency()) {
                throw new RuntimeException('Payment provider transaction conflicts with an accepted transaction.');
            }

            return $existing;
        }

        $transactionId = (int) $connection->table('payment_provider_transactions')->insertGetId([
            'payment_intent_id' => $intentId,
            'provider_event_row_id' => $this->positiveDatabaseInt($providerEvent->id, 'Provider event row ID'),
            'provider_code' => $providerCode,
            'provider_transaction_id' => $evidence->providerTransactionId,
            'evidence_payload_hash' => strtolower($evidence->payloadHash),
            'transaction_status' => $evidence->status->value,
            'amount_irr' => $evidence->amount->amount(),
            'currency' => $evidence->amount->currency(),
            'occurred_at' => $this->databaseDateTime($evidence->occurredAt),
            'settled_at' => $this->databaseDateTime($evidence->settledAt ?? throw new RuntimeException('Payment settlement timestamp is missing.')),
            'created_at' => $this->timestamp(),
        ]);

        /** @var ProviderTransactionRow|null $row */
        $row = $connection->table('payment_provider_transactions')->where('id', $transactionId)->first([
            'id', 'payment_intent_id', 'provider_event_row_id', 'provider_code', 'provider_transaction_id',
            'evidence_payload_hash', 'transaction_status', 'amount_irr', 'currency', 'settled_at',
        ]);
        if ($row === null) {
            throw new RuntimeException('Payment provider transaction persistence failed.');
        }

        return $row;
    }

    /** @return PurchaseSettlementRow|null */
    private function settlementByIntent(Connection $connection, int $intentId, bool $lock = false): ?object
    {
        $query = $connection->table('purchase_settlements')->where('payment_intent_id', $intentId);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var PurchaseSettlementRow|null $row */
        $row = $query->first([
            'id', 'public_id', 'payment_intent_id', 'provider_transaction_row_id', 'user_id',
            'source_quote_id', 'source_quote_public_id', 'provider_code', 'provider_transaction_id',
            'evidence_payload_hash', 'amount_irr', 'currency', 'settled_at',
        ]);

        return $row;
    }

    /**
     * @param  PurchaseIntentRow  $intent
     * @param  PurchaseSettlementRow  $settlement
     */
    private function replaySettlement(
        Connection $connection,
        object $intent,
        object $settlement,
        string $providerCode,
        VerifiedPaymentEvent $event,
    ): PurchaseSettlementReceipt {
        if ($this->intentState($intent->state) !== PaymentIntentState::Captured || $intent->captured_at === null) {
            throw new RuntimeException('Purchase settlement exists without a captured payment intent.');
        }
        $evidence = $event->evidence;
        if (! hash_equals($settlement->provider_code, $providerCode)
            || ! hash_equals($settlement->provider_transaction_id, $evidence->providerTransactionId)
            || ! hash_equals(strtolower($settlement->evidence_payload_hash), strtolower($evidence->payloadHash))
            || (int) $settlement->amount_irr !== $evidence->amount->amount()
            || $settlement->currency !== $evidence->amount->currency()) {
            throw new RuntimeException('Purchase settlement replay conflicts with the accepted settlement.');
        }

        /** @var ProviderTransactionRow|null $providerTransaction */
        $providerTransaction = $connection->table('payment_provider_transactions')
            ->where('id', $settlement->provider_transaction_row_id)
            ->first([
                'id', 'payment_intent_id', 'provider_event_row_id', 'provider_code', 'provider_transaction_id',
                'evidence_payload_hash', 'transaction_status', 'amount_irr', 'currency', 'settled_at',
            ]);
        if ($providerTransaction === null
            || (int) $providerTransaction->payment_intent_id !== (int) $intent->id
            || ! hash_equals($providerTransaction->provider_transaction_id, $evidence->providerTransactionId)
            || ! hash_equals(strtolower($providerTransaction->evidence_payload_hash), strtolower($evidence->payloadHash))) {
            throw new RuntimeException('Stored purchase settlement integrity check failed.');
        }

        return new PurchaseSettlementReceipt(
            $this->positiveDatabaseInt($settlement->id, 'Purchase settlement ID'),
            $settlement->public_id,
            $intent->public_id,
            PaymentIntentState::Captured,
            $this->positiveDatabaseInt($settlement->user_id, 'Purchase settlement user ID'),
            $settlement->source_quote_public_id,
            $settlement->provider_code,
            $event->providerEventId,
            $settlement->provider_transaction_id,
            Money::irr($this->positiveDatabaseInt($settlement->amount_irr, 'Purchase settlement amount')),
            $this->storedDateTime($settlement->settled_at, 'Purchase settlement settled-at'),
            true,
        );
    }

    private function advanceToCaptured(
        Connection $connection,
        int $intentId,
        PaymentIntentState $current,
        string $correlationId,
    ): void {
        $path = match ($current) {
            PaymentIntentState::Submitted => [PaymentIntentState::Verifying, PaymentIntentState::Captured],
            PaymentIntentState::Verifying => [PaymentIntentState::Captured],
            PaymentIntentState::PendingManualReview => [PaymentIntentState::Verifying, PaymentIntentState::Captured],
            PaymentIntentState::Authorized => [PaymentIntentState::Captured],
            default => throw new RuntimeException('Purchase payment intent is not ready for authoritative capture.'),
        };

        $from = $current;
        foreach ($path as $to) {
            $from->transitionTo($to);
            $update = ['state' => $to->value, 'updated_at' => $this->timestamp()];
            if ($to === PaymentIntentState::Captured) {
                $update['captured_at'] = $this->timestamp();
            }
            $updated = $connection->table('payment_intents')
                ->where('id', $intentId)
                ->where('state', $from->value)
                ->update($update);
            if ($updated !== 1) {
                throw new RuntimeException('Payment intent state transition failed.');
            }
            $connection->table('payment_intent_state_histories')->insert([
                'payment_intent_id' => $intentId,
                'from_state' => $from->value,
                'to_state' => $to->value,
                'reason_code' => $to === PaymentIntentState::Captured ? 'authoritative_purchase_capture' : 'purchase_capture_verification',
                'correlation_id' => $correlationId,
                'created_at' => $this->timestamp(),
            ]);
            $from = $to;
        }
    }

    private function recordCaptureAudit(
        Connection $connection,
        string $intentPublicId,
        string $sourceQuotePublicId,
        string $providerCode,
        VerifiedPaymentEvent $event,
        int $settlementId,
        string $correlationId,
    ): void {
        $connection->table('audit_logs')->insert([
            'actor_type' => 'system',
            'actor_id' => null,
            'action' => 'payment.purchase.captured',
            'target_type' => 'payment_intent',
            'target_id' => $intentPublicId,
            'before_safe_data' => null,
            'after_safe_data' => json_encode([
                'source_quote_public_id' => $sourceQuotePublicId,
                'provider_code' => $providerCode,
                'provider_event_id' => $event->providerEventId,
                'provider_transaction_id' => $event->evidence->providerTransactionId,
                'amount_irr' => $event->evidence->amount->amount(),
                'settlement_id' => $settlementId,
            ], JSON_THROW_ON_ERROR),
            'reason_code' => 'authoritative_purchase_capture',
            'reason' => null,
            'correlation_id' => $correlationId,
            'request_fingerprint' => null,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function captureReplayAfterUniqueRace(
        string $intentPublicId,
        string $providerCode,
        VerifiedPaymentEvent $event,
    ): ?PurchaseSettlementReceipt {
        $connection = $this->database->connection();
        $intent = $this->intentByPublicId($connection, $intentPublicId);
        if ($intent === null) {
            return null;
        }
        $settlement = $this->settlementByIntent($connection, $this->positiveDatabaseInt($intent->id, 'Payment intent ID'));
        if ($settlement === null) {
            return null;
        }

        try {
            $this->assertCaptureMatchesIntent($intent, $providerCode, $event->evidence);

            return $this->replaySettlement($connection, $intent, $settlement, $providerCode, $event);
        } catch (DomainException|RuntimeException) {
            return null;
        }
    }

    /**
     * @param  array<string, scalar|null>  $safeEvidence
     * @return array<string, scalar|null>
     */
    private function normalizeSafeEvidence(array $safeEvidence): array
    {
        $normalized = [];
        $forbidden = ['secret', 'token', 'password', 'authorization', 'cookie', 'signature', 'private', 'cvv', 'pan', 'raw_body', 'raw_payload'];
        foreach ($safeEvidence as $key => $value) {
            if (strlen($key) < 1 || strlen($key) > 64 || preg_match('/\A[A-Za-z0-9._:-]+\z/', $key) !== 1) {
                throw new DomainException('Payment safe-evidence key is invalid.');
            }
            $lowerKey = strtolower($key);
            foreach ($forbidden as $needle) {
                if (str_contains($lowerKey, $needle)) {
                    throw new DomainException('Payment safe evidence contains a forbidden sensitive field.');
                }
            }
            if (is_string($value) && (strlen($value) > 512 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1)) {
                throw new DomainException('Payment safe-evidence value is invalid.');
            }
            $normalized[$key] = $value;
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private function intentState(string $state): PaymentIntentState
    {
        return PaymentIntentState::tryFrom($state)
            ?? throw new RuntimeException('Stored payment intent state is invalid.');
    }

    private function assertUlid(string $value, string $label): void
    {
        if (strlen($value) !== 26 || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum || preg_match('/\A[A-Za-z0-9._:-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertPrintableIdentifier(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum || $value !== trim($value) || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertSha256(string $value, string $label): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/i', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function requiredString(?string $value, string $label): string
    {
        if ($value === null || $value === '') {
            throw new RuntimeException($label.' is invalid.');
        }

        return $value;
    }

    private function positiveDatabaseInt(int|string|null $value, string $label): int
    {
        if ($value === null || (is_string($value) && preg_match('/\A[0-9]+\z/', $value) !== 1)) {
            throw new RuntimeException($label.' is invalid.');
        }
        $integer = (int) $value;
        if ($integer < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }

    private function databaseDateTime(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function storedDateTime(string $value, string $label): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception $exception) {
            throw new RuntimeException($label.' is invalid.', previous: $exception);
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
