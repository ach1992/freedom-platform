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

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->bootPurchaseOrderClock();
    }

    public function test_upgrade_preserves_existing_initial_operation_and_assigns_only_canonical_generation_defaults(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_17_000300_enable_service_mutation_authority.php');
        $migration->down();
        $upgraded = false;

        try {
            self::assertFalse(Schema::hasColumn('service_subscriptions', 'lifecycle_state'));
            self::assertFalse(Schema::hasColumn('provisioning_operations', 'operation_generation'));

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
                ->where('event_type', InitialProvisioningQueueService::EVENT_TYPE)->count());

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
                ->where('event_type', InitialProvisioningQueueService::EVENT_TYPE)->count());
        } finally {
            if (! $upgraded) {
                $migration->up();
            }
        }
    }
}
