<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';
require_once __DIR__.'/ServiceMutationAuthorityRuntimeTest.php';

use App\Modules\Catalog\Application\PlanOfferingRoutePolicyService;
use App\Modules\Catalog\Domain\PlanOfferingRouteDefinition;
use App\Modules\Catalog\Domain\PlanOfferingRoutePolicyDefinition;
use App\Modules\Catalog\Domain\PlanOfferingRouteType;
use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\Contracts\PanelOperationResult;
use App\Modules\Panels\Application\PanelAdapterRegistry;
use App\Modules\Panels\Application\PanelCredentialPolicy;
use App\Modules\Provisioning\Application\InitialProvisioningExecutor;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use App\Modules\Provisioning\Application\ServiceDeliveryAttemptQueueService;
use App\Modules\Provisioning\Application\ServiceMutationExecutor;
use App\Modules\Provisioning\Application\ServiceMutationQueueService;
use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Modules\Provisioning\Domain\ServiceDeliveryPurpose;
use App\Modules\Provisioning\Domain\ServiceMutationType;
use App\Shared\Application\OutboxPublisher;
use App\Shared\Application\SafeOutboxPayload;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PanelsAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DomainException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--service-delivery-contention-worker') {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $decoded = base64_decode($argv[2] ?? '', true);
    if ($decoded === false) {
        fwrite(STDERR, "Invalid Service delivery worker payload encoding.\n");
        exit(2);
    }

    try {
        /** @var array<string, string> $payload */
        $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Invalid Service delivery worker payload: '.$exception->getMessage()."\n");
        exit(2);
    }

    echo "READY\n";
    flush();
    if (fgets(STDIN) === false) {
        fwrite(STDERR, "Service delivery worker barrier was not released.\n");
        exit(2);
    }

    try {
        $receipt = $app->make(ServiceDeliveryAttemptQueueService::class)->queue(
            $payload['service_public_id'],
            ServiceDeliveryPurpose::from($payload['purpose']),
            $payload['request_key'],
            $payload['correlation_id'],
        );
        echo json_encode([
            'ok' => true,
            'result' => [
                'attempt_public_id' => $receipt->attemptPublicId,
                'outbox_event_id' => $receipt->outboxEventId,
                'replayed' => $receipt->replayed,
            ],
        ], JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $exception) {
        echo json_encode([
            'ok' => false,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ], JSON_THROW_ON_ERROR)."\n";
    }
    exit(0);
}

/** @requirement SVC-002 SVC-014 ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 INT-001 INT-002 QUA-004 QUA-007 QUA-010 */
final class ServiceDeliveryAttemptAuthorityTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use DatabaseTruncation;
    use PurchaseOrderTestSupport;

    private const WORKER_TIMEOUT_SECONDS = 30;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var Migration $mutationMigration */
        $mutationMigration = require database_path('migrations/2026_08_17_000300_enable_service_mutation_authority.php');
        $mutationMigration->up();

        /** @var Migration $deliveryMigration */
        $deliveryMigration = require database_path('migrations/2026_08_18_000100_create_service_delivery_attempt_authority.php');
        $deliveryMigration->up();

        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PanelsAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->bootPurchaseOrderClock();
    }

    protected function tearDown(): void
    {
        try {
            DB::unprepared('DROP TRIGGER IF EXISTS service_delivery_attempt_test_failure');
            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    public function test_exact_replay_reuses_attempt_and_outbox_while_conflicting_purpose_fails_closed(): void
    {
        $scenario = $this->scenario('replay');
        $first = $this->deliveryQueue()->queue(
            $scenario['service_public_id'],
            ServiceDeliveryPurpose::Resend,
            'request-delivery-replay-0001',
            'correlation-delivery-replay-0001',
        );
        $replay = $this->deliveryQueue()->queue(
            $scenario['service_public_id'],
            ServiceDeliveryPurpose::Resend,
            'request-delivery-replay-0001',
            'correlation-delivery-replay-0002',
        );

        self::assertFalse($first->replayed);
        self::assertTrue($replay->replayed);
        self::assertSame($first->attemptPublicId, $replay->attemptPublicId);
        self::assertSame($first->outboxEventId, $replay->outboxEventId);
        self::assertSame(1, $first->targetRemoteIdentityGeneration);
        self::assertSame(0, $first->targetLifecycleVersion);

        try {
            $this->deliveryQueue()->queue(
                $scenario['service_public_id'],
                ServiceDeliveryPurpose::Initial,
                'request-delivery-replay-0001',
                'correlation-delivery-replay-0003',
            );
            self::fail('A Service delivery request key cannot be reused for another purpose.');
        } catch (DomainException) {
            // Expected.
        }

        self::assertSame(1, DB::table('service_delivery_attempts')
            ->where('service_subscription_id', $scenario['service_id'])->count());
        self::assertSame(1, DB::table('outbox_messages')
            ->where('event_type', ServiceDeliveryAttemptQueueService::OUTBOX_EVENT_TYPE)->count());

        $attempt = DB::table('service_delivery_attempts')->where('public_id', $first->attemptPublicId)->first();
        self::assertNotNull($attempt);
        self::assertSame(hash('sha256', 'request-delivery-replay-0001'), $attempt->request_key_hash);
        self::assertSame('correlation-delivery-replay-0001', $attempt->correlation_id);
        self::assertSame(ServiceDeliveryPurpose::Resend->value, $attempt->purpose);

        $outbox = DB::table('outbox_messages')->where('id', $first->outboxEventId)->first([
            'event_key', 'event_type', 'aggregate_type', 'aggregate_id', 'payload', 'payload_hash',
            'correlation_id', 'dispatch_state', 'attempts', 'processed_at',
        ]);
        self::assertNotNull($outbox);
        self::assertSame(ServiceDeliveryAttemptQueueService::OUTBOX_EVENT_KEY_PREFIX.$first->attemptPublicId, $outbox->event_key);
        self::assertSame(ServiceDeliveryAttemptQueueService::OUTBOX_EVENT_TYPE, $outbox->event_type);
        self::assertSame(ServiceDeliveryAttemptQueueService::OUTBOX_AGGREGATE_TYPE, $outbox->aggregate_type);
        self::assertSame($first->attemptPublicId, $outbox->aggregate_id);
        self::assertSame('correlation-delivery-replay-0001', $outbox->correlation_id);
        self::assertSame('pending', $outbox->dispatch_state);
        self::assertSame(0, (int) $outbox->attempts);
        self::assertNull($outbox->processed_at);

        /** @var array<string, string> $payload */
        $payload = json_decode((string) $outbox->payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['service_delivery_attempt_public_id' => $first->attemptPublicId], $payload);
        self::assertSame(hash('sha256', (string) $outbox->payload), $outbox->payload_hash);
        self::assertSame([], $scenario['adapter']->calls);
    }

    public function test_resend_snapshots_current_suspended_lifecycle_without_provider_effect(): void
    {
        $scenario = $this->scenario('resend-snapshot');
        $suspend = $this->mutationQueue()->queue(
            $scenario['service_public_id'],
            ServiceMutationType::Suspend,
            'request-delivery-suspend-0001',
            'correlation-delivery-suspend-0001',
        );
        self::assertSame(ProvisioningState::Succeeded, $this->mutationExecutor()->execute($suspend->operationPublicId)->state);
        self::assertSame(['suspend'], $scenario['adapter']->calls);

        $receipt = $this->deliveryQueue()->queue(
            $scenario['service_public_id'],
            ServiceDeliveryPurpose::Resend,
            'request-delivery-resend-0001',
            'correlation-delivery-resend-0001',
        );

        self::assertFalse($receipt->replayed);
        self::assertSame(ServiceDeliveryPurpose::Resend, $receipt->purpose);
        self::assertSame(1, $receipt->targetRemoteIdentityGeneration);
        self::assertSame(1, $receipt->targetLifecycleVersion);
        self::assertSame(['suspend'], $scenario['adapter']->calls);
        self::assertSame('suspended', DB::table('service_subscriptions')
            ->where('id', $scenario['service_id'])->value('lifecycle_state'));
    }

    public function test_unresolved_and_uncertain_service_mutations_block_new_delivery_authority(): void
    {
        $scenario = $this->scenario('mutation-fence');
        $mutation = $this->mutationQueue()->queue(
            $scenario['service_public_id'],
            ServiceMutationType::RotateSubscriptionLink,
            'request-delivery-mutation-fence-0001',
            'correlation-delivery-mutation-fence-0001',
        );

        try {
            $this->deliveryQueue()->queue(
                $scenario['service_public_id'],
                ServiceDeliveryPurpose::Resend,
                'request-delivery-while-mutation-queued-0001',
                'correlation-delivery-while-mutation-queued-0001',
            );
            self::fail('Queued Service mutation must block new Delivery Attempt authority.');
        } catch (DomainException) {
            // Expected.
        }

        $scenario['adapter']->forcedMutationResult = new PanelOperationResult(
            PanelOperationOutcome::RetryableFailure,
            null,
            'provider_retryable',
            'Provider reported an uncertain rotation result.',
        );
        $mutationResult = $this->mutationExecutor()->execute($mutation->operationPublicId);
        self::assertSame(ProvisioningState::UncertainRemoteResult, $mutationResult->state);

        try {
            $this->deliveryQueue()->queue(
                $scenario['service_public_id'],
                ServiceDeliveryPurpose::Resend,
                'request-delivery-while-mutation-uncertain-0001',
                'correlation-delivery-while-mutation-uncertain-0001',
            );
            self::fail('Uncertain remote Service mutation must block new Delivery Attempt authority.');
        } catch (DomainException) {
            // Expected.
        }

        self::assertSame(0, DB::table('service_delivery_attempts')->count());
        self::assertSame(0, DB::table('outbox_messages')
            ->where('event_type', ServiceDeliveryAttemptQueueService::OUTBOX_EVENT_TYPE)->count());
    }

    public function test_unprovisioned_and_retired_services_cannot_create_new_delivery_authority(): void
    {
        $queued = $this->queuedScenario('state-fence');

        try {
            $this->deliveryQueue()->queue(
                $queued['service_public_id'],
                ServiceDeliveryPurpose::Initial,
                'request-delivery-unprovisioned-0001',
                'correlation-delivery-unprovisioned-0001',
            );
            self::fail('Unprovisioned Service must not accept a Delivery Attempt.');
        } catch (DomainException) {
            // Expected.
        }
        self::assertSame(0, DB::table('service_delivery_attempts')->count());
        self::assertSame(0, DB::table('outbox_messages')
            ->where('event_type', ServiceDeliveryAttemptQueueService::OUTBOX_EVENT_TYPE)->count());

        $scenario = $this->provisionQueuedScenario($queued, 'state-fence');
        $suspend = $this->mutationQueue()->queue(
            $scenario['service_public_id'],
            ServiceMutationType::Suspend,
            'request-delivery-retire-suspend-0001',
            'correlation-delivery-retire-suspend-0001',
        );
        self::assertSame(ProvisioningState::Succeeded, $this->mutationExecutor()->execute($suspend->operationPublicId)->state);
        $delete = $this->mutationQueue()->queue(
            $scenario['service_public_id'],
            ServiceMutationType::Delete,
            'request-delivery-retire-delete-0001',
            'correlation-delivery-retire-delete-0001',
        );
        self::assertSame(ProvisioningState::Succeeded, $this->mutationExecutor()->execute($delete->operationPublicId)->state);

        try {
            $this->deliveryQueue()->queue(
                $scenario['service_public_id'],
                ServiceDeliveryPurpose::Resend,
                'request-delivery-retired-0001',
                'correlation-delivery-retired-0001',
            );
            self::fail('Retired Service must not accept a new Delivery Attempt.');
        } catch (DomainException) {
            // Expected.
        }
        self::assertSame(0, DB::table('service_delivery_attempts')->count());
        self::assertSame(0, DB::table('outbox_messages')
            ->where('event_type', ServiceDeliveryAttemptQueueService::OUTBOX_EVENT_TYPE)->count());
    }

    public function test_attempt_insert_failure_rolls_back_attempt_and_outbox_together(): void
    {
        $scenario = $this->scenario('rollback');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER service_delivery_attempt_test_failure
AFTER INSERT ON service_delivery_attempts
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Simulated Service Delivery Attempt failure.';
END
SQL);

        try {
            try {
                $this->deliveryQueue()->queue(
                    $scenario['service_public_id'],
                    ServiceDeliveryPurpose::Resend,
                    'request-delivery-rollback-0001',
                    'correlation-delivery-rollback-0001',
                );
                self::fail('Delivery Attempt failure must roll back its Outbox handoff.');
            } catch (QueryException) {
                // Expected.
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS service_delivery_attempt_test_failure');
        }

        self::assertSame(0, DB::table('service_delivery_attempts')->count());
        self::assertSame(0, DB::table('outbox_messages')
            ->where('event_type', ServiceDeliveryAttemptQueueService::OUTBOX_EVENT_TYPE)->count());
    }

    public function test_db_bypass_cannot_forge_mutate_or_delete_delivery_intent_or_outbox(): void
    {
        $scenario = $this->scenario('db-guards');
        $receipt = $this->deliveryQueue()->queue(
            $scenario['service_public_id'],
            ServiceDeliveryPurpose::Resend,
            'request-delivery-db-guard-0001',
            'correlation-delivery-db-guard-0001',
        );

        try {
            DB::table('service_delivery_attempts')->insert([
                'public_id' => (string) Str::ulid(),
                'service_subscription_id' => $scenario['service_id'],
                'purpose' => ServiceDeliveryPurpose::Resend->value,
                'request_key_hash' => hash('sha256', 'request-delivery-forged-0001'),
                'correlation_id' => 'correlation-delivery-forged-0001',
                'target_remote_identity_generation' => 1,
                'target_lifecycle_version' => 0,
                'outbox_event_id' => (string) Str::uuid(),
                'created_at' => $this->purchaseOrderTimestamp(),
            ]);
            self::fail('Direct Delivery Attempt insert without queue authority must fail closed.');
        } catch (QueryException) {
            // Expected.
        }

        try {
            DB::table('service_delivery_attempts')->where('public_id', $receipt->attemptPublicId)
                ->update(['purpose' => ServiceDeliveryPurpose::Initial->value]);
            self::fail('Delivery Attempt intent evidence must be immutable.');
        } catch (QueryException) {
            // Expected.
        }
        try {
            DB::table('service_delivery_attempts')->where('public_id', $receipt->attemptPublicId)->delete();
            self::fail('Delivery Attempt evidence must be non-deletable.');
        } catch (QueryException) {
            // Expected.
        }

        $forgedAttemptPublicId = (string) Str::ulid();
        try {
            DB::transaction(function () use ($forgedAttemptPublicId): void {
                $this->app->make(OutboxPublisher::class)->publish(
                    (string) Str::uuid(),
                    ServiceDeliveryAttemptQueueService::OUTBOX_EVENT_KEY_PREFIX.$forgedAttemptPublicId,
                    ServiceDeliveryAttemptQueueService::OUTBOX_EVENT_TYPE,
                    ServiceDeliveryAttemptQueueService::OUTBOX_AGGREGATE_TYPE,
                    $forgedAttemptPublicId,
                    new SafeOutboxPayload(['service_delivery_attempt_public_id' => $forgedAttemptPublicId]),
                    'correlation-delivery-forged-outbox-0001',
                );
            });
            self::fail('Direct canonical Delivery Outbox insert without queue authority must fail closed.');
        } catch (QueryException) {
            // Expected.
        }

        try {
            DB::table('outbox_messages')->where('id', $receipt->outboxEventId)->delete();
            self::fail('Delivery Outbox evidence must be non-deletable.');
        } catch (QueryException) {
            // Expected.
        }
        try {
            DB::table('outbox_messages')->where('id', $receipt->outboxEventId)->update([
                'aggregate_id' => (string) Str::ulid(),
            ]);
            self::fail('Delivery Outbox identity must be immutable.');
        } catch (QueryException) {
            // Expected.
        }

        self::assertSame(1, DB::table('service_delivery_attempts')->count());
        self::assertSame(1, DB::table('outbox_messages')
            ->where('event_type', ServiceDeliveryAttemptQueueService::OUTBOX_EVENT_TYPE)->count());
    }

    public function test_concurrent_duplicate_requests_create_one_attempt_and_one_outbox_handoff(): void
    {
        $scenario = $this->scenario('contention');
        $payloads = [
            [
                'service_public_id' => $scenario['service_public_id'],
                'purpose' => ServiceDeliveryPurpose::Resend->value,
                'request_key' => 'request-delivery-contention-0001',
                'correlation_id' => 'correlation-delivery-contention-a-0001',
            ],
            [
                'service_public_id' => $scenario['service_public_id'],
                'purpose' => ServiceDeliveryPurpose::Resend->value,
                'request_key' => 'request-delivery-contention-0001',
                'correlation_id' => 'correlation-delivery-contention-b-0001',
            ],
        ];

        $results = $this->runConcurrentDeliveryQueues($payloads);
        self::assertTrue((bool) ($results[0]['ok'] ?? false), json_encode($results, JSON_THROW_ON_ERROR));
        self::assertTrue((bool) ($results[1]['ok'] ?? false), json_encode($results, JSON_THROW_ON_ERROR));
        self::assertSame($results[0]['result']['attempt_public_id'], $results[1]['result']['attempt_public_id']);
        self::assertSame($results[0]['result']['outbox_event_id'], $results[1]['result']['outbox_event_id']);
        self::assertSame(1, count(array_filter([
            (bool) $results[0]['result']['replayed'],
            (bool) $results[1]['result']['replayed'],
        ])));
        self::assertSame(1, DB::table('service_delivery_attempts')
            ->where('service_subscription_id', $scenario['service_id'])->count());
        self::assertSame(1, DB::table('outbox_messages')
            ->where('event_type', ServiceDeliveryAttemptQueueService::OUTBOX_EVENT_TYPE)->count());
    }

    private function deliveryQueue(): ServiceDeliveryAttemptQueueService
    {
        return $this->app->make(ServiceDeliveryAttemptQueueService::class);
    }

    private function mutationQueue(): ServiceMutationQueueService
    {
        return $this->app->make(ServiceMutationQueueService::class);
    }

    private function mutationExecutor(): ServiceMutationExecutor
    {
        return $this->app->make(ServiceMutationExecutor::class);
    }

    /**
     * @return array{service_id:int,service_public_id:string,offering_id:int,user_id:int,provisioning_operation_public_id:string}
     */
    private function queuedScenario(string $suffix): array
    {
        $settlement = $this->createPurchaseOrderSettlement('delivery-'.$suffix);
        $order = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('delivery-order-'.$suffix),
        );
        $queue = $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
            $order->orderPublicId,
            $this->purchaseOrderCorrelation('delivery-queue-'.$suffix),
        );
        $offeringId = (int) DB::table('order_items')->where('order_id', $order->orderId)->value('plan_offering_id');
        $userId = (int) DB::table('orders')->where('id', $order->orderId)->value('user_id');
        $service = DB::table('service_subscriptions')->where('id', $queue->serviceSubscriptionId)->first([
            'id', 'public_id', 'remote_service_id', 'provisioned_at', 'remote_identity_generation',
        ]);
        self::assertNotNull($service);
        self::assertNull($service->remote_service_id);
        self::assertNull($service->provisioned_at);
        self::assertSame(1, (int) $service->remote_identity_generation);

        return [
            'service_id' => (int) $service->id,
            'service_public_id' => (string) $service->public_id,
            'offering_id' => $offeringId,
            'user_id' => $userId,
            'provisioning_operation_public_id' => $queue->provisioningOperationPublicId,
        ];
    }

    /**
     * @param  array{service_id:int,service_public_id:string,offering_id:int,user_id:int,provisioning_operation_public_id:string}  $queued
     * @return array{service_id:int,service_public_id:string,target_id:int,adapter:ServiceMutationTestPanelAdapter}
     */
    private function provisionQueuedScenario(array $queued, string $suffix): array
    {
        $targetId = $this->makeOfferingOperational($queued['offering_id'], $queued['user_id'], $suffix);
        $adapter = new ServiceMutationTestPanelAdapter;
        $this->app->instance(
            PanelAdapterRegistry::class,
            new PanelAdapterRegistry(
                [new ServiceMutationTestPanelAdapterFactory($adapter)],
                $this->app->make(PanelCredentialPolicy::class),
            ),
        );
        $this->app->forgetInstance(InitialProvisioningExecutor::class);
        $this->app->forgetInstance(ServiceMutationExecutor::class);

        $provisioned = $this->app->make(InitialProvisioningExecutor::class)
            ->execute($queued['provisioning_operation_public_id']);
        self::assertSame(ProvisioningState::Succeeded, $provisioned->state);
        $adapter->resetCalls();

        $service = DB::table('service_subscriptions')->where('id', $queued['service_id'])->first([
            'id', 'public_id', 'service_target_id', 'remote_service_id', 'provisioned_at',
            'lifecycle_state', 'lifecycle_version', 'remote_identity_generation', 'remote_deleted_at',
        ]);
        self::assertNotNull($service);
        self::assertSame('active', $service->lifecycle_state);
        self::assertSame(0, (int) $service->lifecycle_version);
        self::assertSame(1, (int) $service->remote_identity_generation);
        self::assertNull($service->remote_deleted_at);
        self::assertNotNull($service->remote_service_id);
        self::assertNotNull($service->provisioned_at);

        return [
            'service_id' => (int) $service->id,
            'service_public_id' => (string) $service->public_id,
            'target_id' => $targetId,
            'adapter' => $adapter,
        ];
    }

    /** @return array{service_id:int,service_public_id:string,target_id:int,adapter:ServiceMutationTestPanelAdapter} */
    private function scenario(string $suffix): array
    {
        return $this->provisionQueuedScenario($this->queuedScenario($suffix), $suffix);
    }

    private function makeOfferingOperational(int $offeringId, int $userId, string $suffix): int
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
        $capabilityHash = hash('sha256', 'service-delivery-capabilities-'.$suffix);
        $targetEvidenceHash = hash('sha256', 'service-delivery-target-evidence-'.$suffix);

        DB::table('panel_connections')->where('id', $connectionId)->update([
            'encrypted_credentials' => Crypt::encryptString(json_encode(['token' => 'service-delivery-test'], JSON_THROW_ON_ERROR)),
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
            $this->catalogContext($ownerId, 'service-delivery-route-'.$suffix),
        );
        DB::table('plan_offerings')->where('id', $offeringId)->update([
            'state' => 'active',
            'visibility' => 'visible',
            'server_selection_mode' => 'system_selects',
            'protocol_selection_mode' => 'system_selects',
            'updated_at' => $now,
        ]);

        return $targetId;
    }

    /**
     * @param  list<array<string, string>>  $payloads
     * @return list<array<string, mixed>>
     */
    private function runConcurrentDeliveryQueues(array $payloads): array
    {
        $workers = [];
        try {
            foreach ($payloads as $payload) {
                $pipes = [];
                $process = proc_open([
                    PHP_BINARY,
                    '-d',
                    'pcov.enabled=0',
                    __FILE__,
                    '--service-delivery-contention-worker',
                    base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                ], [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes, dirname(__DIR__, 2));
                if (! is_resource($process)) {
                    throw new RuntimeException('Unable to start Service delivery contention worker.');
                }
                /** @var array{0:resource,1:resource,2:resource} $pipes */
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $workers[] = ['process' => $process, 'pipes' => $pipes];
            }

            foreach ($workers as $index => $worker) {
                if ($this->readWorkerLine($worker, 'readiness', $index) !== "READY\n") {
                    throw new RuntimeException('Service delivery contention worker returned an invalid readiness marker.');
                }
            }
            foreach ($workers as $worker) {
                fwrite($worker['pipes'][0], "GO\n");
                fflush($worker['pipes'][0]);
                fclose($worker['pipes'][0]);
            }

            $results = [];
            foreach ($workers as $index => $worker) {
                $line = $this->readWorkerLine($worker, 'result', $index);
                $stderr = stream_get_contents($worker['pipes'][2]);
                fclose($worker['pipes'][1]);
                fclose($worker['pipes'][2]);
                if (proc_close($worker['process']) !== 0) {
                    throw new RuntimeException('Service delivery contention worker failed: '.$stderr);
                }
                /** @var array<string, mixed> $result */
                $result = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $results[] = $result;
            }

            return $results;
        } finally {
            $this->terminateWorkers($workers);
        }
    }

    /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker */
    private function readWorkerLine(array $worker, string $phase, int $index): string
    {
        $deadline = microtime(true) + self::WORKER_TIMEOUT_SECONDS;
        $stderr = '';
        while (microtime(true) < $deadline) {
            $read = [$worker['pipes'][1], $worker['pipes'][2]];
            $write = null;
            $except = null;
            $selected = stream_select($read, $write, $except, 0, 200_000);
            if ($selected === false) {
                throw new RuntimeException('Unable to wait for Service delivery contention worker output.');
            }
            foreach ($read as $stream) {
                if ($stream === $worker['pipes'][2]) {
                    $stderr .= stream_get_contents($stream);

                    continue;
                }
                $line = fgets($stream);
                if ($line !== false && trim($line) !== '') {
                    return $line;
                }
            }
            $status = proc_get_status($worker['process']);
            if (! $status['running'] && feof($worker['pipes'][1])) {
                $stderr .= stream_get_contents($worker['pipes'][2]);
                throw new RuntimeException('Service delivery contention worker exited before '.$phase.' output: '.$stderr);
            }
        }

        throw new RuntimeException(sprintf(
            'Service delivery contention worker %d timed out during %s after %d seconds: %s',
            $index,
            $phase,
            self::WORKER_TIMEOUT_SECONDS,
            $stderr,
        ));
    }

    /** @param list<array{process:resource,pipes:array{0:resource,1:resource,2:resource}}> $workers */
    private function terminateWorkers(array $workers): void
    {
        foreach ($workers as $worker) {
            if (! is_resource($worker['process'])) {
                continue;
            }
            $status = proc_get_status($worker['process']);
            if ($status['running']) {
                proc_terminate($worker['process']);
            }
            foreach ($worker['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($worker['process']);
        }
    }
}
