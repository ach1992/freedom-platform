<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Application\PurchaseWalletPaymentService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalInquiryResult;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalRequestResult;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalTransport;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalUnverifiedCandidate;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalVerifyResult;
use App\Modules\Payments\Zarinpal\Application\ZarinpalPaymentService;
use App\Modules\Payments\Zarinpal\Domain\ZarinpalRequestState;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Database\Seeders\WalletFinancialFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ZarinpalPrePaymentFakeTransport implements ZarinpalTransport
{
    public int $requestCalls = 0;

    public int $verifyCalls = 0;

    public ZarinpalRequestResult $requestResult;

    public ZarinpalVerifyResult $verifyResult;

    public function __construct()
    {
        $this->requestResult = ZarinpalRequestResult::accepted('A'.str_repeat('7', 35));
        $this->verifyResult = ZarinpalVerifyResult::verified('260000001', 100);
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
        return ZarinpalInquiryResult::available('PAID');
    }

    public function unverified(string $merchantId): array
    {
        /** @var list<ZarinpalUnverifiedCandidate> */
        return [];
    }
}

final class ZarinpalPrePaymentClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement BUY-001 BUY-003 PAY-001 PAY-002 PAY-003 PRO-001 IPG-001 DAT-002 DAT-003 DAT-004 SEC-002 INT-001 INT-002 QUA-001 QUA-004 */
final class ZarinpalPrePaymentOrderSafetyTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private ZarinpalPrePaymentClock $clock;

    private ZarinpalPrePaymentFakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->seed(WalletFinancialFoundationSeeder::class);
        $this->clock = new ZarinpalPrePaymentClock(new DateTimeImmutable('2026-09-07T10:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
        $this->transport = new ZarinpalPrePaymentFakeTransport;
        $this->app->instance(ZarinpalTransport::class, $this->transport);
        config()->set('app.url', 'http://localhost');
        config()->set('services.zarinpal.enabled', true);
        config()->set('services.zarinpal.merchant_id', '00000000-0000-0000-0000-000000000000');
        config()->set('services.zarinpal.callback_url', 'http://localhost/payments/zarinpal/callback');
    }

    public function test_zarinpal_purchase_replays_and_converges_into_the_existing_pre_payment_order(): void
    {
        [$userId, $quote, $decision, $opening] = $this->purchaseContext('winner', false);
        $service = $this->app->make(ZarinpalPaymentService::class);

        $initiated = $service->initiatePurchase(
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            $this->correlation('winner-initiate'),
        );
        self::assertSame(ZarinpalRequestState::Redirectable, $initiated->state);
        self::assertSame(1, $this->transport->requestCalls);
        self::assertSame(1, DB::table('payment_intents')->where('provider_code', 'zarinpal')->count());
        self::assertSame(0, DB::table('promotion_usage_reservations')->count());

        $replay = $service->initiatePurchase(
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            $this->correlation('winner-initiate-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($initiated->publicId, $replay->publicId);
        self::assertSame(1, $this->transport->requestCalls);

        $verified = $service->handleCallback(
            'A'.str_repeat('7', 35),
            'OK',
            $this->correlation('winner-callback'),
        );
        self::assertSame(ZarinpalRequestState::Verified, $verified->state);
        self::assertNotNull($verified->purchaseSettlementPublicId);
        self::assertSame('260000001', $verified->providerRefId);
        self::assertSame(1, $this->transport->verifyCalls);
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'zarinpal')->count());
        self::assertSame(1, DB::table('zarinpal_payment_verifications')->count());
        self::assertSame(0, DB::table('zarinpal_verified_unsettled_evidence')->count());
        self::assertSame(0, DB::table('zarinpal_reconciliation_findings')->count());
        self::assertSame('paid', DB::table('orders')->where('id', $opening->orderId)->value('state'));
        self::assertSame($verified->purchaseSettlementPublicId, DB::table('orders')->where('id', $opening->orderId)->value('purchase_settlement_public_id'));

        $postSettlementReplay = $service->initiatePurchase(
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            $this->correlation('winner-initiate-after-settlement'),
        );
        self::assertTrue($postSettlementReplay->replayed);
        self::assertSame($initiated->publicId, $postSettlementReplay->publicId);
        self::assertSame($verified->purchaseSettlementPublicId, $postSettlementReplay->purchaseSettlementPublicId);
        self::assertSame(1, $this->transport->requestCalls);
        self::assertSame(1, $this->transport->verifyCalls);

        $callbackReplay = $service->handleCallback(
            'A'.str_repeat('7', 35),
            'OK',
            $this->correlation('winner-callback-replay'),
        );
        self::assertTrue($callbackReplay->replayed);
        self::assertSame($verified->purchaseSettlementPublicId, $callbackReplay->purchaseSettlementPublicId);
        self::assertSame(1, $this->transport->verifyCalls);
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'zarinpal')->count());
    }

    public function test_verified_zarinpal_payment_loses_safely_after_wallet_wins_the_order(): void
    {
        [$userId, $quote, $decision, $opening] = $this->purchaseContext('loser', true);
        $service = $this->app->make(ZarinpalPaymentService::class);
        $initiated = $service->initiatePurchase(
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            $this->correlation('loser-initiate'),
        );
        self::assertSame(ZarinpalRequestState::Redirectable, $initiated->state);
        self::assertSame(1, $this->transport->requestCalls);

        $walletId = $this->fundedWallet($userId, 2_000_000, 'loser');
        $wallet = $this->app->make(PurchaseWalletPaymentService::class);
        $walletIntent = $wallet->reserve(
            'purchase.wallet.zarinpal-race.000001',
            $userId,
            $walletId,
            $quote->quotePublicId,
            $decision->publicId,
            $this->correlation('loser-wallet-reserve'),
        );
        $walletPaid = $wallet->capture($walletIntent->intentPublicId, $this->correlation('loser-wallet-capture'));
        self::assertSame($opening->orderPublicId, $walletPaid->orderPublicId);
        self::assertSame('paid', $walletPaid->state->value);

        $postWinnerReplay = $service->initiatePurchase(
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            $this->correlation('loser-initiate-after-wallet-winner'),
        );
        self::assertTrue($postWinnerReplay->replayed);
        self::assertSame($initiated->publicId, $postWinnerReplay->publicId);
        self::assertSame(1, $this->transport->requestCalls);

        $verified = $service->handleCallback(
            'A'.str_repeat('7', 35),
            'OK',
            $this->correlation('loser-zarinpal-callback'),
        );
        self::assertSame(ZarinpalRequestState::ManualReview, $verified->state);
        self::assertTrue($verified->manualReviewRequired);
        self::assertNull($verified->purchaseSettlementPublicId);
        self::assertSame('260000001', $verified->providerRefId);
        self::assertSame(1, $this->transport->verifyCalls);
        self::assertSame(0, DB::table('purchase_settlements')->where('provider_code', 'zarinpal')->count());
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
        self::assertSame(0, DB::table('zarinpal_payment_verifications')->count());
        self::assertSame(1, DB::table('zarinpal_verified_unsettled_evidence')->count());
        self::assertSame('purchase_order_unavailable', DB::table('zarinpal_verified_unsettled_evidence')->value('reason_code'));
        self::assertSame(1, DB::table('zarinpal_reconciliation_findings')->where('severity', 'critical')->count());
        self::assertSame('pending_manual_review', DB::table('payment_intents')->where('public_id', $initiated->paymentIntentPublicId)->value('state'));
        self::assertSame('paid', DB::table('orders')->where('id', $opening->orderId)->value('state'));

        $replay = $service->handleCallback(
            'A'.str_repeat('7', 35),
            'OK',
            $this->correlation('loser-zarinpal-callback-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame('260000001', $replay->providerRefId);
        self::assertSame(1, $this->transport->verifyCalls);
        self::assertSame(1, DB::table('zarinpal_verified_unsettled_evidence')->count());
        self::assertSame(1, DB::table('zarinpal_reconciliation_findings')->count());
    }

    private function purchaseContext(string $suffix, bool $includeWallet): array
    {
        $administratorId = $this->ownerAdministrator();
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'zarinpal.prepayment.quote.'.$suffix.'.'.substr(hash('sha256', (string) $userId), 0, 16),
            $userId,
            $offering['id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                $this->clock->value->modify('+30 minutes'),
            ),
            $this->correlation('quote-'.$suffix),
        );
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $this->configureHealthyMethod($eligibility, $administratorId, 'zarinpal', 1, $suffix);
        if ($includeWallet) {
            $this->configureHealthyMethod($eligibility, $administratorId, 'wallet', 2, $suffix);
        }
        $decision = $eligibility->evaluate(
            'zarinpal.prepayment.eligibility.'.$suffix.'.000001',
            $userId,
            $quote->quotePublicId,
        );
        $opening = $this->app->make(PurchaseOrderService::class)->openFromQuote(
            $quote->quotePublicId,
            $userId,
            $this->correlation('order-'.$suffix),
        );

        return [$userId, $quote, $decision, $opening];
    }

    private function configureHealthyMethod(
        PaymentMethodEligibilityService $service,
        int $administratorId,
        string $method,
        int $routeOrder,
        string $suffix,
    ): void {
        $service->configureMethod(
            'zarinpal.prepayment.method.'.$method.'.'.$suffix.'.000001',
            $administratorId,
            $method,
            true,
            false,
            $routeOrder,
            'Zarinpal pre-payment safety test configuration.',
            $this->correlation('method-'.$method.'-'.$suffix),
        );
        $service->recordHealth(
            'zarinpal.prepayment.health.'.$method.'.'.$suffix.'.000001',
            $administratorId,
            $method,
            true,
            $this->clock->value->modify('+10 minutes'),
            'Healthy pre-payment safety observation.',
            $this->correlation('health-'.$method.'-'.$suffix),
        );
    }

    private function fundedWallet(int $userId, int $amountIrr, string $suffix): int
    {
        $now = now('UTC');
        $assetId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'system.zarinpal.race.asset.'.$suffix,
            'account_class' => 'asset',
            'owner_user_id' => null,
            'wallet_bucket' => null,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $walletId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'wallet.cash.zarinpal.race.'.$suffix.'.'.$userId,
            'account_class' => 'liability',
            'owner_user_id' => $userId,
            'wallet_bucket' => 'cash',
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->app->make(LedgerPostingService::class)->post(
            'ledger.zarinpal.race.fund.'.$suffix.'.000001',
            'zarinpal_race_test_funding',
            $this->correlation('fund-'.$suffix),
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amountIrr)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amountIrr)),
            ],
            'test_fixture',
            'zarinpal-race-'.$suffix,
        );

        return $walletId;
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'zarinpal-prepayment:'.$suffix);
    }
}
