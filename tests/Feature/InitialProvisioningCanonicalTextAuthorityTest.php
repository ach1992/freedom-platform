<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use App\Modules\Orders\Application\PurchaseOrderReceipt;
use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Domain\OrderState;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-008 QUA-004 */
final class InitialProvisioningCanonicalTextAuthorityTest extends TestCase
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

    public function test_provisioning_authority_text_columns_are_binary_exact(): void
    {
        $row = DB::selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND COLLATION_NAME = 'utf8mb4_bin'
  AND (
      (TABLE_NAME = 'service_subscriptions'
       AND COLUMN_NAME IN ('public_id', 'creation_correlation_id'))
      OR
      (TABLE_NAME = 'provisioning_operations'
       AND COLUMN_NAME IN ('public_id', 'operation_key', 'operation_type', 'state', 'correlation_id'))
      OR
      (TABLE_NAME = 'provisioning_operation_histories'
       AND COLUMN_NAME IN ('from_state', 'to_state', 'actor_type', 'reason_code', 'correlation_id'))
  )
SQL);

        self::assertNotNull($row);
        self::assertSame(12, (int) $row->aggregate);
    }

    public function test_direct_database_case_variants_cannot_form_initial_provisioning_authority(): void
    {
        $order = $this->createPaidOrder('canonical-text-direct');
        $orderRow = DB::table('orders')->where('id', $order->orderId)->first(['id', 'user_id', 'state', 'state_version']);
        self::assertNotNull($orderRow);
        $item = DB::table('order_items')->where('order_id', $order->orderId)->where('line_number', 1)->first(['id', 'public_id']);
        self::assertNotNull($item);

        $timestamp = $this->purchaseOrderTimestamp();
        $correlationId = $this->purchaseOrderCorrelation('canonical-text-direct');
        $serviceId = (int) DB::table('service_subscriptions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'order_id' => (int) $orderRow->id,
            'order_item_id' => (int) $item->id,
            'user_id' => (int) $orderRow->user_id,
            'creation_correlation_id' => $correlationId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $baseOperation = [
            'public_id' => (string) Str::ulid(),
            'operation_key' => 'initial-provision:'.$item->public_id,
            'operation_type' => 'initial_provision',
            'order_id' => (int) $orderRow->id,
            'order_item_id' => (int) $item->id,
            'service_subscription_id' => $serviceId,
            'user_id' => (int) $orderRow->user_id,
            'state' => 'queued',
            'state_version' => 1,
            'correlation_id' => $correlationId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        $typeVariant = $baseOperation;
        $typeVariant['public_id'] = (string) Str::ulid();
        $typeVariant['operation_type'] = 'INITIAL_PROVISION';
        $this->assertOperationInsertRejected($typeVariant, 'Case-variant Operation type must not be accepted as initial provisioning authority.');

        $stateVariant = $baseOperation;
        $stateVariant['public_id'] = (string) Str::ulid();
        $stateVariant['state'] = 'QUEUED';
        $this->assertOperationInsertRejected($stateVariant, 'Case-variant Operation state must not be accepted as queued authority.');

        $keyVariant = $baseOperation;
        $keyVariant['public_id'] = (string) Str::ulid();
        $keyVariant['operation_key'] = 'INITIAL-PROVISION:'.$item->public_id;
        $this->assertOperationInsertRejected($keyVariant, 'Case-variant Operation key must not be accepted as canonical authority.');

        self::assertSame(0, DB::table('provisioning_operations')->where('order_id', $order->orderId)->count());
        self::assertSame(0, DB::table('provisioning_operation_histories')->count());
        self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'provisioning.initial.requested')->count());
        self::assertSame(OrderState::Paid->value, DB::table('orders')->where('id', $order->orderId)->value('state'));
        self::assertSame(1, (int) DB::table('orders')->where('id', $order->orderId)->value('state_version'));
    }

    public function test_canonical_application_queue_still_persists_exact_operation_history_and_dispatch_state(): void
    {
        $order = $this->createPaidOrder('canonical-text-application');
        $correlationId = $this->purchaseOrderCorrelation('canonical-text-application-queue');

        $receipt = $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
            $order->orderPublicId,
            $correlationId,
        );

        $operation = DB::table('provisioning_operations')
            ->where('id', $receipt->provisioningOperationId)
            ->first(['operation_key', 'operation_type', 'state', 'state_version', 'correlation_id']);
        self::assertNotNull($operation);
        self::assertSame('initial_provision', $operation->operation_type);
        self::assertSame('queued', $operation->state);
        self::assertSame(1, (int) $operation->state_version);
        self::assertSame($correlationId, $operation->correlation_id);

        $history = DB::table('provisioning_operation_histories')
            ->where('provisioning_operation_id', $receipt->provisioningOperationId)
            ->first(['from_state', 'to_state', 'from_version', 'to_version', 'actor_type', 'reason_code', 'correlation_id']);
        self::assertNotNull($history);
        self::assertNull($history->from_state);
        self::assertSame('queued', $history->to_state);
        self::assertNull($history->from_version);
        self::assertSame(1, (int) $history->to_version);
        self::assertSame('system', $history->actor_type);
        self::assertSame('initial_provisioning_requested', $history->reason_code);
        self::assertSame($correlationId, $history->correlation_id);
        self::assertSame(1, DB::table('provisioning_operation_histories')->where('provisioning_operation_id', $receipt->provisioningOperationId)->count());

        self::assertSame('pending', DB::table('outbox_messages')->where('id', $receipt->outboxEventId)->value('dispatch_state'));
        self::assertSame(OrderState::ProvisioningQueued->value, DB::table('orders')->where('id', $order->orderId)->value('state'));
        self::assertSame(2, (int) DB::table('orders')->where('id', $order->orderId)->value('state_version'));
    }

    /** @param array<string, mixed> $values */
    private function assertOperationInsertRejected(array $values, string $message): void
    {
        try {
            DB::table('provisioning_operations')->insert($values);
            self::fail($message);
        } catch (QueryException) {
            // Expected: the MariaDB authority boundary must reject non-canonical bytes.
        }
    }

    private function createPaidOrder(string $suffix): PurchaseOrderReceipt
    {
        $settlement = $this->createPurchaseOrderSettlement($suffix);

        return $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('order-'.$suffix),
        );
    }
}
