<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use App\Modules\Orders\Application\PurchaseOrderReceipt;
use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Domain\OrderState;
use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\PurchaseRefundReceipt;
use App\Modules\Payments\Application\PurchaseRefundService;
use App\Modules\Payments\Application\PurchaseSettlementReceipt;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Shared\Domain\Money;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement BUY-001 PAY-002 PAY-003 PRV-002 PRV-003 ARCH-003 ARCH-004 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 QUA-001 QUA-004 */
final class InitialProvisioningQueueAuthorityTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use DatabaseTruncation;
    use PurchaseOrderTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->bootPurchaseOrderClock();
    }

    public function test_currently_captured_purchase_order_queues_once_and_exact_replay_returns_same_identities(): void
    {
        [$settlement, $order] = $this->createPaidOrder('happy');
        $service = $this->app->make(InitialProvisioningQueueService::class);

        $receipt = $service->queueInitial($order->orderPublicId, $this->purchaseOrderCorrelation('queue-happy'));

        self::assertFalse($receipt->replayed);
        self::assertSame($order->orderId, $receipt->orderId);
        self::assertSame($order->orderItemPublicId, $receipt->orderItemPublicId);
        self::assertSame(OrderState::ProvisioningQueued, $receipt->orderState);
        self::assertSame(2, $receipt->orderStateVersion);
        self::assertSame(ProvisioningState::Queued, $receipt->provisioningState);
        self::assertSame(1, $receipt->provisioningStateVersion);
        self::assertTrue(Str::isUlid($receipt->serviceSubscriptionPublicId));
        self::assertTrue(Str::isUlid($receipt->provisioningOperationPublicId));
        self::assertTrue(Str::isUuid($receipt->outboxEventId));

        $storedOrder = DB::table('orders')->where('id', $order->orderId)->first(['state', 'state_version']);
        self::assertNotNull($storedOrder);
        self::assertSame(OrderState::ProvisioningQueued->value, $storedOrder->state);
        self::assertSame(2, (int) $storedOrder->state_version);
        self::assertSame(1, DB::table('service_subscriptions')->count());
        self::assertSame(1, DB::table('provisioning_operations')->count());
        self::assertSame(1, DB::table('provisioning_operation_histories')->count());
        self::assertSame(2, DB::table('order_state_histories')->where('order_id', $order->orderId)->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'provisioning.initial.requested')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'order.initial_provisioning.queued')->count());

        $outbox = DB::table('outbox_messages')->where('id', $receipt->outboxEventId)->first([
            'event_key', 'event_type', 'aggregate_type', 'aggregate_id', 'payload', 'payload_hash',
        ]);
        self::assertNotNull($outbox);
        self::assertSame('provisioning.initial.requested:'.$receipt->provisioningOperationPublicId, $outbox->event_key);
        self::assertSame('provisioning.initial.requested', $outbox->event_type);
        self::assertSame('provisioning_operation', $outbox->aggregate_type);
        self::assertSame($receipt->provisioningOperationPublicId, $outbox->aggregate_id);
        /** @var array<string, string> $payload */
        $payload = json_decode((string) $outbox->payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([
            'order_item_public_id' => $order->orderItemPublicId,
            'order_public_id' => $order->orderPublicId,
            'provisioning_operation_public_id' => $receipt->provisioningOperationPublicId,
            'service_subscription_public_id' => $receipt->serviceSubscriptionPublicId,
        ], $payload);
        self::assertSame(hash('sha256', (string) $outbox->payload), $outbox->payload_hash);

        $replay = $service->queueInitial($order->orderPublicId, $this->purchaseOrderCorrelation('queue-happy-replay'));
        self::assertTrue($replay->replayed);
        self::assertSame($receipt->serviceSubscriptionId, $replay->serviceSubscriptionId);
        self::assertSame($receipt->serviceSubscriptionPublicId, $replay->serviceSubscriptionPublicId);
        self::assertSame($receipt->provisioningOperationId, $replay->provisioningOperationId);
        self::assertSame($receipt->provisioningOperationPublicId, $replay->provisioningOperationPublicId);
        self::assertSame($receipt->outboxEventId, $replay->outboxEventId);
        self::assertSame(1, DB::table('service_subscriptions')->count());
        self::assertSame(1, DB::table('provisioning_operations')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'provisioning.initial.requested')->count());
        self::assertSame(PaymentIntentState::Captured->value, DB::table('payment_intents')->where('public_id', $settlement->intentPublicId)->value('state'));
    }

    public function test_refund_lifecycle_states_before_queue_fail_closed_without_any_provisioning_effect(): void
    {
        [$pendingSettlement, $pendingOrder] = $this->createPaidOrder('refund-pending-before');
        $pendingAmount = $this->partialRefundAmount($pendingSettlement);
        $this->placeAuthoritativeRefundPending($pendingSettlement, 'refund-pending-before', $pendingAmount);
        self::assertSame(
            PaymentIntentState::RefundPending->value,
            DB::table('payment_intents')->where('public_id', $pendingSettlement->intentPublicId)->value('state'),
        );
        $this->assertQueueCreationFailsClosed($pendingOrder, 'refund-pending');

        [$partialSettlement, $partialOrder] = $this->createPaidOrder('partial-refund-before');
        $partialRefund = $this->recordRefund(
            $partialSettlement,
            'partial-refund-before',
            $this->partialRefundAmount($partialSettlement),
        );
        self::assertSame(PaymentIntentState::PartiallyRefunded, $partialRefund->state);
        $this->assertQueueCreationFailsClosed($partialOrder, 'partially-refunded');

        [$refundedSettlement, $refundedOrder] = $this->createPaidOrder('refund-before');
        $refund = $this->recordFullRefund($refundedSettlement, 'refund-before');
        self::assertSame(PaymentIntentState::Refunded, $refund->state);
        $this->assertQueueCreationFailsClosed($refundedOrder, 'refunded');
    }

    public function test_exact_replay_after_later_refund_returns_existing_queue_identity_but_creates_no_new_effect(): void
    {
        [$settlement, $order] = $this->createPaidOrder('refund-after');
        $service = $this->app->make(InitialProvisioningQueueService::class);
        $first = $service->queueInitial($order->orderPublicId, $this->purchaseOrderCorrelation('queue-refund-after'));
        $this->recordFullRefund($settlement, 'refund-after');

        $replay = $service->queueInitial($order->orderPublicId, $this->purchaseOrderCorrelation('queue-refund-after-replay'));

        self::assertTrue($replay->replayed);
        self::assertSame($first->serviceSubscriptionPublicId, $replay->serviceSubscriptionPublicId);
        self::assertSame($first->provisioningOperationPublicId, $replay->provisioningOperationPublicId);
        self::assertSame($first->outboxEventId, $replay->outboxEventId);
        self::assertSame(1, DB::table('service_subscriptions')->count());
        self::assertSame(1, DB::table('provisioning_operations')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'provisioning.initial.requested')->count());
        self::assertSame(PaymentIntentState::Refunded->value, DB::table('payment_intents')->where('public_id', $settlement->intentPublicId)->value('state'));
    }

    public function test_database_guards_reject_missing_authority_wrong_identity_and_lifecycle_mutation(): void
    {
        [$settlement, $order] = $this->createPaidOrder('db-guards');
        $timestamp = $this->purchaseOrderTimestamp();

        try {
            DB::table('orders')->where('id', $order->orderId)->update([
                'state' => OrderState::ProvisioningQueued->value,
                'state_version' => 2,
                'updated_at' => $timestamp,
            ]);
            self::fail('Direct Order queue transition without durable authority should be rejected.');
        } catch (QueryException) {
            self::assertSame(OrderState::Paid->value, DB::table('orders')->where('id', $order->orderId)->value('state'));
        }

        $itemId = (int) DB::table('order_items')->where('order_id', $order->orderId)->value('id');
        $wrongUserId = $this->quoteUser('agent');
        try {
            DB::table('service_subscriptions')->insert([
                'public_id' => (string) Str::ulid(),
                'order_id' => $order->orderId,
                'order_item_id' => $itemId,
                'user_id' => $wrongUserId,
                'creation_correlation_id' => $this->purchaseOrderCorrelation('forged-service'),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            self::fail('Cross-user Service Subscription should be rejected.');
        } catch (QueryException) {
            self::assertSame(0, DB::table('service_subscriptions')->count());
        }

        $receipt = $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
            $order->orderPublicId,
            $this->purchaseOrderCorrelation('db-guards-valid'),
        );

        foreach ([
            ['service_subscriptions', $receipt->serviceSubscriptionId, ['creation_correlation_id' => $this->purchaseOrderCorrelation('mutate-service')]],
            ['provisioning_operations', $receipt->provisioningOperationId, ['correlation_id' => $this->purchaseOrderCorrelation('mutate-operation')]],
        ] as [$table, $id, $changes]) {
            try {
                DB::table($table)->where('id', $id)->update($changes);
                self::fail($table.' mutation should be rejected.');
            } catch (QueryException) {
                self::assertSame(1, DB::table($table)->where('id', $id)->count());
            }
        }

        try {
            DB::table('orders')->where('id', $order->orderId)->update(['total_amount_irr' => (int) $settlement->amount->amount() + 1]);
            self::fail('Order commercial identity mutation should be rejected.');
        } catch (QueryException) {
            self::assertSame((int) $settlement->amount->amount(), (int) DB::table('orders')->where('id', $order->orderId)->value('total_amount_irr'));
        }

        $historyId = (int) DB::table('provisioning_operation_histories')->where('provisioning_operation_id', $receipt->provisioningOperationId)->value('id');
        try {
            DB::table('provisioning_operation_histories')->where('id', $historyId)->delete();
            self::fail('Provisioning Operation history deletion should be rejected.');
        } catch (QueryException) {
            self::assertSame(1, DB::table('provisioning_operation_histories')->where('id', $historyId)->count());
        }
    }

    public function test_database_guard_rejects_service_identity_after_refund_even_with_genuine_order_and_item(): void
    {
        [$settlement, $order] = $this->createPaidOrder('db-refund');
        $this->recordFullRefund($settlement, 'db-refund');
        $itemId = (int) DB::table('order_items')->where('order_id', $order->orderId)->value('id');

        $this->expectException(QueryException::class);
        try {
            DB::table('service_subscriptions')->insert([
                'public_id' => (string) Str::ulid(),
                'order_id' => $order->orderId,
                'order_item_id' => $itemId,
                'user_id' => $settlement->userId,
                'creation_correlation_id' => $this->purchaseOrderCorrelation('db-refund-forge'),
                'created_at' => $this->purchaseOrderTimestamp(),
                'updated_at' => $this->purchaseOrderTimestamp(),
            ]);
        } finally {
            self::assertSame(0, DB::table('service_subscriptions')->count());
            self::assertSame(0, DB::table('provisioning_operations')->count());
            self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'provisioning.initial.requested')->count());
        }
    }

    public function test_malformed_and_unknown_order_ids_fail_without_effect(): void
    {
        $service = $this->app->make(InitialProvisioningQueueService::class);

        foreach (['not-a-ulid', (string) Str::ulid()] as $orderPublicId) {
            try {
                $service->queueInitial($orderPublicId, $this->purchaseOrderCorrelation('invalid-'.substr(hash('sha256', $orderPublicId), 0, 8)));
                self::fail('Invalid or unknown Order should fail closed.');
            } catch (DomainException) {
                self::assertSame(0, DB::table('service_subscriptions')->count());
                self::assertSame(0, DB::table('provisioning_operations')->count());
                self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'provisioning.initial.requested')->count());
            }
        }
    }

    /** @return array{0:PurchaseSettlementReceipt,1:PurchaseOrderReceipt} */
    private function createPaidOrder(string $suffix): array
    {
        $settlement = $this->createPurchaseOrderSettlement($suffix);
        $order = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('order-'.$suffix),
        );

        return [$settlement, $order];
    }

    private function assertQueueCreationFailsClosed(PurchaseOrderReceipt $order, string $suffix): void
    {
        try {
            $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
                $order->orderPublicId,
                $this->purchaseOrderCorrelation('queue-'.$suffix),
            );
            self::fail('Refunded purchase Order must not create initial provisioning authority.');
        } catch (DomainException) {
            self::assertSame(0, DB::table('service_subscriptions')->count());
            self::assertSame(0, DB::table('provisioning_operations')->count());
            self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'provisioning.initial.requested')->count());
            self::assertSame(OrderState::Paid->value, DB::table('orders')->where('id', $order->orderId)->value('state'));
            self::assertSame(1, (int) DB::table('orders')->where('id', $order->orderId)->value('state_version'));
        }
    }

    private function partialRefundAmount(PurchaseSettlementReceipt $settlement): int
    {
        $total = $settlement->amount->amount();
        self::assertGreaterThan(1, $total);

        return intdiv($total, 2);
    }

    private function placeAuthoritativeRefundPending(
        PurchaseSettlementReceipt $settlement,
        string $suffix,
        int $amountIrr,
    ): void {
        $settlementRow = DB::table('purchase_settlements')
            ->where('public_id', $settlement->settlementPublicId)
            ->first(['id', 'payment_intent_id', 'user_id']);
        self::assertNotNull($settlementRow);

        $occurredAt = $this->purchaseOrderClock->value->modify('+5 minutes');
        $occurredAtDatabase = $occurredAt->format('Y-m-d H:i:s.u');
        $providerEventId = 'evt-provisioning-refund-'.$suffix;
        $providerRefundId = 'refund-txn-'.$suffix;
        $eventPayloadHash = hash('sha256', 'provisioning-refund-event:'.$suffix);
        $evidencePayloadHash = hash('sha256', 'provisioning-refund-evidence:'.$suffix);
        $correlationId = $this->purchaseOrderCorrelation('refund-'.$suffix);
        $currency = $settlement->amount->currency();
        $intentId = (int) $settlementRow->payment_intent_id;

        $providerEventRowId = (int) DB::table('payment_provider_events')->insertGetId([
            'payment_intent_id' => $intentId,
            'provider_code' => $settlement->providerCode,
            'provider_event_id' => $providerEventId,
            'event_payload_hash' => $eventPayloadHash,
            'provider_transaction_id' => $providerRefundId,
            'evidence_payload_hash' => $evidencePayloadHash,
            'evidence_authority' => PaymentEvidenceAuthority::Authoritative->value,
            'transaction_status' => PaymentTransactionStatus::Refunded->value,
            'amount_irr' => $amountIrr,
            'currency' => $currency,
            'occurred_at' => $occurredAtDatabase,
            'settled_at' => $occurredAtDatabase,
            'safe_evidence' => json_encode(['provider_reference' => $providerRefundId], JSON_THROW_ON_ERROR),
            'created_at' => $this->purchaseOrderTimestamp(),
        ]);

        $payloadHash = hash('sha256', json_encode([
            'purchase_settlement_public_id' => $settlement->settlementPublicId,
            'provider_code' => $settlement->providerCode,
            'provider_event_id' => $providerEventId,
            'event_payload_hash' => $eventPayloadHash,
            'provider_refund_id' => $providerRefundId,
            'evidence_payload_hash' => $evidencePayloadHash,
            'evidence_authority' => PaymentEvidenceAuthority::Authoritative->value,
            'transaction_status' => PaymentTransactionStatus::Refunded->value,
            'amount_irr' => $amountIrr,
            'currency' => $currency,
            'occurred_at' => $occurredAtDatabase,
            'settled_at' => $occurredAtDatabase,
        ], JSON_THROW_ON_ERROR));

        $refundId = (int) DB::table('purchase_refunds')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'refund_key' => 'provisioning-refund-'.$suffix,
            'payload_hash' => $payloadHash,
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
            'resulting_payment_state' => PaymentIntentState::PartiallyRefunded->value,
            'refunded_at' => $occurredAtDatabase,
            'correlation_id' => $correlationId,
            'created_at' => $this->purchaseOrderTimestamp(),
        ]);

        $updated = DB::table('payment_intents')
            ->where('id', $intentId)
            ->where('state', PaymentIntentState::Captured->value)
            ->update([
                'state' => PaymentIntentState::RefundPending->value,
                'latest_purchase_refund_id' => $refundId,
                'updated_at' => $this->purchaseOrderTimestamp(),
            ]);
        self::assertSame(1, $updated);

        DB::table('payment_intent_state_histories')->insert([
            'payment_intent_id' => $intentId,
            'from_state' => PaymentIntentState::Captured->value,
            'to_state' => PaymentIntentState::RefundPending->value,
            'reason_code' => 'authoritative_purchase_refund_received',
            'correlation_id' => $correlationId,
            'created_at' => $this->purchaseOrderTimestamp(),
        ]);
    }

    private function recordFullRefund(PurchaseSettlementReceipt $settlement, string $suffix): PurchaseRefundReceipt
    {
        return $this->recordRefund($settlement, $suffix, $settlement->amount->amount());
    }

    private function recordRefund(
        PurchaseSettlementReceipt $settlement,
        string $suffix,
        int $amountIrr,
    ): PurchaseRefundReceipt {
        $occurredAt = $this->purchaseOrderClock->value->modify('+5 minutes');

        return $this->app->make(PurchaseRefundService::class)->record(
            'provisioning-refund-'.$suffix,
            $settlement->settlementPublicId,
            $settlement->providerCode,
            new VerifiedPaymentEvent(
                'evt-provisioning-refund-'.$suffix,
                hash('sha256', 'provisioning-refund-event:'.$suffix),
                new PaymentEvidence(
                    ProviderOperationOutcome::Success,
                    PaymentEvidenceAuthority::Authoritative,
                    PaymentTransactionStatus::Refunded,
                    'refund-txn-'.$suffix,
                    'evt-provisioning-refund-'.$suffix,
                    Money::irr($amountIrr),
                    $occurredAt,
                    $occurredAt,
                    hash('sha256', 'provisioning-refund-evidence:'.$suffix),
                    ['provider_reference' => 'refund-txn-'.$suffix],
                ),
            ),
            $this->purchaseOrderCorrelation('refund-'.$suffix),
        );
    }
}
