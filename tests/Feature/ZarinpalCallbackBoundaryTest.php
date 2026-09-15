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
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalVerifyResult;
use App\Modules\Payments\Zarinpal\Application\ZarinpalPaymentService;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CallbackBoundaryZarinpalTransport implements ZarinpalTransport
{
    public ?int $verifiedAmountIrr = null;

    public function request(string $merchantId, int $amountIrr, string $callbackUrl, string $description, string $orderId): ZarinpalRequestResult
    {
        return ZarinpalRequestResult::accepted('A'.str_repeat('8', 35));
    }

    public function verify(string $merchantId, int $amountIrr, string $authority): ZarinpalVerifyResult
    {
        $this->verifiedAmountIrr = $amountIrr;

        return ZarinpalVerifyResult::verified('246813579', 100);
    }

    public function inquiry(string $merchantId, string $authority): ZarinpalInquiryResult
    {
        return ZarinpalInquiryResult::unavailable();
    }

    public function unverified(string $merchantId): array
    {
        return [];
    }
}

/** @requirement IPG-001 PAY-002 PAY-003 SEC-002 INT-001 INT-002 QUA-001 */
final class ZarinpalCallbackBoundaryTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private CallbackBoundaryZarinpalTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->transport = new CallbackBoundaryZarinpalTransport;
        $this->app->instance(ZarinpalTransport::class, $this->transport);
        config()->set('app.url', 'http://localhost');
        config()->set('services.zarinpal.enabled', true);
        config()->set('services.zarinpal.merchant_id', '00000000-0000-0000-0000-000000000000');
        config()->set('services.zarinpal.callback_url', 'http://localhost/payments/zarinpal/callback');
    }

    public function test_callback_amount_query_is_ignored_and_stored_purchase_amount_is_server_verified(): void
    {
        $userId = $this->quoteUser('customer');
        $administratorId = $this->ownerAdministrator();
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'zarinpal.callback.boundary.quote',
            $userId,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, now('UTC')->addMinutes(30)->toDateTimeImmutable()),
            hash('sha256', 'zarinpal-callback-boundary-quote'),
        );
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'zarinpal.callback.boundary.method',
            $administratorId,
            'zarinpal',
            true,
            false,
            1,
            'Zarinpal callback boundary test configuration.',
            hash('sha256', 'zarinpal-callback-boundary-method'),
        );
        $eligibility->recordHealth(
            'zarinpal.callback.boundary.health',
            $administratorId,
            'zarinpal',
            true,
            now('UTC')->addMinutes(10)->toDateTimeImmutable(),
            'Healthy Zarinpal callback boundary observation.',
            hash('sha256', 'zarinpal-callback-boundary-health'),
        );
        $decision = $eligibility->evaluate('zarinpal.callback.boundary.eligibility', $userId, $quote->quotePublicId);
        $intent = $this->app->make(PurchasePaymentIntentService::class)->create(
            'zarinpal.callback.boundary.intent',
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            'zarinpal',
            hash('sha256', 'zarinpal-callback-boundary-intent'),
        );
        $expectedAmount = $intent->amount->amount();

        $this->app->make(ZarinpalPaymentService::class)->initiate(
            'zarinpal.callback.boundary.request',
            $intent->intentPublicId,
            hash('sha256', 'zarinpal-callback-boundary-initiate'),
        );

        $response = $this->get('/payments/zarinpal/callback?Authority='.'A'.str_repeat('8', 35).'&Status=OK&Amount=1');

        $response->assertOk()->assertJson(['status' => 'verified']);
        self::assertSame($expectedAmount, $this->transport->verifiedAmountIrr);
        self::assertNotSame(1, $this->transport->verifiedAmountIrr);
        self::assertSame($expectedAmount, (int) DB::table('purchase_settlements')->value('amount_irr'));
        self::assertSame(1, DB::table('purchase_settlements')->count());
    }
}
