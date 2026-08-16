<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use App\Modules\Catalog\Application\PlanOfferingRoutePolicyService;
use App\Modules\Catalog\Domain\PlanOfferingRouteDefinition;
use App\Modules\Catalog\Domain\PlanOfferingRoutePolicyDefinition;
use App\Modules\Catalog\Domain\PlanOfferingRouteType;
use App\Modules\Orders\Application\PurchaseOrderReceipt;
use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Panels\Application\CapacityOperationContext;
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
use App\Modules\Panels\Application\TargetCapacityAllocator;
use App\Modules\Panels\Domain\PanelProviderType;
use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\PurchaseRefundReceipt;
use App\Modules\Payments\Application\PurchaseRefundService;
use App\Modules\Payments\Application\PurchaseSettlementReceipt;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Provisioning\Application\InitialProvisioningExecutor;
use App\Modules\Provisioning\Application\InitialProvisioningOutboxHandler;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use App\Modules\Provisioning\Application\InitialProvisioningRecoveryService;
use App\Modules\Provisioning\Application\ProvisioningQueueReceipt;
use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxMessage;
use App\Shared\Domain\Money;
use Closure;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PanelsAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;
use Throwable;

final class InitialProvisioningTestPanelAdapter implements PanelAdapter
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<int> */
    public array $transactionLevels = [];

    public ?PanelOperationResult $forcedCreateResult = null;

    public ?PanelCreateServiceRequest $lastCreateRequest = null;

    public ?Closure $beforeCreate = null;

    /** @var array<string, RemoteServiceSnapshot> */
    private array $servicesByUsername = [];

    public function resetCalls(): void
    {
        $this->calls = [];
        $this->transactionLevels = [];
        $this->lastCreateRequest = null;
    }

    public function serviceCount(): int
    {
        return count($this->servicesByUsername);
    }

    public function seed(RemoteServiceSnapshot $service): void
    {
        $this->servicesByUsername[$service->username] = $service;
    }

    public function testConnection(): PanelOperationResult
    {
        return new PanelOperationResult(PanelOperationOutcome::Success, null, 'test_connection_ok', 'Test panel is healthy.');
    }

    public function capabilities(): PanelCapabilities
    {
        return new PanelCapabilities(
            'fake',
            '1.0.0',
            ['authoritative_username_lookup', 'create_service'],
            ['fake-default'],
        );
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
        $this->lastCreateRequest = $request;
        if ($this->beforeCreate !== null) {
            ($this->beforeCreate)();
        }
        if ($this->forcedCreateResult !== null) {
            return $this->forcedCreateResult;
        }

        $hash = $this->createEquivalenceHash($request);
        $service = new RemoteServiceSnapshot(
            'test-'.substr(hash('sha256', $request->idempotencyKey), 0, 24),
            $request->username,
            PanelServiceStatus::Active,
            $request->dataLimitBytes,
            0,
            $request->expiresAt,
            $hash,
            $hash,
        );
        $this->seed($service);

        return new PanelOperationResult(
            PanelOperationOutcome::Success,
            $service,
            'test_service_created',
            'Test service created.',
        );
    }

    public function fetchStatus(string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by initial provisioning remote-effect tests.');
    }

    public function updateExpiry(string $idempotencyKey, string $remoteId, DateTimeImmutable $expiresAt): PanelOperationResult
    {
        throw new LogicException('Not used by initial provisioning remote-effect tests.');
    }

    public function updateDataAllowance(
        string $idempotencyKey,
        string $remoteId,
        int $bytes,
        DataAllowanceMode $mode,
    ): PanelOperationResult {
        throw new LogicException('Not used by initial provisioning remote-effect tests.');
    }

    public function resetUsage(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by initial provisioning remote-effect tests.');
    }

    public function suspend(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by initial provisioning remote-effect tests.');
    }

    public function activate(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by initial provisioning remote-effect tests.');
    }

    public function delete(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by initial provisioning remote-effect tests.');
    }

    public function rotateSubscriptionLink(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by initial provisioning remote-effect tests.');
    }

    public function getDeliveryArtifacts(string $remoteId): SensitiveDeliveryArtifacts
    {
        throw new LogicException('Not used by initial provisioning remote-effect tests.');
    }

    public function synchronize(string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by initial provisioning remote-effect tests.');
    }

    public function listCompatibleTargets(): array
    {
        return [[
            'id' => 'fake-default',
            'type' => 'inbound',
            'name' => 'Test target',
            'capabilities' => ['create_service'],
        ]];
    }

    private function record(string $call): void
    {
        $this->calls[] = $call;
        $this->transactionLevels[] = DB::connection()->transactionLevel();
    }
}

final readonly class InitialProvisioningTestPanelAdapterFactory implements PanelAdapterFactory
{
    public function __construct(private InitialProvisioningTestPanelAdapter $adapter) {}

    public function providerType(): PanelProviderType
    {
        return PanelProviderType::Fake;
    }

    public function make(PanelAdapterSession $session): PanelAdapter
    {
        return $this->adapter;
    }
}

/** @requirement PAY-003 PRV-001 PRV-002 PRV-003 ARCH-004 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 QUA-001 QUA-004 */
final class InitialProvisioningRemoteEffectTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use DatabaseTruncation;
    use PurchaseOrderTestSupport;

    protected function setUp(): void
    {
        parent::setUp();

        // Queue-authority migration re-entry tests intentionally reinstall the older fail-closed
        // update guards. Restore the latest remote-effect guards before exercising this layer so
        // this integration suite observes the canonical post-migration schema.
        /** @var Migration $remoteEffectMigration */
        $remoteEffectMigration = require database_path('migrations/2026_08_16_000100_enable_initial_provisioning_remote_effect.php');
        /** @var Migration $uncertainRecoveryMigration */
        $uncertainRecoveryMigration = require database_path('migrations/2026_08_16_000102_enable_initial_provisioning_uncertain_recovery.php');
        $remoteEffectMigration->up();
        $uncertainRecoveryMigration->up();

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

    public function test_financial_invalidation_prevents_every_provider_call(): void
    {
        $scenario = $this->scenario('financial-invalidation');
        $this->recordFullRefund($scenario['settlement'], 'financial-invalidation');

        try {
            $this->executor()->execute($scenario['queue']->provisioningOperationPublicId);
            self::fail('Invalidated financial authority must fail closed before any remote-effect claim or provider call.');
        } catch (DomainException) {
            // Expected: claim authority is rejected before the operation can enter running state.
        }

        self::assertSame([], $scenario['adapter']->calls);
        self::assertSame(0, $scenario['adapter']->serviceCount());
    }

    public function test_successful_create_is_transaction_free_durable_and_terminal_replay_is_side_effect_free(): void
    {
        $scenario = $this->scenario('success');

        $receipt = $this->executor()->execute($scenario['queue']->provisioningOperationPublicId);

        self::assertSame(ProvisioningState::Succeeded, $receipt->state);
        self::assertSame(['lookup_username', 'create'], $scenario['adapter']->calls);
        self::assertSame([0, 0], $scenario['adapter']->transactionLevels);
        self::assertSame(1, $scenario['adapter']->serviceCount());
        self::assertNotNull($receipt->remoteServiceId);
        self::assertSame(1, DB::table('plan_offering_route_selections')->count());
        self::assertSame('committed', DB::table('panel_capacity_reservations')->value('state'));

        $service = DB::table('service_subscriptions')->where('id', $scenario['queue']->serviceSubscriptionId)->first([
            'route_selection_id', 'service_target_id', 'remote_service_id', 'provisioned_at',
        ]);
        self::assertNotNull($service);
        self::assertSame($receipt->routeSelectionId, (int) $service->route_selection_id);
        self::assertSame($scenario['target_id'], (int) $service->service_target_id);
        self::assertSame($receipt->remoteServiceId, $service->remote_service_id);
        self::assertNotNull($service->provisioned_at);

        $callCount = count($scenario['adapter']->calls);
        $replay = $this->executor()->execute($scenario['queue']->provisioningOperationPublicId);
        self::assertTrue($replay->replayed);
        self::assertSame(ProvisioningState::Succeeded, $replay->state);
        self::assertSame($receipt->remoteServiceId, $replay->remoteServiceId);
        self::assertCount($callCount, $scenario['adapter']->calls);
        self::assertSame(1, DB::table('plan_offering_route_selections')->count());
    }

    public function test_retryable_attempt_reuses_durable_route_and_adopts_exact_preexisting_remote_without_create(): void
    {
        $scenario = $this->scenario('retry-adopt');
        $scenario['adapter']->forcedCreateResult = new PanelOperationResult(
            PanelOperationOutcome::RetryableFailure,
            null,
            'test_retryable',
            'Temporary test failure.',
        );

        $first = $this->executor()->execute($scenario['queue']->provisioningOperationPublicId);
        self::assertSame(ProvisioningState::RetryScheduled, $first->state);
        self::assertNotNull($scenario['adapter']->lastCreateRequest);
        self::assertNotNull($first->routeSelectionId);
        $routeSelectionId = $first->routeSelectionId;
        $request = $scenario['adapter']->lastCreateRequest;
        self::assertNotNull($request);
        $expectedHash = $scenario['adapter']->createEquivalenceHash($request);
        $scenario['adapter']->seed(new RemoteServiceSnapshot(
            'existing-'.$scenario['queue']->provisioningOperationId,
            $request->username,
            PanelServiceStatus::Active,
            $request->dataLimitBytes,
            0,
            $request->expiresAt,
            $expectedHash,
            $expectedHash,
        ));
        $scenario['adapter']->forcedCreateResult = null;
        $scenario['adapter']->resetCalls();

        $retry = $this->executor()->execute($scenario['queue']->provisioningOperationPublicId);

        self::assertSame(ProvisioningState::Succeeded, $retry->state);
        self::assertSame($routeSelectionId, $retry->routeSelectionId);
        self::assertSame(['lookup_username'], $scenario['adapter']->calls);
        self::assertSame([0], $scenario['adapter']->transactionLevels);
        self::assertSame(1, DB::table('plan_offering_route_selections')->count());
        self::assertSame(2, $retry->attemptCount);
    }

    public function test_committed_capacity_recovery_reuses_route_and_adopts_exact_remote_without_create(): void
    {
        $scenario = $this->scenario('committed-capacity-recovery');
        $scenario['adapter']->forcedCreateResult = new PanelOperationResult(
            PanelOperationOutcome::RetryableFailure,
            null,
            'test_retryable',
            'Temporary test failure.',
        );

        $first = $this->executor()->execute($scenario['queue']->provisioningOperationPublicId);
        self::assertSame(ProvisioningState::RetryScheduled, $first->state);
        self::assertNotNull($first->routeSelectionId);
        $request = $scenario['adapter']->lastCreateRequest;
        self::assertNotNull($request);

        $operation = DB::table('provisioning_operations')
            ->where('id', $scenario['queue']->provisioningOperationId)
            ->first(['capacity_reservation_key', 'correlation_id']);
        self::assertNotNull($operation);
        self::assertNotNull($operation->capacity_reservation_key);
        $reservation = DB::table('panel_capacity_reservations')
            ->where('reservation_key', $operation->capacity_reservation_key)
            ->first(['version']);
        self::assertNotNull($reservation);
        $this->app->make(TargetCapacityAllocator::class)->commit(
            (string) $operation->capacity_reservation_key,
            (int) $reservation->version,
            new CapacityOperationContext(
                'test-committed-capacity-recovery:'.$scenario['queue']->provisioningOperationPublicId,
                (string) $operation->correlation_id,
                'provisioning',
                'initial_provision',
                'simulate_post_provider_capacity_commit',
            ),
        );
        self::assertSame('committed', DB::table('panel_capacity_reservations')->value('state'));

        $expectedHash = $scenario['adapter']->createEquivalenceHash($request);
        $scenario['adapter']->seed(new RemoteServiceSnapshot(
            'committed-existing-'.$scenario['queue']->provisioningOperationId,
            $request->username,
            PanelServiceStatus::Active,
            $request->dataLimitBytes,
            0,
            $request->expiresAt,
            $expectedHash,
            $expectedHash,
        ));
        $scenario['adapter']->forcedCreateResult = null;
        $scenario['adapter']->resetCalls();

        $retry = $this->executor()->execute($scenario['queue']->provisioningOperationPublicId);

        self::assertSame(ProvisioningState::Succeeded, $retry->state);
        self::assertSame($first->routeSelectionId, $retry->routeSelectionId);
        self::assertSame(['lookup_username'], $scenario['adapter']->calls);
        self::assertSame([0], $scenario['adapter']->transactionLevels);
        self::assertSame('committed', DB::table('panel_capacity_reservations')->value('state'));
        self::assertSame(1, DB::table('plan_offering_route_selections')->count());
        self::assertSame(2, $retry->attemptCount);
    }

    public function test_capacity_hold_near_expiry_fails_closed_before_retry_provider_effect(): void
    {
        $scenario = $this->scenario('capacity-expiry');
        $scenario['adapter']->forcedCreateResult = new PanelOperationResult(
            PanelOperationOutcome::RetryableFailure,
            null,
            'test_retryable',
            'Temporary test failure.',
        );
        $first = $this->executor()->execute($scenario['queue']->provisioningOperationPublicId);
        self::assertSame(ProvisioningState::RetryScheduled, $first->state);
        self::assertNotNull($first->routeSelectionId);
        self::assertSame('held', DB::table('panel_capacity_reservations')->value('state'));
        $remoteCount = $scenario['adapter']->serviceCount();

        $scenario['adapter']->forcedCreateResult = null;
        $scenario['adapter']->resetCalls();
        $this->purchaseOrderClock->value = $this->purchaseOrderClock->value->modify('+23 hours 51 minutes');

        $retry = $this->executor()->execute($scenario['queue']->provisioningOperationPublicId);

        self::assertSame(ProvisioningState::NeedsReview, $retry->state);
        self::assertSame('capacity_authority_lost', $retry->resultCode);
        self::assertSame([], $scenario['adapter']->calls);
        self::assertSame($remoteCount, $scenario['adapter']->serviceCount());
        self::assertSame('held', DB::table('panel_capacity_reservations')->value('state'));
    }

    public function test_running_remote_effect_fences_capacity_release_until_finalization(): void
    {
        $scenario = $this->scenario('capacity-release-fence');
        $releaseFailure = null;
        $scenario['adapter']->beforeCreate = function () use ($scenario, &$releaseFailure): void {
            $operation = DB::table('provisioning_operations')
                ->where('id', $scenario['queue']->provisioningOperationId)
                ->first(['capacity_reservation_key', 'correlation_id']);
            self::assertNotNull($operation);
            self::assertNotNull($operation->capacity_reservation_key);
            $reservation = DB::table('panel_capacity_reservations')
                ->where('reservation_key', $operation->capacity_reservation_key)
                ->first(['version']);
            self::assertNotNull($reservation);

            try {
                $this->app->make(TargetCapacityAllocator::class)->release(
                    (string) $operation->capacity_reservation_key,
                    (int) $reservation->version,
                    new CapacityOperationContext(
                        'test-running-capacity-release:'.$scenario['queue']->provisioningOperationPublicId,
                        (string) $operation->correlation_id,
                        'provisioning',
                        'initial_provision',
                        'running_effect_fence_test',
                    ),
                );
            } catch (Throwable $exception) {
                $releaseFailure = $exception;
            }
        };

        $receipt = $this->executor()->execute($scenario['queue']->provisioningOperationPublicId);

        self::assertSame(ProvisioningState::Succeeded, $receipt->state);
        self::assertInstanceOf(Throwable::class, $releaseFailure);
        self::assertSame('committed', DB::table('panel_capacity_reservations')->value('state'));
        self::assertSame(['lookup_username', 'create'], $scenario['adapter']->calls);
        self::assertSame([0, 0], $scenario['adapter']->transactionLevels);
    }

    public function test_uncertain_result_recovers_via_retry_and_lookup_before_create(): void
    {
        $scenario = $this->scenario('uncertain');
        $scenario['adapter']->forcedCreateResult = new PanelOperationResult(
            PanelOperationOutcome::UncertainResult,
            null,
            'test_timeout',
            'Test result is uncertain.',
        );

        $uncertain = $this->executor()->execute($scenario['queue']->provisioningOperationPublicId);
        self::assertSame(ProvisioningState::UncertainRemoteResult, $uncertain->state);
        $request = $scenario['adapter']->lastCreateRequest;
        self::assertNotNull($request);
        $expectedHash = $scenario['adapter']->createEquivalenceHash($request);
        $scenario['adapter']->seed(new RemoteServiceSnapshot(
            'uncertain-existing-'.$scenario['queue']->provisioningOperationId,
            $request->username,
            PanelServiceStatus::Active,
            $request->dataLimitBytes,
            0,
            $request->expiresAt,
            $expectedHash,
            $expectedHash,
        ));

        self::assertSame(
            ProvisioningState::RetryScheduled,
            $this->app->make(InitialProvisioningRecoveryService::class)->prepare($scenario['queue']->provisioningOperationPublicId),
        );
        $scenario['adapter']->forcedCreateResult = null;
        $scenario['adapter']->resetCalls();

        $retry = $this->executor()->execute($scenario['queue']->provisioningOperationPublicId);

        self::assertSame(ProvisioningState::Succeeded, $retry->state);
        self::assertSame(['lookup_username'], $scenario['adapter']->calls);
        self::assertSame(1, DB::table('plan_offering_route_selections')->count());
        self::assertSame(1, DB::table('provisioning_remote_effect_events')
            ->where('event_type', 'reconciliation_scheduled')->count());
    }

    public function test_fresh_running_outbox_reclaim_is_retryable_without_second_provider_effect(): void
    {
        $scenario = $this->scenario('fresh-running-reclaim');
        $this->simulateInterruptedClaim($scenario['queue']);
        $event = DB::table('outbox_messages')->where('id', $scenario['queue']->outboxEventId)->first([
            'id', 'event_key', 'event_type', 'aggregate_type', 'aggregate_id', 'payload', 'correlation_id',
        ]);
        self::assertNotNull($event);
        $payload = json_decode((string) $event->payload, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            self::fail('Provisioning Outbox payload must decode to an array.');
        }

        $outcome = $this->app->make(InitialProvisioningOutboxHandler::class)->handle(new OutboxMessage(
            (string) $event->id,
            (string) $event->event_key,
            (string) $event->event_type,
            (string) $event->aggregate_type,
            (string) $event->aggregate_id,
            $payload,
            (string) $event->correlation_id,
            2,
        ));

        self::assertSame(OutboxDispatchOutcome::RetryableFailure, $outcome);
        self::assertSame(ProvisioningState::Running->value, DB::table('provisioning_operations')
            ->where('id', $scenario['queue']->provisioningOperationId)->value('state'));
        self::assertSame([], $scenario['adapter']->calls);
        self::assertSame(0, $scenario['adapter']->serviceCount());
    }

    public function test_interrupted_running_attempt_is_made_durably_uncertain_before_retry(): void
    {
        $scenario = $this->scenario('interrupted');
        $this->simulateInterruptedClaim($scenario['queue'], true);
        $recovery = $this->app->make(InitialProvisioningRecoveryService::class);

        self::assertSame(
            ProvisioningState::UncertainRemoteResult,
            $recovery->prepare($scenario['queue']->provisioningOperationPublicId),
        );
        self::assertSame('interrupted_running_recovery', DB::table('provisioning_operations')
            ->where('id', $scenario['queue']->provisioningOperationId)->value('last_result_code'));
        self::assertSame(
            ProvisioningState::RetryScheduled,
            $recovery->prepare($scenario['queue']->provisioningOperationPublicId),
        );

        $receipt = $this->executor()->execute($scenario['queue']->provisioningOperationPublicId);

        self::assertSame(ProvisioningState::Succeeded, $receipt->state);
        self::assertSame('lookup_username', $scenario['adapter']->calls[0] ?? null);
        self::assertSame([0, 0], $scenario['adapter']->transactionLevels);
    }

    public function test_conflicting_preexisting_remote_requires_review_and_never_blind_creates(): void
    {
        $scenario = $this->scenario('conflict');
        $scenario['adapter']->forcedCreateResult = new PanelOperationResult(
            PanelOperationOutcome::RetryableFailure,
            null,
            'test_retryable',
            'Temporary test failure.',
        );
        $first = $this->executor()->execute($scenario['queue']->provisioningOperationPublicId);
        self::assertSame(ProvisioningState::RetryScheduled, $first->state);
        $request = $scenario['adapter']->lastCreateRequest;
        self::assertNotNull($request);
        $conflictingHash = hash('sha256', 'conflicting-remote-intent');
        $scenario['adapter']->seed(new RemoteServiceSnapshot(
            'conflict-'.$scenario['queue']->provisioningOperationId,
            $request->username,
            PanelServiceStatus::Active,
            $request->dataLimitBytes,
            0,
            $request->expiresAt,
            $conflictingHash,
            $conflictingHash,
        ));
        $scenario['adapter']->forcedCreateResult = null;
        $scenario['adapter']->resetCalls();

        $receipt = $this->executor()->execute($scenario['queue']->provisioningOperationPublicId);

        self::assertSame(ProvisioningState::NeedsReview, $receipt->state);
        self::assertSame(['lookup_username'], $scenario['adapter']->calls);
        self::assertSame(1, DB::table('plan_offering_route_selections')->count());
    }

    public function test_refund_racing_with_provider_mutation_is_fenced_until_remote_attempt_finishes(): void
    {
        $scenario = $this->scenario('refund-fence');
        $refundFailure = null;
        $scenario['adapter']->beforeCreate = function () use ($scenario, &$refundFailure): void {
            try {
                $this->recordFullRefund($scenario['settlement'], 'refund-fence-race');
            } catch (Throwable $exception) {
                $refundFailure = $exception;
            }
        };

        $receipt = $this->executor()->execute($scenario['queue']->provisioningOperationPublicId);

        self::assertSame(ProvisioningState::Succeeded, $receipt->state);
        self::assertInstanceOf(Throwable::class, $refundFailure);
        self::assertSame(PaymentIntentState::Captured->value, DB::table('payment_intents')
            ->where('public_id', $scenario['settlement']->intentPublicId)->value('state'));
        self::assertSame([0, 0], $scenario['adapter']->transactionLevels);
    }

    /**
     * @return array{
     *     settlement:PurchaseSettlementReceipt,
     *     order:PurchaseOrderReceipt,
     *     queue:ProvisioningQueueReceipt,
     *     adapter:InitialProvisioningTestPanelAdapter,
     *     target_id:int
     * }
     */
    private function scenario(string $suffix): array
    {
        $settlement = $this->createPurchaseOrderSettlement($suffix);
        $order = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('order-'.$suffix),
        );
        $queue = $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
            $order->orderPublicId,
            $this->purchaseOrderCorrelation('queue-'.$suffix),
        );
        $offeringId = (int) DB::table('order_items')->where('order_id', $order->orderId)->value('plan_offering_id');
        $userId = (int) DB::table('orders')->where('id', $order->orderId)->value('user_id');
        $targetId = $this->makeOfferingOperational($offeringId, $userId, $suffix);

        $adapter = new InitialProvisioningTestPanelAdapter;
        $this->app->instance(
            PanelAdapterRegistry::class,
            new PanelAdapterRegistry(
                [new InitialProvisioningTestPanelAdapterFactory($adapter)],
                $this->app->make(PanelCredentialPolicy::class),
            ),
        );
        $this->app->forgetInstance(InitialProvisioningExecutor::class);

        return [
            'settlement' => $settlement,
            'order' => $order,
            'queue' => $queue,
            'adapter' => $adapter,
            'target_id' => $targetId,
        ];
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
        $capabilityHash = hash('sha256', 'test-capabilities-'.$suffix);
        $targetEvidenceHash = hash('sha256', 'test-target-evidence-'.$suffix);

        DB::table('panel_connections')->where('id', $connectionId)->update([
            'encrypted_credentials' => Crypt::encryptString(json_encode(['token' => 'remote-effect-test'], JSON_THROW_ON_ERROR)),
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
        // Route-policy compatibility is evaluated while the offering is still draft. Put the
        // server-selection mode into its intended system-selected state before creating the policy.
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
            $this->catalogContext($ownerId, 'remote-effect-route-'.$suffix),
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

    private function executor(): InitialProvisioningExecutor
    {
        return $this->app->make(InitialProvisioningExecutor::class);
    }

    private function simulateInterruptedClaim(ProvisioningQueueReceipt $queue, bool $stale = false): void
    {
        $operation = DB::table('provisioning_operations')->where('id', $queue->provisioningOperationId)->first([
            'operation_key', 'correlation_id', 'state_version', 'attempt_count',
        ]);
        self::assertNotNull($operation);
        $connection = DB::connection();
        $connection->statement('SET @app_provisioning_authority = ?, @app_provisioning_operation_key = ?, @app_provisioning_correlation_id = ?', [
            'initial_remote_effect_v1',
            $operation->operation_key,
            $operation->correlation_id,
        ]);
        try {
            $remoteEffectStartedAt = $stale
                ? $this->purchaseOrderClock->value->modify('-601 seconds')->format('Y-m-d H:i:s.u')
                : $this->purchaseOrderTimestamp();
            $updated = $connection->table('provisioning_operations')->where('id', $queue->provisioningOperationId)->update([
                'state' => ProvisioningState::Running->value,
                'state_version' => (int) $operation->state_version + 1,
                'effect_fence_key' => 'test-interrupted-'.substr(hash('sha256', (string) $queue->provisioningOperationId), 0, 40),
                'route_hold_expires_at' => $this->purchaseOrderClock->value->modify('+24 hours')->format('Y-m-d H:i:s.u'),
                'attempt_count' => (int) $operation->attempt_count + 1,
                'remote_effect_started_at' => $remoteEffectStartedAt,
                'updated_at' => $this->purchaseOrderTimestamp(),
            ]);
            self::assertSame(1, $updated);
        } finally {
            $connection->statement('SET @app_provisioning_authority = NULL, @app_provisioning_operation_key = NULL, @app_provisioning_correlation_id = NULL');
        }
    }

    private function recordFullRefund(PurchaseSettlementReceipt $settlement, string $suffix): PurchaseRefundReceipt
    {
        $occurredAt = $this->purchaseOrderClock->value->modify('+5 minutes');

        return $this->app->make(PurchaseRefundService::class)->record(
            'remote-effect-refund-'.$suffix,
            $settlement->settlementPublicId,
            $settlement->providerCode,
            new VerifiedPaymentEvent(
                'evt-remote-effect-refund-'.$suffix,
                hash('sha256', 'remote-effect-refund-event:'.$suffix),
                new PaymentEvidence(
                    ProviderOperationOutcome::Success,
                    PaymentEvidenceAuthority::Authoritative,
                    PaymentTransactionStatus::Refunded,
                    'refund-remote-effect-'.$suffix,
                    'evt-remote-effect-refund-'.$suffix,
                    Money::irr($settlement->amount->amount()),
                    $occurredAt,
                    $occurredAt,
                    hash('sha256', 'remote-effect-refund-evidence:'.$suffix),
                    ['provider_reference' => 'refund-remote-effect-'.$suffix],
                ),
            ),
            $this->purchaseOrderCorrelation('refund-'.$suffix),
        );
    }
}
