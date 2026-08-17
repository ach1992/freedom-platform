<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\OrderState;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\PurchasePaymentIntentService;
use App\Modules\Payments\Application\PurchaseSettlementService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Shared\Domain\Money;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement BUY-001 BUY-002 PAY-001 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 QUA-001 QUA-004 */
final class PurchaseOrderPrePaymentAuthorityTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use PurchaseOrderTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->bootPurchaseOrderClock();
    }

    public function test_quote_opens_one_stable_pre_payment_order_and_winning_intent_completes_same_identity(): void
    {
        [$userId, $quotePublicId, $methodCode, $eligibilityPublicId] = $this->preparePurchaseQuote('stable');
        $orders = $this->app->make(PurchaseOrderService::class);

        $opened = $orders->openFromQuote(
            $quotePublicId,
            $userId,
            $this->purchaseOrderCorrelation('open-stable'),
        );
        self::assertFalse($opened->replayed);
        self::assertSame(OrderState::AwaitingPayment, $opened->state);
        self::assertSame(0, $opened->stateVersion);
        self::assertSame(1, DB::table('orders')->count());
        self::assertSame(1, DB::table('order_items')->count());

        $openReplay = $orders->openFromQuote(
            $quotePublicId,
            $userId,
            $this->purchaseOrderCorrelation('open-stable-replay'),
        );
        self::assertTrue($openReplay->replayed);
        self::assertSame($opened->orderId, $openReplay->orderId);
        self::assertSame($opened->orderPublicId, $openReplay->orderPublicId);
        self::assertSame(1, DB::table('orders')->count());

        $payments = $this->app->make(PurchasePaymentIntentService::class);
        $firstIntent = $payments->create(
            'purchase.order.intent.stable.first',
            $userId,
            $quotePublicId,
            $eligibilityPublicId,
            $methodCode,
            $this->purchaseOrderCorrelation('intent-stable-first'),
        );
        $winningIntent = $payments->create(
            'purchase.order.intent.stable.winner',
            $userId,
            $quotePublicId,
            $eligibilityPublicId,
            $methodCode,
            $this->purchaseOrderCorrelation('intent-stable-winner'),
        );
        DB::table('payment_intents')->whereIn('public_id', [$firstIntent->intentPublicId, $winningIntent->intentPublicId])->update([
            'state' => 'submitted',
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]);

        $settlement = $this->captureIntent($winningIntent->intentPublicId, $methodCode, 'stable-winner');
        $paid = $orders->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('order-stable-paid'),
        );

        self::assertFalse($paid->replayed);
        self::assertSame($opened->orderId, $paid->orderId);
        self::assertSame($opened->orderPublicId, $paid->orderPublicId);
        self::assertSame($opened->orderItemPublicId, $paid->orderItemPublicId);
        self::assertSame($winningIntent->intentPublicId, $paid->paymentIntentPublicId);
        self::assertSame(OrderState::Paid, $paid->state);
        self::assertSame(1, $paid->stateVersion);
        self::assertSame(1, DB::table('orders')->count());
        self::assertSame(1, DB::table('order_items')->count());
        self::assertSame(2, DB::table('order_state_histories')->where('order_id', $opened->orderId)->count());

        $history = DB::table('order_state_histories')->where('order_id', $opened->orderId)->orderBy('to_version')->get();
        self::assertSame('awaiting_payment', $history[0]->to_state);
        self::assertSame(0, (int) $history[0]->to_version);
        self::assertSame('purchase_quote_accepted', $history[0]->reason_code);
        self::assertSame('awaiting_payment', $history[1]->from_state);
        self::assertSame(0, (int) $history[1]->from_version);
        self::assertSame('paid', $history[1]->to_state);
        self::assertSame(1, (int) $history[1]->to_version);
        self::assertSame('authoritative_purchase_settlement', $history[1]->reason_code);

        self::assertSame(1, DB::table('audit_logs')->where('action', 'order.purchase.opened')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'order.purchase.paid')->count());
    }

    public function test_one_quote_preserves_multiple_authoritative_settlement_facts_but_one_order_binding_wins(): void
    {
        [$userId, $quotePublicId, $methodCode, $eligibilityPublicId] = $this->preparePurchaseQuote('one-capture');
        $orders = $this->app->make(PurchaseOrderService::class);
        $opened = $orders->openFromQuote(
            $quotePublicId,
            $userId,
            $this->purchaseOrderCorrelation('open-one-capture'),
        );

        $payments = $this->app->make(PurchasePaymentIntentService::class);
        $first = $payments->create(
            'purchase.order.intent.one-capture.first',
            $userId,
            $quotePublicId,
            $eligibilityPublicId,
            $methodCode,
            $this->purchaseOrderCorrelation('intent-one-capture-first'),
        );
        $second = $payments->create(
            'purchase.order.intent.one-capture.second',
            $userId,
            $quotePublicId,
            $eligibilityPublicId,
            $methodCode,
            $this->purchaseOrderCorrelation('intent-one-capture-second'),
        );
        DB::table('payment_intents')->whereIn('public_id', [$first->intentPublicId, $second->intentPublicId])->update([
            'state' => 'submitted',
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]);

        $firstSettlement = $this->captureIntent($first->intentPublicId, $methodCode, 'one-capture-first');
        $secondSettlement = $this->captureIntent($second->intentPublicId, $methodCode, 'one-capture-second');

        $paid = $orders->createFromSettlement(
            $firstSettlement->settlementPublicId,
            $this->purchaseOrderCorrelation('order-one-capture-first'),
        );
        self::assertSame($opened->orderId, $paid->orderId);
        self::assertSame(OrderState::Paid, $paid->state);

        try {
            $orders->createFromSettlement(
                $secondSettlement->settlementPublicId,
                $this->purchaseOrderCorrelation('order-one-capture-second'),
            );
            self::fail('A second settlement fact must not rebind the stable purchase Order.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Purchase Order already has conflicting financial or lifecycle authority.', $exception->getMessage());
        }

        $quoteId = (int) DB::table('quotes')->where('public_id', $quotePublicId)->value('id');
        self::assertSame(2, DB::table('purchase_settlements')->where('source_quote_id', $quoteId)->count());
        self::assertSame('captured', DB::table('payment_intents')->where('public_id', $first->intentPublicId)->value('state'));
        self::assertSame('captured', DB::table('payment_intents')->where('public_id', $second->intentPublicId)->value('state'));
        self::assertSame(1, DB::table('orders')->where('source_quote_id', $quoteId)->count());

        $order = DB::table('orders')->where('id', $opened->orderId)->first();
        self::assertNotNull($order);
        self::assertSame(OrderState::Paid->value, $order->state);
        self::assertSame(1, (int) $order->state_version);
        self::assertSame($firstSettlement->settlementId, (int) $order->purchase_settlement_id);
    }

    public function test_database_rejects_forged_pre_payment_order_financial_authority_and_cross_user_quote_binding(): void
    {
        [$userId, $quotePublicId] = $this->preparePurchaseQuote('prepay-guards');
        $quote = DB::table('quotes')->where('public_id', $quotePublicId)->first();
        self::assertNotNull($quote);
        $otherUserId = $this->quoteUser('customer');

        $base = [
            'public_id' => (string) Str::ulid(),
            'source_type' => 'purchase',
            'purchase_settlement_id' => null,
            'purchase_settlement_public_id' => null,
            'payment_intent_id' => null,
            'payment_intent_public_id' => null,
            'user_id' => $userId,
            'source_quote_id' => $quote->id,
            'source_quote_public_id' => $quote->public_id,
            'source_quote_configuration_hash' => $quote->configuration_snapshot_hash,
            'state' => 'awaiting_payment',
            'state_version' => 0,
            'total_amount_irr' => $quote->final_price_irr,
            'settled_amount_irr' => null,
            'currency' => 'IRR',
            'paid_at' => null,
            'creation_correlation_id' => $this->purchaseOrderCorrelation('prepay-forgery'),
            'created_at' => $this->purchaseOrderTimestamp(),
            'updated_at' => $this->purchaseOrderTimestamp(),
        ];

        $this->assertQueryRejected(fn (): bool => DB::table('orders')->insert(array_replace($base, [
            'public_id' => (string) Str::ulid(),
            'user_id' => $otherUserId,
        ])));
        $this->assertQueryRejected(fn (): bool => DB::table('orders')->insert(array_replace($base, [
            'public_id' => (string) Str::ulid(),
            'settled_amount_irr' => (int) $quote->final_price_irr,
        ])));
        $this->assertQueryRejected(fn (): bool => DB::table('orders')->insert(array_replace($base, [
            'public_id' => (string) Str::ulid(),
            'state_version' => 1,
        ])));

        self::assertSame(0, DB::table('orders')->count());
    }

    /** @return array{0:int,1:string,2:string,3:string} */
    private function preparePurchaseQuote(string $suffix): array
    {
        $methodCode = 'order_prepay_'.$suffix;
        $administratorId = $this->ownerAdministrator();
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'purchase.order.prepay.quote.'.$suffix,
            $userId,
            $offering['id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                $this->purchaseOrderClock->value->modify('+30 minutes'),
            ),
            $this->purchaseOrderCorrelation('quote-'.$suffix),
        );

        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'purchase.order.prepay.method.'.$suffix,
            $administratorId,
            $methodCode,
            true,
            false,
            1,
            'Purchase Order pre-payment authority test method.',
            $this->purchaseOrderCorrelation('method-'.$suffix),
        );
        $eligibility->recordHealth(
            'purchase.order.prepay.health.'.$suffix,
            $administratorId,
            $methodCode,
            true,
            $this->purchaseOrderClock->value->modify('+10 minutes'),
            'Healthy Purchase Order pre-payment test observation.',
            $this->purchaseOrderCorrelation('health-'.$suffix),
        );
        $decision = $eligibility->evaluate(
            'purchase.order.prepay.eligibility.'.$suffix,
            $userId,
            $quote->quotePublicId,
        );

        return [$userId, $quote->quotePublicId, $methodCode, $decision->publicId];
    }

    private function captureIntent(string $intentPublicId, string $methodCode, string $suffix): object
    {
        return $this->app->make(PurchaseSettlementService::class)->capture(
            $intentPublicId,
            $methodCode,
            new VerifiedPaymentEvent(
                'evt-order-prepay-'.$suffix,
                hash('sha256', 'purchase-order-prepay-provider-event:'.$suffix),
                new PaymentEvidence(
                    ProviderOperationOutcome::Success,
                    PaymentEvidenceAuthority::Authoritative,
                    PaymentTransactionStatus::Settled,
                    'txn-order-prepay-'.$suffix,
                    'evt-order-prepay-'.$suffix,
                    Money::irr((int) DB::table('payment_intents')->where('public_id', $intentPublicId)->value('amount_irr')),
                    $this->purchaseOrderClock->value,
                    $this->purchaseOrderClock->value,
                    hash('sha256', 'purchase-order-prepay-provider-evidence:'.$suffix),
                    ['provider_reference' => 'txn-order-prepay-'.$suffix],
                ),
            ),
            $this->purchaseOrderCorrelation('settlement-'.$suffix),
        );
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected database guard rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
