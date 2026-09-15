<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use App\Modules\Orders\Application\PurchaseOrderReceipt;
use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Domain\OrderState;
use App\Modules\Payments\Application\PurchaseSettlementReceipt;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\PreservesOutboxTriggerSurface;
use Tests\TestCase;

/** @requirement BUY-001 PAY-002 PAY-003 PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 QUA-004 */
final class InitialProvisioningMigrationBoundarySafetyTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use DatabaseTruncation;
    use PreservesOutboxTriggerSurface;
    use PurchaseOrderTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->bootPurchaseOrderClock();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->app)) {
                $this->truncateDatabaseTables();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_001162_boundary_stays_fail_closed_until_refund_invalidation_and_final_activation_complete(): void
    {
        /** @var Migration $queueMigration */
        $queueMigration = require database_path('migrations/2026_08_14_001162_create_provisioning_queue_authority.php');
        /** @var Migration $invalidationMigration */
        $invalidationMigration = require database_path('migrations/2026_08_14_001163_harden_provisioning_financial_invalidation.php');
        /** @var Migration $outboxOrderMigration */
        $outboxOrderMigration = require database_path('migrations/2026_08_14_001164_harden_initial_provisioning_outbox_order_authority.php');
        /** @var Migration $activationMigration */
        $activationMigration = require database_path('migrations/2026_08_14_001165_activate_provisioning_queue_authority.php');
        /** @var Migration $nonPaidInvalidationMigration */
        $nonPaidInvalidationMigration = require database_path('migrations/2026_08_19_000115_extend_provisioning_invalidation_to_non_paid_sources.php');
        /** @var Migration $nonPaidAuthorityMigration */
        $nonPaidAuthorityMigration = require database_path('migrations/2026_08_19_000120_activate_non_paid_order_authority.php');
        /** @var Migration $paidMutationMigration */
        $paidMutationMigration = require database_path('migrations/2026_08_20_000110_enable_paid_service_mutation_authority.php');

        try {
            $paidMutationMigration->down();
            $activationMigration->down();
            $outboxOrderMigration->down();
            $invalidationMigration->down();
            $queueMigration->up();

            self::assertFalse(Schema::hasTable('provisioning_financial_invalidations'));
            self::assertTrue($this->triggerContains('service_subscriptions_insert_guard', 'creation is disabled until provisioning authority migration completes'));
            self::assertTrue($this->triggerContains('provisioning_operations_insert_guard', 'creation is disabled until provisioning authority migration completes'));
            self::assertTrue($this->triggerContains('orders_update_guard', 'Order mutation is not enabled by the current lifecycle authority'));

            [$settlement, $order] = $this->createPaidOrder('boundary-refund');
            $this->commitDirectRefundWithoutIntentTransition($settlement, 'boundary-refund');
            self::assertSame(
                PaymentIntentState::Captured->value,
                DB::table('payment_intents')->where('public_id', $settlement->intentPublicId)->value('state'),
                'The migration-boundary regression deliberately leaves PaymentIntent captured after a valid direct refund row.',
            );

            $this->assertBoundaryQueueAttemptsFailClosed($order, 'before-invalidation');

            $invalidationMigration->up();
            self::assertTrue(Schema::hasTable('provisioning_financial_invalidations'));
            self::assertSame(1, DB::table('provisioning_financial_invalidations')->count());
            self::assertTrue($this->triggerContains('service_subscriptions_insert_guard', 'creation is disabled until provisioning authority migration completes'));

            try {
                $activationMigration->up();
                self::fail('Final queue activation must fail while the exact Order Outbox envelope guard is still absent.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'Provisioning queue activation prerequisites are incomplete; queue authority remains fail-closed.',
                    $exception->getMessage(),
                );
            }
            self::assertTrue($this->triggerContains('service_subscriptions_insert_guard', 'creation is disabled until provisioning authority migration completes'));

            $outboxOrderMigration->up();
            $activationMigration->up();
            self::assertTrue($this->triggerContains('service_subscriptions_insert_guard', 'currently captured authoritative purchase Order Item'));
            self::assertTrue($this->triggerContains('provisioning_operations_insert_guard', 'matching captured purchase authority and Service identity'));
            self::assertTrue($this->triggerContains('orders_update_guard', 'Only paid/v1 to provisioning_queued/v2'));

            try {
                $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
                    $order->orderPublicId,
                    $this->purchaseOrderCorrelation('queue-after-backfill-refund'),
                );
                self::fail('Backfilled authoritative refund must permanently block queue creation after activation resumes.');
            } catch (QueryException) {
                self::assertSame(OrderState::Paid->value, DB::table('orders')->where('id', $order->orderId)->value('state'));
            }

            [, $happyOrder] = $this->createPaidOrder('boundary-happy');
            $receipt = $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
                $happyOrder->orderPublicId,
                $this->purchaseOrderCorrelation('queue-boundary-happy'),
            );
            self::assertSame(OrderState::ProvisioningQueued, $receipt->orderState);
            self::assertSame(1, DB::table('outbox_messages')->where('id', $receipt->outboxEventId)->count());
        } finally {
            $invalidationMigration->up();
            $outboxOrderMigration->up();
            $activationMigration->up();
            $nonPaidInvalidationMigration->up();
            $nonPaidAuthorityMigration->up();
            $paidMutationMigration->up();
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

    private function commitDirectRefundWithoutIntentTransition(PurchaseSettlementReceipt $settlement, string $suffix): void
    {
        $settlementRow = DB::table('purchase_settlements')
            ->where('public_id', $settlement->settlementPublicId)
            ->first(['id', 'payment_intent_id', 'user_id']);
        self::assertNotNull($settlementRow);

        $intentId = (int) $settlementRow->payment_intent_id;
        $amountIrr = $settlement->amount->amount();
        $currency = $settlement->amount->currency();
        $occurredAt = $this->purchaseOrderClock->value->modify('+5 minutes')->format('Y-m-d H:i:s.u');
        $providerEventId = 'evt-migration-boundary-'.$suffix;
        $providerRefundId = 'refund-migration-boundary-'.$suffix;
        $eventPayloadHash = hash('sha256', 'migration-boundary-event:'.$suffix);
        $evidencePayloadHash = hash('sha256', 'migration-boundary-evidence:'.$suffix);

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
            'refund_key' => 'migration-boundary-'.$suffix,
            'payload_hash' => hash('sha256', 'migration-boundary-payload:'.$suffix),
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
            'correlation_id' => $this->purchaseOrderCorrelation('refund-'.$suffix),
            'created_at' => $this->purchaseOrderTimestamp(),
        ]);
        self::assertGreaterThan(0, $refundId);
        self::assertFalse(Schema::hasTable('provisioning_financial_invalidations'));
    }

    private function assertBoundaryQueueAttemptsFailClosed(PurchaseOrderReceipt $order, string $suffix): void
    {
        try {
            $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
                $order->orderPublicId,
                $this->purchaseOrderCorrelation('application-'.$suffix),
            );
            self::fail('Application queue must remain fail-closed at the committed 001162 migration boundary.');
        } catch (QueryException) {
            // Expected DB authority fence.
        }

        $orderRow = DB::table('orders')->where('id', $order->orderId)->first(['id', 'user_id']);
        self::assertNotNull($orderRow);
        $item = DB::table('order_items')->where('order_id', $order->orderId)->where('line_number', 1)->first(['id', 'public_id']);
        self::assertNotNull($item);
        $timestamp = $this->purchaseOrderTimestamp();

        try {
            DB::table('service_subscriptions')->insert([
                'public_id' => (string) Str::ulid(),
                'order_id' => (int) $orderRow->id,
                'order_item_id' => (int) $item->id,
                'user_id' => (int) $orderRow->user_id,
                'creation_correlation_id' => $this->purchaseOrderCorrelation('direct-service-'.$suffix),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            self::fail('Direct Service creation must remain fail-closed before final activation.');
        } catch (QueryException) {
            // Expected DB authority fence.
        }

        try {
            DB::table('provisioning_operations')->insert([
                'public_id' => (string) Str::ulid(),
                'operation_key' => 'initial-provision:'.$item->public_id,
                'operation_type' => 'initial_provision',
                'order_id' => (int) $orderRow->id,
                'order_item_id' => (int) $item->id,
                'service_subscription_id' => PHP_INT_MAX,
                'user_id' => (int) $orderRow->user_id,
                'state' => 'queued',
                'state_version' => 1,
                'correlation_id' => $this->purchaseOrderCorrelation('direct-operation-'.$suffix),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            self::fail('Direct Provisioning Operation creation must remain fail-closed before final activation.');
        } catch (QueryException) {
            // Expected DB authority fence.
        }

        try {
            DB::table('orders')->where('id', $order->orderId)->update([
                'state' => OrderState::ProvisioningQueued->value,
                'state_version' => 2,
                'updated_at' => $timestamp,
            ]);
            self::fail('Direct Order queue transition must remain fail-closed before final activation.');
        } catch (QueryException) {
            // Expected DB authority fence.
        }

        self::assertSame(0, DB::table('service_subscriptions')->count());
        self::assertSame(0, DB::table('provisioning_operations')->count());
        self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'provisioning.initial.requested')->count());
        self::assertSame(OrderState::Paid->value, DB::table('orders')->where('id', $order->orderId)->value('state'));
        self::assertSame(1, (int) DB::table('orders')->where('id', $order->orderId)->value('state_version'));
    }

    private function triggerContains(string $trigger, string $needle): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ? AND LOCATE(?, ACTION_STATEMENT) > 0',
            [$trigger, $needle],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }
}
