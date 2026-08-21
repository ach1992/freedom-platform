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
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** @requirement PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-008 QUA-004 */
final class InitialProvisioningExactAuthorityUpgradeRollbackTest extends TestCase
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

    public function test_exact_upgrade_rollback_deactivates_queue_without_leaking_temporary_fences(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_14_001164_z_enforce_exact_provisioning_authority_text.php');
        /** @var Migration $paidMutationMigration */
        $paidMutationMigration = require database_path('migrations/2026_08_20_000110_enable_paid_service_mutation_authority.php');

        try {
            $paidMutationMigration->down();
            self::assertTrue(
                DB::table('migrations')->where('migration', '2026_08_14_001165_activate_provisioning_queue_authority')->exists(),
                'The rollback regression must retain the already-recorded 001165 migration state.',
            );

            $migration->down();

            self::assertFalse($this->constraintExists('payment_intents', 'payment_intents_provisioning_exact_authority_chk'));
            self::assertFalse($this->constraintExists('orders', 'orders_provisioning_exact_authority_chk'));
            self::assertTrue($this->triggerContains('service_subscriptions_insert_guard', 'creation is disabled until provisioning authority migration completes'));
            self::assertTrue($this->triggerContains('provisioning_operations_insert_guard', 'creation is disabled until provisioning authority migration completes'));
            self::assertTrue($this->triggerContains('orders_update_guard', 'Order mutation is not enabled by the current lifecycle authority'));

            foreach ([
                'orders_provisioning_exact_upgrade_fence',
                'outbox_initial_provision_exact_upgrade_fence',
                'service_subscriptions_exact_upgrade_fence',
                'provisioning_operations_exact_upgrade_fence',
            ] as $trigger) {
                self::assertFalse($this->triggerExists($trigger), $trigger.' must not leak beyond rollback.');
            }

            $order = $this->createPaidOrder('exact-upgrade-rollback');
            try {
                $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
                    $order->orderPublicId,
                    $this->purchaseOrderCorrelation('exact-upgrade-rollback-queue'),
                );
                self::fail('Rollback must leave queue creation fail-closed even though 001165 remains recorded.');
            } catch (QueryException) {
                self::assertSame(OrderState::Paid->value, DB::table('orders')->where('id', $order->orderId)->value('state'));
                self::assertSame(0, DB::table('service_subscriptions')->where('order_id', $order->orderId)->count());
            }
        } finally {
            $migration->up();
            $paidMutationMigration->up();
        }

        self::assertTrue($this->constraintExists('payment_intents', 'payment_intents_provisioning_exact_authority_chk'));
        self::assertTrue($this->constraintExists('orders', 'orders_provisioning_exact_authority_chk'));
        self::assertTrue($this->triggerContains('service_subscriptions_insert_guard', 'currently captured authoritative purchase Order Item'));
        self::assertTrue($this->triggerContains('orders_update_guard', 'Only paid/v1 to provisioning_queued/v2'));
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

    private function triggerContains(string $trigger, string $needle): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ? AND LOCATE(?, ACTION_STATEMENT) > 0',
            [$trigger, $needle],
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
