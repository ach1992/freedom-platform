<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Domain\OrderState;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement PAY-002 PAY-003 PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-008 QUA-004 */
final class InitialProvisioningPreOrderRefundInvalidationTest extends TestCase
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

    public function test_refund_committed_before_order_materialization_permanently_blocks_later_initial_queue(): void
    {
        $settlement = $this->createPurchaseOrderSettlement('pre-order-refund-invalidation');
        $settlementRow = DB::table('purchase_settlements')
            ->where('public_id', $settlement->settlementPublicId)
            ->first(['id', 'payment_intent_id', 'user_id']);
        self::assertNotNull($settlementRow);

        $intentId = (int) $settlementRow->payment_intent_id;
        self::assertSame(0, DB::table('orders')->where('payment_intent_id', $intentId)->count());
        self::assertSame(
            PaymentIntentState::Captured->value,
            DB::table('payment_intents')->where('id', $intentId)->value('state'),
        );

        $occurredAt = $this->purchaseOrderClock->value->modify('+5 minutes')->format('Y-m-d H:i:s.u');
        $providerEventId = 'evt-pre-order-refund-invalidation';
        $providerRefundId = 'refund-pre-order-invalidation';
        $eventPayloadHash = hash('sha256', 'pre-order-refund-event');
        $evidencePayloadHash = hash('sha256', 'pre-order-refund-evidence');
        $amountIrr = $settlement->amount->amount();
        $currency = $settlement->amount->currency();

        $providerEventRowId = (int) DB::table('payment_provider_events')->insertGetId([
            'payment_intent_id' => $intentId,
            'provider_code' => $settlement->providerCode,
            'provider_event_id' => $providerEventId,
            'event_payload_hash' => $eventPayloadHash,
            'provider_transaction_id' => $providerRefundId,
            'evidence_payload_hash' => $evidencePayloadHash,
            'evidence_authority' => 'authoritative',
            'transaction_status' => 'refunded',
            'amount_irr' => $amountIrr,
            'currency' => $currency,
            'occurred_at' => $occurredAt,
            'settled_at' => $occurredAt,
            'safe_evidence' => json_encode(['provider_reference' => $providerRefundId], JSON_THROW_ON_ERROR),
            'created_at' => $this->purchaseOrderTimestamp(),
        ]);

        $refundId = (int) DB::table('purchase_refunds')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'refund_key' => 'pre-order-refund-invalidation',
            'payload_hash' => hash('sha256', 'pre-order-refund-payload'),
            'purchase_settlement_id' => (int) $settlementRow->id,
            'payment_intent_id' => $intentId,
            'provider_event_row_id' => $providerEventRowId,
            'user_id' => (int) $settlementRow->user_id,
            'provider_code' => $settlement->providerCode,
            'provider_refund_id' => $providerRefundId,
            'evidence_payload_hash' => $evidencePayloadHash,
            'amount_irr' => $amountIrr,
            'cumulative_refunded_irr' => $amountIrr,
            'currency' => $currency,
            'resulting_payment_state' => PaymentIntentState::Refunded->value,
            'refunded_at' => $occurredAt,
            'correlation_id' => $this->purchaseOrderCorrelation('pre-order-refund'),
            'created_at' => $this->purchaseOrderTimestamp(),
        ]);
        self::assertGreaterThan(0, $refundId);

        $invalidation = DB::table('provisioning_financial_invalidations')
            ->where('purchase_settlement_id', (int) $settlementRow->id)
            ->where('payment_intent_id', $intentId)
            ->first(['id', 'order_id', 'purchase_refund_id']);
        self::assertNotNull($invalidation);
        self::assertNull($invalidation->order_id);
        self::assertSame($refundId, (int) $invalidation->purchase_refund_id);
        self::assertSame(
            PaymentIntentState::Captured->value,
            DB::table('payment_intents')->where('id', $intentId)->value('state'),
            'The direct refund row intentionally leaves PaymentIntent captured so the durable invalidation must carry the revocation across later Order creation.',
        );

        $order = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('pre-order-refund-order'),
        );
        self::assertSame(OrderState::Paid, $order->state);
        self::assertSame(1, $order->stateVersion);
        self::assertSame(
            PaymentIntentState::Captured->value,
            DB::table('payment_intents')->where('id', $intentId)->value('state'),
        );

        try {
            $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
                $order->orderPublicId,
                $this->purchaseOrderCorrelation('pre-order-refund-queue'),
            );
            self::fail('A refund invalidation that predates Order materialization must permanently block initial provisioning authority.');
        } catch (QueryException) {
            // Expected: the DB financial invalidation guard rejects Service creation even though
            // the intentionally inconsistent direct-DB PaymentIntent still reads as captured.
        }

        self::assertSame(1, DB::table('provisioning_financial_invalidations')->where('payment_intent_id', $intentId)->count());
        self::assertSame(0, DB::table('service_subscriptions')->count());
        self::assertSame(0, DB::table('provisioning_operations')->count());
        self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'provisioning.initial.requested')->count());
        self::assertSame(OrderState::Paid->value, DB::table('orders')->where('id', $order->orderId)->value('state'));
        self::assertSame(1, (int) DB::table('orders')->where('id', $order->orderId)->value('state_version'));
        self::assertSame(
            PaymentIntentState::Captured->value,
            DB::table('payment_intents')->where('id', $intentId)->value('state'),
        );
    }
}
