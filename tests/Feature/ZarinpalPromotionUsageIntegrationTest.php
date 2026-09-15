<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Application\CatalogChangeContext;
use App\Modules\Catalog\Application\PlanOfferingService;
use App\Modules\Catalog\Domain\ProductVisibility;
use App\Modules\Orders\Application\Contracts\QuoteDiscountAuthority;
use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Application\QuoteDiscountAuthorizationRequest;
use App\Modules\Orders\Application\QuoteDiscountConsumptionRequest;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteReceipt;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Application\Contracts\PurchasePromotionUsageAuthority;
use App\Modules\Payments\Application\PurchasePaymentIntentService;
use App\Modules\Payments\Application\PurchasePaymentMaintenanceService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalInquiryResult;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalRequestResult;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalTransport;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalUnverifiedCandidate;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalVerifyResult;
use App\Modules\Payments\Zarinpal\Application\ZarinpalPaymentService;
use App\Modules\Payments\Zarinpal\Domain\ZarinpalRequestState;
use App\Modules\Promotions\Application\PromotionRuleVersionReceipt;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeType;
use App\Shared\Application\Clock;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

final class ZarinpalPromotionFakeTransport implements ZarinpalTransport
{
    public int $requestCalls = 0;

    public int $verifyCalls = 0;

    public ZarinpalRequestResult $requestResult;

    public ZarinpalVerifyResult $verifyResult;

    public ?Closure $beforeVerifyReturn = null;

    public function __construct()
    {
        $this->requestResult = ZarinpalRequestResult::accepted('A'.str_repeat('8', 35));
        $this->verifyResult = ZarinpalVerifyResult::verified('260000002', 100);
    }

    public function request(string $merchantId, int $amountIrr, string $callbackUrl, string $description, string $orderId): ZarinpalRequestResult
    {
        $this->requestCalls++;

        return $this->requestResult;
    }

    public function verify(string $merchantId, int $amountIrr, string $authority): ZarinpalVerifyResult
    {
        $this->verifyCalls++;
        if ($this->beforeVerifyReturn !== null) {
            ($this->beforeVerifyReturn)();
        }

        return $this->verifyResult;
    }

    public function inquiry(string $merchantId, string $authority): ZarinpalInquiryResult
    {
        return ZarinpalInquiryResult::available('PAID');
    }

    public function unverified(string $merchantId): array
    {
        /** @var list<ZarinpalUnverifiedCandidate> */
        return [];
    }
}

final class ZarinpalPromotionClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement PAY-001 PAY-002 PAY-003 PRO-001 IPG-001 DAT-002 DAT-003 DAT-004 SEC-002 INT-001 INT-002 QUA-001 QUA-004 */
final class ZarinpalPromotionUsageIntegrationTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use RefreshDatabase;

    private ZarinpalPromotionClock $clock;

    private ZarinpalPromotionFakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->clock = new ZarinpalPromotionClock(now('UTC')->toDateTimeImmutable());
        $this->app->instance(Clock::class, $this->clock);
        $this->transport = new ZarinpalPromotionFakeTransport;
        $this->app->instance(ZarinpalTransport::class, $this->transport);
        config()->set('app.url', 'http://localhost');
        config()->set('services.zarinpal.enabled', true);
        config()->set('services.zarinpal.merchant_id', '00000000-0000-0000-0000-000000000000');
        config()->set('services.zarinpal.callback_url', 'http://localhost/payments/zarinpal/callback');
    }

    public function test_discounted_zarinpal_purchase_reserves_replays_and_finalizes_exact_promotion_usage(): void
    {
        $source = $this->promotionSource('success', 1);
        $checkout = $this->discountedCheckout($source, $source['codes'][0], 'success');
        $service = $this->app->make(ZarinpalPaymentService::class);

        $initiated = $service->initiatePurchase(
            $checkout['user_id'],
            $checkout['quote']->quotePublicId,
            $checkout['decision']->publicId,
            $this->correlation('success-initiate'),
        );
        self::assertSame(ZarinpalRequestState::Redirectable, $initiated->state);
        self::assertSame(1, $this->transport->requestCalls);
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        self::assertSame(
            DB::table('promotion_usage_reservations')->value('id'),
            DB::table('zarinpal_payment_requests')->where('id', $initiated->requestId)->value('promotion_usage_reservation_id'),
        );
        self::assertSame(0, DB::table('promotion_usage_redemptions')->count());
        self::assertSame(0, DB::table('promotion_usage_releases')->count());

        $initiateReplay = $service->initiatePurchase(
            $checkout['user_id'],
            $checkout['quote']->quotePublicId,
            $checkout['decision']->publicId,
            $this->correlation('success-initiate-replay'),
        );
        self::assertTrue($initiateReplay->replayed);
        self::assertSame($initiated->publicId, $initiateReplay->publicId);
        self::assertSame(1, $this->transport->requestCalls);
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());

        $verified = $service->handleCallback(
            'A'.str_repeat('8', 35),
            'OK',
            $this->correlation('success-callback'),
        );
        self::assertSame(ZarinpalRequestState::Verified, $verified->state);
        self::assertNotNull($verified->purchaseSettlementPublicId);
        self::assertSame(1, $this->transport->verifyCalls);
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        self::assertSame(1, DB::table('promotion_usage_redemptions')->count());
        self::assertSame(0, DB::table('promotion_usage_releases')->count());
        self::assertSame(
            DB::table('purchase_settlements')->where('public_id', $verified->purchaseSettlementPublicId)->value('id'),
            DB::table('promotion_usage_redemptions')->value('purchase_settlement_id'),
        );
        self::assertSame('paid', DB::table('orders')->where('public_id', $checkout['order']->orderPublicId)->value('state'));

        $postSettlementReplay = $service->initiatePurchase(
            $checkout['user_id'],
            $checkout['quote']->quotePublicId,
            $checkout['decision']->publicId,
            $this->correlation('success-post-settlement-replay'),
        );
        self::assertTrue($postSettlementReplay->replayed);
        self::assertSame($verified->purchaseSettlementPublicId, $postSettlementReplay->purchaseSettlementPublicId);
        self::assertSame(1, $this->transport->requestCalls);
        self::assertSame(1, $this->transport->verifyCalls);
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        self::assertSame(1, DB::table('promotion_usage_redemptions')->count());
    }

    public function test_database_rejects_promotion_authority_unavailable_evidence_when_active_reservation_exists(): void
    {
        $source = $this->promotionSource('promotion-guard', 1);
        $checkout = $this->discountedCheckout($source, $source['codes'][0], 'promotion-guard');
        $service = $this->app->make(ZarinpalPaymentService::class);
        $initiated = $service->initiatePurchase(
            $checkout['user_id'],
            $checkout['quote']->quotePublicId,
            $checkout['decision']->publicId,
            $this->correlation('promotion-guard-initiate'),
        );
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        $this->transport->verifyResult = ZarinpalVerifyResult::uncertain();
        $review = $service->handleCallback(
            'A'.str_repeat('8', 35),
            'OK',
            $this->correlation('promotion-guard-uncertain'),
        );
        self::assertSame(ZarinpalRequestState::ManualReview, $review->state);

        $request = DB::table('zarinpal_payment_requests')->where('id', $review->requestId)->first();
        self::assertNotNull($request);
        $verifiedAt = $this->clock->value->format('Y-m-d H:i:s.u');
        $reverseUntil = $this->clock->value->modify('+30 minutes')->format('Y-m-d H:i:s.u');
        try {
            DB::table('zarinpal_verified_unsettled_evidence')->insert([
                'public_id' => (string) Str::ulid(),
                'zarinpal_payment_request_id' => (int) $request->id,
                'authority' => (string) $request->authority,
                'provider_ref_id' => '260009801',
                'provider_verify_code' => 100,
                'evidence_payload_hash' => hash('sha256', 'forged-promotion-authority-unavailable'),
                'amount_irr' => (int) $request->amount_irr,
                'currency' => (string) $request->currency,
                'verified_at' => $verifiedAt,
                'provider_reverse_eligible_until' => $reverseUntil,
                'reason_code' => 'promotion_authority_unavailable',
                'created_at' => $verifiedAt,
            ]);
            self::fail('Active promotion reservation must reject promotion-authority-unavailable evidence.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('Unsettled Zarinpal verification requires one unique matching uncaptured provider authority and valid reason state.', $exception->getMessage());
        }

        self::assertSame(0, DB::table('zarinpal_verified_unsettled_evidence')->count());
        self::assertSame(0, DB::table('zarinpal_provider_evidence_claims')->count());
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        self::assertSame(0, DB::table('promotion_usage_redemptions')->count());
        self::assertSame('awaiting_payment', DB::table('orders')->where('id', $checkout['order']->orderId)->value('state'));
        self::assertSame('pending_manual_review', DB::table('payment_intents')->where('public_id', $initiated->paymentIntentPublicId)->value('state'));
    }

    public function test_discounted_legacy_request_that_later_gets_order_retains_verified_evidence_for_manual_reconciliation(): void
    {
        $source = $this->promotionSource('legacy-later-order', 1);
        $checkout = $this->discountedCheckout($source, $source['codes'][0], 'legacy-later-order', false);
        $service = $this->app->make(ZarinpalPaymentService::class);
        $intent = $this->app->make(PurchasePaymentIntentService::class)->create(
            'zarinpal.promotion.legacy.intent.later-order',
            $checkout['user_id'],
            $checkout['quote']->quotePublicId,
            $checkout['decision']->publicId,
            'zarinpal',
            $this->correlation('legacy-later-order-intent'),
        );
        $initiated = $service->initiate(
            'zarinpal.promotion.legacy.request.later-order',
            $intent->intentPublicId,
            $this->correlation('legacy-later-order-initiate'),
        );
        self::assertSame(ZarinpalRequestState::Redirectable, $initiated->state);
        self::assertSame(1, $this->transport->requestCalls);
        self::assertSame(0, DB::table('promotion_usage_reservations')->count());
        self::assertNull(DB::table('zarinpal_payment_requests')->where('id', $initiated->requestId)->value('promotion_usage_reservation_id'));
        self::assertSame(0, DB::table('orders')->count());

        $order = $this->app->make(PurchaseOrderService::class)->openFromQuote(
            $checkout['quote']->quotePublicId,
            $checkout['user_id'],
            $this->correlation('legacy-later-order-open'),
        );
        $verified = $service->handleCallback(
            'A'.str_repeat('8', 35),
            'OK',
            $this->correlation('legacy-later-order-callback'),
        );

        $this->assertPromotionAuthorityReconciliation($verified, $intent->intentPublicId, $order->orderId, '260000002');

        $replay = $service->handleCallback(
            'A'.str_repeat('8', 35),
            'OK',
            $this->correlation('legacy-later-order-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame(1, $this->transport->verifyCalls);
        self::assertSame(1, DB::table('zarinpal_verified_unsettled_evidence')->count());
        self::assertSame(1, DB::table('zarinpal_reconciliation_findings')->count());
    }

    public function test_discounted_legacy_request_cannot_use_reservation_created_after_provider_mutation(): void
    {
        $source = $this->promotionSource('legacy-retroactive-reservation', 1);
        $checkout = $this->discountedCheckout($source, $source['codes'][0], 'legacy-retroactive-reservation', false);
        $service = $this->app->make(ZarinpalPaymentService::class);
        $intent = $this->app->make(PurchasePaymentIntentService::class)->create(
            'zarinpal.promotion.legacy.intent.retroactive-reservation',
            $checkout['user_id'],
            $checkout['quote']->quotePublicId,
            $checkout['decision']->publicId,
            'zarinpal',
            $this->correlation('legacy-retroactive-reservation-intent'),
        );
        $initiated = $service->initiate(
            'zarinpal.promotion.legacy.request.retroactive-reservation',
            $intent->intentPublicId,
            $this->correlation('legacy-retroactive-reservation-initiate'),
        );
        self::assertSame(ZarinpalRequestState::Redirectable, $initiated->state);
        self::assertSame(1, $this->transport->requestCalls);
        self::assertSame(0, DB::table('promotion_usage_reservations')->count());

        $reservation = $this->app->make(PurchasePromotionUsageAuthority::class)->reserveForQuote(
            'purchase-promotion-reservation:'.$checkout['quote']->quotePublicId,
            $checkout['user_id'],
            $checkout['quote']->quotePublicId,
        );
        self::assertNotNull($reservation);
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        self::assertNull(DB::table('zarinpal_payment_requests')->where('id', $initiated->requestId)->value('promotion_usage_reservation_id'));
        $reservationId = (int) DB::table('promotion_usage_reservations')->value('id');
        try {
            DB::table('zarinpal_payment_requests')
                ->where('id', $initiated->requestId)
                ->update(['promotion_usage_reservation_id' => $reservationId]);
            self::fail('Legacy Zarinpal request must not acquire promotion authority after provider mutation.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('Zarinpal pre-provider promotion reservation linkage is immutable.', $exception->getMessage());
        }
        self::assertNull(DB::table('zarinpal_payment_requests')->where('id', $initiated->requestId)->value('promotion_usage_reservation_id'));
        $order = $this->app->make(PurchaseOrderService::class)->openFromQuote(
            $checkout['quote']->quotePublicId,
            $checkout['user_id'],
            $this->correlation('legacy-retroactive-reservation-order'),
        );
        $verified = $service->handleCallback(
            'A'.str_repeat('8', 35),
            'OK',
            $this->correlation('legacy-retroactive-reservation-callback'),
        );

        $this->assertPromotionAuthorityReconciliation($verified, $intent->intentPublicId, $order->orderId, '260000002', 1);
    }

    public function test_discounted_order_appearing_during_legacy_verify_retains_verified_evidence_for_manual_reconciliation(): void
    {
        $source = $this->promotionSource('legacy-order-during-verify', 1);
        $checkout = $this->discountedCheckout($source, $source['codes'][0], 'legacy-order-during-verify', false);
        $service = $this->app->make(ZarinpalPaymentService::class);
        $intent = $this->app->make(PurchasePaymentIntentService::class)->create(
            'zarinpal.promotion.legacy.intent.order-during-verify',
            $checkout['user_id'],
            $checkout['quote']->quotePublicId,
            $checkout['decision']->publicId,
            'zarinpal',
            $this->correlation('legacy-order-during-verify-intent'),
        );
        $initiated = $service->initiate(
            'zarinpal.promotion.legacy.request.order-during-verify',
            $intent->intentPublicId,
            $this->correlation('legacy-order-during-verify-initiate'),
        );
        self::assertSame(ZarinpalRequestState::Redirectable, $initiated->state);
        self::assertSame(0, DB::table('orders')->count());
        self::assertSame(0, DB::table('promotion_usage_reservations')->count());

        $order = null;
        $this->transport->beforeVerifyReturn = function () use ($checkout, &$order): void {
            self::assertSame(0, DB::table('orders')->count());
            $order = $this->app->make(PurchaseOrderService::class)->openFromQuote(
                $checkout['quote']->quotePublicId,
                $checkout['user_id'],
                $this->correlation('legacy-order-during-verify-open'),
            );
            $this->transport->beforeVerifyReturn = null;
        };
        $verified = $service->handleCallback(
            'A'.str_repeat('8', 35),
            'OK',
            $this->correlation('legacy-order-during-verify-callback'),
        );

        self::assertNotNull($order);
        $this->assertPromotionAuthorityReconciliation($verified, $intent->intentPublicId, $order->orderId, '260000002');
    }

    public function test_zero_discount_legacy_request_that_later_gets_order_remains_compatible(): void
    {
        $source = $this->promotionSource('legacy-zero', 1);
        $checkout = $this->zeroDiscountCheckout($source, 'legacy-zero');
        $service = $this->app->make(ZarinpalPaymentService::class);
        $intent = $this->app->make(PurchasePaymentIntentService::class)->create(
            'zarinpal.promotion.legacy.intent.zero',
            $checkout['user_id'],
            $checkout['quote']->quotePublicId,
            $checkout['decision']->publicId,
            'zarinpal',
            $this->correlation('legacy-zero-intent'),
        );
        $initiated = $service->initiate(
            'zarinpal.promotion.legacy.request.zero',
            $intent->intentPublicId,
            $this->correlation('legacy-zero-initiate'),
        );
        self::assertSame(ZarinpalRequestState::Redirectable, $initiated->state);
        self::assertSame(0, DB::table('promotion_usage_reservations')->count());

        $order = $this->app->make(PurchaseOrderService::class)->openFromQuote(
            $checkout['quote']->quotePublicId,
            $checkout['user_id'],
            $this->correlation('legacy-zero-order'),
        );
        $verified = $service->handleCallback(
            'A'.str_repeat('8', 35),
            'OK',
            $this->correlation('legacy-zero-callback'),
        );

        self::assertSame(ZarinpalRequestState::Verified, $verified->state);
        self::assertNotNull($verified->purchaseSettlementPublicId);
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'zarinpal')->count());
        self::assertSame(1, DB::table('zarinpal_payment_verifications')->count());
        self::assertSame(0, DB::table('zarinpal_verified_unsettled_evidence')->count());
        self::assertSame(0, DB::table('zarinpal_reconciliation_findings')->count());
        self::assertSame(0, DB::table('promotion_usage_reservations')->count());
        self::assertSame(0, DB::table('promotion_usage_redemptions')->count());
        self::assertSame('paid', DB::table('orders')->where('id', $order->orderId)->value('state'));
    }

    public function test_terminal_rejected_discounted_zarinpal_purchase_releases_reserved_promotion_after_quote_expiry(): void
    {
        $source = $this->promotionSource('release', 1);
        $checkout = $this->discountedCheckout($source, $source['codes'][0], 'release');
        $this->transport->requestResult = ZarinpalRequestResult::rejected(-9);
        $service = $this->app->make(ZarinpalPaymentService::class);

        $failed = $service->initiatePurchase(
            $checkout['user_id'],
            $checkout['quote']->quotePublicId,
            $checkout['decision']->publicId,
            $this->correlation('release-initiate'),
        );
        self::assertSame(ZarinpalRequestState::Failed, $failed->state);
        self::assertSame(1, $this->transport->requestCalls);
        self::assertSame('failed', DB::table('payment_intents')->where('public_id', $failed->paymentIntentPublicId)->value('state'));
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        self::assertSame(0, DB::table('promotion_usage_redemptions')->count());
        self::assertSame(0, DB::table('promotion_usage_releases')->count());

        $this->clock->value = $this->clock->value->modify('+31 minutes');
        $maintenance = $this->app->make(PurchasePaymentMaintenanceService::class)->run();
        self::assertSame(1, $maintenance->promotionReservationsExamined);
        self::assertSame(1, $maintenance->releasedPromotionReservations);
        self::assertSame(0, $maintenance->failures);
        self::assertSame(1, DB::table('promotion_usage_releases')->count());
        self::assertSame(0, DB::table('promotion_usage_redemptions')->count());
        self::assertSame('awaiting_payment', DB::table('orders')->where('public_id', $checkout['order']->orderPublicId)->value('state'));
    }

    private function promotionSource(string $suffix, int $totalUseLimit, int $quantity = 1): array
    {
        $offering = $this->activeBenefitOffering('zarinpal-'.$suffix);
        $version = (int) DB::table('plan_offerings')->where('id', $offering['id'])->value('version');
        $this->app->make(PlanOfferingService::class)->setVisibility(
            $offering['id'],
            $version,
            ProductVisibility::Visible,
            new CatalogChangeContext(
                'zarinpal-promo-visible-'.substr(hash('sha256', $suffix), 0, 24),
                'zarinpal-promo-visible-correlation-'.substr(hash('sha256', $suffix), 0, 20),
                'zarinpal_promotion_payment_test',
                'Expose Zarinpal promotion test Offering.',
                $this->benefitOwner(),
            ),
        );
        $rule = $this->usageRule(
            $offering['id'],
            'zarinpal.promo.'.substr(hash('sha256', $suffix), 0, 16),
            90_000,
            $totalUseLimit,
            null,
        );
        $campaignCode = 'zarinpal.discount.'.substr(hash('sha256', $suffix), 0, 12);
        $this->benefitCampaign(
            $campaignCode,
            BenefitCodeType::DiscountGrant,
            $this->discountDefinition($rule->ruleCode, $offering['id'], $offering['product_id'], $offering['server_id']),
            'zarinpal-'.$suffix,
        );
        $issue = $this->benefitIssue($campaignCode, 'zarinpal-'.$suffix, $quantity);
        $codes = [];
        foreach ($issue->items as $item) {
            $codes[] = (string) $item->fullCode;
        }

        return compact('offering', 'rule', 'codes');
    }

    /** @param array{offering:array{id:int,product_id:int,server_id:int},rule:PromotionRuleVersionReceipt,codes:list<string>} $source
     * @return array{user_id:int,quote:QuoteReceipt,decision:object,order:object|null}
     */
    private function discountedCheckout(array $source, string $code, string $suffix, bool $openOrder = true): array
    {
        $userId = $this->benefitUser('customer');
        $quoteService = $this->app->make(QuoteService::class);
        $sourceQuote = $quoteService->create(
            'zarinpal-promo-source-'.substr(hash('sha256', $suffix), 0, 24),
            $userId,
            $source['offering']['id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                $this->clock->value->modify('+30 minutes'),
            ),
            $this->correlation('source-'.$suffix),
        );
        $discounts = $this->app->make(QuoteDiscountAuthority::class);
        $authorization = $discounts->authorize(new QuoteDiscountAuthorizationRequest(
            'zarinpal-promo-auth-'.substr(hash('sha256', $suffix), 0, 24),
            $userId,
            $sourceQuote->quotePublicId,
            $sourceQuote->configurationSnapshotHash,
            $code,
            $this->correlation('auth-'.$suffix),
        ));
        $discounted = $quoteService->create(
            'zarinpal-promo-quote-'.substr(hash('sha256', $suffix), 0, 24),
            $userId,
            $source['offering']['id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                $authorization->ruleCode,
                $authorization->discountIrr,
                $this->clock->value->modify('+30 minutes'),
            ),
            $this->correlation('discounted-'.$suffix),
        );
        $discounts->consume(new QuoteDiscountConsumptionRequest(
            'zarinpal-promo-consume-'.substr(hash('sha256', $suffix), 0, 24),
            $userId,
            $authorization,
            $discounted->quotePublicId,
            $discounted->configurationSnapshotHash,
            $this->correlation('consume-'.$suffix),
        ));

        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $this->configureZarinpal($eligibility, $suffix);
        $decision = $eligibility->evaluate(
            'zarinpal-promo-decision-'.substr(hash('sha256', $suffix), 0, 24),
            $userId,
            $discounted->quotePublicId,
            $discounted->configurationSnapshotHash,
        );
        $order = $openOrder
            ? $this->app->make(PurchaseOrderService::class)->openFromQuote(
                $discounted->quotePublicId,
                $userId,
                $this->correlation('order-'.$suffix),
            )
            : null;

        return ['user_id' => $userId, 'quote' => $discounted, 'decision' => $decision, 'order' => $order];
    }

    /** @param array{offering:array{id:int,product_id:int,server_id:int}} $source
     * @return array{user_id:int,quote:QuoteReceipt,decision:object}
     */
    private function zeroDiscountCheckout(array $source, string $suffix): array
    {
        $userId = $this->benefitUser('customer');
        $quote = $this->app->make(QuoteService::class)->create(
            'zarinpal-promo-zero-'.substr(hash('sha256', $suffix), 0, 24),
            $userId,
            $source['offering']['id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                $this->clock->value->modify('+30 minutes'),
            ),
            $this->correlation('zero-'.$suffix),
        );
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $this->configureZarinpal($eligibility, $suffix);
        $decision = $eligibility->evaluate(
            'zarinpal-promo-zero-decision-'.substr(hash('sha256', $suffix), 0, 24),
            $userId,
            $quote->quotePublicId,
            $quote->configurationSnapshotHash,
        );

        return ['user_id' => $userId, 'quote' => $quote, 'decision' => $decision];
    }

    private function assertPromotionAuthorityReconciliation(
        object $receipt,
        string $intentPublicId,
        int $orderId,
        string $providerRefId,
        int $expectedReservations = 0,
    ): void {
        self::assertSame(ZarinpalRequestState::ManualReview, $receipt->state);
        self::assertTrue($receipt->manualReviewRequired);
        self::assertNull($receipt->purchaseSettlementPublicId);
        self::assertSame($providerRefId, $receipt->providerRefId);
        self::assertSame(1, $this->transport->verifyCalls);
        self::assertSame(0, DB::table('purchase_settlements')->where('provider_code', 'zarinpal')->count());
        self::assertSame(0, DB::table('zarinpal_payment_verifications')->count());
        self::assertSame(1, DB::table('zarinpal_verified_unsettled_evidence')
            ->where('reason_code', 'promotion_authority_unavailable')
            ->where('provider_ref_id', $providerRefId)
            ->count());
        self::assertSame(1, DB::table('zarinpal_provider_evidence_claims')
            ->where('evidence_disposition', 'unsettled')
            ->where('provider_ref_id', $providerRefId)
            ->count());
        self::assertSame(1, DB::table('zarinpal_reconciliation_findings')
            ->where('finding_type', 'verified_promotion_authority_unavailable')
            ->where('severity', 'critical')
            ->where('observed_result', 'verified')
            ->count());
        self::assertSame($expectedReservations, DB::table('promotion_usage_reservations')->count());
        self::assertSame(0, DB::table('promotion_usage_redemptions')->count());
        self::assertSame('awaiting_payment', DB::table('orders')->where('id', $orderId)->value('state'));
        self::assertNull(DB::table('orders')->where('id', $orderId)->value('purchase_settlement_id'));
        self::assertSame('pending_manual_review', DB::table('payment_intents')->where('public_id', $intentPublicId)->value('state'));
    }

    private function configureZarinpal(PaymentMethodEligibilityService $service, string $suffix): void
    {
        $administratorId = $this->benefitOwner();
        $service->configureMethod(
            'zarinpal-promo-method-'.substr(hash('sha256', $suffix), 0, 24),
            $administratorId,
            'zarinpal',
            true,
            false,
            1,
            'Zarinpal promotion payment test configuration.',
            $this->correlation('method-'.$suffix),
        );
        $service->recordHealth(
            'zarinpal-promo-health-'.substr(hash('sha256', $suffix), 0, 24),
            $administratorId,
            'zarinpal',
            true,
            $this->clock->value->modify('+10 minutes'),
            'Healthy Zarinpal promotion payment observation.',
            $this->correlation('health-'.$suffix),
        );
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'zarinpal-promotion:'.$suffix);
    }
}
