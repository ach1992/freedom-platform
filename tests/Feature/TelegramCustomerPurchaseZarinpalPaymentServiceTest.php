<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalInquiryResult;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalRequestResult;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalTransport;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalUnverifiedCandidate;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalVerifyResult;
use App\Modules\Payments\Zarinpal\Application\TelegramCustomerPurchaseZarinpalPaymentService;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Database\Seeders\WalletFinancialFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TelegramZarinpalAdapterTransport implements ZarinpalTransport
{
    public int $requestCalls = 0;

    public function request(string $merchantId, int $amountIrr, string $callbackUrl, string $description, string $orderId): ZarinpalRequestResult
    {
        $this->requestCalls++;

        return ZarinpalRequestResult::accepted('A'.str_repeat('7', 35));
    }

    public function verify(string $merchantId, int $amountIrr, string $authority): ZarinpalVerifyResult
    {
        return ZarinpalVerifyResult::verified('260000004', 100);
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

final class TelegramZarinpalAdapterClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement BUY-001 BUY-003 PAY-001 PAY-002 IPG-001 DAT-002 DAT-003 SEC-002 QUA-001 QUA-004 */
final class TelegramCustomerPurchaseZarinpalPaymentServiceTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private TelegramZarinpalAdapterClock $clock;

    private TelegramZarinpalAdapterTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->seed(WalletFinancialFoundationSeeder::class);
        $this->clock = new TelegramZarinpalAdapterClock(new DateTimeImmutable('2026-09-10T00:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET timestamp = '.$this->clock->value->getTimestamp());
            $this->beforeApplicationDestroyed(static function (): void {
                DB::statement('SET timestamp = DEFAULT');
            });
        }
        $this->transport = new TelegramZarinpalAdapterTransport;
        $this->app->instance(ZarinpalTransport::class, $this->transport);
        config()->set('app.url', 'http://localhost');
        config()->set('services.zarinpal.enabled', true);
        config()->set('services.zarinpal.merchant_id', '00000000-0000-0000-0000-000000000000');
        config()->set('services.zarinpal.callback_url', 'http://localhost/payments/zarinpal/callback');
    }

    public function test_adapter_revalidates_checkout_and_exact_replay_never_reissues_provider_request(): void
    {
        [$userId, $quote, $decision, $opening] = $this->purchaseContext('success');
        $service = $this->app->make(TelegramCustomerPurchaseZarinpalPaymentService::class);
        $operationKey = hash('sha256', 'telegram-zarinpal-adapter-success');

        $first = $service->prepareForSelf(
            $userId,
            $userId,
            $opening->orderPublicId,
            $quote->quotePublicId,
            $quote->configurationSnapshotHash,
            $decision->publicId,
            $decision->configurationSnapshotHash,
            $operationKey,
        );
        self::assertSame('redirectable', $first->state);
        self::assertSame('https://payment.zarinpal.com/pg/StartPay/A'.str_repeat('7', 35), $first->redirectUrl);
        self::assertFalse($first->manualReviewRequired);
        self::assertSame(1, $this->transport->requestCalls);

        $replay = $service->prepareForSelf(
            $userId,
            $userId,
            $opening->orderPublicId,
            $quote->quotePublicId,
            $quote->configurationSnapshotHash,
            $decision->publicId,
            $decision->configurationSnapshotHash,
            $operationKey,
        );
        self::assertTrue($replay->replayed);
        self::assertSame($first->requestPublicId, $replay->requestPublicId);
        self::assertSame($first->paymentIntentPublicId, $replay->paymentIntentPublicId);
        self::assertSame($first->redirectUrl, $replay->redirectUrl);
        self::assertSame(1, $this->transport->requestCalls);
        self::assertSame(1, DB::table('zarinpal_payment_requests')->count());
        self::assertSame(0, DB::table('purchase_settlements')->where('provider_code', 'zarinpal')->count());
        self::assertSame(0, DB::table('provisioning_operations')->count());
    }

    public function test_stale_or_foreign_telegram_authority_fails_before_provider_mutation(): void
    {
        [$userId, $quote, $decision, $opening] = $this->purchaseContext('denied');
        $service = $this->app->make(TelegramCustomerPurchaseZarinpalPaymentService::class);

        try {
            $service->prepareForSelf(
                $userId,
                $userId,
                $opening->orderPublicId,
                $quote->quotePublicId,
                $quote->configurationSnapshotHash,
                $decision->publicId,
                str_repeat('0', 64),
                hash('sha256', 'telegram-zarinpal-stale-decision'),
            );
            self::fail('A stale Telegram PAY-001 snapshot must fail before Zarinpal provider mutation.');
        } catch (AuthorizationException) {
            // Expected.
        }
        self::assertSame(0, $this->transport->requestCalls);
        self::assertSame(0, DB::table('zarinpal_payment_requests')->count());

        try {
            $service->prepareForSelf(
                $userId,
                $userId + 1,
                $opening->orderPublicId,
                $quote->quotePublicId,
                $quote->configurationSnapshotHash,
                $decision->publicId,
                $decision->configurationSnapshotHash,
                hash('sha256', 'telegram-zarinpal-foreign-actor'),
            );
            self::fail('A foreign Telegram subject must fail before Zarinpal provider mutation.');
        } catch (AuthorizationException) {
            // Expected.
        }
        self::assertSame(0, $this->transport->requestCalls);
        self::assertSame(0, DB::table('payment_intents')->where('provider_code', 'zarinpal')->count());
        self::assertSame(0, DB::table('purchase_settlements')->where('provider_code', 'zarinpal')->count());
    }

    private function purchaseContext(string $suffix): array
    {
        $administratorId = $this->ownerAdministrator();
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'telegram.zarinpal.adapter.quote.'.$suffix.'.'.substr(hash('sha256', (string) $userId), 0, 16),
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
        $this->configureHealthyMethod($eligibility, $administratorId, $suffix);
        $decision = $eligibility->evaluate(
            'telegram.zarinpal.adapter.eligibility.'.$suffix.'.000001',
            $userId,
            $quote->quotePublicId,
            $quote->configurationSnapshotHash,
        );
        $opening = $this->app->make(PurchaseOrderService::class)->openFromQuote(
            $quote->quotePublicId,
            $userId,
            $this->correlation('order-'.$suffix),
        );

        return [$userId, $quote, $decision, $opening];
    }

    private function configureHealthyMethod(PaymentMethodEligibilityService $service, int $administratorId, string $suffix): void
    {
        $service->configureMethod(
            'telegram.zarinpal.adapter.method.'.$suffix.'.000001',
            $administratorId,
            'zarinpal',
            true,
            false,
            1,
            'Telegram Zarinpal adapter test configuration.',
            $this->correlation('method-'.$suffix),
        );
        $service->recordHealth(
            'telegram.zarinpal.adapter.health.'.$suffix.'.000001',
            $administratorId,
            'zarinpal',
            true,
            $this->clock->value->modify('+10 minutes'),
            'Healthy Telegram Zarinpal adapter test observation.',
            $this->correlation('health-'.$suffix),
        );
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'telegram-zarinpal-adapter:'.$suffix);
    }
}
