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
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ZarinpalProviderBoundaryFakeTransport implements ZarinpalTransport
{
    public int $requestCalls = 0;

    public ZarinpalRequestResult $requestResult;

    public function __construct()
    {
        $this->requestResult = ZarinpalRequestResult::accepted('A'.str_repeat('9', 35));
    }

    public function request(string $merchantId, int $amountIrr, string $callbackUrl, string $description, string $orderId): ZarinpalRequestResult
    {
        $this->requestCalls++;

        return $this->requestResult;
    }

    public function verify(string $merchantId, int $amountIrr, string $authority): ZarinpalVerifyResult
    {
        return ZarinpalVerifyResult::verified('260000003', 100);
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

final class ZarinpalProviderBoundaryClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement BUY-001 PAY-001 PAY-002 PAY-003 IPG-001 PRO-001 DAT-002 DAT-003 DAT-004 INT-001 INT-002 QUA-001 QUA-004 */
final class ZarinpalPrePaymentProviderBoundaryTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private ZarinpalProviderBoundaryClock $clock;

    private ZarinpalProviderBoundaryFakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->seed(WalletFinancialFoundationSeeder::class);
        $this->clock = new ZarinpalProviderBoundaryClock(new DateTimeImmutable('2026-09-07T10:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
        $this->transport = new ZarinpalProviderBoundaryFakeTransport;
        $this->app->instance(ZarinpalTransport::class, $this->transport);
        config()->set('app.url', 'http://localhost');
        config()->set('services.zarinpal.enabled', true);
        config()->set('services.zarinpal.merchant_id', '00000000-0000-0000-0000-000000000000');
        config()->set('services.zarinpal.callback_url', 'http://localhost/payments/zarinpal/callback');
    }

    public function test_already_paid_order_prevents_fresh_zarinpal_provider_mutation(): void
    {
        [$userId, $quote, $decision, $opening] = $this->purchaseContext('preflight', true);
        $walletId = $this->fundedWallet($userId, 2_000_000, 'preflight');
        $wallet = $this->app->make(PurchaseWalletPaymentService::class);
        $walletIntent = $wallet->reserve(
            'purchase.wallet.zarinpal-preflight.000001',
            $userId,
            $walletId,
            $quote->quotePublicId,
            $decision->publicId,
            $this->correlation('preflight-wallet-reserve'),
        );
        $wallet->capture($walletIntent->intentPublicId, $this->correlation('preflight-wallet-capture'));
        self::assertSame('paid', DB::table('orders')->where('id', $opening->orderId)->value('state'));

        try {
            $this->app->make(ZarinpalPaymentService::class)->initiatePurchase(
                $userId,
                $quote->quotePublicId,
                $decision->publicId,
                $this->correlation('preflight-zarinpal-initiate'),
            );
            self::fail('An already-paid pre-payment Order must reject a fresh Zarinpal initiation.');
        } catch (DomainException $exception) {
            self::assertSame('Zarinpal purchase requires a payable pre-payment Order.', $exception->getMessage());
        }

        self::assertSame(0, $this->transport->requestCalls);
        self::assertSame(0, DB::table('payment_intents')->where('provider_code', 'zarinpal')->count());
        self::assertSame(0, DB::table('zarinpal_payment_requests')->count());
        self::assertSame(0, DB::table('purchase_settlements')->where('provider_code', 'zarinpal')->count());
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
    }

    public function test_uncertain_purchase_request_is_durable_and_exact_replay_never_reissues_provider_mutation(): void
    {
        [$userId, $quote, $decision] = $this->purchaseContext('uncertain', false);
        $this->transport->requestResult = ZarinpalRequestResult::uncertain();
        $service = $this->app->make(ZarinpalPaymentService::class);

        $uncertain = $service->initiatePurchase(
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            $this->correlation('uncertain-initiate'),
        );
        self::assertSame(ZarinpalRequestState::Uncertain, $uncertain->state);
        self::assertTrue($uncertain->manualReviewRequired);
        self::assertFalse($uncertain->replayed);
        self::assertSame(1, $this->transport->requestCalls);
        self::assertSame('created', DB::table('payment_intents')->where('public_id', $uncertain->paymentIntentPublicId)->value('state'));
        self::assertSame(0, DB::table('purchase_settlements')->where('provider_code', 'zarinpal')->count());

        $replay = $service->initiatePurchase(
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            $this->correlation('uncertain-initiate-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($uncertain->publicId, $replay->publicId);
        self::assertSame(ZarinpalRequestState::Uncertain, $replay->state);
        self::assertSame(1, $this->transport->requestCalls);
        self::assertSame(1, DB::table('zarinpal_payment_requests')->count());
        self::assertSame(1, DB::table('payment_intents')->where('provider_code', 'zarinpal')->count());
    }

    private function purchaseContext(string $suffix, bool $includeWallet): array
    {
        $administratorId = $this->ownerAdministrator();
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'zarinpal.provider-boundary.quote.'.$suffix.'.'.substr(hash('sha256', (string) $userId), 0, 16),
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
            'zarinpal.provider-boundary.eligibility.'.$suffix.'.000001',
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
            'zarinpal.provider-boundary.method.'.$method.'.'.$suffix.'.000001',
            $administratorId,
            $method,
            true,
            false,
            $routeOrder,
            'Zarinpal provider-boundary safety test configuration.',
            $this->correlation('method-'.$method.'-'.$suffix),
        );
        $service->recordHealth(
            'zarinpal.provider-boundary.health.'.$method.'.'.$suffix.'.000001',
            $administratorId,
            $method,
            true,
            $this->clock->value->modify('+10 minutes'),
            'Healthy provider-boundary safety observation.',
            $this->correlation('health-'.$method.'-'.$suffix),
        );
    }

    private function fundedWallet(int $userId, int $amountIrr, string $suffix): int
    {
        $now = now('UTC');
        $assetId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'system.zarinpal.boundary.asset.'.$suffix,
            'account_class' => 'asset',
            'owner_user_id' => null,
            'wallet_bucket' => null,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $walletId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'wallet.cash.zarinpal.boundary.'.$suffix.'.'.$userId,
            'account_class' => 'liability',
            'owner_user_id' => $userId,
            'wallet_bucket' => 'cash',
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->app->make(LedgerPostingService::class)->post(
            'ledger.zarinpal.boundary.fund.'.$suffix.'.000001',
            'zarinpal_boundary_test_funding',
            $this->correlation('fund-'.$suffix),
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amountIrr)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amountIrr)),
            ],
            'test_fixture',
            'zarinpal-boundary-'.$suffix,
        );

        return $walletId;
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'zarinpal-provider-boundary:'.$suffix);
    }
}
