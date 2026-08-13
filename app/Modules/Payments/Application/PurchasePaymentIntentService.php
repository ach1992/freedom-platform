<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

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
 *     creation_key:string,
 *     payload_hash:string,
 *     purpose:string,
 *     user_id:int|string,
 *     wallet_account_id:int|string|null,
 *     source_quote_id:int|string|null,
 *     source_quote_public_id:string|null,
 *     source_quote_configuration_hash:string|null,
 *     payment_eligibility_decision_id:int|string|null,
 *     payment_eligibility_decision_public_id:string|null,
 *     payment_eligibility_configuration_hash:string|null,
 *     payment_eligibility_method_configuration_hash:string|null,
 *     payment_method_version_id:int|string|null,
 *     payment_method_code:string|null,
 *     payment_method_version:int|string|null,
 *     payment_method_configuration_hash:string|null,
 *     provider_code:string,
 *     amount_irr:int|string,
 *     currency:string,
 *     state:string,
 *     captured_at:string|null
 * }
 * @phpstan-type QuoteRow object{
 *     id:int|string,
 *     public_id:string,
 *     user_id:int|string,
 *     final_price_irr:int|string,
 *     currency:string,
 *     configuration_snapshot_hash:string,
 *     valid_from:string,
 *     expires_at:string
 * }
 * @phpstan-type DecisionRow object{
 *     id:int|string,
 *     public_id:string,
 *     source_quote_id:int|string,
 *     source_quote_public_id:string,
 *     user_id:int|string,
 *     action_snapshot:string,
 *     currency_snapshot:string,
 *     amount_irr_snapshot:int|string,
 *     configuration_snapshot_hash:string,
 *     created_at:string
 * }
 * @phpstan-type DecisionMethodRow object{
 *     payment_method_version_id:int|string,
 *     method_code:string,
 *     method_version:int|string,
 *     route_order:int|string|null,
 *     reason_code:string,
 *     decision_method_configuration_hash:string,
 *     method_configuration_hash:string
 * }
 */
final readonly class PurchasePaymentIntentService
{
    private const PURPOSE = 'purchase';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    /** @requirement BUY-002 PAY-001 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function create(
        string $creationKey,
        int $userId,
        string $sourceQuotePublicId,
        string $eligibilityDecisionPublicId,
        string $methodCode,
        string $correlationId,
    ): PurchasePaymentIntentReceipt {
        $this->assertToken($creationKey, 'Payment intent creation key', 8, 128);
        $this->assertPositiveId($userId, 'Payment intent user ID');
        $this->assertUlid($sourceQuotePublicId, 'Source Quote public ID');
        $this->assertUlid($eligibilityDecisionPublicId, 'Payment eligibility decision public ID');
        $this->assertToken($methodCode, 'Payment method code', 2, 64);
        $this->assertToken($correlationId, 'Payment intent correlation ID', 8, 64);

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $creationKey,
                $userId,
                $sourceQuotePublicId,
                $eligibilityDecisionPublicId,
                $methodCode,
                $correlationId,
            ): PurchasePaymentIntentReceipt {
                $existing = $this->intentByCreationKey($connection, $creationKey, true);
                if ($existing !== null) {
                    return $this->replayReceipt($existing, $userId, $sourceQuotePublicId, $eligibilityDecisionPublicId, $methodCode);
                }

                $now = $this->clock->now();
                $quote = $this->quote($connection, $sourceQuotePublicId);
                $this->assertCurrentOwnedQuote($quote, $userId, $now);
                $decision = $this->decision($connection, $eligibilityDecisionPublicId);
                $this->assertDecisionMatchesQuote($decision, $quote, $userId, $now);
                $method = $this->selectedMethod(
                    $connection,
                    $this->positiveDatabaseInt($decision->id, 'Payment eligibility decision ID'),
                    $methodCode,
                );
                $this->assertSelectedMethod($method);

                $existing = $this->intentByCreationKey($connection, $creationKey, true);
                if ($existing !== null) {
                    return $this->replayReceipt($existing, $userId, $sourceQuotePublicId, $eligibilityDecisionPublicId, $methodCode);
                }

                $payloadHash = $this->creationPayloadHash($userId, $quote, $decision, $method);
                $timestamp = $this->databaseDateTime($now);
                $publicId = (string) Str::ulid();
                $intentId = (int) $connection->table('payment_intents')->insertGetId([
                    'public_id' => $publicId,
                    'creation_key' => $creationKey,
                    'payload_hash' => $payloadHash,
                    'purpose' => self::PURPOSE,
                    'user_id' => $userId,
                    'wallet_account_id' => null,
                    'source_quote_id' => $this->positiveDatabaseInt($quote->id, 'Source Quote ID'),
                    'source_quote_public_id' => $quote->public_id,
                    'source_quote_configuration_hash' => strtolower($quote->configuration_snapshot_hash),
                    'payment_eligibility_decision_id' => $this->positiveDatabaseInt($decision->id, 'Payment eligibility decision ID'),
                    'payment_eligibility_decision_public_id' => $decision->public_id,
                    'payment_eligibility_configuration_hash' => strtolower($decision->configuration_snapshot_hash),
                    'payment_eligibility_method_configuration_hash' => strtolower($method->decision_method_configuration_hash),
                    'payment_method_version_id' => $this->positiveDatabaseInt($method->payment_method_version_id, 'Payment method version ID'),
                    'payment_method_code' => $method->method_code,
                    'payment_method_version' => $this->positiveDatabaseInt($method->method_version, 'Payment method version'),
                    'payment_method_configuration_hash' => strtolower($method->method_configuration_hash),
                    'provider_code' => $method->method_code,
                    'amount_irr' => $this->positiveDatabaseInt($quote->final_price_irr, 'Source Quote final price'),
                    'currency' => $quote->currency,
                    'state' => PaymentIntentState::Created->value,
                    'creation_correlation_id' => $correlationId,
                    'captured_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                $this->recordStateHistory($connection, $intentId, null, PaymentIntentState::Created, 'purchase_intent_created', $correlationId);
                $this->transitionIntent(
                    $connection,
                    $intentId,
                    PaymentIntentState::Created,
                    PaymentIntentState::AwaitingUserAction,
                    'awaiting_user_action',
                    $correlationId,
                );

                return new PurchasePaymentIntentReceipt(
                    $publicId,
                    PaymentIntentState::AwaitingUserAction,
                    $userId,
                    $quote->public_id,
                    $decision->public_id,
                    $method->method_code,
                    $this->positiveDatabaseInt($method->method_version, 'Payment method version'),
                    Money::irr($this->positiveDatabaseInt($quote->final_price_irr, 'Source Quote final price')),
                    false,
                );
            });
        } catch (QueryException $exception) {
            $existing = $this->intentByCreationKey($this->database->connection(), $creationKey);
            if ($existing !== null) {
                return $this->replayReceipt($existing, $userId, $sourceQuotePublicId, $eligibilityDecisionPublicId, $methodCode);
            }

            throw $exception;
        }
    }

    /** @return PurchaseIntentRow|null */
    private function intentByCreationKey(Connection $connection, string $creationKey, bool $lock = false): ?object
    {
        $query = $connection->table('payment_intents')->where('creation_key', $creationKey);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var PurchaseIntentRow|null $row */
        $row = $query->first([
            'id', 'public_id', 'creation_key', 'payload_hash', 'purpose', 'user_id', 'wallet_account_id',
            'source_quote_id', 'source_quote_public_id', 'source_quote_configuration_hash',
            'payment_eligibility_decision_id', 'payment_eligibility_decision_public_id',
            'payment_eligibility_configuration_hash', 'payment_eligibility_method_configuration_hash',
            'payment_method_version_id', 'payment_method_code', 'payment_method_version',
            'payment_method_configuration_hash', 'provider_code', 'amount_irr', 'currency', 'state', 'captured_at',
        ]);

        return $row;
    }

    /** @return QuoteRow */
    private function quote(Connection $connection, string $publicId): object
    {
        /** @var QuoteRow|null $row */
        $row = $connection->table('quotes')->where('public_id', $publicId)->first([
            'id', 'public_id', 'user_id', 'final_price_irr', 'currency',
            'configuration_snapshot_hash', 'valid_from', 'expires_at',
        ]);
        if ($row === null) {
            throw new DomainException('Purchase payment requires an existing Quote.');
        }

        return $row;
    }

    /** @param QuoteRow $quote */
    private function assertCurrentOwnedQuote(object $quote, int $userId, DateTimeImmutable $now): void
    {
        if ((int) $quote->user_id !== $userId) {
            throw new DomainException('Purchase payment Quote owner does not match user.');
        }
        if ($quote->currency !== 'IRR' || (int) $quote->final_price_irr < 1) {
            throw new DomainException('Purchase payment requires a positive IRR Quote.');
        }
        $this->assertSha256($quote->configuration_snapshot_hash, 'Source Quote configuration hash');
        $validFrom = $this->storedDateTime($quote->valid_from, 'Source Quote valid-from timestamp');
        $expiresAt = $this->storedDateTime($quote->expires_at, 'Source Quote expiry timestamp');
        if ($validFrom > $now || $expiresAt <= $now) {
            throw new DomainException('Purchase payment requires a current Quote.');
        }
    }

    /** @return DecisionRow */
    private function decision(Connection $connection, string $publicId): object
    {
        /** @var DecisionRow|null $row */
        $row = $connection->table('payment_method_eligibility_decisions')->where('public_id', $publicId)->first([
            'id', 'public_id', 'source_quote_id', 'source_quote_public_id', 'user_id',
            'action_snapshot', 'currency_snapshot', 'amount_irr_snapshot', 'configuration_snapshot_hash', 'created_at',
        ]);
        if ($row === null) {
            throw new DomainException('Purchase payment requires an eligibility decision.');
        }

        return $row;
    }

    /**
     * @param DecisionRow $decision
     * @param QuoteRow $quote
     */
    private function assertDecisionMatchesQuote(object $decision, object $quote, int $userId, DateTimeImmutable $now): void
    {
        if ((int) $decision->user_id !== $userId
            || (int) $decision->source_quote_id !== (int) $quote->id
            || ! hash_equals($decision->source_quote_public_id, $quote->public_id)
            || $decision->action_snapshot !== 'purchase'
            || $decision->currency_snapshot !== $quote->currency
            || (int) $decision->amount_irr_snapshot !== (int) $quote->final_price_irr) {
            throw new DomainException('Payment eligibility decision does not match the purchase Quote.');
        }
        $this->assertSha256($decision->configuration_snapshot_hash, 'Payment eligibility configuration hash');
        if ($this->storedDateTime($decision->created_at, 'Payment eligibility decision timestamp') > $now) {
            throw new DomainException('Payment eligibility decision timestamp is invalid.');
        }
    }

    /** @return DecisionMethodRow */
    private function selectedMethod(Connection $connection, int $decisionId, string $methodCode): object
    {
        /** @var DecisionMethodRow|null $row */
        $row = $connection->table('payment_method_eligibility_decision_methods as decision_method')
            ->join('payment_method_versions as method_version', 'method_version.id', '=', 'decision_method.payment_method_version_id')
            ->where('decision_method.payment_method_eligibility_decision_id', $decisionId)
            ->where('decision_method.method_code', $methodCode)
            ->first([
                'decision_method.payment_method_version_id', 'decision_method.method_code',
                'decision_method.method_version', 'decision_method.route_order', 'decision_method.reason_code',
                'decision_method.configuration_snapshot_hash as decision_method_configuration_hash',
                'method_version.configuration_snapshot_hash as method_configuration_hash',
            ]);
        if ($row === null) {
            throw new DomainException('Payment method was not selected by the eligibility decision.');
        }

        return $row;
    }

    /** @param DecisionMethodRow $method */
    private function assertSelectedMethod(object $method): void
    {
        if ($method->route_order === null || (int) $method->route_order < 1 || $method->reason_code !== 'eligible') {
            throw new DomainException('Payment method was not selected by the eligibility decision.');
        }
        $this->assertSha256($method->decision_method_configuration_hash, 'Payment eligibility method configuration hash');
        $this->assertSha256($method->method_configuration_hash, 'Payment method configuration hash');
    }

    /** @param PurchaseIntentRow $row */
    private function replayReceipt(
        object $row,
        int $userId,
        string $sourceQuotePublicId,
        string $eligibilityDecisionPublicId,
        string $methodCode,
    ): PurchasePaymentIntentReceipt {
        if ($row->purpose !== self::PURPOSE
            || (int) $row->user_id !== $userId
            || $row->wallet_account_id !== null
            || $row->source_quote_public_id === null
            || ! hash_equals($row->source_quote_public_id, $sourceQuotePublicId)
            || $row->payment_eligibility_decision_public_id === null
            || ! hash_equals($row->payment_eligibility_decision_public_id, $eligibilityDecisionPublicId)
            || $row->payment_method_code === null
            || ! hash_equals($row->payment_method_code, $methodCode)
            || ! hash_equals($row->provider_code, $methodCode)
            || $row->currency !== 'IRR') {
            throw new RuntimeException('Payment intent creation key conflict.');
        }

        $payloadHash = $this->storedPayloadHash($row);
        if (! hash_equals(strtolower($row->payload_hash), $payloadHash)) {
            throw new RuntimeException('Stored purchase payment intent integrity check failed.');
        }

        return new PurchasePaymentIntentReceipt(
            $row->public_id,
            $this->intentState($row->state),
            $this->positiveDatabaseInt($row->user_id, 'Payment intent user ID'),
            $sourceQuotePublicId,
            $eligibilityDecisionPublicId,
            $methodCode,
            $this->positiveDatabaseInt($row->payment_method_version, 'Payment method version'),
            Money::irr($this->positiveDatabaseInt($row->amount_irr, 'Payment intent amount')),
            true,
        );
    }

    /**
     * @param QuoteRow $quote
     * @param DecisionRow $decision
     * @param DecisionMethodRow $method
     */
    private function creationPayloadHash(int $userId, object $quote, object $decision, object $method): string
    {
        return hash('sha256', json_encode([
            'purpose' => self::PURPOSE,
            'user_id' => $userId,
            'source_quote_id' => $this->positiveDatabaseInt($quote->id, 'Source Quote ID'),
            'source_quote_public_id' => $quote->public_id,
            'source_quote_configuration_hash' => strtolower($quote->configuration_snapshot_hash),
            'payment_eligibility_decision_id' => $this->positiveDatabaseInt($decision->id, 'Payment eligibility decision ID'),
            'payment_eligibility_decision_public_id' => $decision->public_id,
            'payment_eligibility_configuration_hash' => strtolower($decision->configuration_snapshot_hash),
            'payment_eligibility_method_configuration_hash' => strtolower($method->decision_method_configuration_hash),
            'payment_method_version_id' => $this->positiveDatabaseInt($method->payment_method_version_id, 'Payment method version ID'),
            'payment_method_code' => $method->method_code,
            'payment_method_version' => $this->positiveDatabaseInt($method->method_version, 'Payment method version'),
            'payment_method_configuration_hash' => strtolower($method->method_configuration_hash),
            'provider_code' => $method->method_code,
            'amount_irr' => $this->positiveDatabaseInt($quote->final_price_irr, 'Source Quote final price'),
            'currency' => $quote->currency,
        ], JSON_THROW_ON_ERROR));
    }

    /** @param PurchaseIntentRow $row */
    private function storedPayloadHash(object $row): string
    {
        $sourceQuotePublicId = $this->requiredString($row->source_quote_public_id, 'Source Quote public ID');
        $sourceQuoteConfigurationHash = $this->requiredString($row->source_quote_configuration_hash, 'Source Quote configuration hash');
        $decisionPublicId = $this->requiredString($row->payment_eligibility_decision_public_id, 'Payment eligibility decision public ID');
        $decisionConfigurationHash = $this->requiredString($row->payment_eligibility_configuration_hash, 'Payment eligibility configuration hash');
        $decisionMethodConfigurationHash = $this->requiredString($row->payment_eligibility_method_configuration_hash, 'Payment eligibility method configuration hash');
        $methodCode = $this->requiredString($row->payment_method_code, 'Payment method code');
        $methodConfigurationHash = $this->requiredString($row->payment_method_configuration_hash, 'Payment method configuration hash');

        return hash('sha256', json_encode([
            'purpose' => self::PURPOSE,
            'user_id' => $this->positiveDatabaseInt($row->user_id, 'Payment intent user ID'),
            'source_quote_id' => $this->positiveDatabaseInt($row->source_quote_id, 'Source Quote ID'),
            'source_quote_public_id' => $sourceQuotePublicId,
            'source_quote_configuration_hash' => strtolower($sourceQuoteConfigurationHash),
            'payment_eligibility_decision_id' => $this->positiveDatabaseInt($row->payment_eligibility_decision_id, 'Payment eligibility decision ID'),
            'payment_eligibility_decision_public_id' => $decisionPublicId,
            'payment_eligibility_configuration_hash' => strtolower($decisionConfigurationHash),
            'payment_eligibility_method_configuration_hash' => strtolower($decisionMethodConfigurationHash),
            'payment_method_version_id' => $this->positiveDatabaseInt($row->payment_method_version_id, 'Payment method version ID'),
            'payment_method_code' => $methodCode,
            'payment_method_version' => $this->positiveDatabaseInt($row->payment_method_version, 'Payment method version'),
            'payment_method_configuration_hash' => strtolower($methodConfigurationHash),
            'provider_code' => $row->provider_code,
            'amount_irr' => $this->positiveDatabaseInt($row->amount_irr, 'Payment intent amount'),
            'currency' => $row->currency,
        ], JSON_THROW_ON_ERROR));
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
            ->update(['state' => $to->value, 'updated_at' => $this->timestamp()]);
        if ($updated !== 1) {
            throw new RuntimeException('Payment intent state transition failed.');
        }

        $this->recordStateHistory($connection, $intentId, $from, $to, $reasonCode, $correlationId);
    }

    private function recordStateHistory(
        Connection $connection,
        int $intentId,
        ?PaymentIntentState $from,
        PaymentIntentState $to,
        string $reasonCode,
        string $correlationId,
    ): void {
        $connection->table('payment_intent_state_histories')->insert([
            'payment_intent_id' => $intentId,
            'from_state' => $from?->value,
            'to_state' => $to->value,
            'reason_code' => $reasonCode,
            'correlation_id' => $correlationId,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function intentState(string $state): PaymentIntentState
    {
        return PaymentIntentState::tryFrom($state)
            ?? throw new RuntimeException('Stored payment intent state is invalid.');
    }

    private function assertPositiveId(int $value, string $label): void
    {
        if ($value < 1) {
            throw new DomainException($label.' must be positive.');
        }
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

    private function assertSha256(string $value, string $label): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/i', $value) !== 1) {
            throw new RuntimeException($label.' is invalid.');
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

    private function storedDateTime(string $value, string $label): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception $exception) {
            throw new RuntimeException($label.' is invalid.', previous: $exception);
        }
    }

    private function databaseDateTime(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
