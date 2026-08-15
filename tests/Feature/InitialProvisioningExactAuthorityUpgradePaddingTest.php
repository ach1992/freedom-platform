<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use App\Modules\Orders\Application\PurchaseOrderReceipt;
use App\Modules\Orders\Application\PurchaseOrderService;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-008 QUA-004 */
final class InitialProvisioningExactAuthorityUpgradePaddingTest extends TestCase
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

    protected function tearDown(): void
    {
        try {
            if (isset($this->app)) {
                $this->truncateDatabaseTables();
                /** @var Migration $migration */
                $migration = require database_path('migrations/2026_08_14_001164_z_enforce_exact_provisioning_authority_text.php');
                $migration->up();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_upgrade_refuses_persisted_pad_space_operation_key_and_reconverges_after_cleanup(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_14_001164_z_enforce_exact_provisioning_authority_text.php');

        self::assertTrue($this->constraintExists('provisioning_operations', 'provisioning_operations_provisioning_exact_text_chk'));
        DB::statement('ALTER TABLE provisioning_operations DROP CONSTRAINT provisioning_operations_provisioning_exact_text_chk');

        $order = $this->createPaidOrder('pad-space-upgrade');
        $orderRow = DB::table('orders')->where('id', $order->orderId)->first(['id', 'user_id']);
        self::assertNotNull($orderRow);
        $item = DB::table('order_items')->where('order_id', $order->orderId)->where('line_number', 1)->first(['id', 'public_id']);
        self::assertNotNull($item);

        $timestamp = $this->purchaseOrderTimestamp();
        $correlationId = $this->purchaseOrderCorrelation('pad-space-upgrade-operation');
        $serviceId = (int) DB::table('service_subscriptions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'order_id' => (int) $orderRow->id,
            'order_item_id' => (int) $item->id,
            'user_id' => (int) $orderRow->user_id,
            'creation_correlation_id' => $correlationId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $canonicalKey = 'initial-provision:'.$item->public_id;
        $operationId = (int) DB::table('provisioning_operations')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'operation_key' => $canonicalKey.' ',
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

        $stored = DB::selectOne('SELECT HEX(operation_key) AS operation_key_hex FROM provisioning_operations WHERE id = ?', [$operationId]);
        self::assertNotNull($stored);
        self::assertSame(strtoupper(bin2hex($canonicalKey.' ')), $stored->operation_key_hex);

        try {
            $migration->up();
            self::fail('The exact-text convergence migration must refuse a persisted trailing-space Operation key.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Existing Provisioning Operation authority is not byte-canonical; exact-text upgrade remains fenced.',
                $exception->getMessage(),
            );
        }

        self::assertTrue($this->triggerExists('outbox_initial_provision_exact_upgrade_fence'));
        self::assertTrue($this->triggerExists('orders_provisioning_exact_upgrade_fence'));
        self::assertTrue($this->triggerExists('service_subscriptions_exact_upgrade_fence'));
        self::assertTrue($this->triggerExists('provisioning_operations_exact_upgrade_fence'));
        self::assertFalse($this->constraintExists('provisioning_operations', 'provisioning_operations_provisioning_exact_text_chk'));

        $this->truncateDatabaseTables();
        $migration->up();

        self::assertTrue($this->constraintExists('service_subscriptions', 'service_subscriptions_provisioning_exact_text_chk'));
        self::assertTrue($this->constraintExists('provisioning_operations', 'provisioning_operations_provisioning_exact_text_chk'));
        self::assertTrue($this->constraintExists('provisioning_operation_histories', 'provisioning_operation_histories_provisioning_exact_text_chk'));
        self::assertFalse($this->triggerExists('outbox_initial_provision_exact_upgrade_fence'));
        self::assertFalse($this->triggerExists('orders_provisioning_exact_upgrade_fence'));
        self::assertFalse($this->triggerExists('service_subscriptions_exact_upgrade_fence'));
        self::assertFalse($this->triggerExists('provisioning_operations_exact_upgrade_fence'));
    }

    private function createPaidOrder(string $suffix): PurchaseOrderReceipt
    {
        $settlement = $this->createPurchaseOrderSettlement($suffix);

        return $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('order-'.$suffix),
        );
    }

    private function triggerExists(string $trigger): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$trigger],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$table, $constraint, 'CHECK'],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }
}
