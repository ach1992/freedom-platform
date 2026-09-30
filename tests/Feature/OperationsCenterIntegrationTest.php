<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Operations\Application\OperationalAlertLifecycleService;
use App\Modules\Operations\Application\OperationsCenterActionService;
use App\Modules\Operations\Application\OperationsCenterService;
use App\Shared\Application\OperationalAlertRecorder;
use App\Shared\Application\OutboxRuntime;
use App\Shared\Application\OutboxRuntimeResult;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\OperationsAccessFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement OPS-001 OPS-002 OPS-003 ACL-001 ACL-002 DAT-003 SEC-002 QUA-004 */
final class OperationsCenterIntegrationTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(OperationsAccessFoundationSeeder::class);
    }

    public function test_alert_episode_deduplication_lifecycle_and_reopen_are_durable(): void
    {
        $administratorId = $this->ownerAdministrator();
        $userId = (int) DB::table('administrators')->where('id', $administratorId)->value('user_id');
        $recorder = $this->app->make(OperationalAlertRecorder::class);
        $lifecycle = $this->app->make(OperationalAlertLifecycleService::class);
        $event = 'operations.integration_alert';
        $deduplicationKey = hash('sha256', 'operations-integration-alert');

        $recorder->raise('warning', $event, $deduplicationKey, 'operations.test.alert', ['state' => 'first']);
        $recorder->raise('warning', $event, $deduplicationKey, 'operations.test.alert', ['state' => 'second']);

        $alert = DB::table('alerts')->where('event_name', $event)->first();
        self::assertNotNull($alert);
        self::assertSame(2, (int) $alert->occurrence_count);
        self::assertSame(1, (int) $alert->activation_sequence);
        self::assertSame(1, DB::table('operational_alert_deliveries')->where('alert_id', $alert->id)->count());
        self::assertSame('report_channel', DB::table('operational_alert_deliveries')->where('alert_id', $alert->id)->value('audience'));

        $recorder->raise('critical', $event, $deduplicationKey, 'operations.test.alert', ['state' => 'escalated']);
        self::assertSame(2, DB::table('operational_alert_deliveries')
            ->where('alert_id', $alert->id)
            ->where('activation_sequence', 1)
            ->count());

        $ack = $lifecycle->acknowledge(
            $userId,
            $alert->id,
            'Operations integration acknowledgement.',
            'operations.test.ack',
            'operations-test-ack-request',
        );
        self::assertFalse($ack->replayed);

        $ackReplay = $lifecycle->acknowledge(
            $userId,
            $alert->id,
            'Operations integration acknowledgement.',
            'operations.test.ack',
            'operations-test-ack-request',
        );
        self::assertTrue($ackReplay->replayed);

        try {
            $lifecycle->acknowledge(
                $userId,
                $alert->id,
                'Conflicting acknowledgement.',
                'operations.test.ack',
                'operations-test-ack-request',
            );
            self::fail('Conflicting operational alert lifecycle replay must fail closed.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $lifecycle->resolve(
            $userId,
            $alert->id,
            'Operations integration resolution.',
            'operations.test.resolve',
            'operations-test-resolve-request',
        );
        self::assertNotNull(DB::table('alerts')->where('id', $alert->id)->value('resolved_at'));
        self::assertSame(2, DB::table('operational_alert_events')->where('alert_id', $alert->id)->count());
        self::assertSame(2, DB::table('audit_logs')
            ->where('target_type', 'operational_alert')
            ->where('target_id', $alert->id)
            ->count());

        $recorder->raise('critical', $event, $deduplicationKey, 'operations.test.alert', ['state' => 'reopened']);
        $reopened = DB::table('alerts')->where('id', $alert->id)->first();
        self::assertNotNull($reopened);
        self::assertSame(4, (int) $reopened->occurrence_count);
        self::assertSame(2, (int) $reopened->activation_sequence);
        self::assertNull($reopened->acknowledged_at);
        self::assertNull($reopened->resolved_at);
        self::assertSame(2, DB::table('operational_alert_deliveries')
            ->where('alert_id', $alert->id)
            ->where('activation_sequence', 2)
            ->count());
    }

    public function test_security_alert_activation_has_owner_delivery_and_safe_audit_path(): void
    {
        $recorder = $this->app->make(OperationalAlertRecorder::class);
        $event = 'operations.security_integration_alert';
        $deduplicationKey = hash('sha256', 'operations-security-integration-alert');

        $recorder->raise(
            'security',
            $event,
            $deduplicationKey,
            'operations.test.security',
            ['state' => 'security-condition'],
        );
        $alert = DB::table('alerts')->where('event_name', $event)->first();
        self::assertNotNull($alert);
        self::assertSame(['owner'], DB::table('operational_alert_deliveries')
            ->where('alert_id', $alert->id)
            ->pluck('audience')
            ->all());
        self::assertSame(1, DB::table('audit_logs')
            ->where('action', 'operations.alert.security_activated')
            ->where('target_id', $alert->id)
            ->count());

        $recorder->raise(
            'security',
            $event,
            $deduplicationKey,
            'operations.test.security',
            ['state' => 'security-condition-repeat'],
        );
        self::assertSame(1, DB::table('audit_logs')
            ->where('action', 'operations.alert.security_activated')
            ->where('target_id', $alert->id)
            ->count());

        $auditJson = json_encode(
            DB::table('audit_logs')
                ->where('action', 'operations.alert.security_activated')
                ->where('target_id', $alert->id)
                ->pluck('after_safe_data')
                ->all(),
            JSON_THROW_ON_ERROR,
        );
        self::assertStringNotContainsString('security-condition', $auditJson);

        $recorder->resolve($event, $deduplicationKey);
        $recorder->raise(
            'security',
            $event,
            $deduplicationKey,
            'operations.test.security.reopen',
            ['state' => 'security-condition-reopened'],
        );
        self::assertSame(2, DB::table('audit_logs')
            ->where('action', 'operations.alert.security_activated')
            ->where('target_id', $alert->id)
            ->count());
        self::assertSame(2, (int) DB::table('alerts')->where('id', $alert->id)->value('activation_sequence'));
    }

    public function test_operations_permissions_do_not_widen_safe_actions(): void
    {
        $viewOnlyUserId = $this->quoteUser('customer');
        $now = now('UTC');
        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => $viewOnlyUserId,
            'status' => 'active',
            'is_owner' => false,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $viewPermissionId = (int) DB::table('permissions')->where('code', 'operations.view')->value('id');
        DB::table('administrator_permission_overrides')->insert([
            'administrator_id' => $administratorId,
            'permission_id' => $viewPermissionId,
            'effect' => 'allow',
            'changed_by_administrator_id' => null,
            'reason_code' => 'operations_test',
            'reason' => 'View-only Operations Center integration test.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fakeOutbox = new FakeOperationsOutboxRuntime;
        $this->app->instance(OutboxRuntime::class, $fakeOutbox);
        $actions = $this->app->make(OperationsCenterActionService::class);

        try {
            $actions->dispatchDueOutbox(
                $viewOnlyUserId,
                25,
                'operations.test.dispatch',
                'operations-test-dispatch-request',
            );
            self::fail('operations.view must not imply operations.actions.execute.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(0, $fakeOutbox->calls);
        self::assertSame(0, DB::table('audit_logs')->where('action', 'operations.outbox.dispatch_due')->count());

        $ownerAdministratorId = $this->ownerAdministrator();
        $ownerUserId = (int) DB::table('administrators')->where('id', $ownerAdministratorId)->value('user_id');
        $result = $actions->dispatchDueOutbox(
            $ownerUserId,
            25,
            'operations.test.dispatch-owner',
            'operations-test-dispatch-owner-request',
        );
        self::assertSame(1, $fakeOutbox->calls);
        self::assertSame(1, $result->examined);
        self::assertSame(1, DB::table('audit_logs')->where('action', 'operations.outbox.dispatch_due')->count());

        $replayed = $actions->dispatchDueOutbox(
            $ownerUserId,
            25,
            'operations.test.dispatch-owner',
            'operations-test-dispatch-owner-request',
        );
        self::assertSame(1, $fakeOutbox->calls);
        self::assertSame($result->toArray(), $replayed->toArray());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'operations.outbox.dispatch_due')->count());

        try {
            $actions->dispatchDueOutbox(
                $ownerUserId,
                24,
                'operations.test.dispatch-owner',
                'operations-test-dispatch-owner-request',
            );
            self::fail('Conflicting Operations Center action replay must fail closed.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(1, $fakeOutbox->calls);
    }

    public function test_snapshot_is_permission_gated_bounded_and_does_not_surface_panel_secrets(): void
    {
        $administratorId = $this->ownerAdministrator();
        $userId = (int) DB::table('administrators')->where('id', $administratorId)->value('user_id');
        $now = now('UTC');

        $missingRunHistory = $this->app->make(OperationsCenterService::class)->snapshot($userId);
        self::assertSame('unknown', $missingRunHistory->fact('scheduler.run_history')?->state);
        self::assertSame('empty', $missingRunHistory->fact('scheduler.stale_running_runs')?->state);

        DB::table('scheduled_task_runs')->insert([
            'task_name' => 'operations.test-stale-run',
            'run_id' => (string) Str::uuid(),
            'state' => 'running',
            'started_at' => $now->copy()->subHours(2),
            'finished_at' => null,
            'duration_ms' => null,
            'metrics' => null,
            'error_class' => null,
            'error_code' => null,
            'created_at' => $now->copy()->subHours(2),
            'updated_at' => $now->copy()->subHours(2),
        ]);

        DB::table('panel_connections')->insert([
            'code' => 'operations-sensitive-panel',
            'provider_type' => 'marzban',
            'name_fa' => 'پنل',
            'name_en' => 'Panel',
            'base_url' => 'https://secret-panel.example.test/private',
            'encrypted_credentials' => 'ciphertext-super-secret',
            'credential_key_version' => 1,
            'tls_policy' => 'system_ca',
            'custom_ca_disk' => null,
            'custom_ca_path' => null,
            'certificate_pin_sha256' => null,
            'network_policy' => 'public_only',
            'state' => 'active',
            'last_test_status' => 'success',
            'last_panel_version' => '1.2.3',
            'last_capabilities_hash' => hash('sha256', 'operations-test-capabilities'),
            'last_tested_at' => $now,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->app->make(OperationalAlertRecorder::class)->raise(
            'warning',
            'operations.delivery_dead_letter_test',
            hash('sha256', 'operations-delivery-dead-letter-test'),
            'operations.test.delivery.dead',
            ['state' => 'delivery-dead-letter'],
        );
        $deliveryDeadLetterAlertId = (string) DB::table('alerts')
            ->where('event_name', 'operations.delivery_dead_letter_test')
            ->where('deduplication_key', hash('sha256', 'operations-delivery-dead-letter-test'))
            ->value('id');
        self::assertNotSame('', $deliveryDeadLetterAlertId);

        DB::table('operational_alert_deliveries')
            ->where('alert_id', $deliveryDeadLetterAlertId)
            ->where('state', 'pending')
            ->update([
                'state' => 'failed',
                'attempts' => 4,
                'last_error_code' => 'telegram_queue_failed',
            ]);

        $snapshot = $this->app->make(OperationsCenterService::class)->snapshot($userId);
        self::assertNotNull($snapshot->fact('panels.provider.marzban'));
        self::assertNotNull($snapshot->fact('providers.sms_health'));
        self::assertSame('unknown', $snapshot->fact('providers.sms_health')?->state);
        self::assertSame('observed', $snapshot->fact('scheduler.run_history')?->state);
        self::assertSame('degraded', $snapshot->fact('scheduler.stale_running_runs')?->state);
        self::assertSame(1, $snapshot->fact('scheduler.stale_running_runs')?->value);
        self::assertSame('manual_review', $snapshot->fact('alerts.delivery_failed')?->state);
        self::assertSame(1, $snapshot->fact('alerts.delivery_failed')?->value);
        self::assertLessThanOrEqual(200, count($snapshot->facts));

        $safeJson = json_encode(
            array_map(static fn ($fact): array => $fact->safeArray(), $snapshot->facts),
            JSON_THROW_ON_ERROR,
        );
        self::assertStringNotContainsString('secret-panel.example.test', $safeJson);
        self::assertStringNotContainsString('ciphertext-super-secret', $safeJson);
        self::assertStringNotContainsString('base_url', $safeJson);
        self::assertStringNotContainsString('encrypted_credentials', $safeJson);

        $plainUserId = $this->quoteUser('customer');
        try {
            $this->app->make(OperationsCenterService::class)->snapshot($plainUserId);
            self::fail('Operations Center snapshot must require operations.view.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }
}

final class FakeOperationsOutboxRuntime implements OutboxRuntime
{
    public int $calls = 0;

    public function dispatchBatch(int $limit): OutboxRuntimeResult
    {
        $this->calls++;

        return new OutboxRuntimeResult(
            examined: 1,
            success: 1,
            retryableFailure: 0,
            definitiveFailure: 0,
            uncertainResult: 0,
            dueBacklog: 0,
            oldestDueAgeSeconds: null,
            reviewRequired: 2,
        );
    }
}
