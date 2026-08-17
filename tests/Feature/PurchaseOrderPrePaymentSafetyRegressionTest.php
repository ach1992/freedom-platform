<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\OrderState;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement BUY-001 BUY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
final class PurchaseOrderPrePaymentSafetyRegressionTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use PurchaseOrderTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->bootPurchaseOrderClock();
    }

    public function test_first_open_rejects_expired_quote(): void
    {
        [$userId, $quotePublicId] = $this->createQuote('expired-first-open');
        $this->setPurchaseOrderNow($this->purchaseOrderClock->value->modify('+31 minutes'));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Purchase Quote is not current.');

        $this->app->make(PurchaseOrderService::class)->openFromQuote(
            $quotePublicId,
            $userId,
            $this->purchaseOrderCorrelation('expired-first-open'),
        );
    }

    public function test_first_open_rejects_quote_before_valid_from(): void
    {
        $beforeValidFrom = $this->purchaseOrderClock->value;
        $this->setPurchaseOrderNow($beforeValidFrom->modify('+5 minutes'));
        [$userId, $quotePublicId] = $this->createQuote('future-first-open');
        $this->setPurchaseOrderNow($beforeValidFrom);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Purchase Quote is not current.');

        $this->app->make(PurchaseOrderService::class)->openFromQuote(
            $quotePublicId,
            $userId,
            $this->purchaseOrderCorrelation('future-first-open'),
        );
    }

    public function test_existing_order_replays_after_quote_expiry(): void
    {
        [$userId, $quotePublicId] = $this->createQuote('expired-replay');
        $orders = $this->app->make(PurchaseOrderService::class);
        $opened = $orders->openFromQuote(
            $quotePublicId,
            $userId,
            $this->purchaseOrderCorrelation('expired-replay-open'),
        );

        $this->setPurchaseOrderNow($this->purchaseOrderClock->value->modify('+31 minutes'));
        $replayed = $orders->openFromQuote(
            $quotePublicId,
            $userId,
            $this->purchaseOrderCorrelation('expired-replay-again'),
        );

        self::assertTrue($replayed->replayed);
        self::assertSame($opened->orderId, $replayed->orderId);
        self::assertSame($opened->orderPublicId, $replayed->orderPublicId);
        self::assertSame(OrderState::AwaitingPayment, $replayed->state);
        self::assertSame(0, $replayed->stateVersion);
        self::assertSame(1, DB::table('orders')->count());
        self::assertSame(1, DB::table('order_items')->count());
        self::assertSame(1, DB::table('order_state_histories')->count());
    }

    public function test_direct_database_insert_rejects_expired_quote_for_pre_payment_order(): void
    {
        [$userId, $quotePublicId] = $this->createQuote('expired-db');
        $quote = DB::table('quotes')->where('public_id', $quotePublicId)->first();
        self::assertNotNull($quote);
        $this->setPurchaseOrderNow($this->purchaseOrderClock->value->modify('+31 minutes'));

        $this->assertQueryRejected(fn (): bool => DB::table('orders')->insert([
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
            'currency' => $quote->currency,
            'paid_at' => null,
            'creation_correlation_id' => $this->purchaseOrderCorrelation('expired-db-forgery'),
            'created_at' => $this->purchaseOrderTimestamp(),
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]));

        self::assertSame(0, DB::table('orders')->count());
        self::assertSame(0, DB::table('order_state_histories')->count());
    }

    public function test_initial_paid_history_cannot_be_forged_for_awaiting_payment_order(): void
    {
        [$userId, $quotePublicId] = $this->createQuote('history-forgery');
        $opened = $this->app->make(PurchaseOrderService::class)->openFromQuote(
            $quotePublicId,
            $userId,
            $this->purchaseOrderCorrelation('history-forgery-open'),
        );

        $this->assertQueryRejected(fn (): bool => DB::table('order_state_histories')->insert([
            'order_id' => $opened->orderId,
            'from_state' => null,
            'to_state' => 'paid',
            'from_version' => null,
            'to_version' => 1,
            'actor_type' => 'system',
            'actor_id' => null,
            'reason_code' => 'authoritative_purchase_settlement',
            'correlation_id' => $this->purchaseOrderCorrelation('history-forgery-fake-paid'),
            'created_at' => $this->purchaseOrderTimestamp(),
        ]));

        $order = DB::table('orders')->where('id', $opened->orderId)->first();
        self::assertNotNull($order);
        self::assertSame('awaiting_payment', $order->state);
        self::assertSame(0, (int) $order->state_version);
        self::assertNull($order->purchase_settlement_id);
        self::assertNull($order->payment_intent_id);
        self::assertSame(1, DB::table('order_state_histories')->where('order_id', $opened->orderId)->count());
        self::assertFalse(DB::table('order_state_histories')
            ->where('order_id', $opened->orderId)
            ->where('to_version', 1)
            ->exists());
    }

    /** @return array{0:int,1:string} */
    private function createQuote(string $suffix): array
    {
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'purchase.order.safety.quote.'.$suffix,
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
            $this->purchaseOrderCorrelation('safety-quote-'.$suffix),
        );

        return [$userId, $quote->quotePublicId];
    }

    private function setPurchaseOrderNow(DateTimeImmutable $now): void
    {
        $this->purchaseOrderClock->value = $now;
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET timestamp = '.$now->getTimestamp());
        }
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected database authority guard rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
