<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderCapabilities;
use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderEvidence;
use App\Modules\Payments\GiftCard\Application\GiftCardPaymentService;
use App\Modules\Payments\GiftCard\Application\GiftCardSubmissionService;
use App\Modules\Payments\GiftCard\Application\GiftCardTypeReceipt;
use App\Modules\Payments\GiftCard\Application\GiftCardTypeService;
use App\Modules\Payments\GiftCard\Application\TelegramCustomerPurchaseGiftCardPaymentService;
use App\Modules\Payments\GiftCard\Infrastructure\FakeGiftCardVerificationProvider;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseOrder;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TelegramGiftCardWonOrderClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement BUY-001 BUY-003 PAY-001 PAY-002 PRO-001 GFT-001 GFT-002 GFT-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
final class TelegramGiftCardWonOrderRegressionTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private TelegramGiftCardWonOrderClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);

        $databaseNow = DB::selectOne('SELECT UTC_TIMESTAMP(6) AS current_utc');
        self::assertNotNull($databaseNow);
        $this->clock = new TelegramGiftCardWonOrderClock(
            new DateTimeImmutable((string) $databaseNow->current_utc, new \DateTimeZone('UTC')),
        );
        $this->app->instance(Clock::class, $this->clock);
        config()->set('payments.gift_card.code_lookup_key', str_repeat('w', 32));
        config()->set('payments.gift_card.code_lookup_key_version', 12);
        $this->configureGiftCardMethod();
    }

    public function test_paid_pre_payment_order_rejects_competing_manual_gift_card_without_new_effect(): void
    {
        $manualType = $this->registerType('tg-won-order-manual', 'manual_only');
        $winnerType = $this->registerType('tg-won-order-winner', 'automatic_only');
        $purchase = $this->purchase('won-order');

        $winnerSubmission = $this->app->make(GiftCardSubmissionService::class)->submit(
            'tg.gift.winner.submission.won-order',
            'tg.gift.winner.intent.won-order',
            $purchase['user_id'],
            $purchase['quote_public_id'],
            $purchase['decision_public_id'],
            $winnerType->typeCode,
            $purchase['amount'],
            'IRR',
            'Steam',
            'GLOBAL',
            'WON-ORDER-WINNER-GIFT-CODE-123456',
            null,
            null,
            null,
            null,
            $this->correlation('winner-submit'),
        );

        $provider = new FakeGiftCardVerificationProvider(
            'fake_gift_card',
            new GiftCardProviderCapabilities(true, false, true, false, true),
        );
        $provider->put(
            'validate',
            $this->operationKey($winnerSubmission->publicId, 'validate'),
            $this->providerEvidence('validate', 'valid', 'winner-validate', null, $purchase['amount']),
        );
        $provider->put(
            'redeem',
            $this->operationKey($winnerSubmission->publicId, 'redeem'),
            $this->providerEvidence('redeem', 'redeemed', 'winner-redeem', 'winner-redeem-tx', $purchase['amount']),
        );

        $winner = $this->app->make(GiftCardPaymentService::class)->process(
            $winnerSubmission->publicId,
            $provider,
            $this->correlation('winner-process'),
        );

        self::assertSame('captured', $winner->state);
        self::assertNotNull($winner->purchaseSettlementPublicId);

        $order = DB::table('orders')->where('public_id', $purchase['order_public_id'])->first();
        self::assertNotNull($order);
        self::assertSame('paid', $order->state);
        self::assertSame(1, (int) $order->state_version);
        self::assertSame($winnerSubmission->paymentIntentPublicId, $order->payment_intent_public_id);
        self::assertSame($winner->purchaseSettlementPublicId, $order->purchase_settlement_public_id);

        $before = $this->effectCounts();

        try {
            $this->app->make(TelegramCustomerPurchaseGiftCardPaymentService::class)->submitCodeForSelf(
                $purchase['user_id'],
                $purchase['user_id'],
                $purchase['order_public_id'],
                $purchase['quote_public_id'],
                $purchase['quote_configuration_hash'],
                $purchase['decision_public_id'],
                $purchase['decision_configuration_hash'],
                $manualType->typeCode,
                $manualType->configurationHash,
                $purchase['amount'],
                'WON-ORDER-COMPETING-MANUAL-CODE-654321',
                hash('sha256', 'telegram-gift-card-won-order-competing-manual'),
            );
            self::fail('Expected a paid pre-payment Order to reject a competing manual Gift Card submission.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        self::assertSame($before, $this->effectCounts());
        self::assertSame('paid', DB::table('orders')->where('public_id', $purchase['order_public_id'])->value('state'));
        self::assertSame(
            $winnerSubmission->paymentIntentPublicId,
            DB::table('orders')->where('public_id', $purchase['order_public_id'])->value('payment_intent_public_id'),
        );
        self::assertSame(
            $winner->purchaseSettlementPublicId,
            DB::table('orders')->where('public_id', $purchase['order_public_id'])->value('purchase_settlement_public_id'),
        );
    }

    /** @return array{user_id:int,quote_public_id:string,quote_configuration_hash:string,decision_public_id:string,decision_configuration_hash:string,order_public_id:string,amount:int} */
    private function purchase(string $suffix): array
    {
        $user = $this->quoteUser('customer');
        $offering = $this->quoteOffering(1_000_000);
        $quote = $this->app->make(QuoteService::class)->create(
            'tg.gift.won.quote.'.$suffix,
            $user,
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
        $decision = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
            'tg.gift.won.eligibility.'.$suffix,
            $user,
            $quote->quotePublicId,
            $quote->configurationSnapshotHash,
        );
        self::assertContains('gift_card', array_column($decision->methods, 'method_code'));

        $order = $this->app->make(TelegramCustomerPurchaseOrder::class)->openForSelf(
            $user,
            $user,
            $quote->quotePublicId,
            $quote->configurationSnapshotHash,
            $this->correlation('order-'.$suffix),
        );

        return [
            'user_id' => $user,
            'quote_public_id' => $quote->quotePublicId,
            'quote_configuration_hash' => $quote->configurationSnapshotHash,
            'decision_public_id' => $decision->publicId,
            'decision_configuration_hash' => $decision->configurationSnapshotHash,
            'order_public_id' => $order->orderPublicId,
            'amount' => $quote->finalPriceIrr,
        ];
    }

    private function registerType(string $typeCode, string $verificationMode): GiftCardTypeReceipt
    {
        return $this->app->make(GiftCardTypeService::class)->register(
            $typeCode,
            'Steam Gift Card '.$typeCode,
            'Steam',
            'GLOBAL',
            'IRR',
            'code_only',
            $verificationMode,
            null,
            'fake_gift_card',
        );
    }

    private function configureGiftCardMethod(): void
    {
        $administratorId = $this->ownerAdministrator();
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'tg.gift.won.method.foundation',
            $administratorId,
            'gift_card',
            true,
            false,
            1,
            'Telegram Gift Card won-Order regression configuration.',
            $this->correlation('method'),
        );
        $eligibility->recordHealth(
            'tg.gift.won.health.foundation',
            $administratorId,
            'gift_card',
            true,
            $this->clock->value->modify('+20 minutes'),
            'Healthy Telegram Gift Card won-Order regression observation.',
            $this->correlation('health'),
        );
    }

    private function providerEvidence(
        string $operation,
        string $status,
        string $suffix,
        ?string $transactionId,
        int $amount,
    ): GiftCardProviderEvidence {
        return new GiftCardProviderEvidence(
            $operation,
            'success',
            $status,
            'tg-won-order-event-'.$suffix,
            $transactionId,
            $amount,
            'IRR',
            'Steam',
            'GLOBAL',
            $this->clock->value->modify('+2 minutes'),
            hash('sha256', 'telegram-gift-card-won-order-evidence:'.$suffix),
            ['source' => 'fake_test'],
        );
    }

    private function operationKey(string $submissionPublicId, string $operation): string
    {
        return hash('sha256', 'gift-card:'.$submissionPublicId.':'.$operation);
    }

    /** @return array{gift_card_submissions:int,gift_card_payment_intents:int,promotion_usage_reservations:int,gift_card_reviews:int,gift_card_provider_events:int,gift_card_redemptions:int,purchase_settlements:int} */
    private function effectCounts(): array
    {
        return [
            'gift_card_submissions' => DB::table('gift_card_submissions')->count(),
            'gift_card_payment_intents' => DB::table('payment_intents')->where('payment_method_code', 'gift_card')->count(),
            'promotion_usage_reservations' => DB::table('promotion_usage_reservations')->count(),
            'gift_card_reviews' => DB::table('gift_card_reviews')->count(),
            'gift_card_provider_events' => DB::table('gift_card_provider_events')->count(),
            'gift_card_redemptions' => DB::table('gift_card_redemptions')->count(),
            'purchase_settlements' => DB::table('purchase_settlements')->count(),
        ];
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'telegram-gift-card-won-order-test:'.$suffix);
    }
}
