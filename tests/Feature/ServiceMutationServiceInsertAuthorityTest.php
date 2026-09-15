<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use App\Modules\Orders\Application\PurchaseOrderService;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PanelsAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement SVC-004 PRV-002 PRV-003 DAT-003 SEC-008 QUA-004 */
final class ServiceMutationServiceInsertAuthorityTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use DatabaseTruncation;
    use PurchaseOrderTestSupport;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_17_000300_enable_service_mutation_authority.php');
        $migration->up();

        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PanelsAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->bootPurchaseOrderClock();
    }

    protected function tearDown(): void
    {
        try {
            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    public function test_valid_paid_order_cannot_create_service_with_non_initial_lifecycle_shape(): void
    {
        $settlement = $this->createPurchaseOrderSettlement('mutation-service-insert-shape');
        $order = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('mutation-service-insert-order'),
        );
        $itemId = (int) DB::table('order_items')
            ->where('order_id', $order->orderId)
            ->where('line_number', 1)
            ->value('id');
        $userId = (int) DB::table('orders')->where('id', $order->orderId)->value('user_id');
        $now = $this->purchaseOrderTimestamp();

        try {
            DB::table('service_subscriptions')->insert([
                'public_id' => (string) Str::ulid(),
                'order_id' => $order->orderId,
                'order_item_id' => $itemId,
                'user_id' => $userId,
                'creation_correlation_id' => 'mutation-service-insert-shape',
                'lifecycle_state' => 'suspended',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            self::fail('A newly created Service must start with the canonical active generation-zero lifecycle shape.');
        } catch (QueryException) {
            // Expected: the Service insert authority rejects non-initial lifecycle state before any remote binding exists.
        }

        self::assertFalse(DB::table('service_subscriptions')->where('order_item_id', $itemId)->exists());
    }
}
