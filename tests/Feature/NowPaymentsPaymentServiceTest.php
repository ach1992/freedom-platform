<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Application\PurchasePaymentIntentService;
use App\Modules\Payments\Application\PurchaseSettlementService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Payments\NowPayments\Application\Contracts\NowPaymentsCreateRequest;
use App\Modules\Payments\NowPayments\Application\Contracts\NowPaymentsPaymentResult;
use App\Modules\Payments\NowPayments\Application\Contracts\NowPaymentsTransport;
use App\Modules\Payments\NowPayments\Application\Contracts\NowPaymentsTransportException;
use App\Modules\Payments\NowPayments\Application\NowPaymentsAuthorityState;
use App\Modules\Payments\NowPayments\Application\NowPaymentsPaymentService;
use App\Modules\Payments\Usdt\Application\UsdtCircuitBreaker;
use App\Modules\Payments\Usdt\Application\UsdtRateResolver;
use App\Modules\Payments\Usdt\Domain\UsdtRate;
use App\Modules\Payments\Usdt\Domain\UsdtRatePolicy;
use App\Modules\Payments\Usdt\Domain\UsdtRateProvider;
use App\Modules\Payments\Usdt\Domain\UsdtRateSide;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class FakeNowPaymentsTransport implements NowPaymentsTransport
{
    public int $createCalls = 0;
    public int $statusCalls = 0;
    public ?NowPaymentsCreateRequest $lastCreateRequest = null;
    public bool $createUncertain = false;
    public string $createStatus = 'waiting';
    public string $statusValue = 'waiting';
    public string $payAmount = '12.500000000000000000';
    public ?string $actuallyPaid = null;
    public string $providerPaymentId = '900001';

    public function create(NowPaymentsCreateRequest $request): NowPaymentsPaymentResult
    {
        $this->createCalls++;
        $this->lastCreateRequest = $request;
        if ($this->createUncertain) {
            throw new NowPaymentsTransportException('simulated uncertain create', true);
        }

        return $this->result($this->createStatus, null, 'create');
    }

    public function status(string $providerPaymentId): NowPaymentsPaymentResult
    {
        $this->statusCalls++;
        if ($providerPaymentId !== $this->providerPaymentId || $this->lastCreateRequest === null) {
            throw new RuntimeException('unexpected NOWPayments status lookup');
        }

        return $this->result($this->statusValue, $this->actuallyPaid, 'status-'.$this->statusCalls);
    }

    private function result(string $status, ?string $actuallyPaid, string $suffix): NowPaymentsPaymentResult
    {
        $request = $this->lastCreateRequest ?? throw new RuntimeException('missing NOWPayments create request');
        $time = new DateTimeImmutable('2026-08-14T06:30:00+00:00');
        $hash = hash('sha256', implode('|', [
            $this->providerPaymentId,
            $status,
            $request->priceAmountUsd,
            $request->payCurrency,
            $request->orderId,
            $this->payAmount,
            $actuallyPaid ?? '',
            $suffix,
        ]));

        return new NowPaymentsPaymentResult(
            $this->providerPaymentId,
            $status,
            $request->priceAmountUsd,
            'USD',
            $this->payAmount,
            $actuallyPaid,
            $request->payCurrency,
            '0x1111111111111111111111111111111111111111',
            $request->orderId,
            $time,
            $time,
            $hash,
        );
    }
}

final readonly class NowPaymentsRateProvider implements UsdtRateProvider
{
    public function __construct(private Clock $clock) {}

    public function code(): string
    {
        return 'manual';
    }

    public function fetch(UsdtRateSide $side): UsdtRate
    {
        return new UsdtRate(
            'manual',
            '900000.00000000',
            $this->clock->now(),
            hash('sha256', 'manual|900000|'.$side->value),
        );
    }
}

final class NowPaymentsTestClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement IPG-002 PAY-002 PAY-003 USDT-002 DAT-002 DAT-003 DAT-004 SEC-002 INT-001 INT-002 QUA-001 QUA-004 */
final class NowPaymentsPaymentServiceTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private FakeNowPaymentsTransport $transport;
    private NowPaymentsTestClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->clock = new NowPaymentsTestClock(new DateTimeImmutable('2026-08-14T06:30:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
        $this->transport = new FakeNowPaymentsTransport();

        config()->set('app.url', 'https://payments.example.test');
        config()->set('services.nowpayments.enabled', true);
        config()->set('services.nowpayments.ipn_secret', 'nowpayments-test-secret');
        config()->set('services.nowpayments.ipn_callback_url', 'https://payments.example.test/api/payments/nowpayments/ipn');
        config()->set('services.nowpayments.pay_currency', 'usdtbsc');
        config()->set('services.nowpayments.max_ipn_body_bytes', 262144);
    }

    public function test_shared_usdt_rate_is_snapshotted_as_usd_proxy_and_create_replay_is_exactly_once(): void
    {
        $intentPublicId = $this->purchaseIntent('snapshot', 10_000_000);
        $service = $this->service();
        $requestKey = 'nowpayments.create.snapshot.000001';

        $created = $service->create($intentPublicId, $requestKey, $this->correlation('create-snapshot'));
        self::assertSame(NowPaymentsAuthorityState::Created, $created->state);
        self::assertSame('manual', $created->rateSource);
        self::assertSame('900000.00000000', $created->rateIrr);
        self::assertSame('11.11111112', $created->priceAmountUsd);
        self::assertSame('usdtbsc', $created->payCurrency);
        self::assertSame(1, $this->transport->createCalls);
        self::assertSame('11.11111112', $this->transport->lastCreateRequest?->priceAmountUsd);
        self::assertSame('awaiting_user_action', DB::table('payment_intents')->where('public_id', $intentPublicId)->value('state'));
        self::assertSame('shared_usdt_rate_as_usd_proxy_v1', DB::table('nowpayments_payment_authorities')->value('pricing_policy_code'));

        $replay = $service->create($intentPublicId, $requestKey, $this->correlation('create-snapshot-replay'));
        self::assertTrue($replay->replayed);
        self::assertSame($created->authorityId, $replay->authorityId);
        self::assertSame(1, $this->transport->createCalls);
        self::assertSame(1, DB::table('nowpayments_payment_authorities')->count());
    }

    public function test_finished_server_status_captures_purchase_once_and_replay_never_creates_second_financial_effect(): void
    {
        $intentPublicId = $this->purchaseIntent('finished', 10_000_000);
        $service = $this->service();
        $service->create(''.$intentPublicId, 'nowpayments.create.finished.000001', $this->correlation('create-finished'));
        $this->transport->statusValue = 'finished';
        $this->transport->actuallyPaid = $this->transport->payAmount;

        $finished = $service->refresh($intentPublicId, $this->correlation('status-finished'));
        self::assertSame(NowPaymentsAuthorityState::Finished, $finished->state);
        self::assertNotNull($finished->settlementPublicId);
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'nowpayments')->count());
        self::assertSame('captured', DB::table('payment_intents')->where('public_id', $intentPublicId)->value('state'));

        $replay = $service->refresh($intentPublicId, $this->correlation('status-finished-replay'));
        self::assertSame(NowPaymentsAuthorityState::Finished, $replay->state);
        self::assertSame($finished->settlementPublicId, $replay->settlementPublicId);
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'nowpayments')->count());
    }

    public function test_partial_payment_enters_manual_review_and_never_captures(): void
    {
        $intentPublicId = $this->purchaseIntent('partial', 10_000_000);
        $service = $this->service();
        $service->create($intentPublicId, 'nowpayments.create.partial.000001', $this->correlation('create-partial'));
        $this->transport->statusValue = 'partially_paid';
        $this->transport->actuallyPaid = '10.000000000000000000';

        $review = $service->refresh($intentPublicId, $this->correlation('status-partial'));
        self::assertSame(NowPaymentsAuthorityState::ManualReview, $review->state);
        self::assertTrue($review->manualReviewRequired);
        self::assertSame('pending_manual_review', DB::table('payment_intents')->where('public_id', $intentPublicId)->value('state'));
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(1, DB::table('nowpayments_reconciliation_findings')->where('code', 'partially_paid')->count());
    }

    public function test_uncertain_create_is_not_blindly_retried_and_moves_to_manual_reconciliation_without_provider_id(): void
    {
        $intentPublicId = $this->purchaseIntent('uncertain', 10_000_000);
        $service = $this->service();
        $this->transport->createUncertain = true;
        $requestKey = 'nowpayments.create.uncertain.000001';

        $uncertain = $service->create($intentPublicId, $requestKey, $this->correlation('create-uncertain'));
        self::assertSame(NowPaymentsAuthorityState::Uncertain, $uncertain->state);
        self::assertNull($uncertain->providerPaymentId);
        self::assertSame(1, $this->transport->createCalls);

        $replay = $service->create($intentPublicId, $requestKey, $this->correlation('create-uncertain-replay'));
        self::assertSame(NowPaymentsAuthorityState::Uncertain, $replay->state);
        self::assertSame(1, $this->transport->createCalls);

        $manual = $service->refresh($intentPublicId, $this->correlation('reconcile-uncertain'));
        self::assertSame(NowPaymentsAuthorityState::ManualReview, $manual->state);
        self::assertTrue($manual->manualReviewRequired);
        self::assertSame(0, $this->transport->statusCalls);
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(1, DB::table('nowpayments_reconciliation_findings')->where('code', 'uncertain_create_requires_operator_reconciliation')->count());
    }

    public function test_finished_with_underpayment_never_captures(): void
    {
        $intentPublicId = $this->purchaseIntent('underpaid', 10_000_000);
        $service = $this->service();
        $service->create($intentPublicId, 'nowpayments.create.underpaid.000001', $this->correlation('create-underpaid'));
        $this->transport->statusValue = 'finished';
        $this->transport->actuallyPaid = '12.499999999999999999';

        $review = $service->refresh($intentPublicId, $this->correlation('status-underpaid'));
        self::assertSame(NowPaymentsAuthorityState::ManualReview, $review->state);
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(1, DB::table('nowpayments_reconciliation_findings')->where('code', 'finished_amount_not_exact')->count());
    }

    private function service(): NowPaymentsPaymentService
    {
        return new NowPaymentsPaymentService(
            $this->app['db'],
            $this->transport,
            $this->rateResolver(),
            $this->app->make(PurchaseSettlementService::class),
            $this->clock,
        );
    }

    private function rateResolver(): UsdtRateResolver
    {
        $policy = new UsdtRatePolicy(
            ['manual'],
            UsdtRateSide::Buy,
            120,
            '100000',
            '10000000',
            500,
            false,
            3,
            60,
        );
        $cache = new Repository(new ArrayStore);
        $circuit = new UsdtCircuitBreaker($cache, $this->clock, 3, 60);

        return new UsdtRateResolver([new NowPaymentsRateProvider($this->clock)], $policy, $circuit, $this->clock);
    }

    private function purchaseIntent(string $suffix, int $amountIrr): string
    {
        $userId = $this->quoteUser('customer');
        $administratorId = $this->ownerAdministrator();
        $offering = $this->quoteOffering($amountIrr);
        $quote = $this->app->make(QuoteService::class)->create(
            'nowpayments.quote.'.$suffix,
            $userId,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->value->modify('+30 minutes')),
            $this->correlation('quote-'.$suffix),
        );
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'nowpayments.method.'.$suffix,
            $administratorId,
            'nowpayments',
            true,
            false,
            1,
            'NOWPayments payment test configuration.',
            $this->correlation('method-'.$suffix),
        );
        $eligibility->recordHealth(
            'nowpayments.health.'.$suffix,
            $administratorId,
            'nowpayments',
            true,
            $this->clock->value->modify('+10 minutes'),
            'Healthy NOWPayments test observation.',
            $this->correlation('health-'.$suffix),
        );
        $decision = $eligibility->evaluate('nowpayments.eligibility.'.$suffix, $userId, $quote->quotePublicId);
        $intent = $this->app->make(PurchasePaymentIntentService::class)->create(
            'nowpayments.intent.'.$suffix,
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            'nowpayments',
            $this->correlation('intent-'.$suffix),
        );
        self::assertSame('awaiting_user_action', $intent->state->value);

        return $intent->intentPublicId;
    }

    private function correlation(string $suffix): string
    {
        return substr(hash('sha256', 'nowpayments-correlation:'.$suffix), 0, 64);
    }
}
