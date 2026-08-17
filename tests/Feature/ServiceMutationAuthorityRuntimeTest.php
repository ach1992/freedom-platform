<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use App\Modules\Catalog\Application\PlanOfferingRoutePolicyService;
use App\Modules\Catalog\Domain\PlanOfferingRouteDefinition;
use App\Modules\Catalog\Domain\PlanOfferingRoutePolicyDefinition;
use App\Modules\Catalog\Domain\PlanOfferingRouteType;
use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Panels\Application\Contracts\DataAllowanceMode;
use App\Modules\Panels\Application\Contracts\PanelAdapter;
use App\Modules\Panels\Application\Contracts\PanelAdapterFactory;
use App\Modules\Panels\Application\Contracts\PanelCapabilities;
use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\Contracts\PanelOperationResult;
use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\Contracts\SensitiveDeliveryArtifacts;
use App\Modules\Panels\Application\PanelAdapterRegistry;
use App\Modules\Panels\Application\PanelAdapterSession;
use App\Modules\Panels\Application\PanelCredentialPolicy;
use App\Modules\Panels\Domain\PanelProviderType;
use App\Modules\Provisioning\Application\InitialProvisioningExecutor;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use App\Modules\Provisioning\Application\ServiceMutationExecutor;
use App\Modules\Provisioning\Application\ServiceMutationQueueService;
use App\Modules\Provisioning\Application\ServiceMutationReceipt;
use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Modules\Provisioning\Domain\ServiceMutationType;
use Closure;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PanelsAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class ServiceMutationTestPanelAdapter implements PanelAdapter
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<int> */
    public array $transactionLevels = [];

    /** @var list<string> */
    public array $supportedOperations = [
        'activate',
        'authoritative_username_lookup',
        'create_service',
        'delete',
        'reset_usage',
        'rotate_subscription_link',
        'suspend',
    ];

    public ?PanelOperationResult $forcedMutationResult = null;

    public bool $throwOnMutation = false;

    public ?Closure $beforeMutation = null;

    /** @var array<string, RemoteServiceSnapshot> */
    private array $servicesByUsername = [];

    public function resetCalls(): void
    {
        $this->calls = [];
        $this->transactionLevels = [];
    }

    public function testConnection(): PanelOperationResult
    {
        return new PanelOperationResult(PanelOperationOutcome::Success, null, 'test_connection_ok', 'Test panel is healthy.');
    }

    public function capabilities(): PanelCapabilities
    {
        return new PanelCapabilities('fake', '1.0.0', $this->supportedOperations, ['fake-default']);
    }

    public function findByRemoteId(string $remoteId): ?RemoteServiceSnapshot
    {
        $this->record('lookup_remote_id');
        foreach ($this->servicesByUsername as $service) {
            if (hash_equals($service->remoteId, $remoteId)) {
                return $service;
            }
        }

        return null;
    }

    public function findByDeterministicUsername(string $username): ?RemoteServiceSnapshot
    {
        $this->record('lookup_username');

        return $this->servicesByUsername[$username] ?? null;
    }

    public function createEquivalenceHash(PanelCreateServiceRequest $request): string
    {
        return hash('sha256', json_encode([
            'username' => $request->username,
            'target_reference' => $request->targetReference,
            'data_limit_bytes' => $request->dataLimitBytes,
            'expires_at' => $request->expiresAt?->format(DATE_ATOM),
            'attributes' => $request->validatedAttributes,
        ], JSON_THROW_ON_ERROR));
    }

    public function createService(PanelCreateServiceRequest $request): PanelOperationResult
    {
        $this->record('create');
        $hash = $this->createEquivalenceHash($request);
        $service = new RemoteServiceSnapshot(
            'mutation-test-'.substr(hash('sha256', $request->idempotencyKey), 0, 24),
            $request->username,
            PanelServiceStatus::Active,
            $request->dataLimitBytes,
            0,
            $request->expiresAt,
            $hash,
            $hash,
        );
        $this->servicesByUsername[$service->username] = $service;

        return new PanelOperationResult(
            PanelOperationOutcome::Success,
            $service,
            'test_service_created',
            'Test service created.',
        );
    }

    public function fetchStatus(string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by Service mutation tests.');
    }

    public function updateExpiry(string $idempotencyKey, string $remoteId, DateTimeImmutable $expiresAt): PanelOperationResult
    {
        throw new LogicException('Not used by Service mutation tests.');
    }

    public function updateDataAllowance(
        string $idempotencyKey,
        string $remoteId,
        int $bytes,
        DataAllowanceMode $mode,
    ): PanelOperationResult {
        throw new LogicException('Not used by Service mutation tests.');
    }

    public function resetUsage(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->mutation('reset_usage');
    }

    public function suspend(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->mutation('suspend');
    }

    public function activate(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->mutation('activate');
    }

    public function delete(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->mutation('delete');
    }

    public function rotateSubscriptionLink(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->mutation('rotate_subscription_link');
    }

    public function getDeliveryArtifacts(string $remoteId): SensitiveDeliveryArtifacts
    {
        throw new LogicException('Not used by Service mutation tests.');
    }

    public function synchronize(string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by Service mutation tests.');
    }

    public function listCompatibleTargets(): array
    {
        return [[
            'id' => 'fake-default',
            'type' => 'inbound',
            'name' => 'Test target',
            'capabilities' => $this->supportedOperations,
        ]];
    }

    private function mutation(string $operation): PanelOperationResult
    {
        $this->record($operation);
        if ($this->beforeMutation !== null) {
            ($this->beforeMutation)();
        }
        if ($this->throwOnMutation) {
            throw new LogicException('Simulated provider exception after boundary entry.');
        }
        if ($this->forcedMutationResult !== null) {
            return $this->forcedMutationResult;
        }

        return new PanelOperationResult(
            PanelOperationOutcome::Success,
            null,
            'test_'.$operation.'_ok',
            'Test Service mutation succeeded.',
        );
    }

    private function record(string $call): void
    {
        $this->calls[] = $call;
        $this->transactionLevels[] = DB::connection()->transactionLevel();
    }
}

final readonly class ServiceMutationTestPanelAdapterFactory implements PanelAdapterFactory
{
    public function __construct(private ServiceMutationTestPanelAdapter $adapter) {}

    public function providerType(): PanelProviderType
    {
        return PanelProviderType::Fake;
    }

    public function make(PanelAdapterSession $session): PanelAdapter
    {
        return $this->adapter;
    }
}

/** @requirement SVC-004 PRV-002 PRV-003 ARCH-004 DAT-003 SEC-008 QUA-004 QUA-007 QUA-010 */
final class ServiceMutationAuthorityRuntimeTest extends TestCase
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

    public function test_duplicate_request_replays_same_generation_and_unresolved_mutation_blocks_new_command(): void
    {
        $scenario = $this->scenario('replay');
        $queue = $this->mutationQueue()->queue(
            $scenario['service_public_id'],
            ServiceMutationType::Suspend,
            'request-replay-0001',
            'correlation-replay-0001',
        );

        $replay = $this->mutationQueue()->queue(
            $scenario['service_public_id'],
            ServiceMutationType::Suspend,
            'request-replay-0001',
            'correlation-replay-0002',
        );

        self::assertTrue($replay->replayed);
        self::assertSame($queue->operationPublicId, $replay->operationPublicId);
        self::assertSame($queue->generation, $replay->generation);
        self::assertSame(1, $queue->generation);

        try {
            $this->mutationQueue()->queue(
                $scenario['service_public_id'],
                ServiceMutationType::ResetUsage,
                'request-replay-0002',
                'correlation-replay-0003',
            );
            self::fail('An unresolved mutation must block a second command.');
        } catch (DomainException) {
            // Expected.
        }

        self::assertSame(1, DB::table('provisioning_operations')
            ->where('service_subscription_id', $scenario['service_id'])
            ->where('operation_type', '<>', 'initial_provision')
            ->count());
    }

    public function test_suspend_success_runs_outside_transaction_and_terminal_replay_has_no_second_effect(): void
    {
        $scenario = $this->scenario('suspend-success');
        $queue = $this->queueMutation($scenario, ServiceMutationType::Suspend, 'suspend-success');

        $receipt = $this->mutationExecutor()->execute($queue->operationPublicId);

        self::assertSame(ProvisioningState::Succeeded, $receipt->state);
        self::assertSame(['suspend'], $scenario['adapter']->calls);
        self::assertSame([0], $scenario['adapter']->transactionLevels);
        $service = $this->serviceRow($scenario['service_id']);
        self::assertSame('suspended', $service->lifecycle_state);
        self::assertSame(1, (int) $service->lifecycle_version);
        self::assertSame(1, (int) $service->remote_identity_generation);
        self::assertNull($service->remote_deleted_at);

        $replay = $this->mutationExecutor()->execute($queue->operationPublicId);
        self::assertTrue($replay->replayed);
        self::assertSame(ProvisioningState::Succeeded, $replay->state);
        self::assertSame(['suspend'], $scenario['adapter']->calls);
    }

    public function test_capability_mismatch_fails_final_before_provider_boundary(): void
    {
        $scenario = $this->scenario('capability');
        $scenario['adapter']->supportedOperations = array_values(array_filter(
            $scenario['adapter']->supportedOperations,
            static fn (string $operation): bool => $operation !== 'suspend',
        ));
        $queue = $this->queueMutation($scenario, ServiceMutationType::Suspend, 'capability');

        $receipt = $this->mutationExecutor()->execute($queue->operationPublicId);

        self::assertSame(ProvisioningState::FailedFinal, $receipt->state);
        self::assertSame([], $scenario['adapter']->calls);
        $operation = DB::table('provisioning_operations')->where('public_id', $queue->operationPublicId)->first([
            'last_result_code', 'remote_effect_started_at', 'remote_effect_completed_at',
        ]);
        self::assertNotNull($operation);
        self::assertSame('panel_capability_missing', $operation->last_result_code);
        self::assertNull($operation->remote_effect_started_at);
        self::assertNull($operation->remote_effect_completed_at);
    }

    public function test_retryable_result_after_provider_boundary_is_quarantined_and_cannot_blind_retry(): void
    {
        $scenario = $this->scenario('retryable-boundary');
        $scenario['adapter']->forcedMutationResult = new PanelOperationResult(
            PanelOperationOutcome::RetryableFailure,
            null,
            'provider_retryable',
            'Provider reported a retryable result.',
        );
        $queue = $this->queueMutation($scenario, ServiceMutationType::ResetUsage, 'retryable-boundary');

        $receipt = $this->mutationExecutor()->execute($queue->operationPublicId);

        self::assertSame(ProvisioningState::UncertainRemoteResult, $receipt->state);
        self::assertSame(['reset_usage'], $scenario['adapter']->calls);
        self::assertSame([0], $scenario['adapter']->transactionLevels);
        $operation = DB::table('provisioning_operations')->where('public_id', $queue->operationPublicId)->first([
            'remote_effect_started_at', 'remote_effect_completed_at', 'last_result_code',
        ]);
        self::assertNotNull($operation);
        self::assertNotNull($operation->remote_effect_started_at);
        self::assertNotNull($operation->remote_effect_completed_at);
        self::assertSame('provider_retryable', $operation->last_result_code);

        try {
            $this->mutationExecutor()->execute($queue->operationPublicId);
            self::fail('Uncertain Service mutation must not automatically retry.');
        } catch (DomainException) {
            // Expected.
        }
        self::assertSame(['reset_usage'], $scenario['adapter']->calls);
    }

    public function test_provider_exception_after_boundary_is_quarantined(): void
    {
        $scenario = $this->scenario('provider-exception');
        $scenario['adapter']->throwOnMutation = true;
        $queue = $this->queueMutation($scenario, ServiceMutationType::RotateSubscriptionLink, 'provider-exception');

        $receipt = $this->mutationExecutor()->execute($queue->operationPublicId);

        self::assertSame(ProvisioningState::UncertainRemoteResult, $receipt->state);
        self::assertSame(['rotate_subscription_link'], $scenario['adapter']->calls);
        self::assertSame('remote_effect_exception', DB::table('provisioning_operations')
            ->where('public_id', $queue->operationPublicId)->value('last_result_code'));
    }

    public function test_pre_boundary_panel_runtime_failure_is_retryable_without_provider_effect(): void
    {
        $scenario = $this->scenario('runtime-retry');
        $queue = $this->queueMutation($scenario, ServiceMutationType::ResetUsage, 'runtime-retry');
        DB::table('panel_service_targets')->where('id', $scenario['target_id'])->update([
            'state' => 'disabled',
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]);

        $retryable = $this->mutationExecutor()->execute($queue->operationPublicId);
        self::assertSame(ProvisioningState::RetryScheduled, $retryable->state);
        self::assertSame([], $scenario['adapter']->calls);
        self::assertNull(DB::table('provisioning_operations')
            ->where('public_id', $queue->operationPublicId)->value('remote_effect_started_at'));

        DB::table('panel_service_targets')->where('id', $scenario['target_id'])->update([
            'state' => 'active',
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]);
        $success = $this->mutationExecutor()->execute($queue->operationPublicId);

        self::assertSame(ProvisioningState::Succeeded, $success->state);
        self::assertSame(['reset_usage'], $scenario['adapter']->calls);
        self::assertSame(2, (int) DB::table('provisioning_operations')
            ->where('public_id', $queue->operationPublicId)->value('attempt_count'));
    }

    public function test_running_reentry_and_pre_success_lifecycle_bypass_are_rejected_before_provider_effect(): void
    {
        $scenario = $this->scenario('running-fence');
        $queue = $this->queueMutation($scenario, ServiceMutationType::Suspend, 'running-fence');
        $this->simulateClaim($queue);

        $operation = DB::table('provisioning_operations')->where('public_id', $queue->operationPublicId)->first([
            'operation_key', 'correlation_id', 'operation_generation',
        ]);
        self::assertNotNull($operation);
        $connection = DB::connection();
        $connection->statement(
            'SET @app_provisioning_authority = ?, @app_provisioning_operation_key = ?, @app_provisioning_correlation_id = ?, @app_service_mutation_generation = ?',
            ['service_mutation_effect_v1', $operation->operation_key, $operation->correlation_id, (int) $operation->operation_generation],
        );
        try {
            try {
                $connection->table('service_subscriptions')->where('id', $scenario['service_id'])->update([
                    'lifecycle_state' => 'suspended',
                    'lifecycle_version' => 1,
                    'updated_at' => $this->purchaseOrderTimestamp(),
                ]);
                self::fail('Service lifecycle cannot change before durable provider success.');
            } catch (QueryException) {
                // Expected.
            }
        } finally {
            $connection->statement(
                'SET @app_provisioning_authority = NULL, @app_provisioning_operation_key = NULL, @app_provisioning_correlation_id = NULL, @app_service_mutation_generation = NULL',
            );
        }

        try {
            $this->mutationExecutor()->execute($queue->operationPublicId);
            self::fail('A running Service mutation must not re-enter the provider boundary.');
        } catch (DomainException) {
            // Expected.
        }
        self::assertSame([], $scenario['adapter']->calls);
        self::assertSame('active', DB::table('service_subscriptions')
            ->where('id', $scenario['service_id'])->value('lifecycle_state'));
    }

    public function test_direct_lifecycle_bypass_is_rejected_without_authority(): void
    {
        $scenario = $this->scenario('db-bypass');

        try {
            DB::table('service_subscriptions')->where('id', $scenario['service_id'])->update([
                'lifecycle_state' => 'suspended',
                'lifecycle_version' => 1,
                'updated_at' => $this->purchaseOrderTimestamp(),
            ]);
            self::fail('Direct Service lifecycle mutation must fail closed.');
        } catch (QueryException) {
            // Expected.
        }

        self::assertSame('active', DB::table('service_subscriptions')
            ->where('id', $scenario['service_id'])->value('lifecycle_state'));
    }

    public function test_delete_from_suspended_service_retires_once_and_preserves_remote_identity(): void
    {
        $scenario = $this->scenario('delete');
        $remoteId = (string) DB::table('service_subscriptions')
            ->where('id', $scenario['service_id'])->value('remote_service_id');

        $suspend = $this->queueMutation($scenario, ServiceMutationType::Suspend, 'delete-suspend');
        self::assertSame(ProvisioningState::Succeeded, $this->mutationExecutor()->execute($suspend->operationPublicId)->state);
        $scenario['adapter']->resetCalls();

        $delete = $this->queueMutation($scenario, ServiceMutationType::Delete, 'delete-retire');
        $receipt = $this->mutationExecutor()->execute($delete->operationPublicId);

        self::assertSame(ProvisioningState::Succeeded, $receipt->state);
        self::assertSame(['delete'], $scenario['adapter']->calls);
        $service = $this->serviceRow($scenario['service_id']);
        self::assertSame('retired', $service->lifecycle_state);
        self::assertSame(2, (int) $service->lifecycle_version);
        self::assertSame(2, (int) $service->mutation_generation);
        self::assertSame($remoteId, $service->remote_service_id);
        self::assertSame(1, (int) $service->remote_identity_generation);
        self::assertNotNull($service->remote_deleted_at);

        $replay = $this->mutationExecutor()->execute($delete->operationPublicId);
        self::assertTrue($replay->replayed);
        self::assertSame(['delete'], $scenario['adapter']->calls);

        try {
            $this->mutationQueue()->queue(
                $scenario['service_public_id'],
                ServiceMutationType::Activate,
                'request-delete-reactivate',
                'correlation-delete-reactivate',
            );
            self::fail('Retired Service must not accept a stale activation command.');
        } catch (DomainException) {
            // Expected.
        }
    }

    /**
     * @return array{service_id:int,service_public_id:string,target_id:int,adapter:ServiceMutationTestPanelAdapter}
     */
    private function scenario(string $suffix): array
    {
        $settlement = $this->createPurchaseOrderSettlement('mutation-'.$suffix);
        $order = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('mutation-order-'.$suffix),
        );
        $queue = $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
            $order->orderPublicId,
            $this->purchaseOrderCorrelation('mutation-queue-'.$suffix),
        );
        $offeringId = (int) DB::table('order_items')->where('order_id', $order->orderId)->value('plan_offering_id');
        $userId = (int) DB::table('orders')->where('id', $order->orderId)->value('user_id');
        $targetId = $this->makeOfferingOperational($offeringId, $userId, $suffix);

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

        $provisioned = $this->app->make(InitialProvisioningExecutor::class)->execute($queue->provisioningOperationPublicId);
        self::assertSame(ProvisioningState::Succeeded, $provisioned->state);
        $adapter->resetCalls();

        $service = DB::table('service_subscriptions')->where('id', $queue->serviceSubscriptionId)->first([
            'id', 'public_id', 'service_target_id', 'remote_service_id', 'provisioned_at',
            'lifecycle_state', 'lifecycle_version', 'remote_identity_generation', 'mutation_generation', 'remote_deleted_at',
        ]);
        self::assertNotNull($service);
        self::assertSame('active', $service->lifecycle_state);
        self::assertSame(0, (int) $service->lifecycle_version);
        self::assertSame(1, (int) $service->remote_identity_generation);
        self::assertSame(0, (int) $service->mutation_generation);
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

    /** @param array{service_id:int,service_public_id:string,target_id:int,adapter:ServiceMutationTestPanelAdapter} $scenario */
    private function queueMutation(array $scenario, ServiceMutationType $type, string $suffix): ServiceMutationReceipt
    {
        return $this->mutationQueue()->queue(
            $scenario['service_public_id'],
            $type,
            'request-'.$suffix.'-0001',
            'correlation-'.$suffix.'-0001',
        );
    }

    private function mutationQueue(): ServiceMutationQueueService
    {
        return $this->app->make(ServiceMutationQueueService::class);
    }

    private function mutationExecutor(): ServiceMutationExecutor
    {
        return $this->app->make(ServiceMutationExecutor::class);
    }

    private function simulateClaim(ServiceMutationReceipt $queue): void
    {
        $operation = DB::table('provisioning_operations')->where('public_id', $queue->operationPublicId)->first([
            'id', 'operation_key', 'correlation_id', 'operation_generation', 'state', 'state_version', 'attempt_count',
        ]);
        self::assertNotNull($operation);
        self::assertSame(ProvisioningState::Queued->value, $operation->state);

        $connection = DB::connection();
        $connection->statement(
            'SET @app_provisioning_authority = ?, @app_provisioning_operation_key = ?, @app_provisioning_correlation_id = ?, @app_service_mutation_generation = ?',
            ['service_mutation_effect_v1', $operation->operation_key, $operation->correlation_id, (int) $operation->operation_generation],
        );
        try {
            $updated = $connection->table('provisioning_operations')->where('id', (int) $operation->id)->update([
                'state' => ProvisioningState::Running->value,
                'state_version' => (int) $operation->state_version + 1,
                'effect_fence_key' => 'test-running-'.substr(hash('sha256', $queue->operationPublicId), 0, 40),
                'attempt_count' => (int) $operation->attempt_count + 1,
                'updated_at' => $this->purchaseOrderTimestamp(),
            ]);
            self::assertSame(1, $updated);
        } finally {
            $connection->statement(
                'SET @app_provisioning_authority = NULL, @app_provisioning_operation_key = NULL, @app_provisioning_correlation_id = NULL, @app_service_mutation_generation = NULL',
            );
        }
    }

    private function serviceRow(int $serviceId): object
    {
        $service = DB::table('service_subscriptions')->where('id', $serviceId)->first([
            'lifecycle_state', 'lifecycle_version', 'remote_identity_generation', 'mutation_generation',
            'remote_service_id', 'remote_deleted_at',
        ]);
        self::assertNotNull($service);

        return $service;
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
        $capabilityHash = hash('sha256', 'service-mutation-test-capabilities-'.$suffix);
        $targetEvidenceHash = hash('sha256', 'service-mutation-test-target-evidence-'.$suffix);

        DB::table('panel_connections')->where('id', $connectionId)->update([
            'encrypted_credentials' => Crypt::encryptString(json_encode(['token' => 'service-mutation-test'], JSON_THROW_ON_ERROR)),
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
            $this->catalogContext($ownerId, 'service-mutation-route-'.$suffix),
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
}
