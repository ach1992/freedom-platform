<?php

declare(strict_types=1);

namespace App\Modules\Payments\NowPayments\Application;

use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\PurchaseSettlementService;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Payments\NowPayments\Application\Contracts\NowPaymentsCreateRequest;
use App\Modules\Payments\NowPayments\Application\Contracts\NowPaymentsPaymentResult;
use App\Modules\Payments\NowPayments\Application\Contracts\NowPaymentsTransport;
use App\Modules\Payments\NowPayments\Application\Contracts\NowPaymentsTransportException;
use App\Modules\Payments\Usdt\Application\UsdtRateResolver;
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
use Throwable;

final readonly class NowPaymentsPaymentService
{
    private const PROVIDER_CODE = 'nowpayments';

    private const PRICING_POLICY_CODE = 'shared_usdt_rate_as_usd_proxy_v1';

    /** @var list<string> */
    private const IN_PROGRESS_STATUSES = ['waiting', 'confirming', 'confirmed', 'spending'];

    public function __construct(
        private DatabaseManager $database,
        private NowPaymentsTransport $transport,
        private UsdtRateResolver $rates,
        private PurchaseSettlementService $settlements,
        private Clock $clock,
    ) {}

    /** @requirement IPG-002 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 SEC-002 INT-001 INT-002 QUA-001 QUA-004 */
    public function create(string $intentPublicId, string $requestKey, string $correlationId): NowPaymentsPaymentReceipt
    {
        $this->assertUlid($intentPublicId, 'Payment intent public ID');
        $this->assertToken($requestKey, 'NOWPayments request key', 8, 128);
        $this->assertToken($correlationId, 'NOWPayments correlation ID', 8, 64);
        $configuration = $this->configuration();

        $connection = $this->database->connection();
        $intent = $this->intentByPublicId($connection, $intentPublicId);
        if ($intent === null) {
            throw new DomainException('Payment intent does not exist.');
        }
        $this->assertEligibleIntent($intent);
        $intentId = $this->positiveInt($intent->id, 'Payment intent ID');
        $existing = $this->authorityByIntentId($connection, $intentId);
        if ($existing !== null) {
            if (! hash_equals((string) $existing->request_key, $requestKey)) {
                throw new DomainException('NOWPayments payment intent is already bound to another initiation request.');
            }

            return $this->receipt($existing, true);
        }
        if ($this->intentState((string) $intent->state) !== PaymentIntentState::AwaitingUserAction) {
            throw new DomainException('Payment intent is not ready for NOWPayments initiation.');
        }

        $rate = $this->rates->resolve();
        $amountIrr = $this->positiveInt($intent->amount_irr, 'Payment intent amount');
        $priceAmountUsd = NowPaymentsDecimal::irrToUsdProxy($amountIrr, $rate->rateIrr);
        $orderId = 'payment-intent:'.$intentPublicId;
        $payloadHash = $this->requestPayloadHash(
            $intentPublicId,
            $amountIrr,
            $rate->source,
            $rate->rateIrr,
            $rate->fetchedAt,
            $rate->responseHash,
            $priceAmountUsd,
            $configuration['pay_currency'],
            $configuration['callback_url'],
        );

        [$authority, $claimed] = $connection->transaction(function (Connection $transaction) use (
            $intentPublicId,
            $requestKey,
            $correlationId,
            $amountIrr,
            $rate,
            $priceAmountUsd,
            $orderId,
            $payloadHash,
            $configuration,
        ): array {
            $lockedIntent = $this->intentByPublicId($transaction, $intentPublicId, true);
            if ($lockedIntent === null) {
                throw new DomainException('Payment intent does not exist.');
            }
            $this->assertEligibleIntent($lockedIntent);
            if ($this->positiveInt($lockedIntent->amount_irr, 'Payment intent amount') !== $amountIrr
                || $this->intentState((string) $lockedIntent->state) !== PaymentIntentState::AwaitingUserAction) {
                throw new DomainException('Payment intent changed before NOWPayments initiation was claimed.');
            }

            $lockedIntentId = $this->positiveInt($lockedIntent->id, 'Payment intent ID');
            $existingAuthority = $this->authorityByIntentId($transaction, $lockedIntentId, true);
            if ($existingAuthority !== null) {
                if (! hash_equals((string) $existingAuthority->request_key, $requestKey)
                    || ! hash_equals((string) $existingAuthority->request_payload_hash, $payloadHash)) {
                    throw new DomainException('NOWPayments initiation replay conflicts with persisted authority.');
                }

                return [$existingAuthority, false];
            }

            $now = $this->timestamp();
            $authorityId = (int) $transaction->table('nowpayments_payment_authorities')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'request_key' => $requestKey,
                'payment_intent_id' => $lockedIntentId,
                'order_id' => $orderId,
                'state' => NowPaymentsAuthorityState::Initiating->value,
                'amount_irr' => $amountIrr,
                'currency' => 'IRR',
                'rate_source' => $rate->source,
                'rate_irr' => $rate->rateIrr,
                'rate_fetched_at' => $this->databaseDateTime($rate->fetchedAt),
                'rate_response_hash' => strtolower($rate->responseHash),
                'pricing_policy_code' => self::PRICING_POLICY_CODE,
                'price_amount_usd' => $priceAmountUsd,
                'price_currency' => 'USD',
                'pay_currency' => $configuration['pay_currency'],
                'callback_url' => $configuration['callback_url'],
                'request_payload_hash' => $payloadHash,
                'provider_payment_id' => null,
                'provider_status' => null,
                'provider_pay_amount' => null,
                'provider_actually_paid' => null,
                'provider_pay_address' => null,
                'create_response_hash' => null,
                'create_attempted_at' => $now,
                'provider_created_at' => null,
                'last_status_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($authorityId < 1) {
                throw new RuntimeException('NOWPayments authority persistence failed.');
            }
            $created = $this->authorityById($transaction, $authorityId);
            if ($created === null) {
                throw new RuntimeException('NOWPayments authority disappeared after persistence.');
            }

            $this->audit(
                $transaction,
                (string) $lockedIntent->public_id,
                'payment.nowpayments.create_claimed',
                'nowpayments_create_claimed',
                [
                    'rate_source' => $rate->source,
                    'rate_irr' => $rate->rateIrr,
                    'price_amount_usd' => $priceAmountUsd,
                    'price_currency' => 'USD',
                    'pay_currency' => $configuration['pay_currency'],
                    'pricing_policy_code' => self::PRICING_POLICY_CODE,
                ],
                $correlationId,
            );

            return [$created, true];
        });

        if (! $claimed) {
            return $this->receipt($authority, true);
        }

        try {
            $result = $this->transport->create(new NowPaymentsCreateRequest(
                (string) $authority->price_amount_usd,
                (string) $authority->pay_currency,
                (string) $authority->order_id,
                'Freedom purchase '.$intentPublicId,
                (string) $authority->callback_url,
            ));
        } catch (NowPaymentsTransportException $exception) {
            return $this->recordCreateFailure($authority, $exception->uncertain, $correlationId);
        } catch (Throwable) {
            return $this->recordCreateFailure($authority, true, $correlationId);
        }

        return $this->acceptCreateResult($authority, $result, $correlationId);
    }

    /** @requirement IPG-002 PAY-002 PAY-003 DAT-003 DAT-004 INT-001 INT-002 QUA-004 */
    public function refresh(string $intentPublicId, string $correlationId): NowPaymentsPaymentReceipt
    {
        $this->assertUlid($intentPublicId, 'Payment intent public ID');
        $this->assertToken($correlationId, 'NOWPayments reconciliation correlation ID', 8, 64);
        $this->configuration();

        $authority = $this->authorityByIntentPublicId($this->database->connection(), $intentPublicId);
        if ($authority === null) {
            throw new DomainException('NOWPayments authority does not exist.');
        }
        $state = $this->authorityState((string) $authority->state);
        if ($authority->provider_payment_id === null) {
            if ($state === NowPaymentsAuthorityState::Uncertain || $state === NowPaymentsAuthorityState::ManualReview) {
                return $this->markManualReviewWithoutProviderId($authority, $correlationId);
            }

            return $this->receipt($authority, true);
        }

        try {
            $result = $this->transport->status((string) $authority->provider_payment_id);
        } catch (Throwable) {
            $this->database->connection()->transaction(function (Connection $connection) use ($authority, $correlationId): void {
                $current = $this->requiredAuthority($connection, $this->positiveInt($authority->id, 'NOWPayments authority ID'), true);
                $this->observe($connection, $current, 'provider_unavailable', null, hash('sha256', 'provider-unavailable'), $correlationId);
            });

            return $this->receipt($this->requiredAuthority($this->database->connection(), (int) $authority->id), true);
        }

        $mismatch = $this->statusMismatchCode($authority, $result);
        if ($mismatch !== null) {
            return $this->recordMismatch($authority, $result, $mismatch, $correlationId);
        }

        return $this->applyStatus($authority, $result, $correlationId);
    }

    /** @requirement IPG-002 INT-001 INT-002 QUA-004 */
    public function reconcile(string $authorityPublicId, string $correlationId): NowPaymentsPaymentReceipt
    {
        $this->assertUlid($authorityPublicId, 'NOWPayments authority public ID');
        $this->assertToken($correlationId, 'NOWPayments reconciliation correlation ID', 8, 64);
        $authority = $this->authorityByPublicId($this->database->connection(), $authorityPublicId);
        if ($authority === null) {
            throw new DomainException('NOWPayments authority does not exist.');
        }

        return $this->refresh($this->intentPublicIdForAuthority($authority), $correlationId);
    }

    private function acceptCreateResult(object $authority, NowPaymentsPaymentResult $result, string $correlationId): NowPaymentsPaymentReceipt
    {
        $mismatch = $this->createMismatchCode($authority, $result);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $authority,
            $result,
            $mismatch,
            $correlationId,
        ): NowPaymentsPaymentReceipt {
            $current = $this->requiredAuthority($connection, (int) $authority->id, true);
            if ($this->authorityState((string) $current->state) !== NowPaymentsAuthorityState::Initiating) {
                return $this->receipt($current, true);
            }

            $updated = $connection->table('nowpayments_payment_authorities')
                ->where('id', (int) $current->id)
                ->where('state', NowPaymentsAuthorityState::Initiating->value)
                ->update([
                    'state' => NowPaymentsAuthorityState::Created->value,
                    'provider_payment_id' => $result->providerPaymentId,
                    'provider_status' => $result->paymentStatus,
                    'provider_pay_amount' => $result->payAmount,
                    'provider_actually_paid' => $result->actuallyPaid,
                    'provider_pay_address' => $result->payAddress,
                    'create_response_hash' => strtolower($result->responseHash),
                    'provider_created_at' => $result->createdAt === null ? null : $this->databaseDateTime($result->createdAt),
                    'last_status_at' => $result->updatedAt === null ? null : $this->databaseDateTime($result->updatedAt),
                    'updated_at' => $this->timestamp(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('NOWPayments create result raced with another writer.');
            }
            $fresh = $this->requiredAuthority($connection, (int) $current->id, true);
            $this->observe($connection, $fresh, 'create_response', $result->paymentStatus, $result->responseHash, $correlationId);

            if ($mismatch !== null) {
                $this->transitionAuthority($connection, $fresh, NowPaymentsAuthorityState::ManualReview);
                $manual = $this->requiredAuthority($connection, (int) $fresh->id, true);
                $this->ensureIntentManualReview($connection, (int) $manual->payment_intent_id, $correlationId);
                $this->observe($connection, $manual, 'mismatch', $result->paymentStatus, $result->responseHash, $correlationId);
                $this->finding($connection, $manual, $mismatch, 'high', $result->paymentStatus, $result->responseHash, $correlationId);

                return $this->receipt($manual, false);
            }

            if ($this->intentStateForId($connection, (int) $fresh->payment_intent_id) !== PaymentIntentState::AwaitingUserAction) {
                throw new RuntimeException('NOWPayments purchase intent changed during provider creation.');
            }

            return $this->receipt($fresh, false);
        });
    }

    private function recordCreateFailure(object $authority, bool $uncertain, string $correlationId): NowPaymentsPaymentReceipt
    {
        return $this->database->connection()->transaction(function (Connection $connection) use (
            $authority,
            $uncertain,
            $correlationId,
        ): NowPaymentsPaymentReceipt {
            $current = $this->requiredAuthority($connection, (int) $authority->id, true);
            if ($this->authorityState((string) $current->state) !== NowPaymentsAuthorityState::Initiating) {
                return $this->receipt($current, true);
            }

            $to = $uncertain ? NowPaymentsAuthorityState::Uncertain : NowPaymentsAuthorityState::Failed;
            $this->transitionAuthority($connection, $current, $to);
            $fresh = $this->requiredAuthority($connection, (int) $current->id, true);
            $hash = hash('sha256', $uncertain ? 'create-uncertain' : 'create-rejected');
            $this->observe(
                $connection,
                $fresh,
                $uncertain ? 'create_uncertain' : 'create_response',
                null,
                $hash,
                $correlationId,
            );

            if ($uncertain) {
                $this->ensureIntentManualReview($connection, (int) $fresh->payment_intent_id, $correlationId);
                $this->finding(
                    $connection,
                    $fresh,
                    'create_outcome_unknown_without_provider_id',
                    'high',
                    null,
                    $hash,
                    $correlationId,
                );
            } else {
                $this->ensureIntentFailed(
                    $connection,
                    (int) $fresh->payment_intent_id,
                    $correlationId,
                    'nowpayments_create_rejected',
                );
            }

            return $this->receipt($fresh, false);
        });
    }

    private function applyStatus(object $authority, NowPaymentsPaymentResult $result, string $correlationId): NowPaymentsPaymentReceipt
    {
        $capture = false;
        $settlementEvent = null;

        $fresh = $this->database->connection()->transaction(function (Connection $connection) use (
            $authority,
            $result,
            $correlationId,
            &$capture,
            &$settlementEvent,
        ): object {
            $current = $this->requiredAuthority($connection, (int) $authority->id, true);
            $state = $this->authorityState((string) $current->state);
            $this->observe($connection, $current, 'status_lookup', $result->paymentStatus, $result->responseHash, $correlationId);

            if ($state === NowPaymentsAuthorityState::Finished && $result->paymentStatus !== 'finished') {
                $this->finding(
                    $connection,
                    $current,
                    'post_settlement_provider_status_changed',
                    'critical',
                    $result->paymentStatus,
                    $result->responseHash,
                    $correlationId,
                );

                return $current;
            }
            if (in_array($state, [NowPaymentsAuthorityState::Failed, NowPaymentsAuthorityState::Expired], true)
                && $result->paymentStatus === 'finished') {
                $this->finding(
                    $connection,
                    $current,
                    'terminal_local_state_conflicts_with_finished_provider',
                    'critical',
                    $result->paymentStatus,
                    $result->responseHash,
                    $correlationId,
                );

                return $current;
            }

            $connection->table('nowpayments_payment_authorities')
                ->where('id', (int) $current->id)
                ->update([
                    'provider_status' => $result->paymentStatus,
                    'provider_actually_paid' => $result->actuallyPaid,
                    'last_status_at' => $this->databaseDateTime($result->updatedAt ?? $this->clock->now()),
                    'updated_at' => $this->timestamp(),
                ]);
            $current = $this->requiredAuthority($connection, (int) $current->id, true);

            if (in_array($result->paymentStatus, self::IN_PROGRESS_STATUSES, true)) {
                if ($result->paymentStatus !== 'waiting') {
                    $this->ensureIntentVerifying($connection, (int) $current->payment_intent_id, $correlationId);
                }

                return $current;
            }

            if ($result->paymentStatus === 'partially_paid') {
                $current = $this->moveAuthorityToManualReview($connection, $current);
                $this->ensureIntentManualReview($connection, (int) $current->payment_intent_id, $correlationId);
                $this->finding($connection, $current, 'partially_paid', 'high', $result->paymentStatus, $result->responseHash, $correlationId);

                return $current;
            }

            if ($result->paymentStatus === 'refunded') {
                $current = $this->moveAuthorityToManualReview($connection, $current);
                $this->ensureIntentManualReview($connection, (int) $current->payment_intent_id, $correlationId);
                $this->finding($connection, $current, 'provider_refunded_before_capture', 'high', $result->paymentStatus, $result->responseHash, $correlationId);

                return $current;
            }

            if ($result->paymentStatus === 'failed') {
                if (in_array($state, [NowPaymentsAuthorityState::Created, NowPaymentsAuthorityState::ManualReview], true)) {
                    $this->transitionAuthority($connection, $current, NowPaymentsAuthorityState::Failed);
                    $current = $this->requiredAuthority($connection, (int) $current->id, true);
                }
                $this->ensureIntentFailed($connection, (int) $current->payment_intent_id, $correlationId, 'nowpayments_provider_failed');

                return $current;
            }

            if ($result->paymentStatus === 'expired') {
                if (in_array($state, [NowPaymentsAuthorityState::Created, NowPaymentsAuthorityState::ManualReview], true)) {
                    $this->transitionAuthority($connection, $current, NowPaymentsAuthorityState::Expired);
                    $current = $this->requiredAuthority($connection, (int) $current->id, true);
                }
                $this->ensureIntentExpiredOrFailed($connection, (int) $current->payment_intent_id, $correlationId);

                return $current;
            }

            if ($result->paymentStatus !== 'finished') {
                $current = $this->moveAuthorityToManualReview($connection, $current);
                $this->ensureIntentManualReview($connection, (int) $current->payment_intent_id, $correlationId);
                $this->finding($connection, $current, 'unsupported_provider_status', 'high', $result->paymentStatus, $result->responseHash, $correlationId);

                return $current;
            }

            if ($result->payAmount === null
                || $result->actuallyPaid === null
                || ! NowPaymentsDecimal::equals($result->payAmount, $result->actuallyPaid, 18)) {
                $severity = $state === NowPaymentsAuthorityState::Finished ? 'critical' : 'high';
                $current = $this->moveAuthorityToManualReview($connection, $current);
                $this->ensureIntentManualReview($connection, (int) $current->payment_intent_id, $correlationId);
                $this->finding(
                    $connection,
                    $current,
                    'finished_amount_not_exact',
                    $severity,
                    $result->paymentStatus,
                    $result->responseHash,
                    $correlationId,
                );

                return $current;
            }

            $state = $this->authorityState((string) $current->state);
            if ($state === NowPaymentsAuthorityState::Created || $state === NowPaymentsAuthorityState::ManualReview) {
                $this->transitionAuthority($connection, $current, NowPaymentsAuthorityState::Finished);
                $current = $this->requiredAuthority($connection, (int) $current->id, true);
            }
            $this->ensureIntentSubmitted($connection, (int) $current->payment_intent_id, $correlationId);

            $settledAt = $result->updatedAt ?? $this->clock->now();
            $providerPaymentId = (string) $current->provider_payment_id;
            $amountIrr = $this->positiveInt($current->amount_irr, 'NOWPayments authority amount');
            $rateSource = (string) $current->rate_source;
            $payCurrency = (string) $current->pay_currency;
            $priceAmountUsd = NowPaymentsDecimal::normalize($result->priceAmount, NowPaymentsDecimal::PRICE_PRECISION);
            $providerPayAmount = NowPaymentsDecimal::normalize($result->payAmount, 18);
            $settlementEvidenceHash = hash('sha256', json_encode([
                'provider_code' => self::PROVIDER_CODE,
                'provider_payment_id' => $providerPaymentId,
                'amount_irr' => $amountIrr,
                'currency' => 'IRR',
                'price_amount_usd' => $priceAmountUsd,
                'pay_currency' => $payCurrency,
                'provider_pay_amount' => $providerPayAmount,
                'rate_source' => $rateSource,
                'provider_status' => 'finished',
            ], JSON_THROW_ON_ERROR));
            $eventId = 'settlement:'.$settlementEvidenceHash;
            $settlementEvent = new VerifiedPaymentEvent(
                $eventId,
                $settlementEvidenceHash,
                new PaymentEvidence(
                    ProviderOperationOutcome::Success,
                    PaymentEvidenceAuthority::Authoritative,
                    PaymentTransactionStatus::Settled,
                    $providerPaymentId,
                    $eventId,
                    Money::irr($amountIrr),
                    $settledAt,
                    $settledAt,
                    $settlementEvidenceHash,
                    [
                        'provider_status' => 'finished',
                        'pricing_policy_code' => self::PRICING_POLICY_CODE,
                        'rate_source' => $rateSource,
                        'price_currency' => 'USD',
                        'pay_currency' => $payCurrency,
                    ],
                ),
            );
            $capture = true;

            return $current;
        });

        if ($capture && $settlementEvent instanceof VerifiedPaymentEvent) {
            try {
                $this->settlements->capture(
                    $this->intentPublicIdForAuthority($fresh),
                    self::PROVIDER_CODE,
                    $settlementEvent,
                    $correlationId,
                );
            } catch (Throwable $exception) {
                $this->database->connection()->transaction(function (Connection $connection) use (
                    $fresh,
                    $result,
                    $correlationId,
                ): void {
                    $current = $this->requiredAuthority($connection, (int) $fresh->id, true);
                    $this->finding(
                        $connection,
                        $current,
                        'finished_provider_payment_not_captured_locally',
                        'critical',
                        $result->paymentStatus,
                        $result->responseHash,
                        $correlationId,
                    );
                });
                throw $exception;
            }
        }

        return $this->receipt($this->requiredAuthority($this->database->connection(), (int) $fresh->id), false);
    }

    private function recordMismatch(
        object $authority,
        NowPaymentsPaymentResult $result,
        string $code,
        string $correlationId,
    ): NowPaymentsPaymentReceipt {
        return $this->database->connection()->transaction(function (Connection $connection) use (
            $authority,
            $result,
            $code,
            $correlationId,
        ): NowPaymentsPaymentReceipt {
            $current = $this->requiredAuthority($connection, (int) $authority->id, true);
            $state = $this->authorityState((string) $current->state);
            $this->observe($connection, $current, 'mismatch', $result->paymentStatus, $result->responseHash, $correlationId);
            $this->finding(
                $connection,
                $current,
                $code,
                $state === NowPaymentsAuthorityState::Finished ? 'critical' : 'high',
                $result->paymentStatus,
                $result->responseHash,
                $correlationId,
            );

            if ($state === NowPaymentsAuthorityState::Created) {
                $this->transitionAuthority($connection, $current, NowPaymentsAuthorityState::ManualReview);
                $current = $this->requiredAuthority($connection, (int) $current->id, true);
            }
            if (in_array($this->authorityState((string) $current->state), [NowPaymentsAuthorityState::Created, NowPaymentsAuthorityState::ManualReview], true)) {
                $this->ensureIntentManualReview($connection, (int) $current->payment_intent_id, $correlationId);
            }

            return $this->receipt($current, false);
        });
    }

    private function markManualReviewWithoutProviderId(object $authority, string $correlationId): NowPaymentsPaymentReceipt
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($authority, $correlationId): NowPaymentsPaymentReceipt {
            $current = $this->requiredAuthority($connection, (int) $authority->id, true);
            if ($this->authorityState((string) $current->state) === NowPaymentsAuthorityState::Uncertain) {
                $this->transitionAuthority($connection, $current, NowPaymentsAuthorityState::ManualReview);
                $current = $this->requiredAuthority($connection, (int) $current->id, true);
            }
            $this->ensureIntentManualReview($connection, (int) $current->payment_intent_id, $correlationId);

            $hash = hash('sha256', 'uncertain-without-provider-id');
            $this->observe($connection, $current, 'manual_review', null, $hash, $correlationId);
            $this->finding(
                $connection,
                $current,
                'uncertain_create_requires_operator_reconciliation',
                'high',
                null,
                $hash,
                $correlationId,
            );

            return $this->receipt($current, false);
        });
    }

    private function createMismatchCode(object $authority, NowPaymentsPaymentResult $result): ?string
    {
        if (! hash_equals((string) $authority->order_id, $result->orderId)) {
            return 'create_order_id_mismatch';
        }
        if ($result->priceCurrency !== 'USD') {
            return 'create_price_currency_mismatch';
        }
        if (! NowPaymentsDecimal::equals((string) $authority->price_amount_usd, $result->priceAmount, 8)) {
            return 'create_price_amount_mismatch';
        }
        if (! hash_equals(strtolower((string) $authority->pay_currency), strtolower($result->payCurrency))) {
            return 'create_pay_currency_mismatch';
        }
        if ($result->payAmount === null) {
            return 'create_pay_amount_missing';
        }
        if ($result->payAddress === null || trim($result->payAddress) === '') {
            return 'create_pay_address_missing';
        }

        return null;
    }

    private function statusMismatchCode(object $authority, NowPaymentsPaymentResult $result): ?string
    {
        if (! hash_equals((string) $authority->provider_payment_id, $result->providerPaymentId)) {
            return 'status_payment_id_mismatch';
        }
        if (! hash_equals((string) $authority->order_id, $result->orderId)) {
            return 'status_order_id_mismatch';
        }
        if ($result->priceCurrency !== 'USD') {
            return 'status_price_currency_mismatch';
        }
        if (! NowPaymentsDecimal::equals((string) $authority->price_amount_usd, $result->priceAmount, 8)) {
            return 'status_price_amount_mismatch';
        }
        if (! hash_equals(strtolower((string) $authority->pay_currency), strtolower($result->payCurrency))) {
            return 'status_pay_currency_mismatch';
        }
        if ($result->payAmount === null || $authority->provider_pay_amount === null
            || ! NowPaymentsDecimal::equals((string) $authority->provider_pay_amount, $result->payAmount, 18)) {
            return 'status_pay_amount_mismatch';
        }

        return null;
    }

    private function moveAuthorityToManualReview(Connection $connection, object $authority): object
    {
        $state = $this->authorityState((string) $authority->state);
        if ($state === NowPaymentsAuthorityState::ManualReview) {
            return $authority;
        }
        if ($state === NowPaymentsAuthorityState::Created || $state === NowPaymentsAuthorityState::Uncertain) {
            $this->transitionAuthority($connection, $authority, NowPaymentsAuthorityState::ManualReview);

            return $this->requiredAuthority($connection, (int) $authority->id, true);
        }

        return $authority;
    }

    private function transitionAuthority(Connection $connection, object $authority, NowPaymentsAuthorityState $to): void
    {
        $from = $this->authorityState((string) $authority->state);
        if ($from === $to) {
            return;
        }
        $from->transitionTo($to);
        $updated = $connection->table('nowpayments_payment_authorities')
            ->where('id', $this->positiveInt($authority->id, 'NOWPayments authority ID'))
            ->where('state', $from->value)
            ->update([
                'state' => $to->value,
                'updated_at' => $this->timestamp(),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('NOWPayments authority state changed concurrently.');
        }
    }

    private function ensureIntentSubmitted(Connection $connection, int $intentId, string $correlationId): void
    {
        $state = $this->intentStateForId($connection, $intentId);
        if ($state === PaymentIntentState::AwaitingUserAction) {
            $this->transitionIntent(
                $connection,
                $intentId,
                PaymentIntentState::AwaitingUserAction,
                PaymentIntentState::Submitted,
                'nowpayments_server_status_observed_payment',
                $correlationId,
            );
        }
    }

    private function ensureIntentVerifying(Connection $connection, int $intentId, string $correlationId): void
    {
        $this->ensureIntentSubmitted($connection, $intentId, $correlationId);
        if ($this->intentStateForId($connection, $intentId) === PaymentIntentState::Submitted) {
            $this->transitionIntent(
                $connection,
                $intentId,
                PaymentIntentState::Submitted,
                PaymentIntentState::Verifying,
                'nowpayments_provider_payment_detected',
                $correlationId,
            );
        }
    }

    private function ensureIntentManualReview(Connection $connection, int $intentId, string $correlationId): void
    {
        $state = $this->intentStateForId($connection, $intentId);
        if ($state === PaymentIntentState::AwaitingUserAction) {
            $this->transitionIntent(
                $connection,
                $intentId,
                $state,
                PaymentIntentState::Submitted,
                'nowpayments_review_payment_observed',
                $correlationId,
            );
            $state = PaymentIntentState::Submitted;
        }
        if ($state === PaymentIntentState::Submitted) {
            $this->transitionIntent(
                $connection,
                $intentId,
                $state,
                PaymentIntentState::Verifying,
                'nowpayments_review_started',
                $correlationId,
            );
            $state = PaymentIntentState::Verifying;
        }
        if ($state === PaymentIntentState::Verifying) {
            $this->transitionIntent(
                $connection,
                $intentId,
                $state,
                PaymentIntentState::PendingManualReview,
                'nowpayments_manual_review_required',
                $correlationId,
            );
        }
    }

    private function ensureIntentFailed(Connection $connection, int $intentId, string $correlationId, string $reason): void
    {
        $state = $this->intentStateForId($connection, $intentId);
        if ($state === PaymentIntentState::AwaitingUserAction) {
            $this->transitionIntent(
                $connection,
                $intentId,
                $state,
                PaymentIntentState::Submitted,
                'nowpayments_terminal_status_observed',
                $correlationId,
            );
            $state = PaymentIntentState::Submitted;
        }
        if (in_array($state, [PaymentIntentState::Submitted, PaymentIntentState::Verifying, PaymentIntentState::PendingManualReview], true)) {
            $this->transitionIntent($connection, $intentId, $state, PaymentIntentState::Failed, $reason, $correlationId);
        }
    }

    private function ensureIntentExpiredOrFailed(Connection $connection, int $intentId, string $correlationId): void
    {
        $state = $this->intentStateForId($connection, $intentId);
        if ($state === PaymentIntentState::AwaitingUserAction) {
            $this->transitionIntent(
                $connection,
                $intentId,
                $state,
                PaymentIntentState::Expired,
                'nowpayments_provider_expired',
                $correlationId,
            );

            return;
        }
        if (in_array($state, [PaymentIntentState::Submitted, PaymentIntentState::Verifying, PaymentIntentState::PendingManualReview], true)) {
            $this->transitionIntent(
                $connection,
                $intentId,
                $state,
                PaymentIntentState::Failed,
                'nowpayments_provider_expired_after_submission',
                $correlationId,
            );
        }
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
            ->update([
                'state' => $to->value,
                'updated_at' => $this->timestamp(),
            ]);
        if ($updated !== 1) {
            $actual = $connection->table('payment_intents')->where('id', $intentId)->value('state');
            if ($actual === $to->value) {
                return;
            }
            throw new RuntimeException('NOWPayments payment intent state changed concurrently.');
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

    private function observe(
        Connection $connection,
        object $authority,
        string $eventType,
        ?string $providerStatus,
        string $responseHash,
        string $correlationId,
    ): void {
        $responseHash = strtolower($responseHash);
        $this->assertSha256($responseHash, 'NOWPayments observation response hash');
        $eventKey = 'nowpayments:'.(string) $authority->id.':'.$eventType.':'.substr($responseHash, 0, 32);
        if ($connection->table('nowpayments_payment_observations')->where('event_key', $eventKey)->exists()) {
            return;
        }

        try {
            $connection->table('nowpayments_payment_observations')->insert([
                'nowpayments_payment_authority_id' => (int) $authority->id,
                'event_key' => $eventKey,
                'event_type' => $eventType,
                'provider_payment_id' => $authority->provider_payment_id,
                'provider_status' => $providerStatus,
                'response_hash' => $responseHash,
                'occurred_at' => $this->timestamp(),
                'correlation_id' => $correlationId,
                'created_at' => $this->timestamp(),
            ]);
        } catch (QueryException $exception) {
            if (! $connection->table('nowpayments_payment_observations')->where('event_key', $eventKey)->exists()) {
                throw $exception;
            }
        }
    }

    private function finding(
        Connection $connection,
        object $authority,
        string $code,
        string $severity,
        ?string $providerStatus,
        string $evidenceHash,
        string $correlationId,
    ): void {
        $this->assertToken($code, 'NOWPayments finding code', 2, 64);
        if (! in_array($severity, ['medium', 'high', 'critical'], true)) {
            throw new DomainException('NOWPayments finding severity is invalid.');
        }
        $this->assertSha256($evidenceHash, 'NOWPayments finding evidence hash');
        $findingKey = 'nowpayments:'.(string) $authority->id.':'.$code.':'.substr(strtolower($evidenceHash), 0, 32);
        if ($connection->table('nowpayments_reconciliation_findings')->where('finding_key', $findingKey)->exists()) {
            return;
        }

        try {
            $connection->table('nowpayments_reconciliation_findings')->insert([
                'nowpayments_payment_authority_id' => (int) $authority->id,
                'finding_key' => $findingKey,
                'code' => $code,
                'severity' => $severity,
                'provider_status' => $providerStatus,
                'evidence_hash' => strtolower($evidenceHash),
                'detected_at' => $this->timestamp(),
                'correlation_id' => $correlationId,
                'created_at' => $this->timestamp(),
            ]);
        } catch (QueryException $exception) {
            if (! $connection->table('nowpayments_reconciliation_findings')->where('finding_key', $findingKey)->exists()) {
                throw $exception;
            }
        }
    }

    /** @param array<string, scalar|null> $after */
    private function audit(
        Connection $connection,
        string $targetId,
        string $action,
        string $reasonCode,
        array $after,
        string $correlationId,
    ): void {
        $connection->table('audit_logs')->insert([
            'actor_type' => 'system',
            'actor_id' => null,
            'action' => $action,
            'target_type' => 'payment_intent',
            'target_id' => $targetId,
            'before_safe_data' => null,
            'after_safe_data' => json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'reason_code' => $reasonCode,
            'reason' => null,
            'correlation_id' => $correlationId,
            'request_fingerprint' => null,
            'created_at' => $this->timestamp(),
        ]);
    }

    /** @return array{callback_url:string,pay_currency:string,ipn_secret:string,max_ipn_body_bytes:int} */
    private function configuration(): array
    {
        if (! (bool) config('services.nowpayments.enabled', false)) {
            throw new RuntimeException('NOWPayments payment provider is disabled.');
        }
        $callbackUrl = config('services.nowpayments.ipn_callback_url');
        if (! is_string($callbackUrl) || strlen($callbackUrl) > 512 || filter_var($callbackUrl, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('NOWPayments IPN callback URL configuration is invalid.');
        }
        $parts = parse_url($callbackUrl);
        $appParts = parse_url((string) config('app.url'));
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || ! is_array($appParts)
            || ! isset($appParts['host'])
            || strcasecmp((string) $parts['host'], (string) $appParts['host']) !== 0) {
            throw new RuntimeException('NOWPayments IPN callback URL must use the configured application origin over HTTPS.');
        }
        $payCurrency = config('services.nowpayments.pay_currency');
        if (! is_string($payCurrency) || preg_match('/\A[a-zA-Z0-9_-]{2,32}\z/', $payCurrency) !== 1) {
            throw new RuntimeException('NOWPayments pay currency configuration is invalid.');
        }
        $secret = config('services.nowpayments.ipn_secret');
        if (! is_string($secret) || trim($secret) === '') {
            throw new RuntimeException('NOWPayments IPN secret configuration is invalid.');
        }
        $maxIpnBodyBytes = filter_var(
            config('services.nowpayments.max_ipn_body_bytes', 262144),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1024, 'max_range' => 1048576]],
        );
        if ($maxIpnBodyBytes === false) {
            throw new RuntimeException('NOWPayments IPN body limit configuration is invalid.');
        }

        return [
            'callback_url' => $callbackUrl,
            'pay_currency' => strtolower($payCurrency),
            'ipn_secret' => $secret,
            'max_ipn_body_bytes' => $maxIpnBodyBytes,
        ];
    }

    private function requestPayloadHash(
        string $intentPublicId,
        int $amountIrr,
        string $rateSource,
        string $rateIrr,
        DateTimeImmutable $rateFetchedAt,
        string $rateResponseHash,
        string $priceAmountUsd,
        string $payCurrency,
        string $callbackUrl,
    ): string {
        return hash('sha256', json_encode([
            'payment_intent_public_id' => $intentPublicId,
            'provider_code' => self::PROVIDER_CODE,
            'amount_irr' => $amountIrr,
            'currency' => 'IRR',
            'rate_source' => $rateSource,
            'rate_irr' => $rateIrr,
            'rate_fetched_at' => $this->databaseDateTime($rateFetchedAt),
            'rate_response_hash' => strtolower($rateResponseHash),
            'pricing_policy_code' => self::PRICING_POLICY_CODE,
            'price_amount_usd' => $priceAmountUsd,
            'price_currency' => 'USD',
            'pay_currency' => strtolower($payCurrency),
            'callback_url' => $callbackUrl,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function assertEligibleIntent(object $intent): void
    {
        if ($intent->purpose !== 'purchase'
            || $intent->provider_code !== self::PROVIDER_CODE
            || $intent->currency !== 'IRR'
            || $this->positiveInt($intent->amount_irr, 'Payment intent amount') < 1) {
            throw new DomainException('Payment intent is not eligible for NOWPayments initiation.');
        }
    }

    private function receipt(object $authority, bool $replayed): NowPaymentsPaymentReceipt
    {
        $intentPublicId = $this->intentPublicIdForAuthority($authority);
        $settlementPublicId = $this->database->connection()->table('purchase_settlements')
            ->where('payment_intent_id', (int) $authority->payment_intent_id)
            ->value('public_id');
        $state = $this->authorityState((string) $authority->state);

        return new NowPaymentsPaymentReceipt(
            $this->positiveInt($authority->id, 'NOWPayments authority ID'),
            (string) $authority->public_id,
            $intentPublicId,
            $state,
            (string) $authority->rate_source,
            (string) $authority->rate_irr,
            (string) $authority->price_amount_usd,
            (string) $authority->pay_currency,
            is_string($authority->provider_payment_id) ? $authority->provider_payment_id : null,
            is_string($authority->provider_status) ? $authority->provider_status : null,
            is_string($authority->provider_pay_amount) ? $authority->provider_pay_amount : null,
            is_string($authority->provider_pay_address) ? $authority->provider_pay_address : null,
            is_string($settlementPublicId) ? $settlementPublicId : null,
            $replayed,
            in_array($state, [NowPaymentsAuthorityState::Uncertain, NowPaymentsAuthorityState::ManualReview], true),
        );
    }

    private function intentPublicIdForAuthority(object $authority): string
    {
        $value = $this->database->connection()->table('payment_intents')
            ->where('id', (int) $authority->payment_intent_id)
            ->value('public_id');
        if (! is_string($value)) {
            throw new RuntimeException('NOWPayments payment intent public ID is unavailable.');
        }

        return $value;
    }

    private function intentByPublicId(Connection $connection, string $publicId, bool $lock = false): ?object
    {
        $query = $connection->table('payment_intents')->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first(['id', 'public_id', 'purpose', 'provider_code', 'amount_irr', 'currency', 'state', 'captured_at']);
    }

    private function authorityByIntentPublicId(Connection $connection, string $intentPublicId): ?object
    {
        $intentId = $connection->table('payment_intents')->where('public_id', $intentPublicId)->value('id');
        if ($intentId === null) {
            return null;
        }

        return $this->authorityByIntentId($connection, (int) $intentId);
    }

    private function authorityByIntentId(Connection $connection, int $intentId, bool $lock = false): ?object
    {
        $query = $connection->table('nowpayments_payment_authorities')->where('payment_intent_id', $intentId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first($this->authorityColumns());
    }

    private function authorityByPublicId(Connection $connection, string $publicId): ?object
    {
        return $connection->table('nowpayments_payment_authorities')
            ->where('public_id', $publicId)
            ->first($this->authorityColumns());
    }

    private function authorityById(Connection $connection, int $id, bool $lock = false): ?object
    {
        $query = $connection->table('nowpayments_payment_authorities')->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first($this->authorityColumns());
    }

    private function requiredAuthority(Connection $connection, int $id, bool $lock = false): object
    {
        return $this->authorityById($connection, $id, $lock)
            ?? throw new RuntimeException('NOWPayments authority is unavailable.');
    }

    /** @return list<string> */
    private function authorityColumns(): array
    {
        return [
            'id', 'public_id', 'request_key', 'payment_intent_id', 'order_id', 'state', 'amount_irr', 'currency',
            'rate_source', 'rate_irr', 'rate_fetched_at', 'rate_response_hash', 'pricing_policy_code',
            'price_amount_usd', 'price_currency', 'pay_currency', 'callback_url', 'request_payload_hash',
            'provider_payment_id', 'provider_status', 'provider_pay_amount', 'provider_actually_paid',
            'provider_pay_address', 'create_response_hash', 'create_attempted_at', 'provider_created_at',
            'last_status_at', 'created_at', 'updated_at',
        ];
    }

    private function intentStateForId(Connection $connection, int $intentId): PaymentIntentState
    {
        $value = $connection->table('payment_intents')->where('id', $intentId)->value('state');
        if (! is_string($value)) {
            throw new RuntimeException('NOWPayments payment intent state is unavailable.');
        }

        return $this->intentState($value);
    }

    private function authorityState(string $value): NowPaymentsAuthorityState
    {
        return NowPaymentsAuthorityState::tryFrom($value)
            ?? throw new RuntimeException('Stored NOWPayments authority state is invalid.');
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

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        if (strlen($value) < $minimum || strlen($value) > $maximum
            || preg_match('/\A[A-Za-z0-9._:-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertSha256(string $value, string $label): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/i', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function positiveInt(mixed $value, string $label): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            $integer = (int) $value;
            if ($integer > 0) {
                return $integer;
            }
        }

        throw new RuntimeException($label.' is invalid.');
    }

    private function databaseDateTime(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
