<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use App\Modules\Orders\Application\PurchaseOrderReceipt;
use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Domain\OrderState;
use App\Modules\Payments\Domain\PaymentIntentState;
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

    public function test_direct_db_outbox_rejects_noncanonical_body_and_uppercase_hash_representation(): void
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

        $canonicalPayload = json_encode([
            'order_item_public_id' => $authority['order_item_public_id'],
            'order_public_id' => $order->orderPublicId,
            'provisioning_operation_public_id' => $authority['operation_public_id'],
            'service_subscription_public_id' => $authority['service_public_id'],
        ], JSON_THROW_ON_ERROR);

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

        self::assertSame(OrderState::Paid->value, DB::table('orders')->where('id', $order->orderId)->value('state'));
        self::assertSame(1, (int) DB::table('orders')->where('id', $order->orderId)->value('state_version'));
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
     *     operation_public_id:string
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
            'correlation_id' => $this->purchaseOrderCorrelation('operation-'.$suffix),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return [
            'order_item_public_id' => (string) $item->public_id,
            'service_public_id' => $servicePublicId,
            'operation_public_id' => $operationPublicId,
        ];
    }

    private function insertDirectOutbox(
        string $operationPublicId,
        string $payload,
        string $payloadHash,
        string $suffix,
    ): void {
        $timestamp = $this->purchaseOrderTimestamp();

        DB::table('outbox_messages')->insert([
            'id' => (string) Str::uuid(),
            'event_key' => 'provisioning.initial.requested:'.$operationPublicId,
            'event_type' => 'provisioning.initial.requested',
            'aggregate_type' => 'provisioning_operation',
            'aggregate_id' => $operationPublicId,
            'payload' => $payload,
            'payload_hash' => $payloadHash,
            'correlation_id' => $this->purchaseOrderCorrelation('outbox-'.$suffix),
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
