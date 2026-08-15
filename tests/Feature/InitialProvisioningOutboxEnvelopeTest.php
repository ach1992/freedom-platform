<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use App\Modules\Orders\Application\PurchaseOrderReceipt;
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

/** @requirement PAY-003 PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 QUA-004 */
final class InitialProvisioningOutboxEnvelopeTest extends TestCase
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

    public function test_direct_db_outbox_with_extra_field_cannot_authorize_order_queue_transition(): void
    {
        $order = $this->createPaidOrder('outbox-extra-field');
        $authority = $this->createDirectLocalAuthority($order, 'outbox-extra-field');
        $payload = json_encode([
            'order_item_public_id' => $authority['order_item_public_id'],
            'order_public_id' => $order->orderPublicId,
            'provisioning_operation_public_id' => $authority['operation_public_id'],
            'service_subscription_public_id' => $authority['service_public_id'],
            'secret' => 'must-not-be-here',
        ], JSON_THROW_ON_ERROR);

        try {
            $this->insertDirectOutbox($authority['operation_public_id'], $payload, hash('sha256', $payload), 'extra-field');
            self::fail('Provisioning Outbox payload with an extra field must be rejected by MariaDB.');
        } catch (QueryException) {
            self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'provisioning.initial.requested')->count());
        }

        try {
            DB::table('orders')->where('id', $order->orderId)->update([
                'state' => OrderState::ProvisioningQueued->value,
                'state_version' => 2,
                'updated_at' => $this->purchaseOrderTimestamp(),
            ]);
            self::fail('Order queue transition without one exact canonical Outbox command must be rejected.');
        } catch (QueryException) {
            self::assertSame(OrderState::Paid->value, DB::table('orders')->where('id', $order->orderId)->value('state'));
            self::assertSame(1, (int) DB::table('orders')->where('id', $order->orderId)->value('state_version'));
        }
    }

    public function test_direct_db_outbox_rejects_noncanonical_body_hash_and_event_type_representation(): void
    {
        $order = $this->createPaidOrder('outbox-canonical-envelope');
        $authority = $this->createDirectLocalAuthority($order, 'outbox-canonical-envelope');

        $nonCanonicalPayload = json_encode([
            'service_subscription_public_id' => $authority['service_public_id'],
            'provisioning_operation_public_id' => $authority['operation_public_id'],
            'order_public_id' => $order->orderPublicId,
            'order_item_public_id' => $authority['order_item_public_id'],
        ], JSON_THROW_ON_ERROR);

        try {
            $this->insertDirectOutbox(
                $authority['operation_public_id'],
                $nonCanonicalPayload,
                hash('sha256', $nonCanonicalPayload),
                'noncanonical',
            );
            self::fail('Semantically matching but non-canonical provisioning Outbox bytes must be rejected.');
        } catch (QueryException) {
            self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'provisioning.initial.requested')->count());
        }

        $canonicalPayload = $this->canonicalPayload($order, $authority);

        try {
            $this->insertDirectOutbox(
                $authority['operation_public_id'],
                $canonicalPayload,
                strtoupper(hash('sha256', $canonicalPayload)),
                'uppercase-hash',
            );
            self::fail('Uppercase payload hash representation must be rejected when replay requires exact lowercase SHA-256 identity.');
        } catch (QueryException) {
            self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'provisioning.initial.requested')->count());
        }

        try {
            $this->insertDirectOutbox(
                $authority['operation_public_id'],
                $canonicalPayload,
                hash('sha256', $canonicalPayload),
                'event-type-case',
                'Provisioning.Initial.Requested',
            );
            self::fail('Case-variant provisioning event type must not be accepted as the exact replay identity.');
        } catch (QueryException) {
            self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'provisioning.initial.requested')->count());
        }

        self::assertSame(OrderState::Paid->value, DB::table('orders')->where('id', $order->orderId)->value('state'));
        self::assertSame(1, (int) DB::table('orders')->where('id', $order->orderId)->value('state_version'));
    }

    public function test_canonical_direct_db_outbox_with_wrong_correlation_cannot_authorize_order_transition(): void
    {
        $order = $this->createPaidOrder('outbox-correlation');
        $authority = $this->createDirectLocalAuthority($order, 'outbox-correlation');
        $payload = $this->canonicalPayload($order, $authority);

        $this->insertDirectOutbox(
            $authority['operation_public_id'],
            $payload,
            hash('sha256', $payload),
            'wrong-correlation',
            correlationId: $this->purchaseOrderCorrelation('different-command-correlation'),
        );

        try {
            DB::table('orders')->where('id', $order->orderId)->update([
                'state' => OrderState::ProvisioningQueued->value,
                'state_version' => 2,
                'updated_at' => $this->purchaseOrderTimestamp(),
            ]);
            self::fail('Order queue transition requires the Outbox command correlation to match the immutable operation correlation.');
        } catch (QueryException) {
            self::assertSame(OrderState::Paid->value, DB::table('orders')->where('id', $order->orderId)->value('state'));
            self::assertSame(1, (int) DB::table('orders')->where('id', $order->orderId)->value('state_version'));
        }
    }

    public function test_committed_initial_provisioning_command_is_immutable_but_dispatch_metadata_can_advance(): void
    {
        $order = $this->createPaidOrder('outbox-immutable');
        $receipt = $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
            $order->orderPublicId,
            $this->purchaseOrderCorrelation('queue-outbox-immutable'),
        );

        $stored = DB::table('outbox_messages')->where('id', $receipt->outboxEventId)->first([
            'id', 'event_key', 'event_type', 'aggregate_type', 'aggregate_id', 'payload', 'payload_hash', 'correlation_id',
        ]);
        self::assertNotNull($stored);

        /** @var array<string, string> $tamperedValues */
        $tamperedValues = json_decode((string) $stored->payload, true, flags: JSON_THROW_ON_ERROR);
        $tamperedValues['secret'] = 'must-not-be-here';
        $tamperedPayload = json_encode($tamperedValues, JSON_THROW_ON_ERROR);

        $mutations = [
            ['payload' => $tamperedPayload, 'payload_hash' => hash('sha256', $tamperedPayload)],
            ['event_key' => 'provisioning.initial.requested:'.(string) Str::ulid()],
            ['event_type' => 'Provisioning.Initial.Requested'],
            ['aggregate_type' => 'other_aggregate'],
            ['aggregate_id' => (string) Str::ulid()],
            ['payload_hash' => str_repeat('0', 64)],
            ['correlation_id' => $this->purchaseOrderCorrelation('mutated-command-correlation')],
        ];

        foreach ($mutations as $changes) {
            try {
                DB::table('outbox_messages')->where('id', $receipt->outboxEventId)->update($changes);
                self::fail('Committed initial provisioning command identity must be immutable.');
            } catch (QueryException) {
                $current = DB::table('outbox_messages')->where('id', $receipt->outboxEventId)->first([
                    'id', 'event_key', 'event_type', 'aggregate_type', 'aggregate_id', 'payload', 'payload_hash', 'correlation_id',
                ]);
                self::assertEquals($stored, $current);
            }
        }

        try {
            DB::table('outbox_messages')->where('id', $receipt->outboxEventId)->delete();
            self::fail('Committed initial provisioning command must remain durable and non-deletable.');
        } catch (QueryException) {
            self::assertSame(1, DB::table('outbox_messages')->where('id', $receipt->outboxEventId)->count());
        }

        $updated = DB::table('outbox_messages')->where('id', $receipt->outboxEventId)->update([
            'attempts' => 1,
            'last_error_class' => 'RuntimeException',
            'last_error_code' => 'retryable',
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]);
        self::assertSame(1, $updated);
        self::assertSame(1, (int) DB::table('outbox_messages')->where('id', $receipt->outboxEventId)->value('attempts'));
    }

    public function test_non_provisioning_outbox_row_cannot_be_converted_to_initial_provisioning_command(): void
    {
        $order = $this->createPaidOrder('outbox-conversion');
        $authority = $this->createDirectLocalAuthority($order, 'outbox-conversion');
        $timestamp = $this->purchaseOrderTimestamp();
        $eventId = (string) Str::uuid();
        $genericPayload = json_encode(['safe' => 'value'], JSON_THROW_ON_ERROR);

        DB::table('outbox_messages')->insert([
            'id' => $eventId,
            'event_key' => 'test.generic:'.$eventId,
            'event_type' => 'test.generic',
            'aggregate_type' => 'test',
            'aggregate_id' => $eventId,
            'payload' => $genericPayload,
            'payload_hash' => hash('sha256', $genericPayload),
            'correlation_id' => $this->purchaseOrderCorrelation('generic-outbox'),
            'available_at' => $timestamp,
            'processed_at' => null,
            'attempts' => 0,
            'last_error_class' => null,
            'last_error_code' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $canonicalPayload = $this->canonicalPayload($order, $authority);
        try {
            DB::table('outbox_messages')->where('id', $eventId)->update([
                'event_key' => 'provisioning.initial.requested:'.$authority['operation_public_id'],
                'event_type' => 'provisioning.initial.requested',
                'aggregate_type' => 'provisioning_operation',
                'aggregate_id' => $authority['operation_public_id'],
                'payload' => $canonicalPayload,
                'payload_hash' => hash('sha256', $canonicalPayload),
                'correlation_id' => $authority['operation_correlation_id'],
            ]);
            self::fail('Existing non-provisioning Outbox rows must not be convertible into initial provisioning commands.');
        } catch (QueryException) {
            self::assertSame('test.generic', DB::table('outbox_messages')->where('id', $eventId)->value('event_type'));
        }
    }

    private function createPaidOrder(string $suffix): PurchaseOrderReceipt
    {
        $settlement = $this->createPurchaseOrderSettlement($suffix);
        $order = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('order-'.$suffix),
        );

        self::assertSame(
            PaymentIntentState::Captured->value,
            DB::table('payment_intents')->where('public_id', $settlement->intentPublicId)->value('state'),
        );

        return $order;
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
        $serviceId = (int) DB::table('service_subscriptions')->insertGetId([
            'public_id' => $servicePublicId,
            'order_id' => (int) $orderRow->id,
            'order_item_id' => (int) $item->id,
            'user_id' => (int) $orderRow->user_id,
            'creation_correlation_id' => $this->purchaseOrderCorrelation('service-'.$suffix),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $operationPublicId = (string) Str::ulid();
        $operationCorrelationId = $this->purchaseOrderCorrelation('operation-'.$suffix);
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

    /** @param array{order_item_public_id:string,service_public_id:string,operation_public_id:string,operation_correlation_id:string} $authority */
    private function canonicalPayload(PurchaseOrderReceipt $order, array $authority): string
    {
        return json_encode([
            'order_item_public_id' => $authority['order_item_public_id'],
            'order_public_id' => $order->orderPublicId,
            'provisioning_operation_public_id' => $authority['operation_public_id'],
            'service_subscription_public_id' => $authority['service_public_id'],
        ], JSON_THROW_ON_ERROR);
    }

    private function insertDirectOutbox(
        string $operationPublicId,
        string $payload,
        string $payloadHash,
        string $suffix,
        string $eventType = 'provisioning.initial.requested',
        ?string $correlationId = null,
    ): void {
        $timestamp = $this->purchaseOrderTimestamp();

        DB::table('outbox_messages')->insert([
            'id' => (string) Str::uuid(),
            'event_key' => 'provisioning.initial.requested:'.$operationPublicId,
            'event_type' => $eventType,
            'aggregate_type' => 'provisioning_operation',
            'aggregate_id' => $operationPublicId,
            'payload' => $payload,
            'payload_hash' => $payloadHash,
            'correlation_id' => $correlationId ?? $this->purchaseOrderCorrelation('outbox-'.$suffix),
            'available_at' => $timestamp,
            'processed_at' => null,
            'attempts' => 0,
            'last_error_class' => null,
            'last_error_code' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }
}
