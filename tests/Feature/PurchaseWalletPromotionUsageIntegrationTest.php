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
use App\Modules\Payments\Application\PurchasePaymentMaintenanceService;
use App\Modules\Payments\Application\PurchaseWalletPaymentService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Promotions\Application\PromotionRuleVersionReceipt;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeType;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseWalletPayment;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseWalletUnavailable;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

final class PurchaseWalletPromotionClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement BUY-002 PAY-001 PAY-002 PAY-003 PRO-001 WAL-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
final class PurchaseWalletPromotionUsageIntegrationTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use RefreshDatabase;

    private PurchaseWalletPromotionClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->clock = new PurchaseWalletPromotionClock(now('UTC')->toDateTimeImmutable());
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_purchase_payment_maintenance_command_is_json_safe_and_scheduled(): void
    {
        $expected = json_encode([
            'wallet_intents_examined' => 0,
            'expired_wallet_intents' => 0,
            'promotion_reservations_examined' => 0,
            'released_promotion_reservations' => 0,
            'failures' => 0,
        ], JSON_THROW_ON_ERROR);

        $this->artisan('payments:purchase-maintenance', ['--limit' => 10, '--json' => true])
            ->expectsOutput($expected)
            ->assertExitCode(0);
        $this->artisan('schedule:list')
            ->expectsOutputToContain('payments:purchase-maintenance')
            ->assertExitCode(0);
    }

    public function test_real_telegram_wallet_adapter_reauthorizes_discounted_checkout_and_exposes_only_public_identity(): void
    {
        $source = $this->promotionSource('telegram-adapter', 1);
        $checkout = $this->discountedCheckout($source, $source['codes'][0], 'telegram-adapter');
        $walletId = $this->fundedCashWallet($checkout['user_id'], $checkout['quote']->finalPriceIrr, 'telegram-adapter');
        $adapter = $this->app->make(TelegramCustomerPurchaseWalletPayment::class);
        $operationKey = hash('sha256', 'telegram-wallet-adapter-reserve');

        $reserved = $adapter->reserveForSelf(
            $checkout['user_id'],
            $checkout['user_id'],
            $checkout['order']->orderPublicId,
            $checkout['quote']->quotePublicId,
            $checkout['quote']->configurationSnapshotHash,
            $checkout['decision']->publicId,
            $checkout['decision']->configurationSnapshotHash,
            $operationKey,
        );
        self::assertSame($checkout['order']->orderPublicId, $reserved->orderPublicId);
        self::assertSame($checkout['quote']->quotePublicId, $reserved->quotePublicId);
        self::assertSame($checkout['decision']->publicId, $reserved->decisionPublicId);
        self::assertSame($checkout['quote']->finalPriceIrr, $reserved->amountIrr);
        self::assertGreaterThanOrEqual(0, $reserved->availableBalanceAfterHoldIrr);
        self::assertSame([
            'paymentIntentPublicId',
            'orderPublicId',
            'quotePublicId',
            'decisionPublicId',
            'amountIrr',
            'availableBalanceAfterHoldIrr',
            'replayed',
        ], array_keys(get_object_vars($reserved)));
        $safe = json_encode($reserved, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('account', strtolower($safe));
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(0, $reserved->availableBalanceAfterHoldIrr);

        $reserveReplay = $adapter->reserveForSelf(
            $checkout['user_id'],
            $checkout['user_id'],
            $checkout['order']->orderPublicId,
            $checkout['quote']->quotePublicId,
            $checkout['quote']->configurationSnapshotHash,
            $checkout['decision']->publicId,
            $checkout['decision']->configurationSnapshotHash,
            $operationKey,
        );
        self::assertSame($reserved->paymentIntentPublicId, $reserveReplay->paymentIntentPublicId);
        self::assertTrue($reserveReplay->replayed);
        self::assertSame(0, $reserveReplay->availableBalanceAfterHoldIrr);
        self::assertSame(1, DB::table('wallet_holds')->where('status', 'active')->count());
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());

        $paid = $adapter->captureForSelf(
            $checkout['user_id'],
            $checkout['user_id'],
            $checkout['order']->orderPublicId,
            $checkout['quote']->quotePublicId,
            $checkout['quote']->configurationSnapshotHash,
            $checkout['decision']->publicId,
            $checkout['decision']->configurationSnapshotHash,
            $reserved->paymentIntentPublicId,
            hash('sha256', 'telegram-wallet-adapter-capture'),
        );
        self::assertSame($reserved->paymentIntentPublicId, $paid->paymentIntentPublicId);
        self::assertSame($checkout['order']->orderPublicId, $paid->orderPublicId);
        self::assertSame($checkout['quote']->quotePublicId, $paid->quotePublicId);
        self::assertSame($checkout['quote']->finalPriceIrr, $paid->amountIrr);
        self::assertSame(1, DB::table('purchase_settlements')->count());
        self::assertSame(1, DB::table('promotion_usage_redemptions')->count());

        try {
            $adapter->reserveForSelf(
                $checkout['user_id'] + 1000,
                $checkout['user_id'],
                $checkout['order']->orderPublicId,
                $checkout['quote']->quotePublicId,
                $checkout['quote']->configurationSnapshotHash,
                $checkout['decision']->publicId,
                $checkout['decision']->configurationSnapshotHash,
                hash('sha256', 'telegram-wallet-adapter-forged'),
            );
            self::fail('Expected cross-actor Telegram wallet rejection.');
        } catch (AuthorizationException) {
            self::assertSame(1, DB::table('purchase_settlements')->count());
        }
    }

    public function test_expired_real_telegram_wallet_confirmation_fails_closed_without_settlement_or_second_effect(): void
    {
        $source = $this->promotionSource('telegram-expired', 1);
        $checkout = $this->discountedCheckout($source, $source['codes'][0], 'telegram-expired');
        $this->fundedCashWallet($checkout['user_id'], 2_000_000, 'telegram-expired');
        $adapter = $this->app->make(TelegramCustomerPurchaseWalletPayment::class);
        $reserved = $adapter->reserveForSelf(
            $checkout['user_id'],
            $checkout['user_id'],
            $checkout['order']->orderPublicId,
            $checkout['quote']->quotePublicId,
            $checkout['quote']->configurationSnapshotHash,
            $checkout['decision']->publicId,
            $checkout['decision']->configurationSnapshotHash,
            hash('sha256', 'telegram-wallet-expired-reserve'),
        );

        $this->clock->value = $this->clock->value->modify('+31 minutes');
        $maintenance = $this->app->make(PurchasePaymentMaintenanceService::class)->run();
        self::assertSame(1, $maintenance->expiredWalletIntents);
        self::assertSame('expired', DB::table('payment_intents')->where('public_id', $reserved->paymentIntentPublicId)->value('state'));
        self::assertSame('released', DB::table('wallet_holds')->where('source_id', $reserved->paymentIntentPublicId)->value('status'));

        try {
            $adapter->captureForSelf(
                $checkout['user_id'],
                $checkout['user_id'],
                $checkout['order']->orderPublicId,
                $checkout['quote']->quotePublicId,
                $checkout['quote']->configurationSnapshotHash,
                $checkout['decision']->publicId,
                $checkout['decision']->configurationSnapshotHash,
                $reserved->paymentIntentPublicId,
                hash('sha256', 'telegram-wallet-expired-confirm'),
            );
            self::fail('Expected expired Telegram wallet confirmation rejection.');
        } catch (TelegramCustomerPurchaseWalletUnavailable $exception) {
            self::assertSame('Telegram wallet payment is no longer confirmable.', $exception->getMessage());
        }
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(0, DB::table('promotion_usage_redemptions')->count());
        self::assertSame(1, DB::table('promotion_usage_releases')->count());
        self::assertSame('awaiting_payment', DB::table('orders')->where('public_id', $checkout['order']->orderPublicId)->value('state'));
    }

    public function test_discounted_wallet_reserve_and_capture_reserve_and_finalize_promotion_usage_once(): void
    {
        $source = $this->promotionSource('capture', 1);
        $checkout = $this->discountedCheckout($source, $source['codes'][0], 'capture');
        $walletId = $this->fundedCashWallet($checkout['user_id'], 2_000_000, 'capture');
        $service = $this->app->make(PurchaseWalletPaymentService::class);

        $intent = $service->reserve(
            'wallet-promo-capture-reserve-0001',
            $checkout['user_id'],
            $walletId,
            $checkout['quote']->quotePublicId,
            $checkout['decision']->publicId,
            $this->correlation('capture-reserve'),
        );

        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        self::assertSame(0, DB::table('promotion_usage_releases')->count());
        self::assertSame(0, DB::table('promotion_usage_redemptions')->count());
        self::assertSame(1, DB::table('wallet_holds')->where('source_id', $intent->intentPublicId)->where('status', 'active')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());

        $paid = $service->capture($intent->intentPublicId, $this->correlation('capture'));
        self::assertSame('paid', $paid->state->value);
        self::assertSame(1, DB::table('promotion_usage_redemptions')->count());
        self::assertSame(0, DB::table('promotion_usage_releases')->count());
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
        self::assertSame('captured', DB::table('wallet_holds')->where('source_id', $intent->intentPublicId)->value('status'));

        $replay = $service->capture($intent->intentPublicId, $this->correlation('capture-replay'));
        self::assertTrue($replay->replayed);
        self::assertSame($paid->purchaseSettlementPublicId, $replay->purchaseSettlementPublicId);
        self::assertSame(1, DB::table('promotion_usage_redemptions')->count());
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
    }

    public function test_insufficient_wallet_balance_rolls_back_payment_intent_hold_and_new_promotion_reservation(): void
    {
        $source = $this->promotionSource('insufficient', 1);
        $checkout = $this->discountedCheckout($source, $source['codes'][0], 'insufficient');
        $walletId = $this->fundedCashWallet($checkout['user_id'], 100_000, 'insufficient');
        $service = $this->app->make(PurchaseWalletPaymentService::class);

        try {
            $service->reserve(
                'wallet-promo-insufficient-0001',
                $checkout['user_id'],
                $walletId,
                $checkout['quote']->quotePublicId,
                $checkout['decision']->publicId,
                $this->correlation('insufficient'),
            );
            self::fail('Expected insufficient wallet balance.');
        } catch (DomainException $exception) {
            self::assertSame('Wallet available balance is insufficient for this hold.', $exception->getMessage());
        }

        self::assertSame(0, DB::table('payment_intents')->where('purpose', 'purchase')->count());
        self::assertSame(0, DB::table('purchase_wallet_reservations')->count());
        self::assertSame(0, DB::table('wallet_holds')->where('source_type', 'payment_intent')->count());
        self::assertSame(0, DB::table('promotion_usage_reservations')->count());
        self::assertSame(0, DB::table('promotion_usage_redemptions')->count());
    }

    public function test_payment_time_total_usage_capacity_rejects_second_wallet_before_hold_or_intent_commit(): void
    {
        $source = $this->promotionSource('capacity', 1, 2);
        $first = $this->discountedCheckout($source, $source['codes'][0], 'capacity-first');
        $second = $this->discountedCheckout($source, $source['codes'][1], 'capacity-second');
        $firstWallet = $this->fundedCashWallet($first['user_id'], 2_000_000, 'capacity-first');
        $secondWallet = $this->fundedCashWallet($second['user_id'], 2_000_000, 'capacity-second');
        $service = $this->app->make(PurchaseWalletPaymentService::class);

        $service->reserve(
            'wallet-promo-capacity-first-0001',
            $first['user_id'],
            $firstWallet,
            $first['quote']->quotePublicId,
            $first['decision']->publicId,
            $this->correlation('capacity-first'),
        );
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        self::assertSame(0, DB::table('promotion_usage_releases')->count());

        try {
            $service->reserve(
                'wallet-promo-capacity-second-0001',
                $second['user_id'],
                $secondWallet,
                $second['quote']->quotePublicId,
                $second['decision']->publicId,
                $this->correlation('capacity-second'),
            );
            self::fail('Expected promotion total usage capacity exhaustion.');
        } catch (DomainException $exception) {
            self::assertSame('Promotion global usage capacity is exhausted.', $exception->getMessage());
        }

        self::assertSame(1, DB::table('payment_intents')->where('purpose', 'purchase')->count());
        self::assertSame(1, DB::table('purchase_wallet_reservations')->count());
        self::assertSame(0, DB::table('wallet_holds')->where('ledger_account_id', $secondWallet)->count());
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        self::assertSame(0, DB::table('promotion_usage_releases')->count());
    }

    public function test_cancel_retains_discount_capacity_until_quote_expiry_then_maintenance_releases_it(): void
    {
        $source = $this->promotionSource('maintenance', 1);
        $checkout = $this->discountedCheckout($source, $source['codes'][0], 'maintenance');
        $walletId = $this->fundedCashWallet($checkout['user_id'], 2_000_000, 'maintenance');
        $service = $this->app->make(PurchaseWalletPaymentService::class);
        $intent = $service->reserve(
            'wallet-promo-maintenance-0001',
            $checkout['user_id'],
            $walletId,
            $checkout['quote']->quotePublicId,
            $checkout['decision']->publicId,
            $this->correlation('maintenance-reserve'),
        );

        $service->cancel($intent->intentPublicId, $this->correlation('maintenance-cancel'));
        self::assertSame('canceled', DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->value('state'));
        self::assertSame('released', DB::table('wallet_holds')->where('source_id', $intent->intentPublicId)->value('status'));
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        self::assertSame(0, DB::table('promotion_usage_releases')->count());

        $beforeExpiry = $this->app->make(PurchasePaymentMaintenanceService::class)->run();
        self::assertSame(0, $beforeExpiry->walletIntentsExamined);
        self::assertSame(0, $beforeExpiry->promotionReservationsExamined);
        self::assertSame(0, DB::table('promotion_usage_releases')->count());

        $this->clock->value = $this->clock->value->modify('+31 minutes');
        $releaseTransactionLevel = null;
        DB::connection()->beforeExecuting(function (string $query, array $bindings, Connection $connection) use (&$releaseTransactionLevel): void {
            unset($bindings);
            if (str_contains(strtolower($query), 'insert into `promotion_usage_releases`')) {
                $releaseTransactionLevel = $connection->transactionLevel();
            }
        });
        $afterExpiry = $this->app->make(PurchasePaymentMaintenanceService::class)->run();
        self::assertSame(0, $afterExpiry->walletIntentsExamined);
        self::assertSame(1, $afterExpiry->promotionReservationsExamined);
        self::assertSame(1, $afterExpiry->releasedPromotionReservations);
        self::assertSame(0, $afterExpiry->failures);
        self::assertNotNull($releaseTransactionLevel);
        self::assertGreaterThanOrEqual(2, $releaseTransactionLevel);
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        self::assertSame(1, DB::table('promotion_usage_releases')->count());
        self::assertSame(0, DB::table('promotion_usage_redemptions')->count());
        $replay = $this->app->make(PurchasePaymentMaintenanceService::class)->run();
        self::assertSame(0, $replay->walletIntentsExamined);
        self::assertSame(0, $replay->promotionReservationsExamined);

        $this->clock->value = $this->clock->value->modify('-31 minutes');
        try {
            $service->reserve(
                'wallet-promo-maintenance-after-release-0002',
                $checkout['user_id'],
                $walletId,
                $checkout['quote']->quotePublicId,
                $checkout['decision']->publicId,
                $this->correlation('maintenance-after-release'),
            );
            self::fail('Expected released promotion reservation reuse rejection.');
        } catch (DomainException $exception) {
            self::assertSame('Discounted purchase Quote promotion usage reservation is no longer active.', $exception->getMessage());
        }
        self::assertSame(1, DB::table('payment_intents')->where('purpose', 'purchase')->count());
        self::assertSame(1, DB::table('promotion_usage_releases')->count());
        self::assertSame(0, DB::table('wallet_holds')->where('status', 'active')->count());
    }

    public function test_reselected_wallet_reuses_quote_promotion_reservation_and_maintenance_avoids_stale_terminal_failure(): void
    {
        $source = $this->promotionSource('reselected', 1);
        $checkout = $this->discountedCheckout($source, $source['codes'][0], 'reselected');
        $walletId = $this->fundedCashWallet($checkout['user_id'], 2_000_000, 'reselected');
        $service = $this->app->make(PurchaseWalletPaymentService::class);
        $first = $service->reserve(
            'wallet-promo-reselected-first-0001',
            $checkout['user_id'],
            $walletId,
            $checkout['quote']->quotePublicId,
            $checkout['decision']->publicId,
            $this->correlation('reselected-first'),
        );
        $service->cancel($first->intentPublicId, $this->correlation('reselected-cancel'));
        $second = $service->reserve(
            'wallet-promo-reselected-second-0001',
            $checkout['user_id'],
            $walletId,
            $checkout['quote']->quotePublicId,
            $checkout['decision']->publicId,
            $this->correlation('reselected-second'),
        );

        self::assertNotSame($first->intentPublicId, $second->intentPublicId);
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        self::assertSame('canceled', DB::table('payment_intents')->where('public_id', $first->intentPublicId)->value('state'));
        self::assertSame('awaiting_user_action', DB::table('payment_intents')->where('public_id', $second->intentPublicId)->value('state'));

        $this->clock->value = $this->clock->value->modify('+31 minutes');
        $result = $this->app->make(PurchasePaymentMaintenanceService::class)->run();
        self::assertSame(1, $result->walletIntentsExamined);
        self::assertSame(1, $result->promotionReservationsExamined);
        self::assertSame(1, $result->expiredWalletIntents);
        self::assertSame(0, $result->failures);
        self::assertSame('expired', DB::table('payment_intents')->where('public_id', $second->intentPublicId)->value('state'));
        self::assertSame(1, DB::table('promotion_usage_releases')->count());
        $secondRun = $this->app->make(PurchasePaymentMaintenanceService::class)->run();
        self::assertSame(0, $secondRun->walletIntentsExamined);
        self::assertSame(0, $secondRun->promotionReservationsExamined);
    }

    public function test_abandoned_active_wallet_intent_expires_and_releases_hold_and_promotion_capacity(): void
    {
        $source = $this->promotionSource('abandoned', 1);
        $checkout = $this->discountedCheckout($source, $source['codes'][0], 'abandoned');
        $walletId = $this->fundedCashWallet($checkout['user_id'], 2_000_000, 'abandoned');
        $service = $this->app->make(PurchaseWalletPaymentService::class);
        $intent = $service->reserve(
            'wallet-promo-abandoned-0001',
            $checkout['user_id'],
            $walletId,
            $checkout['quote']->quotePublicId,
            $checkout['decision']->publicId,
            $this->correlation('abandoned-reserve'),
        );

        self::assertSame('awaiting_user_action', DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->value('state'));
        self::assertSame('active', DB::table('wallet_holds')->where('source_id', $intent->intentPublicId)->value('status'));
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        self::assertSame(0, DB::table('promotion_usage_releases')->count());

        $this->clock->value = $this->clock->value->modify('+31 minutes');
        $result = $this->app->make(PurchasePaymentMaintenanceService::class)->run();

        self::assertSame(1, $result->walletIntentsExamined);
        self::assertSame(1, $result->promotionReservationsExamined);
        self::assertSame(1, $result->expiredWalletIntents);
        self::assertSame(0, $result->failures);
        self::assertSame('expired', DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->value('state'));
        self::assertSame('released', DB::table('wallet_holds')->where('source_id', $intent->intentPublicId)->value('status'));
        self::assertSame(1, DB::table('promotion_usage_releases')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(0, DB::table('promotion_usage_redemptions')->count());
        $replay = $this->app->make(PurchasePaymentMaintenanceService::class)->run();
        self::assertSame(0, $replay->walletIntentsExamined);
        self::assertSame(0, $replay->promotionReservationsExamined);
    }

    /** @return array{offering:array{id:int,product_id:int,server_id:int},rule:PromotionRuleVersionReceipt,codes:list<string>} */
    private function promotionSource(string $suffix, int $totalUseLimit, int $quantity = 1): array
    {
        $offering = $this->activeBenefitOffering('wallet-'.$suffix);
        $version = (int) DB::table('plan_offerings')->where('id', $offering['id'])->value('version');
        $this->app->make(PlanOfferingService::class)->setVisibility(
            $offering['id'],
            $version,
            ProductVisibility::Visible,
            new CatalogChangeContext(
                'wallet-promo-visible-'.substr(hash('sha256', $suffix), 0, 24),
                'wallet-promo-visible-correlation-'.substr(hash('sha256', $suffix), 0, 20),
                'wallet_promotion_payment_test',
                'Expose Wallet promotion test Offering.',
                $this->benefitOwner(),
            ),
        );
        $rule = $this->usageRule(
            $offering['id'],
            'wallet.promo.'.substr(hash('sha256', $suffix), 0, 16),
            90_000,
            $totalUseLimit,
            null,
        );
        $campaignCode = 'wallet.discount.'.substr(hash('sha256', $suffix), 0, 12);
        $this->benefitCampaign(
            $campaignCode,
            BenefitCodeType::DiscountGrant,
            $this->discountDefinition($rule->ruleCode, $offering['id'], $offering['product_id'], $offering['server_id']),
            'wallet-'.$suffix,
        );
        $issue = $this->benefitIssue($campaignCode, 'wallet-'.$suffix, $quantity);
        $codes = [];
        foreach ($issue->items as $item) {
            $codes[] = (string) $item->fullCode;
        }

        return compact('offering', 'rule', 'codes');
    }

    /** @param array{offering:array{id:int,product_id:int,server_id:int},rule:PromotionRuleVersionReceipt,codes:list<string>} $source
     * @return array{user_id:int,quote:QuoteReceipt,decision:object,order:object}
     */
    private function discountedCheckout(array $source, string $code, string $suffix): array
    {
        $userId = $this->benefitUser('customer');
        $quoteService = $this->app->make(QuoteService::class);
        $sourceQuote = $quoteService->create(
            'wallet-promo-source-'.substr(hash('sha256', $suffix), 0, 24),
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
            'wallet-promo-auth-'.substr(hash('sha256', $suffix), 0, 24),
            $userId,
            $sourceQuote->quotePublicId,
            $sourceQuote->configurationSnapshotHash,
            $code,
            $this->correlation('auth-'.$suffix),
        ));
        $discounted = $quoteService->create(
            'wallet-promo-quote-'.substr(hash('sha256', $suffix), 0, 24),
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
            'wallet-promo-consume-'.substr(hash('sha256', $suffix), 0, 24),
            $userId,
            $authorization,
            $discounted->quotePublicId,
            $discounted->configurationSnapshotHash,
            $this->correlation('consume-'.$suffix),
        ));

        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $this->configureWallet($eligibility, $suffix);
        $decision = $eligibility->evaluate(
            'wallet-promo-decision-'.substr(hash('sha256', $suffix), 0, 24),
            $userId,
            $discounted->quotePublicId,
            $discounted->configurationSnapshotHash,
        );
        $order = $this->app->make(PurchaseOrderService::class)->openFromQuote(
            $discounted->quotePublicId,
            $userId,
            $this->correlation('order-'.$suffix),
        );

        return ['user_id' => $userId, 'quote' => $discounted, 'decision' => $decision, 'order' => $order];
    }

    private function configureWallet(PaymentMethodEligibilityService $service, string $suffix): void
    {
        $administratorId = $this->benefitOwner();
        $service->configureMethod(
            'wallet-promo-method-'.substr(hash('sha256', $suffix), 0, 24),
            $administratorId,
            'wallet',
            true,
            false,
            1,
            'Wallet promotion payment test configuration.',
            $this->correlation('method-'.$suffix),
        );
        $service->recordHealth(
            'wallet-promo-health-'.substr(hash('sha256', $suffix), 0, 24),
            $administratorId,
            'wallet',
            true,
            $this->clock->value->modify('+20 minutes'),
            'Healthy Wallet promotion test observation.',
            $this->correlation('health-'.$suffix),
        );
    }

    private function fundedCashWallet(int $userId, int $amountIrr, string $suffix): int
    {
        $now = now('UTC');
        $assetId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'system.wallet.promo.asset.'.$suffix,
            'account_class' => 'asset',
            'owner_user_id' => null,
            'wallet_bucket' => null,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $walletId = $this->benefitCashWallet($userId);
        $this->app->make(LedgerPostingService::class)->post(
            'wallet-promo-fund-'.substr(hash('sha256', $suffix), 0, 24),
            'wallet_promotion_test_funding',
            $this->correlation('fund-'.$suffix),
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amountIrr)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amountIrr)),
            ],
            'test_fixture',
            'wallet-promo-'.$suffix,
        );

        return $walletId;
    }

    private function correlation(string $suffix): string
    {
        return substr(hash('sha256', 'wallet-promotion:'.$suffix), 0, 64);
    }
}
