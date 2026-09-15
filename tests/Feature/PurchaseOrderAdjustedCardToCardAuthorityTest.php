<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\CardToCard\Application\CardToCardDestinationService;
use App\Modules\Payments\CardToCard\Application\CardToCardPaymentService;
use App\Modules\Payments\CardToCard\Application\CardToCardProviderPollingService;
use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionObservation;
use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionPage;
use App\Modules\Payments\CardToCard\Application\Contracts\CardToCardAdjustmentGenerator;
use App\Modules\Payments\CardToCard\Infrastructure\FakeBankTransactionVerificationProvider;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PurchaseOrderFixedC2cAdjustmentGenerator implements CardToCardAdjustmentGenerator
{
    public function generate(int $minimumIrr, int $maximumIrr): int
    {
        return $minimumIrr;
    }
}

final class PurchaseOrderC2cClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement BUY-001 PAY-002 C2C-003 C2C-004 DAT-002 DAT-003 DAT-004 QUA-004 */
final class PurchaseOrderAdjustedCardToCardAuthorityTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private PurchaseOrderC2cClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->clock = new PurchaseOrderC2cClock(new DateTimeImmutable('2026-08-14T21:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
        $this->app->instance(CardToCardAdjustmentGenerator::class, new PurchaseOrderFixedC2cAdjustmentGenerator);
        config()->set('payments.card_to_card.lookup_key', str_repeat('o', 32));

        $administratorId = $this->ownerAdministrator();
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'purchase.order.c2c.method',
            $administratorId,
            'card_to_card',
            true,
            false,
            1,
            'Adjusted C2C Order authority test method.',
            $this->correlation('method'),
        );
        $eligibility->recordHealth(
            'purchase.order.c2c.health',
            $administratorId,
            'card_to_card',
            true,
            $this->clock->value->modify('+20 minutes'),
            'Healthy adjusted C2C Order authority test provider.',
            $this->correlation('health'),
        );
        $this->app->make(CardToCardDestinationService::class)->register(
            'purchase-order-c2c-primary',
            '4242424242424242',
            'Purchase Order C2C Account',
            true,
            1000,
            9990,
            5,
            60,
            null,
            10,
            'fake',
            'Adjusted C2C Order authority test destination.',
            $this->correlation('destination'),
        );
    }

    public function test_genuine_adjusted_c2c_settlement_preserves_commercial_and_settled_amounts_through_order_and_replay(): void
    {
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'purchase.order.c2c.quote.0001',
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
            $this->correlation('quote'),
        );
        $decision = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
            'purchase.order.c2c.eligibility.0001',
            $userId,
            $quote->quotePublicId,
        );
        $payment = $this->app->make(CardToCardPaymentService::class)->create(
            'purchase.order.c2c.intent.0001',
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            $this->correlation('payment'),
        );

        self::assertGreaterThan(0, $payment->adjustmentAmountIrr);
        self::assertSame($payment->baseAmountIrr + $payment->adjustmentAmountIrr, $payment->payableAmountIrr);
        self::assertNotSame($payment->baseAmountIrr, $payment->payableAmountIrr);
        self::assertSame($payment->baseAmountIrr, $payment->paymentIntent->amount->amount());

        $provider = new FakeBankTransactionVerificationProvider('fake');
        $provider->put(null, new BankTransactionPage([
            new BankTransactionObservation(
                'purchase-order-c2c-tx-0001',
                'purchase-order-c2c-event-0001',
                '4242424242424242',
                $payment->payableAmountIrr,
                'settled',
                $this->clock->value->modify('+2 minutes'),
                null,
                null,
                'purchase-order-c2c-reference-0001',
                hash('sha256', 'purchase-order-c2c-bank-evidence'),
            ),
        ], 'purchase-order-c2c-cursor-2'));

        $poll = $this->app->make(CardToCardProviderPollingService::class)->poll(
            $provider,
            $this->correlation('poll'),
        );
        self::assertSame(1, $poll['captured']);
        self::assertSame('captured', DB::table('payment_intents')->where('public_id', $payment->paymentIntent->intentPublicId)->value('state'));

        $intent = DB::table('payment_intents')->where('public_id', $payment->paymentIntent->intentPublicId)->first();
        self::assertNotNull($intent);
        $settlement = DB::table('purchase_settlements')->where('payment_intent_id', $intent->id)->first();
        self::assertNotNull($settlement);
        self::assertSame($payment->baseAmountIrr, (int) $intent->amount_irr);
        self::assertSame($payment->payableAmountIrr, (int) $settlement->amount_irr);
        self::assertNotSame((int) $intent->amount_irr, (int) $settlement->amount_irr);

        $orders = $this->app->make(PurchaseOrderService::class);
        $receipt = $orders->createFromSettlement(
            (string) $settlement->public_id,
            $this->correlation('order'),
        );

        self::assertFalse($receipt->replayed);
        self::assertSame($payment->baseAmountIrr, $receipt->commercialAmount->amount());
        self::assertSame($payment->payableAmountIrr, $receipt->settledAmount->amount());
        self::assertNotSame($receipt->commercialAmount->amount(), $receipt->settledAmount->amount());

        $order = DB::table('orders')->where('id', $receipt->orderId)->first();
        self::assertNotNull($order);
        self::assertSame($payment->baseAmountIrr, (int) $order->total_amount_irr);
        self::assertSame($payment->payableAmountIrr, (int) $order->settled_amount_irr);
        self::assertSame((int) $intent->amount_irr, (int) $order->total_amount_irr);
        self::assertSame((int) $settlement->amount_irr, (int) $order->settled_amount_irr);

        $item = DB::table('order_items')->where('order_id', $receipt->orderId)->first();
        self::assertNotNull($item);
        self::assertSame($payment->baseAmountIrr, (int) $item->final_price_irr);

        $audit = DB::table('audit_logs')->where('action', 'order.purchase.created')->where('target_id', $receipt->orderPublicId)->first();
        self::assertNotNull($audit);
        /** @var array<string, mixed> $auditAfter */
        $auditAfter = json_decode((string) $audit->after_safe_data, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($payment->baseAmountIrr, $auditAfter['commercial_amount_irr']);
        self::assertSame($payment->payableAmountIrr, $auditAfter['settled_amount_irr']);

        $replay = $orders->createFromSettlement(
            (string) $settlement->public_id,
            $this->correlation('order-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($receipt->orderId, $replay->orderId);
        self::assertSame($payment->baseAmountIrr, $replay->commercialAmount->amount());
        self::assertSame($payment->payableAmountIrr, $replay->settledAmount->amount());
        self::assertSame(1, DB::table('orders')->count());
        self::assertSame(1, DB::table('order_items')->count());
        self::assertSame(1, DB::table('order_state_histories')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'order.purchase.created')->count());
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'purchase-order-adjusted-c2c:'.$suffix);
    }
}
