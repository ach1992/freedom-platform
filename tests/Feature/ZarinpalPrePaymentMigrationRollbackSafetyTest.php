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
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Application\PurchasePaymentIntentService;
use App\Modules\Payments\Application\PurchaseWalletPaymentService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalInquiryResult;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalRequestResult;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalTransport;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalVerifyResult;
use App\Modules\Payments\Zarinpal\Application\ZarinpalPaymentService;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeType;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

final class ZarinpalPrePaymentRollbackTransport implements ZarinpalTransport
{
    public ZarinpalRequestResult $requestResult;

    public ZarinpalVerifyResult $verifyResult;

    public function __construct()
    {
        $this->requestResult = ZarinpalRequestResult::accepted('A'.str_repeat('4', 35));
        $this->verifyResult = ZarinpalVerifyResult::verified('260003001', 100);
    }

    public function request(string $merchantId, int $amountIrr, string $callbackUrl, string $description, string $orderId): ZarinpalRequestResult
    {
        return $this->requestResult;
    }

    public function verify(string $merchantId, int $amountIrr, string $authority): ZarinpalVerifyResult
    {
        return $this->verifyResult;
    }

    public function inquiry(string $merchantId, string $authority): ZarinpalInquiryResult
    {
        return ZarinpalInquiryResult::available('PAID');
    }

    public function unverified(string $merchantId): array
    {
        return [];
    }
}

/** @requirement IPG-001 PAY-002 PAY-003 PRO-001 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
final class ZarinpalPrePaymentMigrationRollbackSafetyTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use CreatesBenefitCodeFixtures;
    use DatabaseTruncation;

    private ZarinpalPrePaymentRollbackTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Zarinpal pre-payment rollback safety requires MariaDB/MySQL.');
        }
        $this->seed();
        config()->set('app.url', 'http://localhost');
        config()->set('services.zarinpal.enabled', true);
        config()->set('services.zarinpal.merchant_id', '00000000-0000-0000-0000-000000000000');
        config()->set('services.zarinpal.callback_url', 'http://localhost/payments/zarinpal/callback');
        $this->transport = new ZarinpalPrePaymentRollbackTransport;
        $this->app->instance(ZarinpalTransport::class, $this->transport);
    }

    protected function tearDown(): void
    {
        try {
            if (! Schema::hasTable('zarinpal_verified_unsettled_evidence')) {
                $this->migration()->up();
            }
            if (isset($this->app)) {
                $this->truncateDatabaseTables();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_empty_and_legacy_only_zarinpal_authority_can_roll_back(): void
    {
        $migration = $this->migration();
        $migration->down();
        self::assertFalse(Schema::hasTable('zarinpal_verified_unsettled_evidence'));
        self::assertFalse(Schema::hasTable('zarinpal_reconciliation_findings'));
        self::assertFalse(Schema::hasTable('zarinpal_provider_evidence_claims'));
        $migration->up();

        [$userId, $quotePublicId, $decisionPublicId] = $this->plainContext('legacy-only', false, false);
        $intent = $this->app->make(PurchasePaymentIntentService::class)->create(
            'zpal.rollback.legacy.intent.000001',
            $userId,
            $quotePublicId,
            $decisionPublicId,
            'zarinpal',
            $this->correlation('legacy-intent'),
        );
        $legacy = $this->app->make(ZarinpalPaymentService::class)->initiate(
            'zpal.rollback.legacy.request.000001',
            $intent->intentPublicId,
            $this->correlation('legacy-initiate'),
        );
        self::assertSame('redirectable', $legacy->state->value);
        self::assertSame(0, DB::table('orders')->count());

        $migration->down();
        self::assertFalse(Schema::hasTable('zarinpal_verified_unsettled_evidence'));
        self::assertFalse(Schema::hasTable('zarinpal_reconciliation_findings'));
        self::assertFalse(Schema::hasTable('zarinpal_provider_evidence_claims'));
        self::assertSame(1, DB::table('zarinpal_payment_requests')->count());
        $migration->up();
    }

    public function test_legacy_request_that_later_acquires_pre_payment_order_blocks_semantic_rollback(): void
    {
        [$userId, $quotePublicId, $decisionPublicId] = $this->plainContext('legacy-then-order', false, false);
        $intent = $this->app->make(PurchasePaymentIntentService::class)->create(
            'zpal.rollback.legacy.then.order.intent.000001',
            $userId,
            $quotePublicId,
            $decisionPublicId,
            'zarinpal',
            $this->correlation('legacy-then-order-intent'),
        );
        $legacy = $this->app->make(ZarinpalPaymentService::class)->initiate(
            'zpal.rollback.noncanonical.request.000001',
            $intent->intentPublicId,
            $this->correlation('legacy-then-order-initiate'),
        );
        self::assertSame('redirectable', $legacy->state->value);
        self::assertSame(0, DB::table('orders')->count());

        $this->app->make(PurchaseOrderService::class)->openFromQuote(
            $quotePublicId,
            $userId,
            $this->correlation('legacy-then-order-open'),
        );
        self::assertSame(1, DB::table('orders')->where('source_quote_public_id', $quotePublicId)->count());

        $this->assertRollbackRejected();
    }

    public function test_active_redirectable_pre_payment_authority_blocks_rollback(): void
    {
        [$userId, $quotePublicId, $decisionPublicId] = $this->plainContext('redirectable', true, false);
        $receipt = $this->app->make(ZarinpalPaymentService::class)->initiatePurchase(
            $userId,
            $quotePublicId,
            $decisionPublicId,
            $this->correlation('redirectable-initiate'),
        );
        self::assertSame('redirectable', $receipt->state->value);

        $this->assertRollbackRejected();
    }

    public function test_uncertain_manual_review_pre_payment_authority_blocks_rollback(): void
    {
        [$userId, $quotePublicId, $decisionPublicId] = $this->plainContext('manual-review', true, false);
        $service = $this->app->make(ZarinpalPaymentService::class);
        $service->initiatePurchase($userId, $quotePublicId, $decisionPublicId, $this->correlation('manual-review-initiate'));
        $this->transport->verifyResult = ZarinpalVerifyResult::uncertain();
        $review = $service->handleCallback('A'.str_repeat('4', 35), 'OK', $this->correlation('manual-review-callback'));
        self::assertSame('manual_review', $review->state->value);

        $this->assertRollbackRejected();
    }

    public function test_pre_payment_authority_with_promotion_reservation_blocks_rollback(): void
    {
        [$userId, $quotePublicId, $decisionPublicId] = $this->discountedContext('promotion');
        $this->app->make(ZarinpalPaymentService::class)->initiatePurchase(
            $userId,
            $quotePublicId,
            $decisionPublicId,
            $this->correlation('promotion-initiate'),
        );
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        self::assertSame(0, DB::table('promotion_usage_redemptions')->count());

        $this->assertRollbackRejected();
    }

    public function test_losing_verified_evidence_and_finding_block_rollback(): void
    {
        [$userId, $quotePublicId, $decisionPublicId] = $this->plainContext('losing-evidence', true, true);
        $zarinpal = $this->app->make(ZarinpalPaymentService::class);
        $zarinpal->initiatePurchase($userId, $quotePublicId, $decisionPublicId, $this->correlation('losing-initiate'));
        $walletId = $this->fundedWallet($userId, 2_000_000, 'losing-evidence');
        $wallet = $this->app->make(PurchaseWalletPaymentService::class);
        $walletIntent = $wallet->reserve(
            'zpal.rollback.wallet.losing.000001',
            $userId,
            $walletId,
            $quotePublicId,
            $decisionPublicId,
            $this->correlation('losing-wallet-reserve'),
        );
        $wallet->capture($walletIntent->intentPublicId, $this->correlation('losing-wallet-capture'));
        $review = $zarinpal->handleCallback('A'.str_repeat('4', 35), 'OK', $this->correlation('losing-zarinpal-callback'));
        self::assertTrue($review->manualReviewRequired);
        self::assertSame(1, DB::table('zarinpal_verified_unsettled_evidence')->count());
        self::assertSame(1, DB::table('zarinpal_reconciliation_findings')->count());

        $this->assertRollbackRejected();
    }

    public function test_completed_pre_payment_zarinpal_authority_blocks_rollback(): void
    {
        [$userId, $quotePublicId, $decisionPublicId] = $this->plainContext('completed', true, false);
        $service = $this->app->make(ZarinpalPaymentService::class);
        $service->initiatePurchase($userId, $quotePublicId, $decisionPublicId, $this->correlation('completed-initiate'));
        $verified = $service->handleCallback('A'.str_repeat('4', 35), 'OK', $this->correlation('completed-callback'));
        self::assertSame('verified', $verified->state->value);
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'zarinpal')->count());
        self::assertSame('paid', DB::table('orders')->value('state'));

        $this->assertRollbackRejected();
    }

    /** @return array{0:int,1:string,2:string} */
    private function plainContext(string $suffix, bool $openOrder, bool $includeWallet): array
    {
        $administratorId = $this->ownerAdministrator();
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'zpal.rollback.quote.'.$suffix,
            $userId,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, now('UTC')->addMinutes(30)->toDateTimeImmutable()),
            $this->correlation($suffix.'-quote'),
        );
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $this->configureHealthyMethod($eligibility, $administratorId, 'zarinpal', 1, $suffix);
        if ($includeWallet) {
            $this->configureHealthyMethod($eligibility, $administratorId, 'wallet', 2, $suffix);
        }
        $decision = $eligibility->evaluate('zpal.rollback.eligibility.'.$suffix, $userId, $quote->quotePublicId);
        if ($openOrder) {
            $this->app->make(PurchaseOrderService::class)->openFromQuote(
                $quote->quotePublicId,
                $userId,
                $this->correlation($suffix.'-order'),
            );
        }

        return [$userId, $quote->quotePublicId, $decision->publicId];
    }

    /** @return array{0:int,1:string,2:string} */
    private function discountedContext(string $suffix): array
    {
        $offering = $this->activeBenefitOffering('zpal-rollback-'.$suffix);
        $version = (int) DB::table('plan_offerings')->where('id', $offering['id'])->value('version');
        $this->app->make(PlanOfferingService::class)->setVisibility(
            $offering['id'],
            $version,
            ProductVisibility::Visible,
            new CatalogChangeContext(
                'zpal-rollback-visible-'.substr(hash('sha256', $suffix), 0, 20),
                'zpal-rollback-visible-correlation-'.substr(hash('sha256', $suffix), 0, 16),
                'zarinpal_rollback_test',
                'Expose Zarinpal rollback test Offering.',
                $this->benefitOwner(),
            ),
        );
        $rule = $this->usageRule(
            $offering['id'],
            'zpal.rollback.rule.'.substr(hash('sha256', $suffix), 0, 12),
            90_000,
            1,
            null,
        );
        $campaign = 'zpal.rollback.discount.'.substr(hash('sha256', $suffix), 0, 12);
        $this->benefitCampaign(
            $campaign,
            BenefitCodeType::DiscountGrant,
            $this->discountDefinition($rule->ruleCode, $offering['id'], $offering['product_id'], $offering['server_id']),
            'zpal-rollback-'.$suffix,
        );
        $issue = $this->benefitIssue($campaign, 'zpal-rollback-'.$suffix, 1);
        $userId = $this->benefitUser('customer');
        $quoteService = $this->app->make(QuoteService::class);
        $source = $quoteService->create(
            'zpal-rollback-source-'.substr(hash('sha256', $suffix), 0, 20),
            $userId,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, now('UTC')->addMinutes(30)->toDateTimeImmutable()),
            $this->correlation($suffix.'-source'),
        );
        $discounts = $this->app->make(QuoteDiscountAuthority::class);
        $authorization = $discounts->authorize(new QuoteDiscountAuthorizationRequest(
            'zpal-rollback-auth-'.substr(hash('sha256', $suffix), 0, 20),
            $userId,
            $source->quotePublicId,
            $source->configurationSnapshotHash,
            (string) $issue->items[0]->fullCode,
            $this->correlation($suffix.'-auth'),
        ));
        $quote = $quoteService->create(
            'zpal-rollback-discounted-'.substr(hash('sha256', $suffix), 0, 20),
            $userId,
            $offering['id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                $authorization->ruleCode,
                $authorization->discountIrr,
                now('UTC')->addMinutes(30)->toDateTimeImmutable(),
            ),
            $this->correlation($suffix.'-discounted'),
        );
        $discounts->consume(new QuoteDiscountConsumptionRequest(
            'zpal-rollback-consume-'.substr(hash('sha256', $suffix), 0, 20),
            $userId,
            $authorization,
            $quote->quotePublicId,
            $quote->configurationSnapshotHash,
            $this->correlation($suffix.'-consume'),
        ));
        $administratorId = $this->benefitOwner();
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $this->configureHealthyMethod($eligibility, $administratorId, 'zarinpal', 1, $suffix.'-discounted');
        $decision = $eligibility->evaluate(
            'zpal-rollback-decision-'.substr(hash('sha256', $suffix), 0, 20),
            $userId,
            $quote->quotePublicId,
            $quote->configurationSnapshotHash,
        );
        $this->app->make(PurchaseOrderService::class)->openFromQuote(
            $quote->quotePublicId,
            $userId,
            $this->correlation($suffix.'-order'),
        );

        return [$userId, $quote->quotePublicId, $decision->publicId];
    }

    private function configureHealthyMethod(
        PaymentMethodEligibilityService $service,
        int $administratorId,
        string $method,
        int $routeOrder,
        string $suffix,
    ): void {
        $service->configureMethod(
            'zpal.rollback.method.'.$method.'.'.substr(hash('sha256', $suffix), 0, 16),
            $administratorId,
            $method,
            true,
            false,
            $routeOrder,
            'Zarinpal rollback safety test configuration.',
            $this->correlation($suffix.'-method-'.$method),
        );
        $service->recordHealth(
            'zpal.rollback.health.'.$method.'.'.substr(hash('sha256', $suffix), 0, 16),
            $administratorId,
            $method,
            true,
            now('UTC')->addMinutes(10)->toDateTimeImmutable(),
            'Healthy rollback safety observation.',
            $this->correlation($suffix.'-health-'.$method),
        );
    }

    private function fundedWallet(int $userId, int $amountIrr, string $suffix): int
    {
        $now = now('UTC');
        $assetId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'system.zpal.rollback.asset.'.$suffix,
            'account_class' => 'asset',
            'owner_user_id' => null,
            'wallet_bucket' => null,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $walletId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'wallet.cash.zpal.rollback.'.$suffix.'.'.$userId,
            'account_class' => 'liability',
            'owner_user_id' => $userId,
            'wallet_bucket' => 'cash',
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->app->make(LedgerPostingService::class)->post(
            'ledger.zpal.rollback.fund.'.$suffix,
            'zarinpal_rollback_test_funding',
            $this->correlation($suffix.'-fund'),
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amountIrr)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amountIrr)),
            ],
            'test_fixture',
            'zpal-rollback-'.$suffix,
        );

        return $walletId;
    }

    private function assertRollbackRejected(): void
    {
        try {
            $this->migration()->down();
            self::fail('Durable pre-payment Zarinpal authority must refuse semantic rollback.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Cannot roll back Zarinpal pre-payment Order authority while durable pre-payment or reconciliation authority exists.',
                $exception->getMessage(),
            );
        }
        self::assertTrue(Schema::hasTable('zarinpal_verified_unsettled_evidence'));
        self::assertTrue(Schema::hasTable('zarinpal_reconciliation_findings'));
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_09_07_000100_enable_zarinpal_pre_payment_order_authority.php');

        return $migration;
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'zarinpal-prepayment-rollback:'.$suffix);
    }
}
