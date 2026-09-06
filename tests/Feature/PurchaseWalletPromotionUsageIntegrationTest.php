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
use App\Modules\Payments\Application\PurchasePaymentMaintenanceService;
use App\Modules\Payments\Application\PurchasePromotionUsageMaintenanceResult;
use App\Modules\Payments\Application\PurchaseWalletPaymentService;
use App\Modules\Payments\CardToCard\Application\CardToCardBankTransactionService;
use App\Modules\Payments\CardToCard\Application\CardToCardDestinationService;
use App\Modules\Payments\CardToCard\Application\CardToCardMatchingService;
use App\Modules\Payments\CardToCard\Application\CardToCardPaymentService;
use App\Modules\Payments\CardToCard\Application\CardToCardProviderPollingService;
use App\Modules\Payments\CardToCard\Application\CardToCardSettlementService;
use App\Modules\Payments\CardToCard\Application\CardToCardSettlementUnavailable;
use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionObservation;
use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionPage;
use App\Modules\Payments\CardToCard\Application\Contracts\CardToCardAdjustmentGenerator;
use App\Modules\Payments\CardToCard\Infrastructure\FakeBankTransactionVerificationProvider;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderCapabilities;
use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderEvidence;
use App\Modules\Payments\GiftCard\Application\GiftCardPaymentService;
use App\Modules\Payments\GiftCard\Application\GiftCardSubmissionService;
use App\Modules\Payments\GiftCard\Application\GiftCardTypeService;
use App\Modules\Payments\GiftCard\Infrastructure\FakeGiftCardVerificationProvider;
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
use RuntimeException;
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
final class PurchaseWalletPromotionC2cAdjustmentGenerator implements CardToCardAdjustmentGenerator
{
    public function generate(int $minimumIrr, int $maximumIrr): int
    {
        return $minimumIrr;
    }
}

final readonly class FailOnFinalizePurchasePromotionUsageAuthority implements PurchasePromotionUsageAuthority
{
    public function __construct(private PurchasePromotionUsageAuthority $inner) {}

    public function reserveForQuote(string $reservationKey, int $actorUserId, string $quotePublicId): ?string
    {
        return $this->inner->reserveForQuote($reservationKey, $actorUserId, $quotePublicId);
    }

    public function finalizeForSettlement(string $redemptionKey, int $actorUserId, string $purchaseSettlementPublicId): ?string
    {
        unset($redemptionKey, $actorUserId, $purchaseSettlementPublicId);
        throw new RuntimeException('Injected promotion finalization failure.');
    }

    public function releaseEligibleExpiredTerminalPurchases(int $limit = 100): PurchasePromotionUsageMaintenanceResult
    {
        return $this->inner->releaseEligibleExpiredTerminalPurchases($limit);
    }
}

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
        $this->app->instance(CardToCardAdjustmentGenerator::class, new PurchaseWalletPromotionC2cAdjustmentGenerator);
        config()->set('payments.card_to_card.lookup_key', str_repeat('p', 32));
        config()->set('payments.gift_card.code_lookup_key', str_repeat('g', 32));
        config()->set('payments.gift_card.code_lookup_key_version', 7);
    }

    public function test_purchase_payment_maintenance_command_is_json_safe_and_scheduled(): void
    {
        $expected = json_encode([
            'wallet_intents_examined' => 0,
            'expired_wallet_intents' => 0,
            'c2c_intents_examined' => 0,
            'expired_c2c_intents' => 0,
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
    public function test_discounted_card_to_card_capture_finalizes_promotion_and_completes_existing_order(): void
    {
        $this->configureCardToCard('c2c-capture');
        $this->registerCardToCardDestination('c2c-capture');
        $source = $this->promotionSource('c2c-capture', 1);
        $checkout = $this->discountedCheckout($source, $source['codes'][0], 'c2c-capture');
        $payment = $this->app->make(CardToCardPaymentService::class)->create(
            'wallet-promo-c2c-intent-capture',
            $checkout['user_id'],
            $checkout['quote']->quotePublicId,
            $checkout['decision']->publicId,
            $this->correlation('c2c-create-capture'),
        );

        $paymentReplay = $this->app->make(CardToCardPaymentService::class)->create(
            'wallet-promo-c2c-intent-capture',
            $checkout['user_id'],
            $checkout['quote']->quotePublicId,
            $checkout['decision']->publicId,
            $this->correlation('c2c-create-capture-replay'),
        );
        self::assertTrue($paymentReplay->replayed);
        self::assertSame($payment->paymentIntent->intentPublicId, $paymentReplay->paymentIntent->intentPublicId);
        self::assertSame($payment->reservationId, $paymentReplay->reservationId);
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        self::assertSame(0, DB::table('promotion_usage_redemptions')->count());
        self::assertSame('awaiting_payment', DB::table('orders')->where('public_id', $checkout['order']->orderPublicId)->value('state'));

        $provider = new FakeBankTransactionVerificationProvider('fake');
        $provider->put(null, new BankTransactionPage([
            $this->cardToCardObservation('capture', $payment->payableAmountIrr),
        ], 'wallet-promo-c2c-cursor-capture'));
        $poll = $this->app->make(CardToCardProviderPollingService::class)->poll(
            $provider,
            $this->correlation('c2c-poll-capture'),
        );

        self::assertSame(1, $poll['captured']);
        self::assertSame(1, DB::table('purchase_settlements')->count());
        self::assertSame(1, DB::table('promotion_usage_redemptions')->count());
        self::assertSame(1, DB::table('orders')->count());
        $order = DB::table('orders')->where('public_id', $checkout['order']->orderPublicId)->first();
        self::assertNotNull($order);
        self::assertSame('paid', $order->state);
        self::assertSame($payment->paymentIntent->intentPublicId, $order->payment_intent_public_id);
        self::assertNotNull($order->purchase_settlement_public_id);

        $matchPublicId = DB::table('c2c_transaction_matches')->value('public_id');
        self::assertIsString($matchPublicId);
        $replay = $this->app->make(CardToCardSettlementService::class)->capture(
            $matchPublicId,
            $this->correlation('c2c-capture-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame(1, DB::table('purchase_settlements')->count());
        self::assertSame(1, DB::table('promotion_usage_redemptions')->count());
        self::assertSame(1, DB::table('orders')->count());
    }

    public function test_discounted_card_to_card_amount_allocation_failure_rolls_back_intent_and_promotion_reservation(): void
    {
        $this->configureCardToCard('c2c-rollback');
        $source = $this->promotionSource('c2c-rollback', 1);
        $checkout = $this->discountedCheckout($source, $source['codes'][0], 'c2c-rollback');

        try {
            $this->app->make(CardToCardPaymentService::class)->create(
                'wallet-promo-c2c-intent-rollback',
                $checkout['user_id'],
                $checkout['quote']->quotePublicId,
                $checkout['decision']->publicId,
                $this->correlation('c2c-create-rollback'),
            );
            self::fail('Expected C2C destination allocation failure.');
        } catch (DomainException $exception) {
            self::assertSame('No active card-to-card destination is available.', $exception->getMessage());
        }

        self::assertSame(0, DB::table('payment_intents')->where('payment_method_code', 'card_to_card')->count());
        self::assertSame(0, DB::table('c2c_amount_reservations')->count());
        self::assertSame(0, DB::table('promotion_usage_reservations')->count());
        self::assertSame('awaiting_payment', DB::table('orders')->where('public_id', $checkout['order']->orderPublicId)->value('state'));
    }

    public function test_card_to_card_promotion_capacity_failure_rolls_back_second_intent_and_amount_reservation(): void
    {
        $this->configureCardToCard('c2c-capacity');
        $this->registerCardToCardDestination('c2c-capacity');
        $source = $this->promotionSource('c2c-capacity', 1, 2);
        $first = $this->discountedCheckout($source, $source['codes'][0], 'c2c-capacity-a');
        $second = $this->discountedCheckout($source, $source['codes'][1], 'c2c-capacity-b');
        $service = $this->app->make(CardToCardPaymentService::class);

        $service->create(
            'wallet-promo-c2c-capacity-first',
            $first['user_id'],
            $first['quote']->quotePublicId,
            $first['decision']->publicId,
            $this->correlation('c2c-capacity-first'),
        );
        self::assertSame(1, DB::table('payment_intents')->where('payment_method_code', 'card_to_card')->count());
        self::assertSame(1, DB::table('c2c_amount_reservations')->count());
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());

        try {
            $service->create(
                'wallet-promo-c2c-capacity-second',
                $second['user_id'],
                $second['quote']->quotePublicId,
                $second['decision']->publicId,
                $this->correlation('c2c-capacity-second'),
            );
            self::fail('Expected C2C promotion capacity rejection.');
        } catch (DomainException $exception) {
            self::assertSame('Promotion global usage capacity is exhausted.', $exception->getMessage());
        }

        self::assertSame(1, DB::table('payment_intents')->where('payment_method_code', 'card_to_card')->count());
        self::assertSame(1, DB::table('c2c_amount_reservations')->count());
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        self::assertSame(0, DB::table('promotion_usage_redemptions')->count());
    }

    public function test_card_to_card_matching_excludes_quote_after_wallet_wins_pre_payment_order(): void
    {
        $this->configureCardToCard('c2c-match-lost');
        $this->registerCardToCardDestination('c2c-match-lost');
        $source = $this->promotionSource('c2c-match-lost', 1);
        $checkout = $this->discountedCheckout($source, $source['codes'][0], 'c2c-match-lost');
        $c2c = $this->app->make(CardToCardPaymentService::class)->create(
            'wallet-promo-c2c-intent-match-lost',
            $checkout['user_id'],
            $checkout['quote']->quotePublicId,
            $checkout['decision']->publicId,
            $this->correlation('c2c-create-match-lost'),
        );
        $walletId = $this->fundedCashWallet($checkout['user_id'], $checkout['quote']->finalPriceIrr, 'c2c-match-lost');
        $wallet = $this->app->make(PurchaseWalletPaymentService::class);
        $walletIntent = $wallet->reserve(
            'wallet-promo-c2c-wallet-match-lost',
            $checkout['user_id'],
            $walletId,
            $checkout['quote']->quotePublicId,
            $checkout['decision']->publicId,
            $this->correlation('wallet-reserve-match-lost'),
        );
        $wallet->capture($walletIntent->intentPublicId, $this->correlation('wallet-capture-match-lost'));

        $transaction = $this->app->make(CardToCardBankTransactionService::class)->ingest(
            'fake',
            $this->cardToCardObservation('match-lost', $c2c->payableAmountIrr),
            'fake',
            $this->correlation('c2c-ingest-match-lost'),
        );
        $outcome = $this->app->make(CardToCardMatchingService::class)->match(
            $transaction->publicId,
            $this->correlation('c2c-match-lost'),
        );

        self::assertSame('review_pending:no_candidate', $outcome->outcome);
        self::assertSame(0, $outcome->candidateCount);
        self::assertNull($outcome->matchPublicId);
        self::assertNotNull($outcome->reviewPublicId);
        self::assertSame(1, DB::table('purchase_settlements')->count());
        self::assertSame(1, DB::table('promotion_usage_redemptions')->count());
        self::assertSame(0, DB::table('c2c_transaction_matches')->count());
    }

    public function test_card_to_card_capture_fails_closed_when_wallet_wins_after_match_and_reconciliation_keeps_bank_evidence(): void
    {
        $this->configureCardToCard('c2c-capture-lost');
        $this->registerCardToCardDestination('c2c-capture-lost');
        $source = $this->promotionSource('c2c-capture-lost', 1);
        $checkout = $this->discountedCheckout($source, $source['codes'][0], 'c2c-capture-lost');
        $c2c = $this->app->make(CardToCardPaymentService::class)->create(
            'wallet-promo-c2c-intent-capture-lost',
            $checkout['user_id'],
            $checkout['quote']->quotePublicId,
            $checkout['decision']->publicId,
            $this->correlation('c2c-create-capture-lost'),
        );
        $transaction = $this->app->make(CardToCardBankTransactionService::class)->ingest(
            'fake',
            $this->cardToCardObservation('capture-lost', $c2c->payableAmountIrr),
            'fake',
            $this->correlation('c2c-ingest-capture-lost'),
        );
        $match = $this->app->make(CardToCardMatchingService::class)->match(
            $transaction->publicId,
            $this->correlation('c2c-match-capture-lost'),
        );
        self::assertSame('matched', $match->outcome);
        self::assertNotNull($match->matchPublicId);

        $walletId = $this->fundedCashWallet($checkout['user_id'], $checkout['quote']->finalPriceIrr, 'c2c-capture-lost');
        $wallet = $this->app->make(PurchaseWalletPaymentService::class);
        $walletIntent = $wallet->reserve(
            'wallet-promo-c2c-wallet-capture-lost',
            $checkout['user_id'],
            $walletId,
            $checkout['quote']->quotePublicId,
            $checkout['decision']->publicId,
            $this->correlation('wallet-reserve-capture-lost'),
        );
        $wallet->capture($walletIntent->intentPublicId, $this->correlation('wallet-capture-capture-lost'));

        try {
            $this->app->make(CardToCardSettlementService::class)->capture(
                $match->matchPublicId,
                $this->correlation('c2c-settle-capture-lost'),
            );
            self::fail('Expected C2C settlement single-winner rejection.');
        } catch (CardToCardSettlementUnavailable $exception) {
            self::assertSame('Pre-payment purchase Order was already won by another payment outcome.', $exception->getMessage());
        }

        self::assertSame(1, DB::table('purchase_settlements')->count());
        self::assertSame(1, DB::table('promotion_usage_redemptions')->count());
        self::assertSame('matched', DB::table('c2c_transaction_matches')->where('public_id', $match->matchPublicId)->value('state'));
        self::assertSame('settled', DB::table('c2c_bank_transactions')->where('public_id', $transaction->publicId)->value('status'));
        $provider = new FakeBankTransactionVerificationProvider('fake');
        $provider->put(null, new BankTransactionPage([
            $this->cardToCardObservation('capture-lost', $c2c->payableAmountIrr),
        ], 'wallet-promo-c2c-cursor-capture-lost'));
        $poll = $this->app->make(CardToCardProviderPollingService::class)->poll(
            $provider,
            $this->correlation('c2c-poll-capture-lost'),
        );
        self::assertSame(1, $poll['ingested']);
        self::assertSame(1, $poll['matched']);
        self::assertSame(0, $poll['captured']);
        self::assertSame('wallet-promo-c2c-cursor-capture-lost', $poll['next_cursor']);
        self::assertSame('wallet-promo-c2c-cursor-capture-lost', DB::table('c2c_provider_cursors')->where('provider_code', 'fake')->value('cursor'));
        self::assertNull(DB::table('c2c_provider_cursors')->where('provider_code', 'fake')->value('last_failure_code'));
        $finding = DB::table('c2c_reconciliation_findings')
            ->where('c2c_bank_transaction_id', $transaction->transactionId)
            ->where('finding_type', 'unlinked_settled')
            ->first();
        self::assertNotNull($finding);
        self::assertSame($match->matchId, (int) $finding->c2c_transaction_match_id);
    }

    public function test_abandoned_discounted_card_to_card_intent_releases_promotion_after_late_review_window(): void
    {
        $this->configureCardToCard('c2c-abandoned-release');
        $this->registerCardToCardDestination('c2c-abandoned-release');
        $source = $this->promotionSource('c2c-abandoned-release', 1);
        $checkout = $this->discountedCheckout($source, $source['codes'][0], 'c2c-abandoned-release');
        $payment = $this->app->make(CardToCardPaymentService::class)->create(
            'wallet-promo-c2c-intent-abandoned-release',
            $checkout['user_id'],
            $checkout['quote']->quotePublicId,
            $checkout['decision']->publicId,
            $this->correlation('c2c-create-abandoned-release'),
        );

        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        self::assertSame(0, DB::table('promotion_usage_releases')->count());
        self::assertSame('awaiting_user_action', DB::table('payment_intents')->where('public_id', $payment->paymentIntent->intentPublicId)->value('state'));

        $this->clock->value = $this->clock->value->modify('+61 minutes');
        $maintenance = $this->app->make(PurchasePaymentMaintenanceService::class)->run();

        self::assertSame(0, $maintenance->walletIntentsExamined);
        self::assertSame(1, $maintenance->c2cIntentsExamined);
        self::assertSame(1, $maintenance->expiredC2cIntents);
        self::assertSame(1, $maintenance->promotionReservationsExamined);
        self::assertSame(1, $maintenance->releasedPromotionReservations);
        self::assertSame(0, $maintenance->failures);
        self::assertSame('expired', DB::table('payment_intents')->where('public_id', $payment->paymentIntent->intentPublicId)->value('state'));
        self::assertSame(1, DB::table('promotion_usage_releases')->count());
        self::assertSame(0, DB::table('promotion_usage_redemptions')->count());
        self::assertSame('awaiting_payment', DB::table('orders')->where('public_id', $checkout['order']->orderPublicId)->value('state'));

        $replay = $this->app->make(PurchasePaymentMaintenanceService::class)->run();
        self::assertSame(0, $replay->c2cIntentsExamined);
        self::assertSame(0, $replay->expiredC2cIntents);
        self::assertSame(0, $replay->releasedPromotionReservations);
        self::assertSame(1, DB::table('promotion_usage_releases')->count());
    }

    public function test_discounted_gift_card_reserves_and_finalizes_promotion_and_completes_pre_payment_order(): void
    {
        $suffix = 'gift-card-finalize';
        $this->configureGiftCard($suffix);
        $source = $this->promotionSource($suffix, 1);
        $checkout = $this->discountedCheckout($source, $source['codes'][0], $suffix);
        $typeCode = 'gift-promo-finalize';
        $this->registerGiftCardType($typeCode);
        $submission = $this->submitGiftCard($checkout, $suffix, $typeCode, 'PROMO-GIFT-CARD-0001');

        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        self::assertSame(0, DB::table('promotion_usage_redemptions')->count());
        self::assertSame('awaiting_payment', DB::table('orders')->where('public_id', $checkout['order']->orderPublicId)->value('state'));

        $provider = new FakeGiftCardVerificationProvider(
            'fake_gift_card',
            new GiftCardProviderCapabilities(true, false, true, false, true),
        );
        $provider->put('validate', $this->giftCardOperationKey($submission->publicId, 'validate'), $this->giftCardEvidence(
            'validate', 'success', 'valid', 'promo-finalize-validate', null, $checkout['quote']->finalPriceIrr,
        ));
        $provider->put('redeem', $this->giftCardOperationKey($submission->publicId, 'redeem'), $this->giftCardEvidence(
            'redeem', 'success', 'redeemed', 'promo-finalize-redeem', 'promo-finalize-tx', $checkout['quote']->finalPriceIrr,
        ));

        $captured = $this->app->make(GiftCardPaymentService::class)->process(
            $submission->publicId,
            $provider,
            $this->correlation('gift-card-finalize-process'),
        );

        self::assertSame('captured', $captured->state);
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        self::assertSame(1, DB::table('promotion_usage_redemptions')->count());
        self::assertSame(0, DB::table('promotion_usage_releases')->count());
        self::assertSame('paid', DB::table('orders')->where('public_id', $checkout['order']->orderPublicId)->value('state'));
        self::assertSame($submission->paymentIntentPublicId, DB::table('orders')->where('public_id', $checkout['order']->orderPublicId)->value('payment_intent_public_id'));

        $replay = $this->app->make(GiftCardPaymentService::class)->process(
            $submission->publicId,
            $provider,
            $this->correlation('gift-card-finalize-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($captured->purchaseSettlementPublicId, $replay->purchaseSettlementPublicId);
        self::assertSame(1, DB::table('promotion_usage_redemptions')->count());
        self::assertSame(1, DB::table('purchase_settlements')->where('source_quote_public_id', $checkout['quote']->quotePublicId)->count());
    }

    public function test_gift_card_post_redeem_promotion_failure_rolls_back_local_capture_but_retains_reconciliation_evidence(): void
    {
        $suffix = 'gift-card-finalize-failure';
        $this->configureGiftCard($suffix);
        $source = $this->promotionSource($suffix, 1);
        $checkout = $this->discountedCheckout($source, $source['codes'][0], $suffix);
        $typeCode = 'gift-promo-fail-finalize';
        $this->registerGiftCardType($typeCode);
        $submission = $this->submitGiftCard($checkout, $suffix, $typeCode, 'PROMO-GIFT-CARD-FAIL-0001');

        $realPromotionAuthority = $this->app->make(PurchasePromotionUsageAuthority::class);
        $this->app->instance(
            PurchasePromotionUsageAuthority::class,
            new FailOnFinalizePurchasePromotionUsageAuthority($realPromotionAuthority),
        );

        $provider = new FakeGiftCardVerificationProvider(
            'fake_gift_card',
            new GiftCardProviderCapabilities(true, false, true, false, true),
        );
        $provider->put('validate', $this->giftCardOperationKey($submission->publicId, 'validate'), $this->giftCardEvidence(
            'validate', 'success', 'valid', 'promo-fail-finalize-validate', null, $checkout['quote']->finalPriceIrr,
        ));
        $provider->put('redeem', $this->giftCardOperationKey($submission->publicId, 'redeem'), $this->giftCardEvidence(
            'redeem', 'success', 'redeemed', 'promo-fail-finalize-redeem', 'promo-fail-finalize-tx', $checkout['quote']->finalPriceIrr,
        ));

        try {
            $this->app->make(GiftCardPaymentService::class)->process(
                $submission->publicId,
                $provider,
                $this->correlation('gift-card-finalize-failure-process'),
            );
            self::fail('Expected injected promotion finalization failure after authoritative provider redemption.');
        } catch (RuntimeException $exception) {
            self::assertSame('Injected promotion finalization failure.', $exception->getMessage());
        } finally {
            $this->app->instance(PurchasePromotionUsageAuthority::class, $realPromotionAuthority);
        }

        $redemption = DB::table('gift_card_redemptions')
            ->where('gift_card_submission_id', $submission->submissionId)
            ->first(['public_id', 'purchase_settlement_id']);
        self::assertNotNull($redemption);
        self::assertNotNull($redemption->public_id);
        self::assertNull($redemption->purchase_settlement_id);
        self::assertSame('redeeming', DB::table('gift_card_submissions')->where('id', $submission->submissionId)->value('state'));
        self::assertSame('verifying', DB::table('payment_intents')->where('public_id', $submission->paymentIntentPublicId)->value('state'));
        self::assertSame(0, DB::table('purchase_settlements')->where('source_quote_public_id', $checkout['quote']->quotePublicId)->count());
        self::assertSame('awaiting_payment', DB::table('orders')->where('public_id', $checkout['order']->orderPublicId)->value('state'));
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        self::assertSame(0, DB::table('promotion_usage_redemptions')->count());
        self::assertSame(1, DB::table('gift_card_reconciliation_findings')
            ->where('gift_card_submission_id', $submission->submissionId)
            ->where('finding_type', 'provider_captured_local_not_captured')
            ->count());
    }

    public function test_failed_discounted_gift_card_releases_promotion_capacity_after_quote_expiry(): void
    {
        $suffix = 'gift-card-release';
        $this->configureGiftCard($suffix);
        $source = $this->promotionSource($suffix, 1);
        $checkout = $this->discountedCheckout($source, $source['codes'][0], $suffix);
        $typeCode = 'gift-promo-release';
        $this->registerGiftCardType($typeCode);
        $submission = $this->submitGiftCard($checkout, $suffix, $typeCode, 'PROMO-GIFT-CARD-INVALID');
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());

        $provider = new FakeGiftCardVerificationProvider(
            'fake_gift_card',
            new GiftCardProviderCapabilities(true, false, true, false, true),
        );
        $provider->put('validate', $this->giftCardOperationKey($submission->publicId, 'validate'), $this->giftCardEvidence(
            'validate', 'rejected', 'invalid', 'promo-release-invalid', null, $checkout['quote']->finalPriceIrr,
        ));
        $invalid = $this->app->make(GiftCardPaymentService::class)->process(
            $submission->publicId,
            $provider,
            $this->correlation('gift-card-release-process'),
        );
        self::assertSame('invalid', $invalid->state);
        self::assertSame('failed', DB::table('payment_intents')->where('public_id', $submission->paymentIntentPublicId)->value('state'));
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

    private function configureGiftCard(string $suffix): void
    {
        $administratorId = $this->benefitOwner();
        $service = $this->app->make(PaymentMethodEligibilityService::class);
        $service->configureMethod(
            'gift-promo-method-'.substr(hash('sha256', $suffix), 0, 24),
            $administratorId,
            'gift_card',
            true,
            false,
            1,
            'Gift-card promotion payment test configuration.',
            $this->correlation('gift-method-'.$suffix),
        );
        $service->recordHealth(
            'gift-promo-health-'.substr(hash('sha256', $suffix), 0, 24),
            $administratorId,
            'gift_card',
            true,
            $this->clock->value->modify('+20 minutes'),
            'Healthy gift-card promotion provider observation.',
            $this->correlation('gift-health-'.$suffix),
        );
    }

    private function registerGiftCardType(string $typeCode): void
    {
        $this->app->make(GiftCardTypeService::class)->register(
            $typeCode,
            'Steam Gift Card',
            'Steam',
            'GLOBAL',
            'IRR',
            'code_only',
            'automatic_only',
            null,
            'fake_gift_card',
        );
    }

    /** @param array{user_id:int,quote:QuoteReceipt,decision:object,order:object} $checkout */
    private function submitGiftCard(array $checkout, string $suffix, string $typeCode, string $code): object
    {
        return $this->app->make(GiftCardSubmissionService::class)->submit(
            'gift-promo-submission-'.substr(hash('sha256', $suffix), 0, 24),
            'gift-promo-intent-'.substr(hash('sha256', $suffix), 0, 24),
            $checkout['user_id'],
            $checkout['quote']->quotePublicId,
            $checkout['decision']->publicId,
            $typeCode,
            $checkout['quote']->finalPriceIrr,
            'IRR',
            'Steam',
            'GLOBAL',
            $code,
            null,
            null,
            null,
            null,
            $this->correlation('gift-submit-'.$suffix),
        );
    }

    private function giftCardEvidence(
        string $operation,
        string $outcome,
        string $status,
        string $suffix,
        ?string $transactionId,
        int $amountIrr,
    ): GiftCardProviderEvidence {
        return new GiftCardProviderEvidence(
            $operation,
            $outcome,
            $status,
            'gift-promo-event-'.$suffix,
            $transactionId,
            $amountIrr,
            'IRR',
            'Steam',
            'GLOBAL',
            $this->clock->value->modify('+2 minutes'),
            hash('sha256', 'gift-promo-evidence:'.$suffix),
            ['source' => 'fake_test'],
        );
    }

    private function giftCardOperationKey(string $submissionPublicId, string $operation): string
    {
        return hash('sha256', 'gift-card:'.$submissionPublicId.':'.$operation);
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

    private function configureCardToCard(string $suffix): void
    {
        $administratorId = $this->benefitOwner();
        $service = $this->app->make(PaymentMethodEligibilityService::class);
        $service->configureMethod(
            'wallet-promo-c2c-method-'.substr(hash('sha256', $suffix), 0, 24),
            $administratorId,
            'card_to_card',
            true,
            false,
            2,
            'C2C promotion payment test configuration.',
            $this->correlation('c2c-method-'.$suffix),
        );
        $service->recordHealth(
            'wallet-promo-c2c-health-'.substr(hash('sha256', $suffix), 0, 24),
            $administratorId,
            'card_to_card',
            true,
            $this->clock->value->modify('+20 minutes'),
            'Healthy C2C promotion test observation.',
            $this->correlation('c2c-health-'.$suffix),
        );
    }

    private function registerCardToCardDestination(string $suffix): void
    {
        $this->app->make(CardToCardDestinationService::class)->register(
            'wallet-promo-c2c-'.$suffix,
            '4242424242424242',
            'Promotion C2C Account',
            true,
            1000,
            1000,
            30,
            60,
            null,
            10,
            'fake',
            'Promotion-safe C2C test destination.',
            $this->correlation('c2c-destination-'.$suffix),
        );
    }

    private function cardToCardObservation(string $suffix, int $amountIrr): BankTransactionObservation
    {
        return new BankTransactionObservation(
            'wallet-promo-c2c-tx-'.$suffix,
            'wallet-promo-c2c-event-'.$suffix,
            '4242424242424242',
            $amountIrr,
            'settled',
            $this->clock->value->modify('+2 minutes'),
            null,
            null,
            'wallet-promo-c2c-reference-'.$suffix,
            hash('sha256', 'wallet-promo-c2c-evidence-'.$suffix),
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
