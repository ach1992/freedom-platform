<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use App\Modules\Orders\Application\PurchaseOrderReceipt;
use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Domain\OrderState;
use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxMessage;
use App\Shared\Application\OutboxMessageHandler;
use App\Shared\Infrastructure\DatabaseOutboxDispatcher;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-008 QUA-004 */
final class InitialProvisioningExactAuthorityPaddingDispatchTest extends TestCase
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

    public function test_trailing_space_operation_key_cannot_reach_order_or_generic_dispatch_authority(): void
    {
        $order = $this->createPaidOrder('pad-space-final-dispatch');
        $orderRow = DB::table('orders')->where('id', $order->orderId)->first(['id', 'user_id']);
        self::assertNotNull($orderRow);
        $item = DB::table('order_items')->where('order_id', $order->orderId)->where('line_number', 1)->first(['id', 'public_id']);
        self::assertNotNull($item);

        $timestamp = $this->purchaseOrderTimestamp();
        $correlationId = $this->purchaseOrderCorrelation('pad-space-final-dispatch');
        $serviceId = (int) DB::table('service_subscriptions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'order_id' => (int) $orderRow->id,
            'order_item_id' => (int) $item->id,
            'user_id' => (int) $orderRow->user_id,
            'creation_correlation_id' => $correlationId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        try {
            DB::table('provisioning_operations')->insert([
                'public_id' => (string) Str::ulid(),
                'operation_key' => 'initial-provision:'.$item->public_id.' ',
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
            ]);
            self::fail('A trailing-space Operation key must be rejected before it can become provisioning authority.');
        } catch (QueryException) {
            // Expected from the byte-sensitive Operation CHECK on the final schema.
        }

        self::assertSame(0, DB::table('provisioning_operations')->where('order_id', $order->orderId)->count());
        self::assertSame(OrderState::Paid->value, DB::table('orders')->where('id', $order->orderId)->value('state'));
        self::assertSame(1, (int) DB::table('orders')->where('id', $order->orderId)->value('state_version'));
        self::assertSame(
            0,
            DB::table('outbox_messages')
                ->where('event_type', 'provisioning.initial.requested')
                ->where('payload', 'like', '%'.$order->orderPublicId.'%')
                ->count(),
        );

        DB::table('outbox_messages')->update(['available_at' => '2037-01-01 00:00:00.000000']);

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
