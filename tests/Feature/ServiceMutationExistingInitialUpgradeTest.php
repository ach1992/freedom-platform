<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** @requirement SVC-004 PRV-002 PRV-003 DAT-003 QUA-004 */
final class ServiceMutationExistingInitialUpgradeTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use DatabaseTruncation;
    use PurchaseOrderTestSupport;

    private const INITIAL_PROVISIONING_EVENT_TYPE = 'provisioning.initial.requested';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->bootPurchaseOrderClock();
    }

    public function test_upgrade_repairs_interrupted_rollback_and_preserves_existing_initial_authority(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_17_000300_enable_service_mutation_authority.php');
        /** @var Migration $servicePackageQuoteMigration */
        $servicePackageQuoteMigration = require database_path('migrations/2026_08_20_000100_enable_service_package_quotes.php');
        /** @var Migration $paidServiceMutationMigration */
        $paidServiceMutationMigration = require database_path('migrations/2026_08_20_000110_enable_paid_service_mutation_authority.php');
        $upgraded = false;

        try {
            $paidServiceMutationMigration->down();
            $servicePackageQuoteMigration->down();
            $migration->down();
            $migration->down();
            self::assertFalse(Schema::hasColumn('service_subscriptions', 'lifecycle_state'));
            self::assertFalse(Schema::hasColumn('provisioning_operations', 'operation_generation'));
            // This upgrade test targets the older Service-mutation schema, not the
            // unrelated Quote read contract used by the shared purchase fixture.
            $servicePackageQuoteMigration->up();

            $settlement = $this->createPurchaseOrderSettlement('mutation-existing-initial-upgrade');
            $order = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
                $settlement->settlementPublicId,
                $this->purchaseOrderCorrelation('mutation-existing-initial-order'),
            );
            $queued = $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
                $order->orderPublicId,
                $this->purchaseOrderCorrelation('mutation-existing-initial-queue'),
            );

            self::assertSame('initial_provision', DB::table('provisioning_operations')
                ->where('id', $queued->provisioningOperationId)->value('operation_type'));
            self::assertSame(1, DB::table('service_subscriptions')->count());
            self::assertSame(1, DB::table('provisioning_operations')->count());
            self::assertSame(1, DB::table('outbox_messages')
                ->where('event_type', self::INITIAL_PROVISIONING_EVENT_TYPE)->count());

            DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_operations_update_guard
BEFORE UPDATE ON provisioning_operations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning operation mutation is disabled during Service mutation authority upgrade.';
END
SQL);
            $brokenGuard = DB::selectOne(
                'SELECT ACTION_STATEMENT AS statement FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
                ['provisioning_operations_update_guard'],
            );
            self::assertNotNull($brokenGuard);
            self::assertStringContainsString('disabled during Service mutation authority upgrade', (string) $brokenGuard->statement);
            self::assertStringNotContainsString('initial_remote_effect_v1', (string) $brokenGuard->statement);

            $migration->up();
            $upgraded = true;

            $service = DB::table('service_subscriptions')->where('id', $queued->serviceSubscriptionId)->first([
                'lifecycle_state', 'lifecycle_version', 'remote_identity_generation', 'mutation_generation', 'remote_deleted_at',
            ]);
            self::assertNotNull($service);
            self::assertSame('active', $service->lifecycle_state);
            self::assertSame(0, (int) $service->lifecycle_version);
            self::assertSame(1, (int) $service->remote_identity_generation);
            self::assertSame(0, (int) $service->mutation_generation);
            self::assertNull($service->remote_deleted_at);

            $operation = DB::table('provisioning_operations')->where('id', $queued->provisioningOperationId)->first([
                'operation_type', 'operation_generation', 'target_remote_identity_generation',
                'target_lifecycle_version', 'request_key_hash',
            ]);
            self::assertNotNull($operation);
            self::assertSame('initial_provision', $operation->operation_type);
            self::assertSame(0, (int) $operation->operation_generation);
            self::assertSame(0, (int) $operation->target_remote_identity_generation);
            self::assertSame(0, (int) $operation->target_lifecycle_version);
            self::assertNull($operation->request_key_hash);

            $restoredGuard = DB::selectOne(
                'SELECT ACTION_STATEMENT AS statement FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
                ['provisioning_operations_update_guard'],
            );
            self::assertNotNull($restoredGuard);
            self::assertStringContainsString('service_mutation_effect_v1', (string) $restoredGuard->statement);

            $replay = $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
                $order->orderPublicId,
                $this->purchaseOrderCorrelation('mutation-existing-initial-replay'),
            );
            self::assertTrue($replay->replayed);
            self::assertSame($queued->serviceSubscriptionPublicId, $replay->serviceSubscriptionPublicId);
            self::assertSame($queued->provisioningOperationPublicId, $replay->provisioningOperationPublicId);
            self::assertSame(1, DB::table('service_subscriptions')->count());
            self::assertSame(1, DB::table('provisioning_operations')->count());
            self::assertSame(1, DB::table('outbox_messages')
                ->where('event_type', self::INITIAL_PROVISIONING_EVENT_TYPE)->count());
        } finally {
            if (! $upgraded) {
                $migration->up();
            }
            $servicePackageQuoteMigration->up();
            $paidServiceMutationMigration->up();
        }
    }
}
