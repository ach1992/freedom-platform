<?php

declare(strict_types=1);

namespace App\Modules\Payments\Zarinpal\Application;

use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Application\PurchaseOrderSettlementAvailability;
use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\PurchasePromotionUsageAuthority;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\PurchasePaymentIntentService;
use App\Modules\Payments\Application\PurchaseSettlementService;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalTransport;
use App\Modules\Payments\Zarinpal\Domain\ZarinpalRequestState;
use App\Shared\Application\Clock;
use App\Shared\Domain\Money;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

final readonly class ZarinpalPaymentService
{
    private const PROVIDER_CODE = 'zarinpal';

    private const START_PAY_URL = 'https://payment.zarinpal.com/pg/StartPay/';

    public function __construct(
        private DatabaseManager $database,
        private ZarinpalTransport $transport,
        private PurchasePaymentIntentService $purchaseIntents,
        private PurchasePromotionUsageAuthority $promotionUsage,
        private PurchaseOrderService $purchaseOrders,
        private PurchaseSettlementService $settlements,
        private Clock $clock,
    ) {}

    /** @requirement IPG-001 PAY-002 PAY-003 PRO-001 DAT-002 DAT-003 DAT-004 SEC-002 INT-001 INT-002 QUA-001 QUA-004 */
    public function initiatePurchase(
        int $actorUserId,
        string $quotePublicId,
        string $eligibilityDecisionPublicId,
        string $correlationId,
    ): ZarinpalPaymentReceipt {
        if ($actorUserId < 1) {
            throw new DomainException('Zarinpal purchase actor user ID is invalid.');
        }
        $this->assertUlid($quotePublicId, 'Zarinpal purchase Quote public ID');
        $this->assertUlid($eligibilityDecisionPublicId, 'Zarinpal payment eligibility decision public ID');
        $this->assertToken($correlationId, 'Zarinpal purchase correlation ID', 8, 64);
        $configuration = $this->configuration();

        [$request, $claimed] = $this->database->connection()->transaction(function (Connection $connection) use (
            $actorUserId,
            $quotePublicId,
            $eligibilityDecisionPublicId,
            $correlationId,
            $configuration,
        ): array {
            $intent = $this->purchaseIntents->create(
                $this->purchaseIntentCreationKey($quotePublicId),
                $actorUserId,
                $quotePublicId,
                $eligibilityDecisionPublicId,
                self::PROVIDER_CODE,
                $correlationId,
            );
            $this->promotionUsage->reserveForQuote(
                $this->promotionReservationKey($quotePublicId),
                $actorUserId,
                $quotePublicId,
            );
            [$request, $claimed] = $this->claimRequest(
                $this->purchaseRequestKey($quotePublicId),
                $intent->intentPublicId,
                $configuration,
            );
            if (! $claimed) {
                return [$request, false];
            }
            if ($this->purchaseOrders->settlementAvailabilityFromQuote($quotePublicId, $actorUserId)
                !== PurchaseOrderSettlementAvailability::AwaitingPayment) {
                throw new DomainException('Zarinpal purchase requires a payable pre-payment Order.');
            }

            return [$request, true];
        }, 3);

        if (! $claimed) {
            return $this->receipt($request, true);
        }

        if ($this->purchaseOrders->settlementAvailabilityFromQuote($quotePublicId, $actorUserId)
            !== PurchaseOrderSettlementAvailability::AwaitingPayment) {
            return $this->abortFreshRequestBeforeProvider($request, $correlationId);
        }

        return $this->executeFreshRequest($request, $configuration, $correlationId);
    }

    /** @requirement IPG-001 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 SEC-002 INT-001 INT-002 QUA-001 QUA-004 */
    public function initiate(string $requestKey, string $paymentIntentPublicId, string $correlationId): ZarinpalPaymentReceipt
    {
        $this->assertToken($requestKey, 'Zarinpal request key', 8, 128);
        $this->assertUlid($paymentIntentPublicId, 'Payment intent public ID');
        $this->assertToken($correlationId, 'Zarinpal request correlation ID', 8, 64);
        $configuration = $this->configuration();
        [$request, $claimed] = $this->claimRequest($requestKey, $paymentIntentPublicId, $configuration);

        if (! $claimed) {
            return $this->receipt($request, true);
        }

        return $this->executeFreshRequest($request, $configuration, $correlationId);
    }

    /** @param array{merchant_id:string,callback_url:string,hash:string} $configuration
     * @return array{0:stdClass,1:bool}
     */
    private function claimRequest(string $requestKey, string $paymentIntentPublicId, array $configuration): array
    {
        return $this->database->connection()->transaction(function (Connection $connection) use (
            $requestKey,
            $paymentIntentPublicId,
            $configuration,
        ): array {
            $intent = $this->intentByPublicId($connection, $paymentIntentPublicId, true);
            if ($intent === null) {
                throw new DomainException('Payment intent does not exist.');
            }
            $this->assertZarinpalIntentIdentity($intent);
            $payloadHash = $this->requestPayloadHash($intent, $configuration['hash']);

            $existing = $this->requestByIntentId($connection, $this->positiveInt($intent->id, 'Payment intent ID'), true);
            if ($existing !== null) {
                if (! hash_equals($existing->request_key, $requestKey)
                    || ! hash_equals(strtolower($existing->payload_hash), $payloadHash)) {
                    throw new RuntimeException('Zarinpal payment intent is already bound to a different request identity.');
                }

                return [$existing, false];
            }

            $requestId = (int) $connection->table('zarinpal_payment_requests')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'request_key' => $requestKey,
                'payload_hash' => $payloadHash,
                'payment_intent_id' => $this->positiveInt($intent->id, 'Payment intent ID'),
                'merchant_configuration_hash' => $configuration['hash'],
                'authority' => null,
                'state' => ZarinpalRequestState::Initiating->value,
                'amount_irr' => $this->positiveInt($intent->amount_irr, 'Payment intent amount'),
                'currency' => 'IRR',
                'callback_url' => $configuration['callback_url'],
                'request_provider_code' => null,
                'request_attempted_at' => $this->timestamp(),
                'authority_received_at' => null,
                'created_at' => $this->timestamp(),
                'updated_at' => $this->timestamp(),
            ]);

            $created = $this->requestById($connection, $requestId);
            if ($created === null) {
                throw new RuntimeException('Zarinpal request persistence failed.');
            }

            return [$created, true];
        });
    }

    /** @param array{merchant_id:string,callback_url:string,hash:string} $configuration */
    private function executeFreshRequest(stdClass $request, array $configuration, string $correlationId): ZarinpalPaymentReceipt
    {
        $intent = $this->intentById($this->database->connection(), $this->positiveInt($request->payment_intent_id, 'Payment intent ID'));
        if ($intent === null) {
            throw new RuntimeException('Zarinpal request payment intent disappeared.');
        }
        $result = $this->transport->request(
            $configuration['merchant_id'],
            $this->positiveInt($request->amount_irr, 'Zarinpal request amount'),
            $request->callback_url,
            'Freedom purchase '.$intent->public_id,
            $intent->public_id,
        );

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $request,
            $result,
            $correlationId,
        ): ZarinpalPaymentReceipt {
            $current = $this->requestById($connection, $this->positiveInt($request->id, 'Zarinpal request ID'), true);
            if ($current === null) {
                throw new RuntimeException('Zarinpal request disappeared after provider mutation.');
            }
            if ($this->state($current->state) !== ZarinpalRequestState::Initiating) {
                return $this->receipt($current, true);
            }

            if ($result->uncertain) {
                $this->updateRequestState($connection, $current, ZarinpalRequestState::Uncertain);
                $fresh = $this->requiredRequest($connection, (int) $current->id);
                $this->observe($connection, $fresh, 'request_uncertain', null, null, null, $correlationId);

                return $this->receipt($fresh, false);
            }
            if (! $result->accepted || $result->authority === null) {
                $this->updateRequestState(
                    $connection,
                    $current,
                    ZarinpalRequestState::Failed,
                    ['request_provider_code' => $result->providerCode],
                );
                $this->terminalizeIntentBeforeProvider(
                    $connection,
                    $this->positiveInt($current->payment_intent_id, 'Payment intent ID'),
                    $correlationId,
                    'zarinpal_request_rejected',
                );
                $fresh = $this->requiredRequest($connection, (int) $current->id);
                $this->observe($connection, $fresh, 'request_rejected', null, $result->providerCode, null, $correlationId);

                return $this->receipt($fresh, false);
            }

            $this->assertAuthority($result->authority);
            $acceptedAt = $this->timestamp();
            $this->updateRequestState(
                $connection,
                $current,
                ZarinpalRequestState::Redirectable,
                [
                    'authority' => $result->authority,
                    'request_provider_code' => $result->providerCode ?? 100,
                    'authority_received_at' => $acceptedAt,
                ],
            );
            $this->transitionIntent(
                $connection,
                $this->positiveInt($current->payment_intent_id, 'Payment intent ID'),
                PaymentIntentState::Created,
                PaymentIntentState::AwaitingUserAction,
                'zarinpal_authority_accepted',
                $correlationId,
            );
            $fresh = $this->requiredRequest($connection, (int) $current->id);
            $this->observe($connection, $fresh, 'request_accepted', null, $result->providerCode ?? 100, null, $correlationId);

            return $this->receipt($fresh, false);
        });
    }

    private function abortFreshRequestBeforeProvider(stdClass $request, string $correlationId): ZarinpalPaymentReceipt
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($request, $correlationId): ZarinpalPaymentReceipt {
            $current = $this->requiredRequest($connection, $this->positiveInt($request->id, 'Zarinpal request ID'), true);
            if ($this->state($current->state) !== ZarinpalRequestState::Initiating) {
                return $this->receipt($current, true);
            }
            $this->updateRequestState($connection, $current, ZarinpalRequestState::Failed);
            $this->terminalizeIntentBeforeProvider(
                $connection,
                $this->positiveInt($current->payment_intent_id, 'Payment intent ID'),
                $correlationId,
                'zarinpal_purchase_order_unavailable',
            );

            return $this->receipt($this->requiredRequest($connection, (int) $current->id), false);
        });
    }

    /** @requirement IPG-001 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 SEC-002 INT-001 INT-002 QUA-001 QUA-004 */
    public function handleCallback(string $authority, string $status, string $correlationId): ZarinpalPaymentReceipt
    {
        $this->assertAuthority($authority);
        $this->assertToken($correlationId, 'Zarinpal callback correlation ID', 8, 64);
        $status = strtoupper(trim($status));
        if (! in_array($status, ['OK', 'NOK'], true)) {
            throw new DomainException('Zarinpal callback status is invalid.');
        }

        $connection = $this->database->connection();
        $request = $this->requestByAuthority($connection, $authority);
        if ($request === null) {
            throw new DomainException('Zarinpal callback authority is not bound to a payment intent.');
        }
        $this->observe(
            $connection,
            $request,
            $status === 'OK' ? 'callback_ok' : 'callback_nok',
            $status,
            null,
            null,
            $correlationId,
        );
        if ($status === 'NOK') {
            return $this->receipt($request, true);
        }

        return $this->verifyRequest($this->positiveInt($request->id, 'Zarinpal request ID'), $correlationId);
    }

    /** @requirement IPG-001 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 INT-001 INT-002 QUA-004 */
    public function reconcile(string $requestPublicId, string $correlationId): ZarinpalPaymentReceipt
    {
        $this->assertUlid($requestPublicId, 'Zarinpal request public ID');
        $this->assertToken($correlationId, 'Zarinpal reconciliation correlation ID', 8, 64);
        $configuration = $this->configuration();
        $connection = $this->database->connection();
        $request = $this->requestByPublicId($connection, $requestPublicId);
        if ($request === null) {
            throw new DomainException('Zarinpal request does not exist.');
        }
        if (! hash_equals($request->merchant_configuration_hash, $configuration['hash'])) {
            return $this->moveToManualReview($request, 'manual_review', null, null, null, $correlationId);
        }

        if ($request->authority === null) {
            $candidates = $this->transport->unverified($configuration['merchant_id']);
            $matching = array_filter(
                $candidates,
                fn ($candidate): bool => $candidate->amount === (int) $request->amount_irr
                    && hash_equals($candidate->callbackUrl, $request->callback_url),
            );
            $this->observe(
                $connection,
                $request,
                'unverified_discovery',
                null,
                null,
                min(count($matching), 65535),
                $correlationId,
            );
            if ($this->state($request->state) === ZarinpalRequestState::Initiating) {
                $request = $this->setState($request, ZarinpalRequestState::Uncertain);
            }

            return $this->moveToManualReview($request, 'manual_review', null, null, min(count($matching), 65535), $correlationId);
        }

        $inquiry = $this->transport->inquiry($configuration['merchant_id'], $request->authority);
        if (! $inquiry->available || $inquiry->status === null) {
            $this->observe($connection, $request, 'inquiry_unavailable', null, $inquiry->providerCode, null, $correlationId);

            return $this->receipt($request, true);
        }

        $eventType = match ($inquiry->status) {
            'VERIFIED' => 'inquiry_verified',
            'PAID' => 'inquiry_paid',
            'IN_BANK' => 'inquiry_in_bank',
            'FAILED' => 'inquiry_failed',
            'REVERSED' => 'inquiry_reversed',
            default => 'inquiry_unavailable',
        };
        $this->observe($connection, $request, $eventType, $inquiry->status, $inquiry->providerCode, null, $correlationId);

        if (in_array($inquiry->status, ['VERIFIED', 'PAID'], true)) {
            return $this->verifyRequest((int) $request->id, $correlationId);
        }
        if ($inquiry->status === 'REVERSED') {
            return $this->moveToManualReview($request, 'manual_review', 'REVERSED', $inquiry->providerCode, null, $correlationId);
        }
        if ($inquiry->status === 'FAILED') {
            if ($this->state($request->state) === ZarinpalRequestState::Verified) {
                return $this->moveToManualReview($request, 'manual_review', 'FAILED', $inquiry->providerCode, null, $correlationId);
            }
            if (in_array($this->state($request->state), [ZarinpalRequestState::Redirectable, ZarinpalRequestState::ManualReview], true)) {
                return $this->failRequestAndIntent($request, $correlationId, 'zarinpal_inquiry_failed');
            }
        }

        return $this->receipt($request, true);
    }

    private function verifyRequest(int $requestId, string $correlationId): ZarinpalPaymentReceipt
    {
        $configuration = $this->configuration();
        $connection = $this->database->connection();
        $request = $this->requestById($connection, $requestId);
        if ($request === null) {
            throw new DomainException('Zarinpal request does not exist.');
        }
        if ($this->verificationByRequestId($connection, $requestId) !== null
            || $this->unsettledVerificationByRequestId($connection, $requestId) !== null) {
            return $this->receipt($request, true);
        }
        if ($request->authority === null) {
            throw new RuntimeException('Zarinpal request has no durable authority for server verification.');
        }
        if (! hash_equals($request->merchant_configuration_hash, $configuration['hash'])) {
            return $this->moveToManualReview($request, 'manual_review', null, null, null, $correlationId);
        }
        if (! in_array($this->state($request->state), [ZarinpalRequestState::Redirectable, ZarinpalRequestState::ManualReview], true)) {
            return $this->receipt($request, true);
        }

        $this->prepareIntentForVerification(
            $this->positiveInt($request->payment_intent_id, 'Payment intent ID'),
            $correlationId,
        );
        $result = $this->transport->verify(
            $configuration['merchant_id'],
            $this->positiveInt($request->amount_irr, 'Zarinpal request amount'),
            $request->authority,
        );
        if ($result->uncertain) {
            $this->observe($connection, $request, 'verify_uncertain', null, null, null, $correlationId);
            $this->moveIntentToManualReview(
                $this->positiveInt($request->payment_intent_id, 'Payment intent ID'),
                $correlationId,
                'zarinpal_verify_uncertain',
            );

            return $this->moveToManualReview($request, 'manual_review', null, null, null, $correlationId);
        }
        if (! $result->verified || $result->refId === null || ! in_array($result->providerCode, [100, 101], true)) {
            $this->observe($connection, $request, 'verify_rejected', null, $result->providerCode, null, $correlationId);

            return $this->failRequestAndIntent($request, $correlationId, 'zarinpal_verify_rejected');
        }

        $verifiedAt = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $normalizedHash = hash('sha256', json_encode([
            'provider' => self::PROVIDER_CODE,
            'authority' => $request->authority,
            'provider_ref_id' => $result->refId,
            'amount_irr' => $this->positiveInt($request->amount_irr, 'Zarinpal request amount'),
            'currency' => 'IRR',
            'result' => 'verified',
        ], JSON_THROW_ON_ERROR));
        $providerEventId = 'zarinpal.verify:'.$request->authority;
        $verifiedEvent = new VerifiedPaymentEvent(
            $providerEventId,
            $normalizedHash,
            new PaymentEvidence(
                ProviderOperationOutcome::Success,
                PaymentEvidenceAuthority::Authoritative,
                PaymentTransactionStatus::Settled,
                $result->refId,
                $providerEventId,
                Money::irr($this->positiveInt($request->amount_irr, 'Zarinpal request amount')),
                $verifiedAt,
                $verifiedAt,
                $normalizedHash,
                [
                    'authority_hash' => hash('sha256', $request->authority),
                    'provider' => self::PROVIDER_CODE,
                    'verification' => 'server_side',
                ],
            ),
        );

        return $this->convergeVerifiedPayment(
            $requestId,
            $result->refId,
            $result->providerCode,
            $normalizedHash,
            $verifiedAt,
            $verifiedEvent,
            $correlationId,
        );
    }

    private function convergeVerifiedPayment(
        int $requestId,
        string $providerRefId,
        int $providerCode,
        string $normalizedHash,
        DateTimeImmutable $verifiedAt,
        VerifiedPaymentEvent $verifiedEvent,
        string $correlationId,
    ): ZarinpalPaymentReceipt {
        return $this->database->connection()->transaction(function (Connection $connection) use (
            $requestId,
            $providerRefId,
            $providerCode,
            $normalizedHash,
            $verifiedAt,
            $verifiedEvent,
            $correlationId,
        ): ZarinpalPaymentReceipt {
            $current = $this->requiredRequest($connection, $requestId, true);
            $existing = $this->verificationByRequestId($connection, $requestId, true);
            $unsettled = $this->unsettledVerificationByRequestId($connection, $requestId, true);
            if ($existing !== null || $unsettled !== null) {
                return $this->receipt($current, true);
            }

            $intent = $this->intentById($connection, $this->positiveInt($current->payment_intent_id, 'Payment intent ID'), true);
            if ($intent === null) {
                throw new RuntimeException('Zarinpal request payment intent is unavailable.');
            }
            $this->assertZarinpalIntentIdentity($intent);
            if (! is_string($intent->source_quote_public_id) || (int) $intent->user_id < 1) {
                throw new RuntimeException('Zarinpal purchase identity is incomplete.');
            }

            $orderAvailability = $this->purchaseOrders->settlementAvailabilityFromQuote(
                $intent->source_quote_public_id,
                (int) $intent->user_id,
            );
            if ($orderAvailability === PurchaseOrderSettlementAvailability::Unavailable) {
                $this->persistUnsettledVerification(
                    $connection,
                    $current,
                    $providerRefId,
                    $providerCode,
                    $normalizedHash,
                    $verifiedAt,
                    $correlationId,
                );
                $this->moveIntentToManualReviewInConnection(
                    $connection,
                    $this->positiveInt($intent->id, 'Payment intent ID'),
                    $correlationId,
                    'zarinpal_verified_purchase_order_unavailable',
                );
                if ($this->state($current->state) !== ZarinpalRequestState::ManualReview) {
                    $this->updateRequestState($connection, $current, ZarinpalRequestState::ManualReview);
                }

                return $this->receipt($this->requiredRequest($connection, $requestId), false);
            }

            $settlement = $this->settlements->capture(
                $intent->public_id,
                self::PROVIDER_CODE,
                $verifiedEvent,
                $correlationId,
            );
            if ($orderAvailability === PurchaseOrderSettlementAvailability::AwaitingPayment) {
                $this->promotionUsage->finalizeForSettlement(
                    $this->promotionRedemptionKey($settlement->settlementPublicId),
                    (int) $intent->user_id,
                    $settlement->settlementPublicId,
                );
                $this->purchaseOrders->createFromSettlement($settlement->settlementPublicId, $correlationId);
            }

            $existing = $this->verificationByRequestId($connection, $requestId, true);
            if ($existing === null) {
                $connection->table('zarinpal_payment_verifications')->insert([
                    'public_id' => (string) Str::ulid(),
                    'zarinpal_payment_request_id' => $requestId,
                    'purchase_settlement_id' => $settlement->settlementId,
                    'authority' => $current->authority,
                    'provider_ref_id' => $providerRefId,
                    'provider_verify_code' => $providerCode,
                    'evidence_payload_hash' => $normalizedHash,
                    'amount_irr' => $settlement->amount->amount(),
                    'currency' => $settlement->amount->currency(),
                    'verified_at' => $this->databaseDateTime($verifiedAt),
                    'provider_reverse_eligible_until' => $this->databaseDateTime($verifiedAt->add(new DateInterval('PT30M'))),
                    'created_at' => $this->timestamp(),
                ]);
            } elseif (! hash_equals($existing->provider_ref_id, $providerRefId)
                || (int) $existing->purchase_settlement_id !== $settlement->settlementId) {
                throw new RuntimeException('Zarinpal verification replay conflicts with accepted settlement authority.');
            }

            if ($this->state($current->state) !== ZarinpalRequestState::Verified) {
                $this->updateRequestState($connection, $current, ZarinpalRequestState::Verified);
            }

            return $this->receipt(
                $this->requiredRequest($connection, $requestId),
                $settlement->replayed || $existing !== null,
            );
        }, 3);
    }

    private function persistUnsettledVerification(
        Connection $connection,
        stdClass $request,
        string $providerRefId,
        int $providerCode,
        string $normalizedHash,
        DateTimeImmutable $verifiedAt,
        string $correlationId,
    ): void {
        $requestId = $this->positiveInt($request->id, 'Zarinpal request ID');
        $existing = $this->unsettledVerificationByRequestId($connection, $requestId, true);
        if ($existing !== null) {
            if (! hash_equals((string) $existing->provider_ref_id, $providerRefId)
                || ! hash_equals(strtolower((string) $existing->evidence_payload_hash), $normalizedHash)) {
                throw new RuntimeException('Zarinpal unsettled verification replay conflicts with accepted provider evidence.');
            }

            return;
        }

        $connection->table('zarinpal_verified_unsettled_evidence')->insert([
            'public_id' => (string) Str::ulid(),
            'zarinpal_payment_request_id' => $requestId,
            'authority' => $request->authority,
            'provider_ref_id' => $providerRefId,
            'provider_verify_code' => $providerCode,
            'evidence_payload_hash' => $normalizedHash,
            'amount_irr' => $this->positiveInt($request->amount_irr, 'Zarinpal request amount'),
            'currency' => $request->currency,
            'verified_at' => $this->databaseDateTime($verifiedAt),
            'provider_reverse_eligible_until' => $this->databaseDateTime($verifiedAt->add(new DateInterval('PT30M'))),
            'reason_code' => 'purchase_order_unavailable',
            'created_at' => $this->timestamp(),
        ]);

        $findingKey = hash('sha256', implode("\0", [
            (string) $request->public_id,
            'verified_purchase_order_unavailable',
            $providerRefId,
            $normalizedHash,
        ]));
        $connection->table('zarinpal_reconciliation_findings')->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'zarinpal_payment_request_id' => $requestId,
            'finding_key' => $findingKey,
            'finding_type' => 'verified_purchase_order_unavailable',
            'severity' => 'critical',
            'provider_ref_id' => $providerRefId,
            'evidence_hash' => $normalizedHash,
            'correlation_id' => $correlationId,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function prepareIntentForVerification(int $intentId, string $correlationId): void
    {
        $this->database->connection()->transaction(function (Connection $connection) use ($intentId, $correlationId): void {
            $intent = $this->intentById($connection, $intentId, true);
            if ($intent === null) {
                throw new RuntimeException('Zarinpal payment intent is unavailable for verification.');
            }
            $state = PaymentIntentState::tryFrom($intent->state)
                ?? throw new RuntimeException('Stored payment intent state is invalid.');
            if ($state === PaymentIntentState::AwaitingUserAction) {
                $this->transitionIntent(
                    $connection,
                    $intentId,
                    PaymentIntentState::AwaitingUserAction,
                    PaymentIntentState::Submitted,
                    'zarinpal_server_verification_started',
                    $correlationId,
                );
                $state = PaymentIntentState::Submitted;
            }
            if ($state === PaymentIntentState::Submitted) {
                $this->transitionIntent(
                    $connection,
                    $intentId,
                    PaymentIntentState::Submitted,
                    PaymentIntentState::Verifying,
                    'zarinpal_server_verification_in_progress',
                    $correlationId,
                );

                return;
            }
            if ($state === PaymentIntentState::PendingManualReview) {
                $this->transitionIntent(
                    $connection,
                    $intentId,
                    PaymentIntentState::PendingManualReview,
                    PaymentIntentState::Verifying,
                    'zarinpal_server_verification_retried',
                    $correlationId,
                );

                return;
            }
            if (! in_array($state, [
                PaymentIntentState::Verifying,
                PaymentIntentState::Authorized,
                PaymentIntentState::Captured,
            ], true)) {
                throw new RuntimeException('Zarinpal payment intent is not ready for server verification.');
            }
        });
    }

    private function moveIntentToManualReview(int $intentId, string $correlationId, string $reasonCode): void
    {
        $this->database->connection()->transaction(function (Connection $connection) use ($intentId, $correlationId, $reasonCode): void {
            $this->moveIntentToManualReviewInConnection($connection, $intentId, $correlationId, $reasonCode);
        });
    }

    private function moveIntentToManualReviewInConnection(
        Connection $connection,
        int $intentId,
        string $correlationId,
        string $reasonCode,
    ): void {
        $intent = $this->intentById($connection, $intentId, true);
        if ($intent === null) {
            throw new RuntimeException('Zarinpal payment intent is unavailable for manual review.');
        }
        $state = PaymentIntentState::tryFrom($intent->state)
            ?? throw new RuntimeException('Stored payment intent state is invalid.');
        if ($state === PaymentIntentState::Verifying) {
            $this->transitionIntent(
                $connection,
                $intentId,
                PaymentIntentState::Verifying,
                PaymentIntentState::PendingManualReview,
                $reasonCode,
                $correlationId,
            );
        }
    }

    private function failRequestAndIntent(stdClass $request, string $correlationId, string $reasonCode): ZarinpalPaymentReceipt
    {
        return $this->database->connection()->transaction(function (Connection $connection) use (
            $request,
            $correlationId,
            $reasonCode,
        ): ZarinpalPaymentReceipt {
            $current = $this->requiredRequest($connection, $this->positiveInt($request->id, 'Zarinpal request ID'), true);
            if ($this->state($current->state) !== ZarinpalRequestState::Failed) {
                $this->updateRequestState($connection, $current, ZarinpalRequestState::Failed);
            }
            $this->terminalizeIntentFailure(
                $connection,
                $this->positiveInt($current->payment_intent_id, 'Payment intent ID'),
                $correlationId,
                $reasonCode,
            );

            return $this->receipt($this->requiredRequest($connection, (int) $current->id), false);
        });
    }

    private function terminalizeIntentBeforeProvider(
        Connection $connection,
        int $intentId,
        string $correlationId,
        string $reasonCode,
    ): void {
        $intent = $this->intentById($connection, $intentId, true);
        if ($intent === null) {
            throw new RuntimeException('Zarinpal payment intent is unavailable for cancellation.');
        }
        $state = PaymentIntentState::tryFrom($intent->state)
            ?? throw new RuntimeException('Stored payment intent state is invalid.');
        if ($state === PaymentIntentState::Created) {
            $this->transitionIntent($connection, $intentId, $state, PaymentIntentState::Canceled, $reasonCode, $correlationId);
        } elseif ($state === PaymentIntentState::AwaitingUserAction) {
            $this->transitionIntent($connection, $intentId, $state, PaymentIntentState::Canceled, $reasonCode, $correlationId);
        }
    }

    private function terminalizeIntentFailure(
        Connection $connection,
        int $intentId,
        string $correlationId,
        string $reasonCode,
    ): void {
        $intent = $this->intentById($connection, $intentId, true);
        if ($intent === null) {
            throw new RuntimeException('Zarinpal payment intent is unavailable for terminal failure.');
        }
        $state = PaymentIntentState::tryFrom($intent->state)
            ?? throw new RuntimeException('Stored payment intent state is invalid.');
        if (in_array($state, [PaymentIntentState::Created, PaymentIntentState::AwaitingUserAction], true)) {
            $this->transitionIntent($connection, $intentId, $state, PaymentIntentState::Canceled, $reasonCode, $correlationId);
        } elseif (in_array($state, [PaymentIntentState::Submitted, PaymentIntentState::Verifying, PaymentIntentState::PendingManualReview], true)) {
            $this->transitionIntent($connection, $intentId, $state, PaymentIntentState::Failed, $reasonCode, $correlationId);
        }
    }

    private function moveToManualReview(
        stdClass $request,
        string $eventType,
        ?string $providerStatus,
        ?int $providerCode,
        ?int $candidateCount,
        string $correlationId,
    ): ZarinpalPaymentReceipt {
        $state = $this->state($request->state);
        if ($state === ZarinpalRequestState::Initiating) {
            $request = $this->setState($request, ZarinpalRequestState::Uncertain);
            $state = ZarinpalRequestState::Uncertain;
        }
        if ($state === ZarinpalRequestState::Uncertain
            || $state === ZarinpalRequestState::Redirectable
            || $state === ZarinpalRequestState::Verified) {
            $request = $this->setState($request, ZarinpalRequestState::ManualReview);
        }
        $this->observe(
            $this->database->connection(),
            $request,
            $eventType,
            $providerStatus,
            $providerCode,
            $candidateCount,
            $correlationId,
        );

        return $this->receipt($request, true);
    }

    private function setState(stdClass $request, ZarinpalRequestState $to): stdClass
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($request, $to): stdClass {
            $current = $this->requiredRequest($connection, (int) $request->id, true);
            if ($this->state($current->state) !== $to) {
                $this->updateRequestState($connection, $current, $to);
            }

            return $this->requiredRequest($connection, (int) $request->id);
        });
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
            $actual = $connection->table('payment_intents')->where('id', $intentId)->value('state');
            if ($actual === $to->value) {
                return;
            }
            throw new RuntimeException('Zarinpal payment intent state changed concurrently.');
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

    /** @param array<string, int|string|null> $extra */
    private function updateRequestState(
        Connection $connection,
        stdClass $request,
        ZarinpalRequestState $to,
        array $extra = [],
    ): void {
        $from = $this->state($request->state);
        if ($from === $to) {
            return;
        }
        $updated = $connection->table('zarinpal_payment_requests')
            ->where('id', $this->positiveInt($request->id, 'Zarinpal request ID'))
            ->where('state', $from->value)
            ->update(array_merge($extra, [
                'state' => $to->value,
                'updated_at' => $this->timestamp(),
            ]));
        if ($updated !== 1) {
            throw new RuntimeException('Zarinpal request state changed concurrently.');
        }
    }

    private function observe(
        Connection $connection,
        stdClass $request,
        string $eventType,
        ?string $providerStatus,
        ?int $providerCode,
        ?int $candidateCount,
        string $correlationId,
    ): void {
        $eventKey = implode(':', [
            'zarinpal',
            (string) $request->id,
            $eventType,
            $providerStatus ?? '-',
            $providerCode === null ? '-' : (string) $providerCode,
            $candidateCount === null ? '-' : (string) $candidateCount,
        ]);
        if ($connection->table('zarinpal_payment_observations')->where('event_key', $eventKey)->exists()) {
            return;
        }
        try {
            $connection->table('zarinpal_payment_observations')->insert([
                'zarinpal_payment_request_id' => $this->positiveInt($request->id, 'Zarinpal request ID'),
                'event_key' => $eventKey,
                'event_type' => $eventType,
                'provider_status' => $providerStatus,
                'provider_code' => $providerCode,
                'candidate_count' => $candidateCount,
                'occurred_at' => $this->timestamp(),
                'correlation_id' => $correlationId,
                'created_at' => $this->timestamp(),
            ]);
        } catch (QueryException $exception) {
            if ($connection->table('zarinpal_payment_observations')->where('event_key', $eventKey)->first(['id']) !== null) {
                return;
            }
            throw $exception;
        }
    }

    private function receipt(stdClass $request, bool $replayed): ZarinpalPaymentReceipt
    {
        $state = $this->state($request->state);
        $verification = $this->verificationByRequestId(
            $this->database->connection(),
            $this->positiveInt($request->id, 'Zarinpal request ID'),
        );
        $unsettled = $this->unsettledVerificationByRequestId(
            $this->database->connection(),
            $this->positiveInt($request->id, 'Zarinpal request ID'),
        );
        $settlementPublicId = null;
        $providerRefId = null;
        $reverseWindowOpen = false;
        if ($verification !== null) {
            $settlementPublicId = $this->database->connection()->table('purchase_settlements')
                ->where('id', $this->positiveInt($verification->purchase_settlement_id, 'Purchase settlement ID'))
                ->value('public_id');
            $providerRefId = $verification->provider_ref_id;
            $reverseWindowOpen = $this->clock->now() <= $this->storedDateTime($verification->provider_reverse_eligible_until);
        } elseif ($unsettled !== null) {
            $providerRefId = $unsettled->provider_ref_id;
            $reverseWindowOpen = $this->clock->now() <= $this->storedDateTime($unsettled->provider_reverse_eligible_until);
        }
        $intentPublicId = $this->database->connection()->table('payment_intents')
            ->where('id', $this->positiveInt($request->payment_intent_id, 'Payment intent ID'))
            ->value('public_id');
        if (! is_string($intentPublicId)) {
            throw new RuntimeException('Zarinpal payment intent public ID is unavailable.');
        }

        return new ZarinpalPaymentReceipt(
            $this->positiveInt($request->id, 'Zarinpal request ID'),
            $request->public_id,
            $intentPublicId,
            $state,
            $state === ZarinpalRequestState::Redirectable && is_string($request->authority)
                ? self::START_PAY_URL.$request->authority
                : null,
            is_string($settlementPublicId) ? $settlementPublicId : null,
            is_string($providerRefId) ? $providerRefId : null,
            $reverseWindowOpen,
            $replayed,
            in_array($state, [ZarinpalRequestState::Uncertain, ZarinpalRequestState::ManualReview], true),
        );
    }

    /** @return array{merchant_id:string,callback_url:string,hash:string} */
    private function configuration(): array
    {
        if (! (bool) config('services.zarinpal.enabled', false)) {
            throw new RuntimeException('Zarinpal payment provider is disabled.');
        }
        $merchantId = config('services.zarinpal.merchant_id');
        if (! is_string($merchantId) || preg_match('/\A[A-Za-z0-9-]{36}\z/', $merchantId) !== 1) {
            throw new RuntimeException('Zarinpal merchant configuration is invalid.');
        }
        $callbackUrl = config('services.zarinpal.callback_url');
        if (! is_string($callbackUrl) || strlen($callbackUrl) > 512) {
            throw new RuntimeException('Zarinpal callback URL configuration is invalid.');
        }
        $parts = parse_url($callbackUrl);
        $appParts = parse_url((string) config('app.url'));
        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ! isset($parts['host'])
            || ! is_array($appParts)
            || ! isset($appParts['host'])
            || strcasecmp((string) $parts['host'], (string) $appParts['host']) !== 0
            || (app()->environment('production') && ($parts['scheme'] ?? null) !== 'https')) {
            throw new RuntimeException('Zarinpal callback URL must use the configured application origin and HTTPS in production.');
        }

        return [
            'merchant_id' => $merchantId,
            'callback_url' => $callbackUrl,
            'hash' => hash('sha256', $merchantId."\0".$callbackUrl."\0IRR\0auto_verify=false"),
        ];
    }

    private function purchaseIntentCreationKey(string $quotePublicId): string
    {
        return 'zarinpal.purchase.intent:'.$quotePublicId;
    }

    private function purchaseRequestKey(string $quotePublicId): string
    {
        return 'zarinpal.purchase.request:'.$quotePublicId;
    }

    private function promotionReservationKey(string $quotePublicId): string
    {
        return 'purchase-promotion-reservation:'.$quotePublicId;
    }

    private function promotionRedemptionKey(string $settlementPublicId): string
    {
        return 'purchase-promotion-redemption:'.$settlementPublicId;
    }

    private function requestPayloadHash(stdClass $intent, string $configurationHash): string
    {
        return hash('sha256', json_encode([
            'payment_intent_public_id' => $intent->public_id,
            'provider_code' => self::PROVIDER_CODE,
            'amount_irr' => $this->positiveInt($intent->amount_irr, 'Payment intent amount'),
            'currency' => $intent->currency,
            'configuration_hash' => $configurationHash,
            'auto_verify' => false,
        ], JSON_THROW_ON_ERROR));
    }

    private function assertZarinpalIntentIdentity(stdClass $intent): void
    {
        if ($intent->purpose !== 'purchase'
            || $intent->provider_code !== self::PROVIDER_CODE
            || $intent->currency !== 'IRR'
            || $this->positiveInt($intent->amount_irr, 'Payment intent amount') < 1) {
            throw new DomainException('Payment intent is not eligible for Zarinpal initiation.');
        }
    }

    private function intentByPublicId(Connection $connection, string $publicId, bool $lock = false): ?stdClass
    {
        $query = $connection->table('payment_intents')->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first([
            'id', 'public_id', 'purpose', 'user_id', 'source_quote_public_id', 'payment_method_code', 'provider_code',
            'amount_irr', 'currency', 'state', 'captured_at',
        ]);
    }

    private function intentById(Connection $connection, int $id, bool $lock = false): ?stdClass
    {
        $query = $connection->table('payment_intents')->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first([
            'id', 'public_id', 'purpose', 'user_id', 'source_quote_public_id', 'payment_method_code', 'provider_code',
            'amount_irr', 'currency', 'state', 'captured_at',
        ]);
    }

    private function requestByIntentId(Connection $connection, int $intentId, bool $lock = false): ?stdClass
    {
        $query = $connection->table('zarinpal_payment_requests')->where('payment_intent_id', $intentId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first($this->requestColumns());
    }

    private function requestByAuthority(Connection $connection, string $authority): ?stdClass
    {
        return $connection->table('zarinpal_payment_requests')->where('authority', $authority)->first($this->requestColumns());
    }

    private function requestByPublicId(Connection $connection, string $publicId): ?stdClass
    {
        return $connection->table('zarinpal_payment_requests')->where('public_id', $publicId)->first($this->requestColumns());
    }

    private function requestById(Connection $connection, int $id, bool $lock = false): ?stdClass
    {
        $query = $connection->table('zarinpal_payment_requests')->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first($this->requestColumns());
    }

    private function requiredRequest(Connection $connection, int $id, bool $lock = false): stdClass
    {
        return $this->requestById($connection, $id, $lock)
            ?? throw new RuntimeException('Zarinpal request is unavailable.');
    }

    private function verificationByRequestId(Connection $connection, int $requestId, bool $lock = false): ?stdClass
    {
        $query = $connection->table('zarinpal_payment_verifications')->where('zarinpal_payment_request_id', $requestId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first([
            'id', 'public_id', 'zarinpal_payment_request_id', 'purchase_settlement_id', 'authority', 'provider_ref_id',
            'provider_verify_code', 'evidence_payload_hash', 'amount_irr', 'currency', 'verified_at',
            'provider_reverse_eligible_until', 'created_at',
        ]);
    }

    private function unsettledVerificationByRequestId(Connection $connection, int $requestId, bool $lock = false): ?stdClass
    {
        $query = $connection->table('zarinpal_verified_unsettled_evidence')
            ->where('zarinpal_payment_request_id', $requestId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first([
            'id', 'public_id', 'zarinpal_payment_request_id', 'authority', 'provider_ref_id', 'provider_verify_code',
            'evidence_payload_hash', 'amount_irr', 'currency', 'verified_at', 'provider_reverse_eligible_until',
            'reason_code', 'created_at',
        ]);
    }

    /** @return list<string> */
    private function requestColumns(): array
    {
        return [
            'id', 'public_id', 'request_key', 'payload_hash', 'payment_intent_id', 'merchant_configuration_hash',
            'authority', 'state', 'amount_irr', 'currency', 'callback_url', 'request_provider_code',
            'request_attempted_at', 'authority_received_at', 'created_at', 'updated_at',
        ];
    }

    private function state(string $value): ZarinpalRequestState
    {
        return ZarinpalRequestState::tryFrom($value)
            ?? throw new RuntimeException('Stored Zarinpal request state is invalid.');
    }

    private function assertAuthority(string $authority): void
    {
        if (strlen($authority) > 64 || preg_match('/\AA[A-Za-z0-9]{20,63}\z/', $authority) !== 1) {
            throw new DomainException('Zarinpal authority is invalid.');
        }
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
            throw new RuntimeException('Stored Zarinpal timestamp is invalid.');
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
