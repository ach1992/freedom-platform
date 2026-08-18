<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';
require_once __DIR__.'/ServiceDeliveryEffectTestDoubles.php';

use App\Modules\Catalog\Application\PlanOfferingRoutePolicyService;
use App\Modules\Catalog\Domain\PlanOfferingRouteDefinition;
use App\Modules\Catalog\Domain\PlanOfferingRoutePolicyDefinition;
use App\Modules\Catalog\Domain\PlanOfferingRouteType;
use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Panels\Application\PanelAdapterRegistry;
use App\Modules\Panels\Application\PanelCredentialPolicy;
use App\Modules\Provisioning\Application\InitialProvisioningDeliveryScheduler;
use App\Modules\Provisioning\Application\InitialProvisioningExecutor;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use App\Modules\Provisioning\Application\ServiceDeliveryAttemptQueueService;
use App\Modules\Provisioning\Application\ServiceMutationQueueService;
use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Modules\Provisioning\Domain\ServiceDeliveryPurpose;
use App\Modules\Provisioning\Domain\ServiceMutationType;
use App\Modules\Telegram\Application\ProtectedTelegramSendOutcome;
use App\Modules\Telegram\Application\ProtectedTelegramSendResult;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PanelsAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** @requirement SVC-002 SVC-014 PRV-002 PRV-003 ARCH-004 DAT-003 SEC-002 SEC-008 QUA-004 QUA-007 QUA-010 */
final class InitialProvisioningDeliverySchedulingRaceTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use DatabaseTruncation;
    use PurchaseOrderTestSupport;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var Migration $mutationMigration */
        $mutationMigration = require database_path('migrations/2026_08_17_000300_enable_service_mutation_authority.php');
        $mutationMigration->up();

        /** @var Migration $deliveryAttemptMigration */
        $deliveryAttemptMigration = require database_path('migrations/2026_08_18_000100_create_service_delivery_attempt_authority.php');
        $deliveryAttemptMigration->up();

        /** @var Migration $deliveryEffectMigration */
        $deliveryEffectMigration = require database_path('migrations/2026_08_18_000200_enable_service_delivery_effect_authority.php');
        $deliveryEffectMigration->up();

        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PanelsAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->bootPurchaseOrderClock();
    }

    protected function tearDown(): void
    {
        DB::purge('initial_delivery_race_contender');

        try {
            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    public function test_second_connection_mutation_cannot_enter_post_provisioning_pre_delivery_gap(): void
    {
        $scenario = $this->queuedScenario('initial-delivery-race');
        $operationId = (int) DB::table('provisioning_operations')
            ->where('public_id', $scenario['provisioning_operation_public_id'])
            ->value('id');
        self::assertGreaterThan(0, $operationId);

        try {
            DB::table('service_initial_delivery_fences')->insert([
                'service_subscription_id' => $scenario['service_id'],
                'provisioning_operation_id' => $operationId,
                'created_at' => $this->purchaseOrderTimestamp(),
            ]);
            self::fail('Direct DB initial-delivery fence forgery must fail closed.');
        } catch (QueryException) {
            // Expected.
        }

        $scheduler = $this->app->make(InitialProvisioningDeliveryScheduler::class);
        $scheduler->establishFence($scenario['provisioning_operation_public_id']);

        self::assertSame(1, DB::table('service_initial_delivery_fences')
            ->where('service_subscription_id', $scenario['service_id'])
            ->count());
        try {
            DB::table('service_initial_delivery_fences')
                ->where('service_subscription_id', $scenario['service_id'])
                ->update(['created_at' => $this->purchaseOrderTimestamp()]);
            self::fail('Direct DB initial-delivery fence mutation must fail closed.');
        } catch (QueryException) {
            // Expected.
        }
        try {
            DB::table('service_initial_delivery_fences')
                ->where('service_subscription_id', $scenario['service_id'])
                ->delete();
            self::fail('Direct DB initial-delivery fence deletion must fail closed.');
        } catch (QueryException) {
            // Expected.
        }

        $receipt = $this->app->make(InitialProvisioningExecutor::class)
            ->execute($scenario['provisioning_operation_public_id']);

        self::assertSame(ProvisioningState::Succeeded, $receipt->state);
        self::assertSame(0, DB::table('service_delivery_attempts')
            ->where('service_subscription_id', $scenario['service_id'])
            ->count());

        try {
            $this->app->make(ServiceDeliveryAttemptQueueService::class)->queue(
                $scenario['service_public_id'],
                ServiceDeliveryPurpose::Initial,
                'request-nondeterministic-initial-delivery-0001',
                $scenario['correlation_id'],
            );
            self::fail('A pending initial-delivery fence must reject a non-deterministic initial Delivery Attempt key.');
        } catch (QueryException) {
            // Expected from the DB authority guard; the surrounding queue transaction rolls back its Outbox command.
        }
        try {
            $this->app->make(ServiceDeliveryAttemptQueueService::class)->queue(
                $scenario['service_public_id'],
                ServiceDeliveryPurpose::Resend,
                'request-resend-before-initial-delivery-0001',
                $scenario['correlation_id'],
            );
            self::fail('A pending initial-delivery fence must reject resend admission before the deterministic initial attempt.');
        } catch (QueryException) {
            // Expected.
        }
        self::assertSame(0, DB::table('service_delivery_attempts')
            ->where('service_subscription_id', $scenario['service_id'])
            ->count());
        self::assertSame(0, DB::table('outbox_messages')
            ->where('event_type', ServiceDeliveryAttemptQueueService::OUTBOX_EVENT_TYPE)
            ->count());

        $originalConnection = (string) config('database.default');
        $connectionConfig = config('database.connections.'.$originalConnection);
        self::assertIsArray($connectionConfig);
        config(['database.connections.initial_delivery_race_contender' => $connectionConfig]);
        DB::purge('initial_delivery_race_contender');

        $primaryPdo = DB::connection($originalConnection)->getPdo();
        $contenderPdo = DB::connection('initial_delivery_race_contender')->getPdo();
        self::assertNotSame($primaryPdo, $contenderPdo);

        config(['database.default' => 'initial_delivery_race_contender']);
        $this->app->forgetInstance(ServiceMutationQueueService::class);
        try {
            try {
                $this->app->make(ServiceMutationQueueService::class)->queue(
                    $scenario['service_public_id'],
                    ServiceMutationType::Suspend,
                    'request-initial-delivery-race-suspend-0001',
                    'correlation-initial-delivery-race-suspend-0001',
                );
                self::fail('A Service mutation must not enter after provisioning succeeds but before the initial Delivery Attempt exists.');
            } catch (QueryException) {
                // The explicit initial-delivery fence is visible to another MariaDB connection.
            }
        } finally {
            config(['database.default' => $originalConnection]);
            $this->app->forgetInstance(ServiceMutationQueueService::class);
            DB::purge('initial_delivery_race_contender');
        }

        self::assertSame(0, DB::table('provisioning_operations')
            ->where('service_subscription_id', $scenario['service_id'])
            ->where('operation_type', '<>', 'initial_provision')
            ->count());
        self::assertSame(0, (int) DB::table('service_subscriptions')
            ->where('id', $scenario['service_id'])
            ->value('mutation_generation'));

        $scheduler->schedule(
            $scenario['provisioning_operation_public_id'],
            $scenario['correlation_id'],
        );

        self::assertSame(0, DB::table('service_initial_delivery_fences')
            ->where('service_subscription_id', $scenario['service_id'])
            ->count());
        self::assertSame(1, DB::table('service_delivery_attempts')
            ->where('service_subscription_id', $scenario['service_id'])
            ->where('purpose', ServiceDeliveryPurpose::Initial->value)
            ->count());
        self::assertSame(1, DB::table('outbox_messages')
            ->where('event_type', 'provisioning.service_delivery.requested')
            ->count());
    }

    /**
     * @return array{service_id:int,service_public_id:string,provisioning_operation_public_id:string,correlation_id:string}
     */
    private function queuedScenario(string $suffix): array
    {
        $settlement = $this->createPurchaseOrderSettlement('delivery-race-'.$suffix);
        $order = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('delivery-race-order-'.$suffix),
        );
        $correlationId = $this->purchaseOrderCorrelation('delivery-race-queue-'.$suffix);
        $queue = $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
            $order->orderPublicId,
            $correlationId,
        );
        $offeringId = (int) DB::table('order_items')->where('order_id', $order->orderId)->value('plan_offering_id');
        $userId = (int) DB::table('orders')->where('id', $order->orderId)->value('user_id');
        $this->makeOfferingOperational($offeringId, $userId, $suffix);

        $doubles = new ServiceDeliveryEffectTestDoubles(
            'https://subscription.example.test/initial-delivery-race',
            new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::Success,
                'telegram_success',
                messageId: 9100,
            ),
        );
        $this->app->instance(
            PanelAdapterRegistry::class,
            new PanelAdapterRegistry(
                [$doubles],
                $this->app->make(PanelCredentialPolicy::class),
            ),
        );
        $this->app->forgetInstance(InitialProvisioningExecutor::class);

        return [
            'service_id' => $queue->serviceSubscriptionId,
            'service_public_id' => $queue->serviceSubscriptionPublicId,
            'provisioning_operation_public_id' => $queue->provisioningOperationPublicId,
            'correlation_id' => $correlationId,
        ];
    }

    private function makeOfferingOperational(int $offeringId, int $userId, string $suffix): void
    {
        $now = $this->purchaseOrderTimestamp();
        $offering = DB::table('plan_offerings')->where('id', $offeringId)->first([
            'sales_server_id', 'panel_service_target_id',
        ]);
        self::assertNotNull($offering);
        $serverId = (int) $offering->sales_server_id;
        $targetId = (int) $offering->panel_service_target_id;
        $connectionId = (int) DB::table('panel_service_targets')->where('id', $targetId)->value('panel_connection_id');
        $connectionVersion = (int) DB::table('panel_connections')->where('id', $connectionId)->value('version') + 1;
        $capabilityHash = hash('sha256', 'initial-delivery-race-capabilities-'.$suffix);
        $targetEvidenceHash = hash('sha256', 'initial-delivery-race-target-evidence-'.$suffix);

        DB::table('panel_connections')->where('id', $connectionId)->update([
            'encrypted_credentials' => Crypt::encryptString(json_encode(['token' => 'initial-delivery-race-test'], JSON_THROW_ON_ERROR)),
            'state' => 'active',
            'last_test_status' => 'success',
            'last_panel_version' => '1.0.0',
            'last_capabilities_hash' => $capabilityHash,
            'last_tested_at' => $now,
            'version' => $connectionVersion,
            'updated_at' => $now,
        ]);
        DB::table('panel_service_targets')->where('id', $targetId)->update([
            'state' => 'active',
            'capability_status' => 'verified',
            'capability_evidence_hash' => $targetEvidenceHash,
            'capability_verified_at' => $now,
            'verified_connection_version' => $connectionVersion,
            'version' => DB::raw('version + 1'),
            'updated_at' => $now,
        ]);
        DB::table('panel_target_capabilities')->where('panel_service_target_id', $targetId)->update([
            'verification_status' => 'verified',
            'evidence_hash' => $targetEvidenceHash,
            'verified_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('sales_servers')->where('id', $serverId)->update([
            'state' => 'active',
            'visibility' => 'listed',
            'version' => DB::raw('version + 1'),
            'updated_at' => $now,
        ]);
        DB::table('plan_offering_protocol_profiles')->where('plan_offering_id', $offeringId)->update([
            'customer_selectable' => false,
            'updated_at' => $now,
        ]);
        DB::table('plan_offerings')->where('id', $offeringId)->update([
            'server_selection_mode' => 'system_selects',
            'updated_at' => $now,
        ]);

        $tierId = (int) DB::table('customer_tiers')->where('code', 'normal')->value('id');
        if (! DB::table('customer_profiles')->where('user_id', $userId)->exists()) {
            DB::table('customer_profiles')->insert([
                'user_id' => $userId,
                'current_tier_id' => $tierId,
                'tier_locked' => false,
                'tier_lock_reason_code' => null,
                'phone_verification_status' => 'verified',
                'identity_verification_status' => 'verified',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $ownerId = $this->ownerAdministrator();
        foreach (DB::table('plan_offering_tags')->where('plan_offering_id', $offeringId)->pluck('customer_tag_id') as $tagId) {
            DB::table('customer_tag_assignments')->insert([
                'user_id' => $userId,
                'tag_id' => (int) $tagId,
                'assigned_by_administrator_id' => $ownerId,
                'assigned_at' => $now,
                'removed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('panel_target_capacities')->insert([
            'panel_service_target_id' => $targetId,
            'hard_limit' => 10,
            'held_units' => 0,
            'committed_units' => 0,
            'state' => 'enabled',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->app->make(PlanOfferingRoutePolicyService::class)->create(
            $offeringId,
            new PlanOfferingRoutePolicyDefinition([
                new PlanOfferingRouteDefinition(
                    $serverId,
                    $targetId,
                    PlanOfferingRouteType::Primary,
                    0,
                    false,
                    null,
                    null,
                ),
            ]),
            $this->catalogContext($ownerId, 'initial-delivery-race-route-'.$suffix),
        );
        DB::table('plan_offerings')->where('id', $offeringId)->update([
            'state' => 'active',
            'visibility' => 'visible',
            'server_selection_mode' => 'system_selects',
            'protocol_selection_mode' => 'system_selects',
            'updated_at' => $now,
        ]);
    }
}
