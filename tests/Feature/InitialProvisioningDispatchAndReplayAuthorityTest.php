<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use App\Modules\Orders\Application\PurchaseOrderReceipt;
use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Domain\OrderState;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxMessage;
use App\Shared\Application\OutboxMessageHandler;
use App\Shared\Application\SafeOutboxPayload;
use App\Shared\Infrastructure\DatabaseOutboxDispatcher;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\RestoresDatabaseTrigger;
use Tests\TestCase;

/** @requirement PAY-003 PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 QUA-004 */
final class InitialProvisioningDispatchAndReplayAuthorityTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use DatabaseTruncation;
    use PurchaseOrderTestSupport;
    use RestoresDatabaseTrigger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->bootPurchaseOrderClock();
    }

    public function test_direct_canonical_outbox_cannot_be_claimed_before_final_order_authority(): void
    {
        $order = $this->createPaidOrder('dispatch-authority');
        $authority = $this->createDirectLocalAuthority($order, 'dispatch-authority');
        $payload = new SafeOutboxPayload([
            'order_public_id' => $order->orderPublicId,
            'order_item_public_id' => $authority['order_item_public_id'],
            'provisioning_operation_public_id' => $authority['operation_public_id'],
            'service_subscription_public_id' => $authority['service_public_id'],
        ]);
        $eventId = (string) Str::uuid();
        $timestamp = $this->purchaseOrderTimestamp();

        DB::table('outbox_messages')->insert([
            'id' => $eventId,
            'event_key' => 'provisioning.initial.requested:'.$authority['operation_public_id'],
            'event_type' => 'provisioning.initial.requested',
            'aggregate_type' => 'provisioning_operation',
            'aggregate_id' => $authority['operation_public_id'],
            'payload' => $payload->json(),
            'payload_hash' => $payload->hash(),
            'correlation_id' => $authority['operation_correlation_id'],
            'available_at' => $timestamp,
            'processed_at' => null,
            'attempts' => 0,
            'last_error_class' => null,
            'last_error_code' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        self::assertSame('authority_pending', DB::table('outbox_messages')->where('id', $eventId)->value('dispatch_state'));

        try {
            DB::table('outbox_messages')->where('id', $eventId)->update(['dispatch_state' => 'pending']);
            self::fail('Direct DML must not release a provisioning command before final Order authority.');
        } catch (QueryException) {
            self::assertSame('authority_pending', DB::table('outbox_messages')->where('id', $eventId)->value('dispatch_state'));
        }

        DB::table('outbox_messages')
            ->where('id', '<>', $eventId)
            ->update(['available_at' => '2037-01-01 00:00:00.000000']);

        $handler = new class implements OutboxMessageHandler
        {
            public int $handled = 0;

            public function handle(OutboxMessage $message): OutboxDispatchOutcome
            {
                $this->handled++;

                return OutboxDispatchOutcome::Success;
            }
        };

        $dispatcher = $this->app->make(DatabaseOutboxDispatcher::class);
        self::assertNull($dispatcher->dispatchOne($handler));
        self::assertSame(0, $handler->handled);

        $updated = DB::table('orders')
            ->where('id', $order->orderId)
            ->where('state', OrderState::Paid->value)
            ->where('state_version', 1)
            ->update([
                'state' => OrderState::ProvisioningQueued->value,
                'state_version' => 2,
                'updated_at' => $timestamp,
            ]);
        self::assertSame(1, $updated);
        self::assertSame('authority_pending', DB::table('outbox_messages')->where('id', $eventId)->value('dispatch_state'));

        $forgedOperationPublicId = (string) Str::ulid();
        $forgedPayload = new SafeOutboxPayload([
            'order_public_id' => $order->orderPublicId,
            'order_item_public_id' => $authority['order_item_public_id'],
            'provisioning_operation_public_id' => $forgedOperationPublicId,
            'service_subscription_public_id' => $authority['service_public_id'],
        ]);
        $forgedEventId = (string) Str::uuid();
        DB::table('outbox_messages')->insert([
            'id' => $forgedEventId,
            'event_key' => 'provisioning.initial.requested:'.$forgedOperationPublicId,
            'event_type' => 'provisioning.initial.requested',
            'aggregate_type' => 'provisioning_operation',
            'aggregate_id' => $forgedOperationPublicId,
            'payload' => $forgedPayload->json(),
            'payload_hash' => $forgedPayload->hash(),
            'correlation_id' => $authority['operation_correlation_id'],
            'available_at' => $timestamp,
            'processed_at' => null,
            'attempts' => 0,
            'last_error_class' => null,
            'last_error_code' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        self::assertSame('authority_pending', DB::table('outbox_messages')->where('id', $forgedEventId)->value('dispatch_state'));

        try {
            DB::table('outbox_messages')->where('id', $forgedEventId)->update(['dispatch_state' => 'pending']);
            self::fail('Queued Order state alone must not release a newly forged provisioning command.');
        } catch (QueryException) {
            self::assertSame('authority_pending', DB::table('outbox_messages')->where('id', $forgedEventId)->value('dispatch_state'));
        }

        $released = DB::table('outbox_messages')->where('id', $eventId)->where('dispatch_state', 'authority_pending')->update([
            'dispatch_state' => 'pending',
            'updated_at' => $timestamp,
        ]);
        self::assertSame(1, $released);

        $result = $dispatcher->dispatchOne($handler);
        self::assertNotNull($result);
        self::assertSame($eventId, $result->messageId);
        self::assertSame(1, $handler->handled);
        self::assertSame('processed', DB::table('outbox_messages')->where('id', $eventId)->value('dispatch_state'));
        self::assertSame('authority_pending', DB::table('outbox_messages')->where('id', $forgedEventId)->value('dispatch_state'));
    }

    public function test_failed_final_order_transition_leaves_command_unclaimable(): void
    {
        $order = $this->createPaidOrder('failed-transition');
        $authority = $this->createDirectLocalAuthority($order, 'failed-transition');
        $payload = new SafeOutboxPayload([
            'order_public_id' => $order->orderPublicId,
            'order_item_public_id' => $authority['order_item_public_id'],
            'provisioning_operation_public_id' => $authority['operation_public_id'],
            'service_subscription_public_id' => $authority['service_public_id'],
        ]);
        $eventId = (string) Str::uuid();
        $timestamp = $this->purchaseOrderTimestamp();

        DB::table('outbox_messages')->insert([
            'id' => $eventId,
            'event_key' => 'provisioning.initial.requested:'.$authority['operation_public_id'],
            'event_type' => 'provisioning.initial.requested',
            'aggregate_type' => 'provisioning_operation',
            'aggregate_id' => $authority['operation_public_id'],
            'payload' => $payload->json(),
            'payload_hash' => $payload->hash(),
            'correlation_id' => $this->purchaseOrderCorrelation('failed-transition-wrong-correlation'),
            'available_at' => $timestamp,
            'processed_at' => null,
            'attempts' => 0,
            'last_error_class' => null,
            'last_error_code' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        self::assertSame('authority_pending', DB::table('outbox_messages')->where('id', $eventId)->value('dispatch_state'));

        try {
            DB::table('orders')->where('id', $order->orderId)->update([
                'state' => OrderState::ProvisioningQueued->value,
                'state_version' => 2,
                'updated_at' => $timestamp,
            ]);
            self::fail('Final Order transition must reject a command whose correlation is not the Operation correlation.');
        } catch (QueryException) {
            self::assertSame(OrderState::Paid->value, DB::table('orders')->where('id', $order->orderId)->value('state'));
            self::assertSame(1, (int) DB::table('orders')->where('id', $order->orderId)->value('state_version'));
        }

        DB::table('outbox_messages')
            ->where('id', '<>', $eventId)
            ->update(['available_at' => '2037-01-01 00:00:00.000000']);

        $handler = new class implements OutboxMessageHandler
        {
            public int $handled = 0;

            public function handle(OutboxMessage $message): OutboxDispatchOutcome
            {
                $this->handled++;

                return OutboxDispatchOutcome::Success;
            }
        };

        self::assertNull($this->app->make(DatabaseOutboxDispatcher::class)->dispatchOne($handler));
        self::assertSame(0, $handler->handled);
        self::assertSame('authority_pending', DB::table('outbox_messages')->where('id', $eventId)->value('dispatch_state'));
    }

    public function test_processed_command_cannot_authorize_first_order_transition(): void
    {
        $order = $this->createPaidOrder('processed-before-authority');
        $authority = $this->createDirectLocalAuthority($order, 'processed-before-authority');
        $payload = new SafeOutboxPayload([
            'order_public_id' => $order->orderPublicId,
            'order_item_public_id' => $authority['order_item_public_id'],
            'provisioning_operation_public_id' => $authority['operation_public_id'],
            'service_subscription_public_id' => $authority['service_public_id'],
        ]);
        $eventId = (string) Str::uuid();
        $timestamp = $this->purchaseOrderTimestamp();

        DB::table('outbox_messages')->insert([
            'id' => $eventId,
            'event_key' => 'provisioning.initial.requested:'.$authority['operation_public_id'],
            'event_type' => 'provisioning.initial.requested',
            'aggregate_type' => 'provisioning_operation',
            'aggregate_id' => $authority['operation_public_id'],
            'payload' => $payload->json(),
            'payload_hash' => $payload->hash(),
            'correlation_id' => $authority['operation_correlation_id'],
            'available_at' => $timestamp,
            'processed_at' => null,
            'attempts' => 0,
            'last_error_class' => null,
            'last_error_code' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $this->withDatabaseTriggerDisabled('outbox_initial_provision_envelope_update_guard', function () use ($eventId, $timestamp): void {
            $updated = DB::table('outbox_messages')->where('id', $eventId)->update([
                'dispatch_state' => 'processed',
                'processed_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            self::assertSame(1, $updated);
        });
        self::assertSame('processed', DB::table('outbox_messages')->where('id', $eventId)->value('dispatch_state'));

        try {
            DB::table('orders')->where('id', $order->orderId)->update([
                'state' => OrderState::ProvisioningQueued->value,
                'state_version' => 2,
                'updated_at' => $timestamp,
            ]);
            self::fail('A processed initial provisioning command must not authorize the first Order queue transition.');
        } catch (QueryException) {
            self::assertSame(OrderState::Paid->value, DB::table('orders')->where('id', $order->orderId)->value('state'));
            self::assertSame(1, (int) DB::table('orders')->where('id', $order->orderId)->value('state_version'));
        }
    }

    public function test_application_queue_releases_exact_command_and_replay_rejects_correlation_only_corruption(): void
    {
        $order = $this->createPaidOrder('replay-correlation');
        $service = $this->app->make(InitialProvisioningQueueService::class);
        $first = $service->queueInitial(
            $order->orderPublicId,
            $this->purchaseOrderCorrelation('replay-correlation-first'),
        );

        self::assertSame('pending', DB::table('outbox_messages')->where('id', $first->outboxEventId)->value('dispatch_state'));

        $exactReplay = $service->queueInitial(
            $order->orderPublicId,
            $this->purchaseOrderCorrelation('replay-correlation-exact'),
        );
        self::assertTrue($exactReplay->replayed);
        self::assertSame($first->serviceSubscriptionPublicId, $exactReplay->serviceSubscriptionPublicId);
        self::assertSame($first->provisioningOperationPublicId, $exactReplay->provisioningOperationPublicId);
        self::assertSame($first->outboxEventId, $exactReplay->outboxEventId);

        $this->withDatabaseTriggerDisabled('outbox_initial_provision_envelope_update_guard', function () use ($first): void {
            $updated = DB::table('outbox_messages')->where('id', $first->outboxEventId)->update([
                'correlation_id' => $this->purchaseOrderCorrelation('replay-correlation-corrupt'),
            ]);
            self::assertSame(1, $updated);
        });

        try {
            $service->queueInitial(
                $order->orderPublicId,
                $this->purchaseOrderCorrelation('replay-correlation-after-corruption'),
            );
            self::fail('Replay must reject an Outbox command whose correlation differs from the Provisioning Operation.');
        } catch (RuntimeException $exception) {
            self::assertSame('Stored initial provisioning queue authority is inconsistent.', $exception->getMessage());
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

    /**
     * @return array{
     *     order_item_public_id:string,
     *     service_public_id:string,
     *     operation_public_id:string,
     *     operation_correlation_id:string
     * }
     */
    private function createDirectLocalAuthority(PurchaseOrderReceipt $order, string $suffix): array
    {
        $orderRow = DB::table('orders')->where('id', $order->orderId)->first(['id', 'user_id']);
        self::assertNotNull($orderRow);
        $item = DB::table('order_items')
            ->where('order_id', $order->orderId)
            ->where('line_number', 1)
            ->first(['id', 'public_id']);
        self::assertNotNull($item);

        $timestamp = $this->purchaseOrderTimestamp();
        $servicePublicId = (string) Str::ulid();
        $operationCorrelationId = $this->purchaseOrderCorrelation('operation-'.$suffix);
        $serviceId = (int) DB::table('service_subscriptions')->insertGetId([
            'public_id' => $servicePublicId,
            'order_id' => (int) $orderRow->id,
            'order_item_id' => (int) $item->id,
            'user_id' => (int) $orderRow->user_id,
            'creation_correlation_id' => $operationCorrelationId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $operationPublicId = (string) Str::ulid();
        DB::table('provisioning_operations')->insert([
            'public_id' => $operationPublicId,
            'operation_key' => 'initial-provision:'.$item->public_id,
            'operation_type' => 'initial_provision',
            'order_id' => (int) $orderRow->id,
            'order_item_id' => (int) $item->id,
            'service_subscription_id' => $serviceId,
            'user_id' => (int) $orderRow->user_id,
            'state' => 'queued',
            'state_version' => 1,
            'correlation_id' => $operationCorrelationId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return [
            'order_item_public_id' => (string) $item->public_id,
            'service_public_id' => $servicePublicId,
            'operation_public_id' => $operationPublicId,
            'operation_correlation_id' => $operationCorrelationId,
        ];
    }
}
