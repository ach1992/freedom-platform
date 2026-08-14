<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Domain\OrderState;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement BUY-001 BUY-002 PAY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
final class PurchaseOrderAuthorityTest extends TestCase
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

    public function test_authoritative_purchase_settlement_creates_one_paid_order_and_immutable_item_with_exact_replay(): void
    {
        $settlement = $this->createPurchaseOrderSettlement('success');
        $service = $this->app->make(PurchaseOrderService::class);

        $receipt = $service->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('create-success'),
        );

        self::assertFalse($receipt->replayed);
        self::assertSame(OrderState::Paid, $receipt->state);
        self::assertSame(1, $receipt->stateVersion);
        self::assertSame($settlement->settlementPublicId, $receipt->purchaseSettlementPublicId);
        self::assertSame($settlement->intentPublicId, $receipt->paymentIntentPublicId);
        self::assertSame($settlement->sourceQuotePublicId, $receipt->sourceQuotePublicId);
        self::assertSame($settlement->userId, $receipt->userId);
        self::assertSame($settlement->amount->amount(), $receipt->amount->amount());
        self::assertSame(1, DB::table('orders')->count());
        self::assertSame(1, DB::table('order_items')->count());
        self::assertSame(1, DB::table('order_state_histories')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'order.purchase.created')->count());

        $order = DB::table('orders')->where('id', $receipt->orderId)->first();
        self::assertNotNull($order);
        self::assertSame('purchase', $order->source_type);
        self::assertSame('paid', $order->state);
        self::assertSame(1, (int) $order->state_version);
        self::assertSame($settlement->settlementId, (int) $order->purchase_settlement_id);
        self::assertSame($settlement->amount->amount(), (int) $order->total_amount_irr);
        self::assertSame('IRR', $order->currency);

        $item = DB::table('order_items')->where('order_id', $receipt->orderId)->first();
        self::assertNotNull($item);
        $quote = DB::table('quotes')->where('public_id', $settlement->sourceQuotePublicId)->first();
        self::assertNotNull($quote);
        self::assertSame((int) $quote->plan_offering_id, (int) $item->plan_offering_id);
        self::assertSame($quote->offering_code_snapshot, $item->offering_code_snapshot);
        self::assertSame((int) $quote->offering_version, (int) $item->offering_version);
        self::assertSame((int) $quote->final_price_irr, (int) $item->final_price_irr);
        self::assertSame($quote->configuration_snapshot_hash, $item->configuration_snapshot_hash);
        self::assertSame($quote->configuration_snapshot, $item->configuration_snapshot);

        $history = DB::table('order_state_histories')->where('order_id', $receipt->orderId)->first();
        self::assertNotNull($history);
        self::assertNull($history->from_state);
        self::assertSame('paid', $history->to_state);
        self::assertNull($history->from_version);
        self::assertSame(1, (int) $history->to_version);
        self::assertSame('system', $history->actor_type);
        self::assertSame('authoritative_purchase_settlement', $history->reason_code);

        $replay = $service->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('create-success-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($receipt->orderId, $replay->orderId);
        self::assertSame($receipt->orderPublicId, $replay->orderPublicId);
        self::assertSame($receipt->orderItemPublicId, $replay->orderItemPublicId);
        self::assertSame(1, DB::table('orders')->count());
        self::assertSame(1, DB::table('order_items')->count());
        self::assertSame(1, DB::table('order_state_histories')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'order.purchase.created')->count());
    }

    public function test_database_rejects_cross_user_paid_order_forgery_and_immutable_history_mutation(): void
    {
        $settlement = $this->createPurchaseOrderSettlement('guards');
        $otherUserId = $this->quoteUser('customer');
        $settlementRow = DB::table('purchase_settlements')->where('id', $settlement->settlementId)->first();
        self::assertNotNull($settlementRow);
        $intent = DB::table('payment_intents')->where('id', $settlementRow->payment_intent_id)->first();
        self::assertNotNull($intent);
        $quote = DB::table('quotes')->where('id', $settlementRow->source_quote_id)->first();
        self::assertNotNull($quote);

        $this->assertQueryRejected(fn (): bool => DB::table('orders')->insert([
            'public_id' => (string) Str::ulid(),
            'source_type' => 'purchase',
            'purchase_settlement_id' => $settlementRow->id,
            'purchase_settlement_public_id' => $settlementRow->public_id,
            'payment_intent_id' => $intent->id,
            'payment_intent_public_id' => $intent->public_id,
            'user_id' => $otherUserId,
            'source_quote_id' => $quote->id,
            'source_quote_public_id' => $quote->public_id,
            'source_quote_configuration_hash' => $quote->configuration_snapshot_hash,
            'state' => 'paid',
            'state_version' => 1,
            'total_amount_irr' => $settlementRow->amount_irr,
            'currency' => 'IRR',
            'paid_at' => $settlementRow->settled_at,
            'creation_correlation_id' => $this->purchaseOrderCorrelation('forged-order'),
            'created_at' => $this->purchaseOrderTimestamp(),
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]));
        self::assertSame(0, DB::table('orders')->count());

        $receipt = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('guarded-create'),
        );

        $this->assertQueryRejected(fn (): int => DB::table('orders')->where('id', $receipt->orderId)->update([
            'state' => 'provisioning_queued',
            'state_version' => 2,
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]));
        $this->assertQueryRejected(fn (): int => DB::table('orders')->where('id', $receipt->orderId)->delete());
        $this->assertQueryRejected(fn (): int => DB::table('order_items')->where('order_id', $receipt->orderId)->update([
            'final_price_irr' => $settlement->amount->amount() + 1,
        ]));
        $this->assertQueryRejected(fn (): int => DB::table('order_items')->where('order_id', $receipt->orderId)->delete());
        $this->assertQueryRejected(fn (): int => DB::table('order_state_histories')->where('order_id', $receipt->orderId)->update([
            'reason_code' => 'forged',
        ]));
        $this->assertQueryRejected(fn (): int => DB::table('order_state_histories')->where('order_id', $receipt->orderId)->delete());
        $this->assertQueryRejected(fn (): bool => DB::table('order_state_histories')->insert([
            'order_id' => $receipt->orderId,
            'from_state' => 'paid',
            'to_state' => 'provisioning_queued',
            'from_version' => 1,
            'to_version' => 2,
            'actor_type' => 'system',
            'actor_id' => null,
            'reason_code' => 'forged',
            'correlation_id' => $this->purchaseOrderCorrelation('forged-history'),
            'created_at' => $this->purchaseOrderTimestamp(),
        ]));

        self::assertSame('paid', DB::table('orders')->where('id', $receipt->orderId)->value('state'));
        self::assertSame(1, DB::table('order_state_histories')->where('order_id', $receipt->orderId)->count());
    }

    public function test_unknown_or_malformed_settlement_cannot_create_order(): void
    {
        $service = $this->app->make(PurchaseOrderService::class);

        try {
            $service->createFromSettlement('not-a-ulid', $this->purchaseOrderCorrelation('malformed'));
            self::fail('Expected malformed settlement ID rejection.');
        } catch (DomainException $exception) {
            self::assertSame('Purchase settlement public ID is invalid.', $exception->getMessage());
        }

        try {
            $service->createFromSettlement('01K2ED4F2H9S6DRX8JY2G4M7QT', $this->purchaseOrderCorrelation('unknown'));
            self::fail('Expected unknown settlement rejection.');
        } catch (DomainException $exception) {
            self::assertSame('Purchase settlement does not exist.', $exception->getMessage());
        }

        self::assertSame(0, DB::table('orders')->count());
        self::assertSame(0, DB::table('order_items')->count());
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
