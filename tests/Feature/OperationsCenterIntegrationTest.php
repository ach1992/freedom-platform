<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Operations\Application\Contracts\BackupRepository;
use App\Modules\Operations\Application\Contracts\RestoreWorkspace;
use App\Modules\Operations\Application\Contracts\UpdateWorkspace;
use App\Modules\Operations\Application\OperationalAlertLifecycleService;
use App\Modules\Operations\Application\OperationsCenterActionService;
use App\Modules\Operations\Application\OperationsCenterService;
use App\Modules\Operations\Infrastructure\FilesystemBackupRepository;
use App\Modules\Operations\Infrastructure\FilesystemRestoreWorkspace;
use App\Modules\Operations\Infrastructure\FilesystemUpdateWorkspace;
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

    public function test_snapshot_uses_canonical_outbox_claimability_and_hides_failed_job_and_outbox_payloads(): void
    {
        $administratorId = $this->ownerAdministrator();
        $userId = (int) DB::table('administrators')->where('id', $administratorId)->value('user_id');
        $now = now('UTC');

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{"secret":"failed-job-payload-secret"}',
            'exception' => 'failed-job-exception-secret',
            'failed_at' => $now->copy()->subMinute(),
        ]);

        $this->insertOutboxFixture('pending', 'pending', $now->copy()->subMinutes(5), null, null, 'pending-secret-destination');
        $this->insertOutboxFixture('retry', 'retry', $now->copy()->subMinutes(4), null, null, 'retry-secret-destination');
        $this->insertOutboxFixture('active-lease', 'leased', $now->copy()->subMinutes(3), $now->copy()->addMinutes(5), null, 'active-lease-secret');
        $this->insertOutboxFixture('expired-lease', 'leased', $now->copy()->subMinutes(2), $now->copy()->subMinute(), null, 'expired-lease-secret');
        $this->insertOutboxFixture('review', 'review_required', $now->copy()->subMinute(), null, null, 'review-secret-destination');
        $this->insertOutboxFixture('processed', 'processed', $now->copy()->subMinutes(10), null, $now->copy()->subMinutes(9), 'processed-secret-destination');

        $snapshot = $this->app->make(OperationsCenterService::class)->snapshot($userId);
        self::assertSame('manual_review', $snapshot->fact('queue.failed_jobs')?->state);
        self::assertSame(1, $snapshot->fact('queue.failed_jobs')?->value);
        self::assertSame('observed', $snapshot->fact('outbox.due_backlog')?->state);
        self::assertSame(3, $snapshot->fact('outbox.due_backlog')?->value);
        self::assertSame('manual_review', $snapshot->fact('outbox.review_required')?->state);
        self::assertSame(1, $snapshot->fact('outbox.review_required')?->value);

        $safeJson = json_encode(
            array_map(static fn ($fact): array => $fact->safeArray(), $snapshot->facts),
            JSON_THROW_ON_ERROR,
        );
        foreach ([
            'failed-job-payload-secret',
            'failed-job-exception-secret',
            'pending-secret-destination',
            'retry-secret-destination',
            'active-lease-secret',
            'expired-lease-secret',
            'review-secret-destination',
            'processed-secret-destination',
        ] as $secret) {
            self::assertStringNotContainsString($secret, $safeJson);
        }
    }

    /** @requirement OPS-001 OPS-003 RUN-003 DAT-003 QUA-011 */
    public function test_snapshot_expands_observed_worker_queue_groups_into_individual_backlog_facts(): void
    {
        $administratorId = $this->ownerAdministrator();
        $userId = (int) DB::table('administrators')->where('id', $administratorId)->value('user_id');
        $now = now('UTC');

        DB::table('worker_heartbeats')->insert([
            'worker_id' => 'operations-queue-group-worker',
            'queue' => 'provisioning,telegram-ingress,telegram-delivery,synchronization,default',
            'host_hash' => hash('sha256', 'operations-queue-group-worker-host'),
            'release_version' => 'test',
            'boot_id' => null,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $snapshot = $this->app->make(OperationsCenterService::class)->snapshot($userId);

        foreach (['provisioning', 'telegram-ingress', 'telegram-delivery', 'synchronization', 'default'] as $queue) {
            self::assertNotNull($snapshot->fact('queue.backlog.'.$queue));
        }
        foreach ($snapshot->facts as $fact) {
            self::assertStringNotContainsString(',', $fact->code);
        }
    }

    public function test_snapshot_preserves_panel_and_worker_inventory_above_previous_row_bounds(): void
    {
        $administratorId = $this->ownerAdministrator();
        $userId = (int) DB::table('administrators')->where('id', $administratorId)->value('user_id');
        $now = now('UTC');

        $panels = [];
        for ($index = 1; $index <= 125; $index++) {
            $panels[] = [
                'code' => 'operations-panel-'.$index,
                'provider_type' => 'marzban',
                'name_fa' => 'پنل '.$index,
                'name_en' => 'Panel '.$index,
                'base_url' => 'https://panel-'.$index.'.example.test/private',
                'encrypted_credentials' => 'panel-credential-secret-'.$index,
                'credential_key_version' => 1,
                'tls_policy' => 'system_ca',
                'custom_ca_disk' => null,
                'custom_ca_path' => null,
                'certificate_pin_sha256' => null,
                'network_policy' => 'public_only',
                'state' => 'disabled',
                'last_test_status' => 'success',
                'last_panel_version' => '1.0.0',
                'last_capabilities_hash' => hash('sha256', 'operations-panel-capabilities-'.$index),
                'last_tested_at' => $now,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('panel_connections')->insert($panels);

        $workers = [];
        for ($index = 1; $index <= 150; $index++) {
            $workers[] = [
                'worker_id' => 'operations-worker-'.$index,
                'queue' => 'default',
                'host_hash' => hash('sha256', 'operations-worker-host-'.$index),
                'release_version' => 'test',
                'boot_id' => null,
                'last_seen_at' => $index <= 100 ? $now : $now->copy()->subHours(2),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('worker_heartbeats')->insert($workers);

        $snapshot = $this->app->make(OperationsCenterService::class)->snapshot($userId);
        self::assertSame(125, $snapshot->fact('panels.inventory')?->value);
        self::assertSame(125, $snapshot->fact('panels.provider.marzban')?->value);
        self::assertSame(150, $snapshot->fact('workers.heartbeat')?->value);
        self::assertSame('degraded', $snapshot->fact('workers.heartbeat')?->state);
        self::assertStringContainsString('fresh=100;stale=50;', (string) $snapshot->fact('workers.heartbeat')?->detail);

        $safeJson = json_encode(
            array_map(static fn ($fact): array => $fact->safeArray(), $snapshot->facts),
            JSON_THROW_ON_ERROR,
        );
        self::assertStringNotContainsString('panel-credential-secret-', $safeJson);
        self::assertStringNotContainsString('example.test/private', $safeJson);
    }

    public function test_snapshot_consumes_durable_backup_update_and_restore_status_authorities(): void
    {
        $administratorId = $this->ownerAdministrator();
        $userId = (int) DB::table('administrators')->where('id', $administratorId)->value('user_id');

        $backup = $this->createStub(BackupRepository::class);
        $backup->method('operationalStatus')->willReturn([
            'completed_count' => 2,
            'inspection_complete' => true,
            'inspected_entries' => 2,
            'latest_bytes' => 4096,
            'latest_completed_at' => '2026-09-30T08:00:00+00:00',
        ]);
        $update = $this->createStub(UpdateWorkspace::class);
        $update->method('operationalStatus')->willReturn([
            'current_release_id' => 'release-20260930',
            'application_version' => '0.8.0',
            'latest_status' => 'failed_pre_mutation',
            'latest_failure_code' => 'preflight_failed',
            'latest_completed_at' => '2026-09-30T08:01:00+00:00',
            'report_inventory_complete' => true,
            'inspected_entries' => 1,
        ]);
        $restore = $this->createStub(RestoreWorkspace::class);
        $restore->method('operationalStatus')->willReturn([
            'latest_status' => 'completed',
            'latest_failure_code' => null,
            'latest_completed_at' => '2026-09-30T08:02:00+00:00',
            'report_inventory_complete' => true,
            'inspected_entries' => 1,
        ]);
        $this->app->instance(BackupRepository::class, $backup);
        $this->app->instance(UpdateWorkspace::class, $update);
        $this->app->instance(RestoreWorkspace::class, $restore);

        config()->set('operations.backup.enabled', true);
        $snapshot = $this->app->make(OperationsCenterService::class)->snapshot($userId);

        self::assertSame('observed', $snapshot->fact('backup.runtime')?->state);
        self::assertSame(2, $snapshot->fact('backup.completed')?->value);
        self::assertSame('release-20260930;app=0.8.0', $snapshot->fact('release.version')?->detail);
        self::assertSame('degraded', $snapshot->fact('update.latest')?->state);
        self::assertSame('status=failed_pre_mutation;failure_code=preflight_failed', $snapshot->fact('update.latest')?->detail);
        self::assertSame('observed', $snapshot->fact('restore.latest')?->state);
        self::assertSame('status=completed', $snapshot->fact('restore.latest')?->detail);
    }

    public function test_snapshot_with_real_filesystem_status_authorities_is_non_mutating(): void
    {
        $administratorId = $this->ownerAdministrator();
        $userId = (int) DB::table('administrators')->where('id', $administratorId)->value('user_id');
        $base = storage_path('framework/testing/operations-center-readonly-'.bin2hex(random_bytes(4)));
        $deployment = $base.'/deployment';
        $backupRoot = $base.'/backups';

        mkdir($deployment.'/releases', 0700, true);
        mkdir($deployment.'/shared/storage', 0700, true);
        chmod($deployment.'/shared', 0750);
        clearstatcache(true, $deployment.'/shared');
        $sharedMode = fileperms($deployment.'/shared') & 0777;

        $this->app->instance(BackupRepository::class, new FilesystemBackupRepository($backupRoot));
        $this->app->instance(UpdateWorkspace::class, new FilesystemUpdateWorkspace($deployment));
        $this->app->instance(RestoreWorkspace::class, new FilesystemRestoreWorkspace($backupRoot));
        config()->set('operations.backup.enabled', true);

        try {
            $snapshot = $this->app->make(OperationsCenterService::class)->snapshot($userId);

            self::assertSame('unknown', $snapshot->fact('backup.runtime')?->state);
            self::assertSame('empty', $snapshot->fact('backup.completed')?->state);
            self::assertSame('unknown', $snapshot->fact('update.latest')?->state);
            self::assertSame('unknown', $snapshot->fact('restore.latest')?->state);
            self::assertDirectoryDoesNotExist($backupRoot);
            self::assertDirectoryDoesNotExist($deployment.'/shared/update-reports');
            clearstatcache(true, $deployment.'/shared');
            self::assertSame($sharedMode, fileperms($deployment.'/shared') & 0777);
        } finally {
            $this->removeOperationsStatusTree($base);
        }
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

    private function removeOperationsStatusTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->removeOperationsStatusTree($path.'/'.$name);
            }
        }
        @rmdir($path);
    }

    private function insertOutboxFixture(
        string $name,
        string $state,
        mixed $availableAt,
        mixed $leasedUntil,
        mixed $processedAt,
        string $secret,
    ): void {
        $payload = json_encode(['destination' => $secret, 'body' => 'payload-'.$secret], JSON_THROW_ON_ERROR);
        DB::table('outbox_messages')->insert([
            'id' => (string) Str::uuid(),
            'event_key' => 'operations:test:'.$name,
            'event_type' => 'operations.test',
            'aggregate_type' => 'operations_test',
            'aggregate_id' => $name,
            'payload' => $payload,
            'payload_hash' => hash('sha256', $payload),
            'correlation_id' => 'operations-test-'.$name,
            'available_at' => $availableAt,
            'processed_at' => $processedAt,
            'dispatch_state' => $state,
            'lease_token' => $state === 'leased' ? 'operations-lease-'.$name : null,
            'leased_until' => $leasedUntil,
            'review_reason' => $state === 'review_required' ? 'operations_test_review' : null,
            'attempts' => $state === 'retry' ? 1 : 0,
            'last_error_class' => null,
            'last_error_code' => null,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
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
