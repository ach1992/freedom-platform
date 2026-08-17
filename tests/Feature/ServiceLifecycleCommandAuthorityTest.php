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
use App\Modules\Panels\Application\PanelAdapterRegistry;
use App\Modules\Panels\Application\PanelCredentialPolicy;
use App\Modules\Provisioning\Application\InitialProvisioningExecutor;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use App\Modules\Provisioning\Application\ServiceLifecycleCommandAudit;
use App\Modules\Provisioning\Application\ServiceLifecycleCommandContext;
use App\Modules\Provisioning\Application\ServiceLifecycleCommandService;
use App\Modules\Provisioning\Application\ServiceMutationQueueService;
use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Modules\Provisioning\Domain\ServiceMutationType;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PanelsAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** @requirement SVC-004 SVC-006 ARCH-002 ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-003 SEC-008 QUA-004 QUA-007 QUA-010 */
final class ServiceLifecycleCommandAuthorityTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use DatabaseTruncation;
    use PurchaseOrderTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PanelsAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->bootPurchaseOrderClock();
    }

    public function test_service_owner_command_is_audited_and_replays_without_second_operation_outbox_or_audit(): void
    {
        $scenario = $this->scenario('owner-replay');
        $context = $this->userContext($scenario['user_id'], 'owner-replay');

        $first = $this->commands()->execute(
            $scenario['service_public_id'],
            ServiceMutationType::Suspend,
            $context,
        );
        self::assertFalse($first->mutation->replayed);
        self::assertFalse($first->auditReplayed);
        self::assertSame(1, $first->mutation->generation);
        self::assertSame(ProvisioningState::Queued, $first->mutation->state);

        $audit = DB::table('audit_logs')->where('id', $first->auditLogId)->first([
            'actor_type', 'actor_id', 'action', 'target_type', 'target_id', 'after_safe_data',
            'reason_code', 'reason', 'correlation_id', 'request_fingerprint',
        ]);
        self::assertNotNull($audit);
        self::assertSame('user', $audit->actor_type);
        self::assertSame((string) $scenario['user_id'], $audit->actor_id);
        self::assertSame(ServiceLifecycleCommandAudit::ACTION, $audit->action);
        self::assertSame('service_subscription', $audit->target_type);
        self::assertSame($scenario['service_public_id'], $audit->target_id);
        self::assertSame($context->reasonCode, $audit->reason_code);
        self::assertSame($context->reason, $audit->reason);
        self::assertSame($context->correlationId, $audit->correlation_id);
        self::assertSame($context->requestHash(), $audit->request_fingerprint);
        /** @var array<string, mixed> $after */
        $after = json_decode((string) $audit->after_safe_data, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($first->mutation->operationPublicId, $after['operation_public_id']);
        self::assertSame(ServiceMutationType::Suspend->value, $after['operation_type']);
        self::assertSame(1, $after['operation_generation']);

        $replay = $this->commands()->execute(
            $scenario['service_public_id'],
            ServiceMutationType::Suspend,
            $context,
        );
        self::assertTrue($replay->mutation->replayed);
        self::assertTrue($replay->auditReplayed);
        self::assertSame($first->mutation->operationPublicId, $replay->mutation->operationPublicId);
        self::assertSame($first->auditLogId, $replay->auditLogId);
        self::assertSame(1, DB::table('provisioning_operations')
            ->where('service_subscription_id', $scenario['service_id'])
            ->where('operation_type', '<>', 'initial_provision')
            ->count());
        self::assertSame(1, DB::table('outbox_messages')
            ->where('event_type', ServiceMutationQueueService::OUTBOX_EVENT_TYPE)
            ->count());
        self::assertSame(1, DB::table('audit_logs')
            ->where('action', ServiceLifecycleCommandAudit::ACTION)
            ->count());
    }

    public function test_request_fingerprint_replay_scope_is_per_service(): void
    {
        $firstScenario = $this->scenario('request-scope-first');
        $secondScenario = $this->scenario('request-scope-second');
        $requestKey = 'request-lifecycle-shared-scope-0001';

        $firstContext = new ServiceLifecycleCommandContext(
            $requestKey,
            'correlation-lifecycle-shared-first-0001',
            'user_requested',
            'User requested this Service lifecycle action.',
            actorUserId: $firstScenario['user_id'],
        );
        $secondContext = new ServiceLifecycleCommandContext(
            $requestKey,
            'correlation-lifecycle-shared-second-0001',
            'user_requested',
            'User requested this Service lifecycle action.',
            actorUserId: $secondScenario['user_id'],
        );

        $first = $this->commands()->execute(
            $firstScenario['service_public_id'],
            ServiceMutationType::ResetUsage,
            $firstContext,
        );
        $second = $this->commands()->execute(
            $secondScenario['service_public_id'],
            ServiceMutationType::ResetUsage,
            $secondContext,
        );

        self::assertNotSame($first->mutation->operationPublicId, $second->mutation->operationPublicId);
        self::assertNotSame($first->auditLogId, $second->auditLogId);
        self::assertSame(2, DB::table('audit_logs')
            ->where('action', ServiceLifecycleCommandAudit::ACTION)
            ->where('request_fingerprint', hash('sha256', $requestKey))
            ->count());
        self::assertTrue(DB::table('audit_logs')
            ->where('action', ServiceLifecycleCommandAudit::ACTION)
            ->where('request_fingerprint', hash('sha256', $requestKey))
            ->where('target_type', 'service_subscription')
            ->where('target_id', $firstScenario['service_public_id'])
            ->exists());
        self::assertTrue(DB::table('audit_logs')
            ->where('action', ServiceLifecycleCommandAudit::ACTION)
            ->where('request_fingerprint', hash('sha256', $requestKey))
            ->where('target_type', 'service_subscription')
            ->where('target_id', $secondScenario['service_public_id'])
            ->exists());
        self::assertSame(2, DB::table('outbox_messages')
            ->where('event_type', ServiceMutationQueueService::OUTBOX_EVENT_TYPE)
            ->count());
    }

    public function test_cross_user_command_is_rejected_before_mutation_or_audit_authority(): void
    {
        $scenario = $this->scenario('cross-user');

        try {
            $this->commands()->execute(
                $scenario['service_public_id'],
                ServiceMutationType::ResetUsage,
                $this->userContext($scenario['user_id'] + 1000000, 'cross-user'),
            );
            self::fail('A user must not mutate another user Service.');
        } catch (AuthorizationException) {
            // Expected.
        }

        self::assertSame(0, (int) DB::table('service_subscriptions')
            ->where('id', $scenario['service_id'])->value('mutation_generation'));
        self::assertSame(0, DB::table('provisioning_operations')
            ->where('service_subscription_id', $scenario['service_id'])
            ->where('operation_type', '<>', 'initial_provision')
            ->count());
        self::assertSame(0, DB::table('outbox_messages')
            ->where('event_type', ServiceMutationQueueService::OUTBOX_EVENT_TYPE)->count());
        self::assertSame(0, DB::table('audit_logs')
            ->where('action', ServiceLifecycleCommandAudit::ACTION)->count());
    }

    public function test_audit_insert_failure_rolls_back_generation_operation_and_outbox(): void
    {
        $scenario = $this->scenario('audit-rollback');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER service_lifecycle_command_test_audit_failure
BEFORE INSERT ON audit_logs
FOR EACH ROW
BEGIN
    IF BINARY NEW.action = BINARY 'service.lifecycle.command.accepted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Simulated lifecycle caller audit failure.';
    END IF;
END
SQL);

        try {
            try {
                $this->commands()->execute(
                    $scenario['service_public_id'],
                    ServiceMutationType::ResetUsage,
                    $this->userContext($scenario['user_id'], 'audit-rollback'),
                );
                self::fail('Caller audit failure must roll back Service mutation authority.');
            } catch (QueryException) {
                // Expected.
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS service_lifecycle_command_test_audit_failure');
        }

        self::assertSame(0, (int) DB::table('service_subscriptions')
            ->where('id', $scenario['service_id'])->value('mutation_generation'));
        self::assertSame(0, DB::table('provisioning_operations')
            ->where('service_subscription_id', $scenario['service_id'])
            ->where('operation_type', '<>', 'initial_provision')->count());
        self::assertSame(0, DB::table('outbox_messages')
            ->where('event_type', ServiceMutationQueueService::OUTBOX_EVENT_TYPE)->count());
        self::assertSame(0, DB::table('audit_logs')
            ->where('action', ServiceLifecycleCommandAudit::ACTION)->count());
    }

    public function test_service_lifecycle_caller_audit_is_db_guarded_and_immutable(): void
    {
        $scenario = $this->scenario('audit-guard');
        $receipt = $this->commands()->execute(
            $scenario['service_public_id'],
            ServiceMutationType::RotateSubscriptionLink,
            $this->userContext($scenario['user_id'], 'audit-guard'),
        );

        try {
            DB::table('audit_logs')->where('id', $receipt->auditLogId)->update(['reason' => 'tampered']);
            self::fail('Service lifecycle caller audit must be immutable.');
        } catch (QueryException) {
            // Expected.
        }
        try {
            DB::table('audit_logs')->where('id', $receipt->auditLogId)->delete();
            self::fail('Service lifecycle caller audit must be non-deletable.');
        } catch (QueryException) {
            // Expected.
        }
        try {
            DB::table('audit_logs')->insert([
                'actor_type' => 'user',
                'actor_id' => (string) $scenario['user_id'],
                'action' => ServiceLifecycleCommandAudit::ACTION,
                'target_type' => 'service_subscription',
                'target_id' => $scenario['service_public_id'],
                'before_safe_data' => '{}',
                'after_safe_data' => '{}',
                'reason_code' => 'forged',
                'reason' => 'forged',
                'correlation_id' => 'correlation-forged-0001',
                'request_fingerprint' => hash('sha256', 'request-forged-0001'),
                'created_at' => $this->purchaseOrderTimestamp(),
            ]);
            self::fail('Direct caller audit insertion without authority must fail closed.');
        } catch (QueryException) {
            // Expected.
        }

        self::assertSame(1, DB::table('audit_logs')
            ->where('action', ServiceLifecycleCommandAudit::ACTION)->count());
    }

    public function test_replay_of_low_level_mutation_without_caller_audit_fails_closed(): void
    {
        $scenario = $this->scenario('missing-audit');
        $context = $this->userContext($scenario['user_id'], 'missing-audit');
        $lowLevel = $this->app->make(ServiceMutationQueueService::class)->queue(
            $scenario['service_public_id'],
            ServiceMutationType::ResetUsage,
            $context->requestKey,
            $context->correlationId,
        );
        self::assertFalse($lowLevel->replayed);

        try {
            $this->commands()->execute(
                $scenario['service_public_id'],
                ServiceMutationType::ResetUsage,
                $context,
            );
            self::fail('A low-level mutation replay without caller audit must not be retroactively attributed.');
        } catch (RuntimeException) {
            // Expected.
        }

        self::assertSame(0, DB::table('audit_logs')
            ->where('action', ServiceLifecycleCommandAudit::ACTION)->count());
        self::assertSame(1, DB::table('provisioning_operations')
            ->where('service_subscription_id', $scenario['service_id'])
            ->where('operation_type', '<>', 'initial_provision')->count());
        self::assertSame(1, DB::table('outbox_messages')
            ->where('event_type', ServiceMutationQueueService::OUTBOX_EVENT_TYPE)->count());
    }

    private function commands(): ServiceLifecycleCommandService
    {
        return $this->app->make(ServiceLifecycleCommandService::class);
    }

    private function userContext(int $userId, string $suffix): ServiceLifecycleCommandContext
    {
        return new ServiceLifecycleCommandContext(
            'request-lifecycle-'.$suffix.'-0001',
            'correlation-lifecycle-'.$suffix.'-0001',
            'user_requested',
            'User requested this Service lifecycle action.',
            actorUserId: $userId,
        );
    }

    /** @return array{service_id:int,service_public_id:string,user_id:int,adapter:ServiceMutationTestPanelAdapter} */
    private function scenario(string $suffix): array
    {
        $settlement = $this->createPurchaseOrderSettlement('lifecycle-command-'.$suffix);
        $order = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('lifecycle-command-order-'.$suffix),
        );
        $queue = $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
            $order->orderPublicId,
            $this->purchaseOrderCorrelation('lifecycle-command-queue-'.$suffix),
        );
        $offeringId = (int) DB::table('order_items')->where('order_id', $order->orderId)->value('plan_offering_id');
        $userId = (int) DB::table('orders')->where('id', $order->orderId)->value('user_id');
        $this->makeOfferingOperational($offeringId, $userId, $suffix);

        $adapter = new ServiceMutationTestPanelAdapter;
        $this->app->instance(
            PanelAdapterRegistry::class,
            new PanelAdapterRegistry(
                [new ServiceMutationTestPanelAdapterFactory($adapter)],
                $this->app->make(PanelCredentialPolicy::class),
            ),
        );
        $this->app->forgetInstance(InitialProvisioningExecutor::class);

        $provisioned = $this->app->make(InitialProvisioningExecutor::class)->execute($queue->provisioningOperationPublicId);
        self::assertSame(ProvisioningState::Succeeded, $provisioned->state);
        $adapter->resetCalls();

        $service = DB::table('service_subscriptions')->where('id', $queue->serviceSubscriptionId)->first([
            'id', 'public_id', 'user_id', 'mutation_generation',
        ]);
        self::assertNotNull($service);
        self::assertSame(0, (int) $service->mutation_generation);

        return [
            'service_id' => (int) $service->id,
            'service_public_id' => (string) $service->public_id,
            'user_id' => (int) $service->user_id,
            'adapter' => $adapter,
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
        $capabilityHash = hash('sha256', 'lifecycle-command-capabilities-'.$suffix);
        $targetEvidenceHash = hash('sha256', 'lifecycle-command-target-evidence-'.$suffix);

        DB::table('panel_connections')->where('id', $connectionId)->update([
            'encrypted_credentials' => Crypt::encryptString(json_encode(['token' => 'lifecycle-command-test'], JSON_THROW_ON_ERROR)),
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
            $this->catalogContext($ownerId, 'service-lifecycle-command-route-'.$suffix),
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
