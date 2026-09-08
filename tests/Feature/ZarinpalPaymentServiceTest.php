<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Application\PurchasePaymentIntentService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalInquiryResult;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalRequestResult;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalTransport;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalUnverifiedCandidate;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalVerifyResult;
use App\Modules\Payments\Zarinpal\Application\ZarinpalPaymentService;
use App\Modules\Payments\Zarinpal\Domain\ZarinpalRequestState;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FakeZarinpalTransport implements ZarinpalTransport
{
    public int $requestCalls = 0;

    public int $verifyCalls = 0;

    public int $inquiryCalls = 0;

    public int $unverifiedCalls = 0;

    public ZarinpalRequestResult $requestResult;

    public ZarinpalVerifyResult $verifyResult;

    public ZarinpalInquiryResult $inquiryResult;

    /** @var list<ZarinpalUnverifiedCandidate> */
    public array $unverifiedCandidates = [];

    public function __construct()
    {
        $this->requestResult = ZarinpalRequestResult::accepted('A'.str_repeat('1', 35));
        $this->verifyResult = ZarinpalVerifyResult::verified('123456789', 100);
        $this->inquiryResult = ZarinpalInquiryResult::available('PAID');
    }

    public function request(string $merchantId, int $amountIrr, string $callbackUrl, string $description, string $orderId): ZarinpalRequestResult
    {
        $this->requestCalls++;

        return $this->requestResult;
    }

    public function verify(string $merchantId, int $amountIrr, string $authority): ZarinpalVerifyResult
    {
        $this->verifyCalls++;

        return $this->verifyResult;
    }

    public function inquiry(string $merchantId, string $authority): ZarinpalInquiryResult
    {
        $this->inquiryCalls++;

        return $this->inquiryResult;
    }

    public function unverified(string $merchantId): array
    {
        $this->unverifiedCalls++;

        return $this->unverifiedCandidates;
    }
}

final class ZarinpalPaymentTestClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement IPG-001 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 SEC-002 INT-001 INT-002 QUA-001 QUA-004 */
final class ZarinpalPaymentServiceTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private FakeZarinpalTransport $transport;

    private ZarinpalPaymentTestClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->clock = new ZarinpalPaymentTestClock(new DateTimeImmutable('2026-08-14T08:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
        $this->transport = new FakeZarinpalTransport;
        $this->app->instance(ZarinpalTransport::class, $this->transport);
        config()->set('app.url', 'http://localhost');
        config()->set('services.zarinpal.enabled', true);
        config()->set('services.zarinpal.merchant_id', '00000000-0000-0000-0000-000000000000');
        config()->set('services.zarinpal.callback_url', 'http://localhost/payments/zarinpal/callback');
    }

    public function test_request_redirect_callback_verify_and_duplicate_callback_are_exactly_once(): void
    {
        $intentPublicId = $this->purchaseIntent('happy');
        $service = $this->app->make(ZarinpalPaymentService::class);
        $requestKey = 'zarinpal.request.happy.000001';

        $created = $service->initiate($requestKey, $intentPublicId, $this->correlation('initiate-happy'));
        self::assertSame(ZarinpalRequestState::Redirectable, $created->state);
        self::assertSame('https://payment.zarinpal.com/pg/StartPay/A'.str_repeat('1', 35), $created->redirectUrl);
        self::assertSame(1, $this->transport->requestCalls);
        self::assertSame('awaiting_user_action', DB::table('payment_intents')->where('public_id', $intentPublicId)->value('state'));

        $replay = $service->initiate($requestKey, $intentPublicId, $this->correlation('initiate-happy-replay'));
        self::assertTrue($replay->replayed);
        self::assertSame($created->requestId, $replay->requestId);
        self::assertSame(1, $this->transport->requestCalls);

        $verified = $service->handleCallback('A'.str_repeat('1', 35), 'OK', $this->correlation('callback-happy'));
        self::assertSame(ZarinpalRequestState::Verified, $verified->state);
        self::assertNotNull($verified->purchaseSettlementPublicId);
        self::assertSame('123456789', $verified->providerRefId);
        self::assertSame(1, $this->transport->verifyCalls);
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'zarinpal')->count());
        self::assertSame(1, DB::table('zarinpal_payment_verifications')->count());
        self::assertSame('captured', DB::table('payment_intents')->where('public_id', $intentPublicId)->value('state'));

        $duplicate = $service->handleCallback('A'.str_repeat('1', 35), 'OK', $this->correlation('callback-happy-duplicate'));
        self::assertSame(ZarinpalRequestState::Verified, $duplicate->state);
        self::assertTrue($duplicate->replayed);
        self::assertSame($verified->purchaseSettlementPublicId, $duplicate->purchaseSettlementPublicId);
        self::assertSame(1, $this->transport->verifyCalls);
        self::assertSame(1, DB::table('purchase_settlements')->count());
    }

    public function test_provider_ref_reuse_across_distinct_requests_enters_manual_review_without_second_settlement(): void
    {
        $service = $this->app->make(ZarinpalPaymentService::class);
        $firstIntent = $this->purchaseIntent('provider-ref-first');
        $service->initiate(
            'zarinpal.request.provider-ref.first.000001',
            $firstIntent,
            $this->correlation('provider-ref-first-initiate'),
        );
        $first = $service->handleCallback(
            'A'.str_repeat('1', 35),
            'OK',
            $this->correlation('provider-ref-first-callback'),
        );
        self::assertSame(ZarinpalRequestState::Verified, $first->state);
        self::assertSame(1, DB::table('zarinpal_provider_evidence_claims')->where('evidence_disposition', 'settled')->count());

        $secondIntent = $this->purchaseIntent('provider-ref-second');
        $this->transport->requestResult = ZarinpalRequestResult::accepted('A'.str_repeat('2', 35));
        $this->transport->verifyResult = ZarinpalVerifyResult::verified('123456789', 100);
        $second = $service->initiate(
            'zarinpal.request.provider-ref.second.000001',
            $secondIntent,
            $this->correlation('provider-ref-second-initiate'),
        );
        self::assertSame(ZarinpalRequestState::Redirectable, $second->state);

        $review = $service->handleCallback(
            'A'.str_repeat('2', 35),
            'OK',
            $this->correlation('provider-ref-second-callback'),
        );

        self::assertSame(ZarinpalRequestState::ManualReview, $review->state);
        self::assertTrue($review->manualReviewRequired);
        self::assertNull($review->purchaseSettlementPublicId);
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'zarinpal')->count());
        self::assertSame(1, DB::table('zarinpal_payment_verifications')->count());
        self::assertSame(1, DB::table('zarinpal_provider_evidence_claims')->count());
        self::assertSame(
            1,
            DB::table('zarinpal_reconciliation_findings')
                ->where('zarinpal_payment_request_id', $review->requestId)
                ->where('finding_type', 'provider_verification_conflict')
                ->where('observed_result', 'verified')
                ->where('severity', 'critical')
                ->count(),
        );
        self::assertSame('pending_manual_review', DB::table('payment_intents')->where('public_id', $secondIntent)->value('state'));
    }

    public function test_callback_nok_is_only_an_observation_and_never_captures(): void
    {
        $intentPublicId = $this->purchaseIntent('nok');
        $service = $this->app->make(ZarinpalPaymentService::class);
        $service->initiate('zarinpal.request.nok.000001', $intentPublicId, $this->correlation('initiate-nok'));

        $receipt = $service->handleCallback('A'.str_repeat('1', 35), 'NOK', $this->correlation('callback-nok'));
        self::assertSame(ZarinpalRequestState::Redirectable, $receipt->state);
        self::assertSame(0, $this->transport->verifyCalls);
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(1, DB::table('zarinpal_payment_observations')->where('event_type', 'callback_nok')->count());
    }

    public function test_callback_configuration_drift_fails_closed_without_server_verification(): void
    {
        $intentPublicId = $this->purchaseIntent('configuration-drift');
        $service = $this->app->make(ZarinpalPaymentService::class);
        $requestKey = 'zarinpal.request.configuration-drift.000001';
        $service->initiate(
            $requestKey,
            $intentPublicId,
            $this->correlation('initiate-configuration-drift'),
        );
        config()->set('services.zarinpal.merchant_id', '11111111-1111-1111-1111-111111111111');

        try {
            $service->initiate(
                $requestKey,
                $intentPublicId,
                $this->correlation('replay-configuration-drift'),
            );
            self::fail('Changed Zarinpal request identity must fail closed on replay.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Zarinpal payment intent is already bound to a different request identity.', $exception->getMessage());
        }
        self::assertSame(1, $this->transport->requestCalls);

        $review = $service->handleCallback(
            'A'.str_repeat('1', 35),
            'OK',
            $this->correlation('callback-configuration-drift'),
        );

        self::assertSame(ZarinpalRequestState::ManualReview, $review->state);
        self::assertTrue($review->manualReviewRequired);
        self::assertSame(0, $this->transport->verifyCalls);
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(
            'awaiting_user_action',
            DB::table('payment_intents')->where('public_id', $intentPublicId)->value('state'),
        );
    }

    public function test_unknown_callback_authority_fails_closed_without_provider_or_settlement_effect(): void
    {
        $service = $this->app->make(ZarinpalPaymentService::class);

        try {
            $service->handleCallback(
                'A'.str_repeat('9', 35),
                'OK',
                $this->correlation('callback-unknown-authority'),
            );
            self::fail('Unknown Zarinpal callback authority must be rejected.');
        } catch (DomainException $exception) {
            self::assertSame('Zarinpal callback authority is not bound to a payment intent.', $exception->getMessage());
        }

        self::assertSame(0, $this->transport->verifyCalls);
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(0, DB::table('zarinpal_payment_verifications')->count());
    }

    public function test_uncertain_request_is_never_retried_and_discovery_never_auto_adopts_authority(): void
    {
        $intentPublicId = $this->purchaseIntent('uncertain');
        $this->transport->requestResult = ZarinpalRequestResult::uncertain();
        $service = $this->app->make(ZarinpalPaymentService::class);

        $uncertain = $service->initiate('zarinpal.request.uncertain.000001', $intentPublicId, $this->correlation('initiate-uncertain'));
        self::assertSame(ZarinpalRequestState::Uncertain, $uncertain->state);
        self::assertTrue($uncertain->manualReviewRequired);
        self::assertSame(1, $this->transport->requestCalls);

        $replay = $service->initiate('zarinpal.request.uncertain.000001', $intentPublicId, $this->correlation('initiate-uncertain-replay'));
        self::assertSame(ZarinpalRequestState::Uncertain, $replay->state);
        self::assertSame(1, $this->transport->requestCalls);

        $request = DB::table('zarinpal_payment_requests')->where('payment_intent_id', function ($query) use ($intentPublicId): void {
            $query->select('id')->from('payment_intents')->where('public_id', $intentPublicId);
        })->first();
        self::assertNotNull($request);
        $this->transport->unverifiedCandidates = [
            new ZarinpalUnverifiedCandidate('A'.str_repeat('2', 35), (int) $request->amount_irr, (string) $request->callback_url, '2026-08-14 08:01:00'),
        ];
        $review = $service->reconcile((string) $request->public_id, $this->correlation('reconcile-uncertain'));
        self::assertSame(ZarinpalRequestState::ManualReview, $review->state);
        self::assertTrue($review->manualReviewRequired);
        self::assertSame(1, $this->transport->unverifiedCalls);
        self::assertNull(DB::table('zarinpal_payment_requests')->where('id', $request->id)->value('authority'));
        self::assertSame(0, DB::table('purchase_settlements')->count());
    }

    public function test_legacy_no_pre_payment_verify_rejection_preserves_existing_intent_semantics(): void
    {
        $intentPublicId = $this->purchaseIntent('legacy-verify-rejected');
        $service = $this->app->make(ZarinpalPaymentService::class);
        $service->initiate(
            'zarinpal.request.legacy.verify.rejected.000001',
            $intentPublicId,
            $this->correlation('legacy-verify-rejected-initiate'),
        );
        $this->transport->verifyResult = ZarinpalVerifyResult::rejected(-51);

        $failed = $service->handleCallback(
            'A'.str_repeat('1', 35),
            'OK',
            $this->correlation('legacy-verify-rejected-callback'),
        );

        self::assertSame(ZarinpalRequestState::Failed, $failed->state);
        self::assertSame(1, $this->transport->verifyCalls);
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(
            'awaiting_user_action',
            DB::table('payment_intents')->where('public_id', $intentPublicId)->value('state'),
        );
    }

    public function test_legacy_no_pre_payment_verify_uncertainty_preserves_existing_intent_semantics(): void
    {
        $intentPublicId = $this->purchaseIntent('legacy-verify-uncertain');
        $service = $this->app->make(ZarinpalPaymentService::class);
        $service->initiate(
            'zarinpal.request.legacy.verify.uncertain.000001',
            $intentPublicId,
            $this->correlation('legacy-verify-uncertain-initiate'),
        );
        $this->transport->verifyResult = ZarinpalVerifyResult::uncertain();

        $review = $service->handleCallback(
            'A'.str_repeat('1', 35),
            'OK',
            $this->correlation('legacy-verify-uncertain-callback'),
        );

        self::assertSame(ZarinpalRequestState::ManualReview, $review->state);
        self::assertTrue($review->manualReviewRequired);
        self::assertSame(1, $this->transport->verifyCalls);
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(
            'awaiting_user_action',
            DB::table('payment_intents')->where('public_id', $intentPublicId)->value('state'),
        );
    }

    public function test_legacy_no_pre_payment_failed_inquiry_preserves_existing_intent_semantics(): void
    {
        $intentPublicId = $this->purchaseIntent('legacy-inquiry-failed');
        $service = $this->app->make(ZarinpalPaymentService::class);
        $initiated = $service->initiate(
            'zarinpal.request.legacy.inquiry.failed.000001',
            $intentPublicId,
            $this->correlation('legacy-inquiry-failed-initiate'),
        );
        $this->transport->inquiryResult = ZarinpalInquiryResult::available('FAILED', -51);

        $failed = $service->reconcile(
            $initiated->publicId,
            $this->correlation('legacy-inquiry-failed-reconcile'),
        );

        self::assertSame(ZarinpalRequestState::Failed, $failed->state);
        self::assertSame(1, $this->transport->inquiryCalls);
        self::assertSame(0, $this->transport->verifyCalls);
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(
            'awaiting_user_action',
            DB::table('payment_intents')->where('public_id', $intentPublicId)->value('state'),
        );
    }

    public function test_reconciliation_uses_inquiry_as_status_only_then_server_verify_for_paid(): void
    {
        $intentPublicId = $this->purchaseIntent('reconcile');
        $service = $this->app->make(ZarinpalPaymentService::class);
        $initiated = $service->initiate('zarinpal.request.reconcile.000001', $intentPublicId, $this->correlation('initiate-reconcile'));
        self::assertSame(ZarinpalRequestState::Redirectable, $initiated->state);
        $this->transport->inquiryResult = ZarinpalInquiryResult::available('PAID');
        $this->transport->verifyResult = ZarinpalVerifyResult::verified('987654321', 101);

        $verified = $service->reconcile($initiated->publicId, $this->correlation('reconcile-paid'));
        self::assertSame(ZarinpalRequestState::Verified, $verified->state);
        self::assertSame('987654321', $verified->providerRefId);
        self::assertSame(1, $this->transport->inquiryCalls);
        self::assertSame(1, $this->transport->verifyCalls);
        self::assertSame(1, DB::table('purchase_settlements')->count());
        self::assertSame(1, DB::table('zarinpal_payment_observations')->where('event_type', 'inquiry_paid')->count());
    }

    public function test_provider_reversal_observation_after_capture_requires_manual_review_without_bypassing_purchase_refund_authority(): void
    {
        $intentPublicId = $this->purchaseIntent('reversed');
        $service = $this->app->make(ZarinpalPaymentService::class);
        $initiated = $service->initiate('zarinpal.request.reversed.000001', $intentPublicId, $this->correlation('initiate-reversed'));
        $verified = $service->handleCallback('A'.str_repeat('1', 35), 'OK', $this->correlation('verify-reversed'));
        self::assertSame(ZarinpalRequestState::Verified, $verified->state);
        $this->transport->inquiryResult = ZarinpalInquiryResult::available('REVERSED');

        $review = $service->reconcile($initiated->publicId, $this->correlation('reconcile-reversed'));
        self::assertSame(ZarinpalRequestState::ManualReview, $review->state);
        self::assertTrue($review->manualReviewRequired);
        self::assertSame(1, DB::table('purchase_settlements')->count());
        self::assertSame(0, DB::table('purchase_refunds')->count());
        self::assertSame(1, DB::table('zarinpal_payment_observations')->where('event_type', 'inquiry_reversed')->count());
    }

    public function test_database_rejects_cross_intent_authority_reuse_and_verification_mutation(): void
    {
        $service = $this->app->make(ZarinpalPaymentService::class);
        $firstIntent = $this->purchaseIntent('db-first');
        $service->initiate('zarinpal.request.db.first.000001', $firstIntent, $this->correlation('db-first-init'));
        $service->handleCallback('A'.str_repeat('1', 35), 'OK', $this->correlation('db-first-verify'));
        $verificationId = (int) DB::table('zarinpal_payment_verifications')->value('id');
        $this->assertQueryRejected(static fn (): int => DB::table('zarinpal_payment_verifications')->where('id', $verificationId)->update(['provider_ref_id' => '1']));
        $this->assertQueryRejected(static fn (): int => DB::table('zarinpal_payment_verifications')->where('id', $verificationId)->delete());

        $secondIntent = $this->purchaseIntent('db-second');
        $this->transport->requestResult = ZarinpalRequestResult::accepted('A'.str_repeat('1', 35));
        $this->assertQueryRejected(fn (): mixed => $service->initiate(
            'zarinpal.request.db.second.000001',
            $secondIntent,
            $this->correlation('db-second-init'),
        ));
        self::assertSame(1, DB::table('zarinpal_payment_requests')->whereNotNull('authority')->count());
    }

    private function purchaseIntent(string $suffix): string
    {
        $userId = $this->quoteUser('customer');
        $administratorId = $this->ownerAdministrator();
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'zarinpal.quote.'.$suffix,
            $userId,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->value->modify('+30 minutes')),
            $this->correlation('quote-'.$suffix),
        );
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'zarinpal.method.'.$suffix,
            $administratorId,
            'zarinpal',
            true,
            false,
            1,
            'Zarinpal payment test configuration.',
            $this->correlation('method-'.$suffix),
        );
        $eligibility->recordHealth(
            'zarinpal.health.'.$suffix,
            $administratorId,
            'zarinpal',
            true,
            $this->clock->value->modify('+10 minutes'),
            'Healthy Zarinpal test observation.',
            $this->correlation('health-'.$suffix),
        );
        $decision = $eligibility->evaluate('zarinpal.eligibility.'.$suffix, $userId, $quote->quotePublicId);
        $intent = $this->app->make(PurchasePaymentIntentService::class)->create(
            'zarinpal.intent.'.$suffix,
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            'zarinpal',
            $this->correlation('intent-'.$suffix),
        );

        return $intent->intentPublicId;
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'zarinpal:'.$suffix);
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected database authority rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
