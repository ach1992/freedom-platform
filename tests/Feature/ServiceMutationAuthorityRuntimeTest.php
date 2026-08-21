<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use App\Modules\Catalog\Application\PlanOfferingRoutePolicyService;
use App\Modules\Catalog\Domain\PlanOfferingRouteDefinition;
use App\Modules\Catalog\Domain\PlanOfferingRoutePolicyDefinition;
use App\Modules\Catalog\Domain\PlanOfferingRouteType;
use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteReceipt;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Application\ServicePackageQuoteContext;
use App\Modules\Orders\Domain\QuoteAction;
use App\Modules\Orders\Domain\QuoteOverrideSource;
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
use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\PurchasePaymentIntentService;
use App\Modules\Payments\Application\PurchaseRefundService;
use App\Modules\Payments\Application\PurchaseSettlementReceipt;
use App\Modules\Payments\Application\PurchaseSettlementService;
use App\Modules\Payments\Application\PurchaseWalletPaymentService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Provisioning\Application\InitialProvisioningExecutor;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use App\Modules\Provisioning\Application\ServiceMutationExecutor;
use App\Modules\Provisioning\Application\ServiceMutationQueueService;
use App\Modules\Provisioning\Application\ServiceMutationReceipt;
use App\Modules\Provisioning\Application\ServicePurchaseMutationQueueService;
use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Modules\Provisioning\Domain\ServiceMutationType;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Shared\Domain\Money;
use Closure;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PanelsAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Database\Seeders\WalletFinancialFoundationSeeder;
use DateTimeImmutable;
use DomainException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

$serviceMutationWorkerMode = $argv[1] ?? null;
if (PHP_SAPI === 'cli' && in_array($serviceMutationWorkerMode, [
    '--service-mutation-contention-worker',
    '--service-paid-mutation-contention-worker',
], true)) {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $decoded = base64_decode($argv[2] ?? '', true);
    if ($decoded === false) {
        fwrite(STDERR, "Invalid Service mutation worker payload encoding.\n");
        exit(2);
    }

    try {
        /** @var array<string, string> $payload */
        $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Invalid Service mutation worker payload: '.$exception->getMessage()."\n");
        exit(2);
    }

    echo "READY\n";
    flush();
    if (fgets(STDIN) === false) {
        fwrite(STDERR, "Service mutation worker barrier was not released.\n");
        exit(2);
    }

    try {
        $receipt = $serviceMutationWorkerMode === '--service-paid-mutation-contention-worker'
            ? $app->make(ServicePurchaseMutationQueueService::class)->queueFromSettlement(
                $payload['purchase_settlement_public_id'],
                $payload['request_key'],
                $payload['correlation_id'],
            )
            : $app->make(ServiceMutationQueueService::class)->queue(
                $payload['service_public_id'],
                ServiceMutationType::from($payload['operation_type']),
                $payload['request_key'],
                $payload['correlation_id'],
            );
        echo json_encode([
            'ok' => true,
            'result' => [
                'operation_public_id' => $receipt->operationPublicId,
                'generation' => $receipt->generation,
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
        'add_data_allowance',
        'atomic_service_entitlements',
        'delete',
        'reset_usage',
        'rotate_subscription_link',
        'suspend',
        'update_expiry',
    ];

    public ?PanelOperationResult $forcedMutationResult = null;

    public bool $throwOnMutation = false;

    public ?Closure $beforeMutation = null;

    public ?int $lastDataAllowanceBytes = null;

    public ?DataAllowanceMode $lastDataAllowanceMode = null;

    public ?DateTimeImmutable $lastExpiryAt = null;

    /** @var array<string, RemoteServiceSnapshot> */
    private array $servicesByUsername = [];

    public function resetCalls(): void
    {
        $this->calls = [];
        $this->transactionLevels = [];
        $this->lastDataAllowanceBytes = null;
        $this->lastDataAllowanceMode = null;
        $this->lastExpiryAt = null;
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
        $this->lastExpiryAt = $expiresAt;

        return $this->mutation('update_expiry');
    }

    public function updateDataAllowance(
        string $idempotencyKey,
        string $remoteId,
        int $bytes,
        DataAllowanceMode $mode,
    ): PanelOperationResult {
        $this->lastDataAllowanceBytes = $bytes;
        $this->lastDataAllowanceMode = $mode;

        return $this->mutation('add_data_allowance');
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

    private const WORKER_TIMEOUT_SECONDS = 30;

    protected function setUp(): void
    {
        parent::setUp();

        // DatabaseTruncation migrates the current schema once, then clears rows between
        // tests. Runtime scenarios must retain the final composed guards; historical
        // authority migrations are exercised exclusively by the dedicated migration tests.

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

    public function test_concurrent_distinct_commands_allocate_one_generation_then_advance_monotonically(): void
    {
        $scenario = $this->scenario('queue-contention');
        $payloads = [
            [
                'service_public_id' => $scenario['service_public_id'],
                'operation_type' => ServiceMutationType::Suspend->value,
                'request_key' => 'request-contention-suspend-0001',
                'correlation_id' => 'correlation-contention-suspend-0001',
            ],
            [
                'service_public_id' => $scenario['service_public_id'],
                'operation_type' => ServiceMutationType::ResetUsage->value,
                'request_key' => 'request-contention-reset-0001',
                'correlation_id' => 'correlation-contention-reset-0001',
            ],
        ];

        $results = $this->runConcurrentMutationQueues($payloads);
        $successfulIndexes = [];
        foreach ($results as $index => $result) {
            if (($result['ok'] ?? false) === true) {
                $successfulIndexes[] = $index;
            }
        }

        self::assertCount(1, $successfulIndexes, json_encode($results, JSON_THROW_ON_ERROR));
        $winnerIndex = $successfulIndexes[0];
        $loserIndex = $winnerIndex === 0 ? 1 : 0;
        self::assertSame(1, $results[$winnerIndex]['result']['generation']);
        self::assertFalse($results[$winnerIndex]['result']['replayed']);
        self::assertFalse($results[$loserIndex]['ok']);
        self::assertSame(DomainException::class, $results[$loserIndex]['exception']);
        self::assertSame(1, (int) DB::table('service_subscriptions')
            ->where('id', $scenario['service_id'])->value('mutation_generation'));
        self::assertSame(1, DB::table('provisioning_operations')
            ->where('service_subscription_id', $scenario['service_id'])
            ->where('operation_type', '<>', 'initial_provision')
            ->count());

        $winnerReceipt = $this->mutationExecutor()->execute((string) $results[$winnerIndex]['result']['operation_public_id']);
        self::assertSame(ProvisioningState::Succeeded, $winnerReceipt->state);

        $loserPayload = $payloads[$loserIndex];
        $second = $this->mutationQueue()->queue(
            $loserPayload['service_public_id'],
            ServiceMutationType::from($loserPayload['operation_type']),
            $loserPayload['request_key'],
            $loserPayload['correlation_id'],
        );
        self::assertSame(2, $second->generation);
        self::assertFalse($second->replayed);
        self::assertSame(2, (int) DB::table('service_subscriptions')
            ->where('id', $scenario['service_id'])->value('mutation_generation'));
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
        $credentialPolicy = $this->app->make(PanelCredentialPolicy::class);
        $this->app->instance(PanelAdapterRegistry::class, new PanelAdapterRegistry([], $credentialPolicy));
        $this->app->forgetInstance(ServiceMutationExecutor::class);

        $retryable = $this->mutationExecutor()->execute($queue->operationPublicId);
        self::assertSame(ProvisioningState::RetryScheduled, $retryable->state);
        self::assertSame([], $scenario['adapter']->calls);
        self::assertNull(DB::table('provisioning_operations')
            ->where('public_id', $queue->operationPublicId)->value('remote_effect_started_at'));

        $this->app->instance(
            PanelAdapterRegistry::class,
            new PanelAdapterRegistry(
                [new ServiceMutationTestPanelAdapterFactory($scenario['adapter'])],
                $credentialPolicy,
            ),
        );
        $this->app->forgetInstance(ServiceMutationExecutor::class);
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

    public function test_paid_service_authority_migrations_reenter_and_empty_rollback_round_trips(): void
    {
        /** @var Migration $quoteMigration */
        $quoteMigration = require database_path('migrations/2026_08_20_000100_enable_service_package_quotes.php');
        /** @var Migration $paidMutationMigration */
        $paidMutationMigration = require database_path('migrations/2026_08_20_000110_enable_paid_service_mutation_authority.php');

        try {
            $quoteMigration->up();
            $paidMutationMigration->up();
            $quoteMigration->up();
            $paidMutationMigration->up();

            self::assertTrue(DB::getSchemaBuilder()->hasColumn('quotes', 'action_snapshot'));
            self::assertTrue(DB::getSchemaBuilder()->hasTable('service_paid_mutation_authorities'));
            $this->assertPaidMutationDatabaseSurface();

            $paidMutationMigration->down();
            $quoteMigration->down();
            $legacyRefundGuard = DB::selectOne(<<<'SQL'
SELECT ACTION_STATEMENT AS action_statement
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME = 'purchase_refunds_provisioning_invalidation'
LIMIT 1
SQL);
            self::assertNotNull($legacyRefundGuard);
            self::assertStringContainsString(
                'Initial provisioning remote-effect fence is active',
                (string) $legacyRefundGuard->action_statement,
            );

            self::assertFalse(DB::getSchemaBuilder()->hasColumn('quotes', 'action_snapshot'));
            self::assertFalse(DB::getSchemaBuilder()->hasTable('service_paid_mutation_authorities'));
            $this->assertLegacyMutationDatabaseSurface();
        } finally {
            $quoteMigration->up();
            $paidMutationMigration->up();
        }

        self::assertTrue(DB::getSchemaBuilder()->hasColumn('quotes', 'action_snapshot'));
        self::assertTrue(DB::getSchemaBuilder()->hasTable('service_paid_mutation_authorities'));
        $this->assertPaidMutationDatabaseSurface();
        $operationGuard = DB::selectOne(<<<'SQL'
SELECT ACTION_STATEMENT AS action_statement
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME = 'provisioning_operations_insert_guard'
LIMIT 1
SQL);
        self::assertNotNull($operationGuard);
        self::assertStringContainsString('service_paid_mutation_queue_v1', (string) $operationGuard->action_statement);

        $refundGuard = DB::selectOne(<<<'SQL'
SELECT ACTION_STATEMENT AS action_statement
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME = 'purchase_refunds_provisioning_invalidation'
LIMIT 1
SQL);
        self::assertNotNull($refundGuard);
        self::assertStringContainsString('Initial provisioning remote-effect fence is active', (string) $refundGuard->action_statement);
        self::assertStringContainsString('Paid Service mutation remote-effect fence is active', (string) $refundGuard->action_statement);
        self::assertStringContainsString('service_paid_mutation_authorities', (string) $refundGuard->action_statement);
    }

    public function test_paid_authority_rollback_refuses_to_strand_service_package_quote_authority(): void
    {
        $scenario = $this->scenario('paid-rollback-quote-fence');
        $this->paidPackageQuote($scenario, 'aq-extra-10gb', 'paid-rollback-quote-fence');

        /** @var Migration $paidMutationMigration */
        $paidMutationMigration = require database_path('migrations/2026_08_20_000110_enable_paid_service_mutation_authority.php');

        try {
            $paidMutationMigration->down();
            self::fail('Paid mutation authority rollback must fail before DDL when Service package Quote authority cannot roll back.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Cannot roll back paid Service mutation authority while Service-operation Quotes exist.',
                $exception->getMessage(),
            );
        }

        $this->assertPaidMutationDatabaseSurface();
    }

    public function test_paid_service_package_quotes_snapshot_all_supported_actions(): void
    {
        $scenario = $this->scenario('paid-package-quotes');
        $this->verifySyntheticTargetCapability($scenario['target_id'], 'atomic_service_entitlements', 'paid-package-quotes');
        $cases = [
            ['aq-renew-30d', QuoteAction::Renew, 600_000, 30, null],
            ['aq-extra-10gb', QuoteAction::AddData, 500_000, null, 10 * 1024 * 1024 * 1024],
            ['aq-extra-7d', QuoteAction::AddDays, 200_000, 7, null],
            ['aq-extra-5gb-7d', QuoteAction::AddDataDays, 450_000, 7, 5 * 1024 * 1024 * 1024],
        ];

        foreach ($cases as [$packageCode, $action, $priceIrr, $durationDays, $dataBytes]) {
            $quote = $this->paidPackageQuote($scenario, $packageCode, 'snapshot-'.$action->value);

            self::assertSame($action, $quote->action);
            self::assertSame($priceIrr, $quote->basePriceIrr);
            self::assertSame($priceIrr, $quote->finalPriceIrr);
            self::assertNotNull($quote->servicePackage);
            self::assertSame($action, $quote->servicePackage->action);
            self::assertSame($scenario['service_id'], $quote->servicePackage->serviceSubscriptionId);
            self::assertSame($scenario['service_public_id'], $quote->servicePackage->serviceSubscriptionPublicId);
            self::assertSame($scenario['target_id'], $quote->servicePackage->serviceTargetId);
            self::assertSame($packageCode, $quote->servicePackage->packageCode);
            self::assertSame($durationDays, $quote->servicePackage->durationDays);
            self::assertSame($dataBytes, $quote->servicePackage->dataBytes);
        }
    }

    public function test_combined_paid_package_requires_explicit_verified_atomic_capability_before_quote(): void
    {
        $scenario = $this->scenario('paid-combined-capability');

        try {
            $this->paidPackageQuote($scenario, 'aq-extra-5gb-7d', 'paid-combined-capability-denied');
            self::fail('Combined paid package must not be quoted without explicit verified atomic entitlement capability.');
        } catch (DomainException $exception) {
            self::assertSame('Service target does not have the verified capability required by this package.', $exception->getMessage());
        }

        $this->verifySyntheticTargetCapability($scenario['target_id'], 'atomic_service_entitlements', 'paid-combined-capability');
        $quote = $this->paidPackageQuote($scenario, 'aq-extra-5gb-7d', 'paid-combined-capability-allowed');

        self::assertSame(QuoteAction::AddDataDays, $quote->action);
    }

    public function test_paid_add_data_reuses_settled_purchase_and_replays_one_remote_mutation(): void
    {
        $scenario = $this->scenario('paid-add-data');
        $quote = $this->paidPackageQuote($scenario, 'aq-extra-10gb', 'paid-add-data');
        $settlement = $this->capturePaidPackageQuote($quote, 'paid-add-data');
        $order = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('paid-add-data-order'),
        );

        try {
            $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
                $order->orderPublicId,
                $this->purchaseOrderCorrelation('paid-add-data-initial'),
            );
            self::fail('Paid add-on Order must not create a second Service through initial provisioning.');
        } catch (DomainException) {
            self::assertSame(1, DB::table('service_subscriptions')->where('user_id', $scenario['user_id'])->count());
        }

        $queueService = $this->app->make(ServicePurchaseMutationQueueService::class);
        $queue = $queueService->queueFromSettlement(
            $settlement->settlementPublicId,
            'paid-add-data-request-0001',
            $this->purchaseOrderCorrelation('paid-add-data-queue'),
        );
        $replay = $queueService->queueFromSettlement(
            $settlement->settlementPublicId,
            'paid-add-data-request-0001',
            $this->purchaseOrderCorrelation('paid-add-data-replay'),
        );

        self::assertSame(ServiceMutationType::AddData, $queue->type);
        self::assertSame(1, $queue->generation);
        self::assertFalse($queue->replayed);
        self::assertTrue($replay->replayed);
        self::assertSame($queue->operationPublicId, $replay->operationPublicId);
        self::assertSame(1, DB::table('service_paid_mutation_authorities')->count());

        $executed = $this->mutationExecutor()->execute($queue->operationPublicId);
        self::assertSame(ProvisioningState::Succeeded, $executed->state);
        self::assertSame(['lookup_remote_id', 'add_data_allowance'], $scenario['adapter']->calls);
        self::assertSame([0, 0], $scenario['adapter']->transactionLevels);
        self::assertSame(30 * 1024 * 1024 * 1024, $scenario['adapter']->lastDataAllowanceBytes);
        self::assertSame(DataAllowanceMode::Set, $scenario['adapter']->lastDataAllowanceMode);

        $authority = DB::table('service_paid_mutation_authorities')->first([
            'remote_snapshot_hash', 'target_expires_at', 'target_data_limit_bytes', 'targets_resolved_at',
        ]);
        self::assertNotNull($authority);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) $authority->remote_snapshot_hash);
        self::assertNull($authority->target_expires_at);
        self::assertSame(30 * 1024 * 1024 * 1024, (int) $authority->target_data_limit_bytes);
        self::assertNotNull($authority->targets_resolved_at);

        $operation = DB::table('provisioning_operations')->where('public_id', $queue->operationPublicId)->first([
            'state', 'remote_effect_started_at', 'remote_effect_completed_at',
        ]);
        self::assertNotNull($operation);
        self::assertSame(ProvisioningState::Succeeded->value, $operation->state);
        self::assertNotNull($operation->remote_effect_started_at);
        self::assertNotNull($operation->remote_effect_completed_at);

        $terminalReplay = $this->mutationExecutor()->execute($queue->operationPublicId);
        self::assertTrue($terminalReplay->replayed);
        self::assertSame(['lookup_remote_id', 'add_data_allowance'], $scenario['adapter']->calls);
    }

    public function test_paid_expiry_mutations_use_one_remote_effect_and_persist_the_exact_absolute_target(): void
    {
        foreach ([
            ['renew', 'aq-renew-30d', ServiceMutationType::Renew],
            ['add-days', 'aq-extra-7d', ServiceMutationType::AddDays],
        ] as [$suffix, $packageCode, $type]) {
            $scenario = $this->scenario('paid-'.$suffix);
            $quote = $this->paidPackageQuote($scenario, $packageCode, 'paid-'.$suffix);
            $settlement = $this->capturePaidPackageQuote($quote, 'paid-'.$suffix);
            $this->app->make(PurchaseOrderService::class)->createFromSettlement(
                $settlement->settlementPublicId,
                $this->purchaseOrderCorrelation('paid-'.$suffix.'-order'),
            );

            $queue = $this->app->make(ServicePurchaseMutationQueueService::class)->queueFromSettlement(
                $settlement->settlementPublicId,
                'paid-'.$suffix.'-request-0001',
                $this->purchaseOrderCorrelation('paid-'.$suffix.'-queue'),
            );
            self::assertSame($type, $queue->type);

            $executed = $this->mutationExecutor()->execute($queue->operationPublicId);
            self::assertSame(ProvisioningState::Succeeded, $executed->state);
            self::assertSame(['lookup_remote_id', 'update_expiry'], $scenario['adapter']->calls);
            self::assertSame([0, 0], $scenario['adapter']->transactionLevels);
            self::assertNotNull($scenario['adapter']->lastExpiryAt);

            $authority = DB::table('service_paid_mutation_authorities')
                ->where('provisioning_operation_id', DB::table('provisioning_operations')
                    ->where('public_id', $queue->operationPublicId)->value('id'))
                ->first(['target_expires_at', 'target_data_limit_bytes', 'targets_resolved_at']);
            self::assertNotNull($authority);
            self::assertSame(
                $scenario['adapter']->lastExpiryAt->format('Y-m-d H:i:s.u'),
                (string) $authority->target_expires_at,
            );
            self::assertNull($authority->target_data_limit_bytes);
            self::assertNotNull($authority->targets_resolved_at);
        }
    }

    public function test_concurrent_paid_queue_replays_one_settlement_authority_and_generation(): void
    {
        $scenario = $this->scenario('paid-queue-contention');
        $quote = $this->paidPackageQuote($scenario, 'aq-extra-10gb', 'paid-queue-contention');
        $settlement = $this->capturePaidPackageQuote($quote, 'paid-queue-contention');
        $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('paid-queue-contention-order'),
        );
        $payloads = [
            [
                'purchase_settlement_public_id' => $settlement->settlementPublicId,
                'request_key' => 'paid-queue-contention-request-0001',
                'correlation_id' => $this->purchaseOrderCorrelation('paid-queue-contention-a'),
            ],
            [
                'purchase_settlement_public_id' => $settlement->settlementPublicId,
                'request_key' => 'paid-queue-contention-request-0001',
                'correlation_id' => $this->purchaseOrderCorrelation('paid-queue-contention-b'),
            ],
        ];

        $results = $this->runConcurrentMutationQueues($payloads, '--service-paid-mutation-contention-worker');

        self::assertTrue((bool) ($results[0]['ok'] ?? false), json_encode($results, JSON_THROW_ON_ERROR));
        self::assertTrue((bool) ($results[1]['ok'] ?? false), json_encode($results, JSON_THROW_ON_ERROR));
        self::assertSame(1, $results[0]['result']['generation']);
        self::assertSame(1, $results[1]['result']['generation']);
        self::assertSame($results[0]['result']['operation_public_id'], $results[1]['result']['operation_public_id']);
        $replayStatuses = [
            (bool) $results[0]['result']['replayed'],
            (bool) $results[1]['result']['replayed'],
        ];
        sort($replayStatuses);
        self::assertSame([false, true], $replayStatuses);
        self::assertSame(1, DB::table('service_paid_mutation_authorities')->count());
        self::assertSame(1, (int) DB::table('service_subscriptions')->where('id', $scenario['service_id'])->value('mutation_generation'));
    }

    public function test_wallet_funded_paid_addon_reuses_purchase_settlement_authority(): void
    {
        $scenario = $this->scenario('paid-wallet-add-data');
        $quote = $this->paidPackageQuote($scenario, 'aq-extra-10gb', 'paid-wallet-add-data');
        $this->seed(WalletFinancialFoundationSeeder::class);

        $administratorId = $this->ownerAdministrator();
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'paid.service.wallet.method.000001',
            $administratorId,
            'wallet',
            true,
            false,
            1,
            'Wallet funded paid Service package test method.',
            $this->purchaseOrderCorrelation('paid-wallet-method'),
        );
        $eligibility->recordHealth(
            'paid.service.wallet.health.000001',
            $administratorId,
            'wallet',
            true,
            $this->purchaseOrderClock->value->modify('+10 minutes'),
            'Healthy wallet paid Service package test observation.',
            $this->purchaseOrderCorrelation('paid-wallet-health'),
        );
        $decision = $eligibility->evaluate(
            'paid.service.wallet.eligibility.000001',
            $scenario['user_id'],
            $quote->quotePublicId,
        );
        $walletAccountId = $this->fundedPaidServiceWallet(
            $scenario['user_id'],
            $quote->finalPriceIrr,
            'paid-wallet-add-data',
        );

        $wallet = $this->app->make(PurchaseWalletPaymentService::class);
        $intent = $wallet->reserve(
            'paid.service.wallet.intent.000001',
            $scenario['user_id'],
            $walletAccountId,
            $quote->quotePublicId,
            $decision->publicId,
            $this->purchaseOrderCorrelation('paid-wallet-reserve'),
        );
        self::assertSame('purchase', DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->value('purpose'));
        self::assertSame('wallet', DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->value('provider_code'));

        $order = $wallet->capture(
            $intent->intentPublicId,
            $this->purchaseOrderCorrelation('paid-wallet-capture'),
        );
        self::assertSame('wallet', DB::table('purchase_settlements')
            ->where('public_id', $order->purchaseSettlementPublicId)->value('provider_code'));

        $queue = $this->app->make(ServicePurchaseMutationQueueService::class)->queueFromSettlement(
            $order->purchaseSettlementPublicId,
            'paid-wallet-add-data-request-0001',
            $this->purchaseOrderCorrelation('paid-wallet-queue'),
        );
        $receipt = $this->mutationExecutor()->execute($queue->operationPublicId);

        self::assertSame(ProvisioningState::Succeeded, $receipt->state);
        self::assertSame(['lookup_remote_id', 'add_data_allowance'], $scenario['adapter']->calls);
        self::assertSame(30 * 1024 * 1024 * 1024, $scenario['adapter']->lastDataAllowanceBytes);
    }

    public function test_paid_provider_exception_after_boundary_is_quarantined_with_durable_absolute_target(): void
    {
        $scenario = $this->scenario('paid-provider-exception');
        $quote = $this->paidPackageQuote($scenario, 'aq-extra-10gb', 'paid-provider-exception');
        $settlement = $this->capturePaidPackageQuote($quote, 'paid-provider-exception');
        $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('paid-provider-exception-order'),
        );
        $queue = $this->app->make(ServicePurchaseMutationQueueService::class)->queueFromSettlement(
            $settlement->settlementPublicId,
            'paid-provider-exception-request-0001',
            $this->purchaseOrderCorrelation('paid-provider-exception-queue'),
        );
        $scenario['adapter']->throwOnMutation = true;

        $receipt = $this->mutationExecutor()->execute($queue->operationPublicId);

        self::assertSame(
            ProvisioningState::UncertainRemoteResult,
            $receipt->state,
            $this->operationOutcome($queue->operationPublicId),
        );
        self::assertSame(['lookup_remote_id', 'add_data_allowance'], $scenario['adapter']->calls);
        self::assertSame([0, 0], $scenario['adapter']->transactionLevels);
        self::assertSame(30 * 1024 * 1024 * 1024, $scenario['adapter']->lastDataAllowanceBytes);

        $authority = DB::table('service_paid_mutation_authorities')->first([
            'remote_snapshot_hash', 'target_expires_at', 'target_data_limit_bytes', 'targets_resolved_at',
        ]);
        self::assertNotNull($authority);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) $authority->remote_snapshot_hash);
        self::assertNull($authority->target_expires_at);
        self::assertSame(30 * 1024 * 1024 * 1024, (int) $authority->target_data_limit_bytes);
        self::assertNotNull($authority->targets_resolved_at);

        $operation = DB::table('provisioning_operations')->where('public_id', $queue->operationPublicId)->first([
            'state', 'last_result_code', 'remote_effect_started_at', 'remote_effect_completed_at',
        ]);
        self::assertNotNull($operation);
        self::assertSame(ProvisioningState::UncertainRemoteResult->value, $operation->state);
        self::assertSame('remote_effect_exception', $operation->last_result_code);
        self::assertNotNull($operation->remote_effect_started_at);
        self::assertNotNull($operation->remote_effect_completed_at);

        try {
            $this->mutationExecutor()->execute($queue->operationPublicId);
            self::fail('Uncertain paid Service mutation must require reconciliation before another provider attempt.');
        } catch (DomainException) {
            self::assertSame(['lookup_remote_id', 'add_data_allowance'], $scenario['adapter']->calls);
        }
    }

    public function test_paid_mutation_rejects_service_changed_after_immutable_quote(): void
    {
        $scenario = $this->scenario('paid-stale-service');
        $quote = $this->paidPackageQuote($scenario, 'aq-extra-7d', 'paid-stale-service');

        $suspend = $this->queueMutation($scenario, ServiceMutationType::Suspend, 'paid-stale-service-suspend');
        $suspended = $this->mutationExecutor()->execute($suspend->operationPublicId);
        self::assertSame(ProvisioningState::Succeeded, $suspended->state);
        self::assertSame(1, (int) DB::table('service_subscriptions')->where('id', $scenario['service_id'])->value('lifecycle_version'));

        $settlement = $this->capturePaidPackageQuote($quote, 'paid-stale-service');
        $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('paid-stale-service-order'),
        );

        try {
            $this->app->make(ServicePurchaseMutationQueueService::class)->queueFromSettlement(
                $settlement->settlementPublicId,
                'paid-stale-service-request-0001',
                $this->purchaseOrderCorrelation('paid-stale-service-queue'),
            );
            self::fail('Paid mutation must reject a Service changed after the immutable Quote snapshot.');
        } catch (DomainException $exception) {
            self::assertSame(
                'Service changed after the paid package Quote and requires reconciliation before mutation.',
                $exception->getMessage(),
            );
        }
        self::assertSame(0, DB::table('service_paid_mutation_authorities')->count());
    }

    public function test_combined_paid_addon_fails_before_boundary_without_atomic_adapter(): void
    {
        $scenario = $this->scenario('paid-combined-non-atomic');
        $this->verifySyntheticTargetCapability($scenario['target_id'], 'atomic_service_entitlements', 'paid-combined-non-atomic');
        $quote = $this->paidPackageQuote($scenario, 'aq-extra-5gb-7d', 'paid-combined-non-atomic');
        $settlement = $this->capturePaidPackageQuote($quote, 'paid-combined-non-atomic');
        $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('paid-combined-non-atomic-order'),
        );
        $queue = $this->app->make(ServicePurchaseMutationQueueService::class)->queueFromSettlement(
            $settlement->settlementPublicId,
            'paid-combined-request-0001',
            $this->purchaseOrderCorrelation('paid-combined-queue'),
        );

        $receipt = $this->mutationExecutor()->execute($queue->operationPublicId);

        self::assertSame(ProvisioningState::FailedFinal, $receipt->state);
        self::assertSame([], $scenario['adapter']->calls);
        $operation = DB::table('provisioning_operations')->where('public_id', $queue->operationPublicId)->first([
            'last_result_code', 'remote_effect_started_at', 'remote_effect_completed_at',
        ]);
        self::assertNotNull($operation);
        self::assertSame('atomic_combined_mutation_unavailable', $operation->last_result_code);
        self::assertNull($operation->remote_effect_started_at);
        self::assertNull($operation->remote_effect_completed_at);
        self::assertNull(DB::table('service_paid_mutation_authorities')->value('targets_resolved_at'));
        self::assertSame('paid', DB::table('orders')->where('purchase_settlement_id', $settlement->settlementId)->value('state'));
    }

    public function test_refund_after_queue_before_claim_definitively_rejects_paid_mutation_without_provider_io(): void
    {
        $scenario = $this->scenario('paid-refund-before-claim');
        $quote = $this->paidPackageQuote($scenario, 'aq-extra-7d', 'paid-refund-before-claim');
        $settlement = $this->capturePaidPackageQuote($quote, 'paid-refund-before-claim');
        $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('paid-refund-before-claim-order'),
        );
        $queue = $this->app->make(ServicePurchaseMutationQueueService::class)->queueFromSettlement(
            $settlement->settlementPublicId,
            'paid-refund-before-claim-request-0001',
            $this->purchaseOrderCorrelation('paid-refund-before-claim-queue'),
        );

        $this->recordFullPaidPackageRefund($settlement, 'paid-refund-before-claim');
        $receipt = $this->mutationExecutor()->execute($queue->operationPublicId);

        self::assertSame(ProvisioningState::FailedFinal, $receipt->state);
        self::assertSame([], $scenario['adapter']->calls);
        self::assertSame(1, DB::table('provisioning_financial_invalidations')
            ->where('purchase_settlement_id', $settlement->settlementId)->count());
        $operation = DB::table('provisioning_operations')->where('public_id', $queue->operationPublicId)->first([
            'state', 'attempt_count', 'last_result_code', 'remote_effect_started_at', 'remote_effect_completed_at',
        ]);
        self::assertNotNull($operation);
        self::assertSame(ProvisioningState::FailedFinal->value, $operation->state);
        self::assertSame(0, (int) $operation->attempt_count);
        self::assertSame('paid_mutation_financially_invalidated', $operation->last_result_code);
        self::assertNull($operation->remote_effect_started_at);
        self::assertNull($operation->remote_effect_completed_at);
    }

    public function test_refund_is_blocked_while_paid_service_mutation_holds_running_effect_fence(): void
    {
        $scenario = $this->scenario('paid-refund-fence');
        $quote = $this->paidPackageQuote($scenario, 'aq-extra-7d', 'paid-refund-fence');
        $settlement = $this->capturePaidPackageQuote($quote, 'paid-refund-fence');
        $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('paid-refund-fence-order'),
        );
        $queue = $this->app->make(ServicePurchaseMutationQueueService::class)->queueFromSettlement(
            $settlement->settlementPublicId,
            'paid-refund-fence-request-0001',
            $this->purchaseOrderCorrelation('paid-refund-fence-queue'),
        );
        $this->simulateClaim($queue);

        try {
            $this->recordFullPaidPackageRefund($settlement, 'paid-refund-fence');
            self::fail('Refund must be rejected while a paid Service mutation holds the running effect fence.');
        } catch (QueryException) {
            self::assertSame(0, DB::table('purchase_refunds')->where('purchase_settlement_id', $settlement->settlementId)->count());
            self::assertSame(0, DB::table('provisioning_financial_invalidations')->where('purchase_settlement_id', $settlement->settlementId)->count());
            self::assertSame('captured', DB::table('payment_intents')->where('public_id', $settlement->intentPublicId)->value('state'));
        }
    }

    /** @param array{service_id:int,service_public_id:string,target_id:int,offering_id:int,user_id:int,adapter:ServiceMutationTestPanelAdapter} $scenario */
    private function paidPackageQuote(array $scenario, string $packageCode, string $suffix): QuoteReceipt
    {
        return $this->app->make(QuoteService::class)->create(
            'service.package.quote.'.$suffix,
            $scenario['user_id'],
            $scenario['offering_id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                $this->purchaseOrderClock->value->modify('+30 minutes'),
            ),
            $this->purchaseOrderCorrelation('paid-package-quote-'.$suffix),
            null,
            new ServicePackageQuoteContext($scenario['service_public_id'], $packageCode),
        );
    }

    private function capturePaidPackageQuote(QuoteReceipt $quote, string $suffix): PurchaseSettlementReceipt
    {
        $methodCode = 'paid_service_gateway_'.$suffix;
        $administratorId = $this->ownerAdministrator();
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'paid.service.method.'.$suffix,
            $administratorId,
            $methodCode,
            true,
            false,
            1,
            'Paid Service package test method.',
            $this->purchaseOrderCorrelation('paid-method-'.$suffix),
        );
        $eligibility->recordHealth(
            'paid.service.health.'.$suffix,
            $administratorId,
            $methodCode,
            true,
            $this->purchaseOrderClock->value->modify('+10 minutes'),
            'Healthy paid Service package test observation.',
            $this->purchaseOrderCorrelation('paid-health-'.$suffix),
        );
        $decision = $eligibility->evaluate(
            'paid.service.eligibility.'.$suffix,
            $quote->userId,
            $quote->quotePublicId,
        );
        self::assertSame($quote->action->value, DB::table('payment_method_eligibility_decisions')
            ->where('id', $decision->decisionId)->value('action_snapshot'));

        $intent = $this->app->make(PurchasePaymentIntentService::class)->create(
            'paid.service.intent.'.$suffix,
            $quote->userId,
            $quote->quotePublicId,
            $decision->publicId,
            $methodCode,
            $this->purchaseOrderCorrelation('paid-intent-'.$suffix),
        );
        DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->update([
            'state' => 'submitted',
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]);

        return $this->app->make(PurchaseSettlementService::class)->capture(
            $intent->intentPublicId,
            $methodCode,
            new VerifiedPaymentEvent(
                'evt-paid-service-'.$suffix,
                hash('sha256', 'paid-service-provider-event:'.$suffix),
                new PaymentEvidence(
                    ProviderOperationOutcome::Success,
                    PaymentEvidenceAuthority::Authoritative,
                    PaymentTransactionStatus::Settled,
                    'txn-paid-service-'.$suffix,
                    'evt-paid-service-'.$suffix,
                    Money::irr($intent->amount->amount()),
                    $this->purchaseOrderClock->value,
                    $this->purchaseOrderClock->value,
                    hash('sha256', 'paid-service-provider-evidence:'.$suffix),
                    ['provider_reference' => 'txn-paid-service-'.$suffix],
                ),
            ),
            $this->purchaseOrderCorrelation('paid-settlement-'.$suffix),
        );
    }

    private function fundedPaidServiceWallet(int $userId, int $amountIrr, string $suffix): int
    {
        $now = now('UTC');
        $assetId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'system.paid.service.wallet.asset.'.$suffix,
            'account_class' => 'asset',
            'owner_user_id' => null,
            'wallet_bucket' => null,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $walletId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'wallet.cash.paid.service.'.$suffix.'.'.$userId,
            'account_class' => 'liability',
            'owner_user_id' => $userId,
            'wallet_bucket' => 'cash',
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->app->make(LedgerPostingService::class)->post(
            'ledger.paid.service.wallet.fund.'.$suffix.'.000001',
            'paid_service_wallet_test_funding',
            $this->purchaseOrderCorrelation('paid-wallet-fund-'.$suffix),
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amountIrr)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amountIrr)),
            ],
            'test_fixture',
            'paid-service-wallet-'.$suffix,
        );

        return $walletId;
    }

    private function recordFullPaidPackageRefund(PurchaseSettlementReceipt $settlement, string $suffix): void
    {
        $occurredAt = $this->purchaseOrderClock->value->modify('+5 minutes');
        $this->app->make(PurchaseRefundService::class)->record(
            'paid.service.refund.'.$suffix,
            $settlement->settlementPublicId,
            $settlement->providerCode,
            new VerifiedPaymentEvent(
                'evt-paid-service-refund-'.$suffix,
                hash('sha256', 'paid-service-refund-event:'.$suffix),
                new PaymentEvidence(
                    ProviderOperationOutcome::Success,
                    PaymentEvidenceAuthority::Authoritative,
                    PaymentTransactionStatus::Refunded,
                    'refund-paid-service-'.$suffix,
                    'evt-paid-service-refund-'.$suffix,
                    Money::irr($settlement->amount->amount()),
                    $occurredAt,
                    $occurredAt,
                    hash('sha256', 'paid-service-refund-evidence:'.$suffix),
                    ['provider_reference' => 'refund-paid-service-'.$suffix],
                ),
            ),
            $this->purchaseOrderCorrelation('paid-refund-'.$suffix),
        );
    }

    /**
     * @return array{service_id:int,service_public_id:string,target_id:int,offering_id:int,user_id:int,adapter:ServiceMutationTestPanelAdapter}
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
            'offering_id' => $offeringId,
            'user_id' => $userId,
            'adapter' => $adapter,
        ];
    }

    /** @param array{service_id:int,service_public_id:string,target_id:int,offering_id:int,user_id:int,adapter:ServiceMutationTestPanelAdapter} $scenario */
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

    private function operationOutcome(string $operationPublicId): string
    {
        $operation = DB::table('provisioning_operations')->where('public_id', $operationPublicId)->first([
            'state', 'last_result_code', 'last_result_message', 'remote_effect_started_at', 'remote_effect_completed_at',
        ]);

        return json_encode($operation, JSON_THROW_ON_ERROR);
    }

    private function mutationExecutor(): ServiceMutationExecutor
    {
        return $this->app->make(ServiceMutationExecutor::class);
    }

    /**
     * @param  list<array<string, string>>  $payloads
     * @return list<array<string, mixed>>
     */
    private function runConcurrentMutationQueues(
        array $payloads,
        string $workerMode = '--service-mutation-contention-worker',
    ): array {
        if (! in_array($workerMode, ['--service-mutation-contention-worker', '--service-paid-mutation-contention-worker'], true)) {
            throw new RuntimeException('Unsupported Service mutation contention worker mode.');
        }

        $workers = [];
        try {
            foreach ($payloads as $payload) {
                $pipes = [];
                $process = proc_open([
                    PHP_BINARY,
                    '-d',
                    'pcov.enabled=0',
                    __FILE__,
                    $workerMode,
                    base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                ], [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes, dirname(__DIR__, 2));
                if (! is_resource($process)) {
                    throw new RuntimeException('Unable to start Service mutation contention worker.');
                }
                /** @var array{0:resource,1:resource,2:resource} $pipes */
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $workers[] = ['process' => $process, 'pipes' => $pipes];
            }

            foreach ($workers as $index => $worker) {
                if ($this->readWorkerLine($worker, 'readiness', $index) !== "READY\n") {
                    throw new RuntimeException('Service mutation contention worker returned an invalid readiness marker.');
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
                    throw new RuntimeException('Service mutation contention worker failed: '.$stderr);
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
                throw new RuntimeException('Unable to wait for Service mutation contention worker output.');
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
                throw new RuntimeException('Service mutation contention worker exited before '.$phase.' output: '.$stderr);
            }
        }

        throw new RuntimeException(sprintf(
            'Service mutation contention worker %d timed out during %s after %d seconds: %s',
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

    private function assertPaidMutationDatabaseSurface(): void
    {
        $operationInsertGuard = $this->triggerStatement('provisioning_operations_insert_guard');
        $serviceUpdateGuard = $this->triggerStatement('service_subscriptions_update_guard');

        self::assertStringContainsString('add_data_days', $this->checkConstraintClause('provisioning_operations', 'provisioning_operations_type_chk'));
        self::assertStringContainsString('add_data_days', $this->checkConstraintClause('provisioning_operations', 'provisioning_operations_provisioning_exact_text_chk'));
        self::assertStringContainsString('service_paid_mutation_queue_v1', $operationInsertGuard);
        self::assertStringContainsString('order_source_authorizations', $operationInsertGuard);
        self::assertStringContainsString("action_snapshot = 'purchase'", $operationInsertGuard);
        self::assertStringContainsString('service_paid_mutation_queue_v1', $serviceUpdateGuard);
        self::assertStringContainsString('service_operational_authority_capability', $serviceUpdateGuard);
        self::assertStringContainsString('add_data_days', $this->triggerStatement('provisioning_remote_effect_events_insert_guard'));
        self::assertStringContainsString('add_data_days', $this->triggerStatement('provisioning_operations_delivery_effect_insert_guard'));
        self::assertStringContainsString('add_data_days', $this->triggerStatement('provisioning_operation_histories_insert_guard'));
        self::assertFalse($this->triggerExists('provisioning_operations_paid_mutation_upgrade_fence'));
    }

    private function assertLegacyMutationDatabaseSurface(): void
    {
        $operationInsertGuard = $this->triggerStatement('provisioning_operations_insert_guard');
        $serviceUpdateGuard = $this->triggerStatement('service_subscriptions_update_guard');

        self::assertStringNotContainsString('add_data_days', $this->checkConstraintClause('provisioning_operations', 'provisioning_operations_type_chk'));
        self::assertStringNotContainsString('add_data_days', $this->checkConstraintClause('provisioning_operations', 'provisioning_operations_provisioning_exact_text_chk'));
        self::assertStringNotContainsString('service_paid_mutation_queue_v1', $operationInsertGuard);
        self::assertStringContainsString('order_source_authorizations', $operationInsertGuard);
        self::assertStringNotContainsString('service_paid_mutation_queue_v1', $serviceUpdateGuard);
        self::assertStringContainsString('service_operational_authority_capability', $serviceUpdateGuard);
        self::assertStringNotContainsString('add_data_days', $this->triggerStatement('provisioning_remote_effect_events_insert_guard'));
        self::assertStringNotContainsString('add_data_days', $this->triggerStatement('provisioning_operations_delivery_effect_insert_guard'));
        self::assertStringNotContainsString('add_data_days', $this->triggerStatement('provisioning_operation_histories_insert_guard'));
        self::assertFalse($this->triggerExists('provisioning_operations_paid_mutation_upgrade_fence'));
    }

    private function checkConstraintClause(string $table, string $constraint): string
    {
        $row = DB::selectOne(
            'SELECT CHECK_CLAUSE AS check_clause FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $constraint],
        );
        self::assertNotNull($row);
        self::assertIsString($row->check_clause);

        return $row->check_clause;
    }

    private function triggerStatement(string $trigger): string
    {
        $row = DB::selectOne(
            'SELECT ACTION_STATEMENT AS action_statement FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$trigger],
        );
        self::assertNotNull($row);
        self::assertIsString($row->action_statement);

        return $row->action_statement;
    }

    private function triggerExists(string $trigger): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$trigger],
        );

        return $row !== null && (int) $row->aggregate === 1;
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

    private function verifySyntheticTargetCapability(int $targetId, string $capability, string $suffix): void
    {
        $now = $this->purchaseOrderTimestamp();
        $evidenceHash = hash('sha256', 'service-mutation-synthetic-capability-'.$suffix.'-'.$capability);
        DB::table('panel_target_capabilities')->insert([
            'panel_service_target_id' => $targetId,
            'capability_code' => $capability,
            'verification_status' => 'verified',
            'evidence_hash' => $evidenceHash,
            'verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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
