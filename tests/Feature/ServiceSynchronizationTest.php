<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/ServiceOperationalPanelAdapter.php';

use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\PanelAdapterRegistry;
use App\Modules\Panels\Application\PanelCredentialPolicy;
use App\Modules\Provisioning\Application\ProvisioningPanelAdapterResolver;
use App\Modules\Provisioning\Application\ServiceImportService;
use App\Modules\Provisioning\Application\ServiceOperationalContext;
use App\Modules\Provisioning\Application\ServiceSyncDatabaseAuthority;
use App\Modules\Provisioning\Application\ServiceSynchronizationService;
use App\Modules\Provisioning\Domain\ServiceSyncResolutionAction;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

/** @requirement SVC-001 SVC-010 SVC-013 PRV-003 DAT-003 DAT-004 SEC-002 QUA-004 */
final class ServiceSynchronizationTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config()->set('service_sync.lease_seconds', 120);
        config()->set('service_sync.severity.missing_remote', 'critical');
        config()->set('service_sync.severity.expired_local_active_remote', 'warning');
        config()->set('service_sync.severity.lifecycle_mismatch', 'warning');
        config()->set('service_sync.severity.remote_identity_mismatch', 'critical');
        config()->set('service_sync.severity.unexpected_entitlement', 'warning');
    }

    public function test_clean_sync_records_authoritative_remote_snapshot_outside_transaction(): void
    {
        $fixture = $this->syncFixture('clean');
        $remote = $this->snapshot('remote-clean', 'sync-clean', PanelServiceStatus::Active, 10_000, 500, '+30 days');
        $servicePublicId = $this->attachedService($fixture, $remote, 'clean');

        $receipt = $this->app->make(ServiceSynchronizationService::class)->syncOne($servicePublicId);

        self::assertSame(1, $receipt->candidates);
        self::assertSame(1, $receipt->processed);
        self::assertSame(0, $receipt->anomalies);
        self::assertSame(0, $receipt->failures);
        self::assertSame(0, $receipt->skipped);
        $snapshot = DB::table('service_sync_snapshots')->where('service_sync_run_id', $receipt->runId)->first();
        self::assertNotNull($snapshot);
        self::assertSame('present', $snapshot->remote_disposition);
        self::assertSame('active', $snapshot->remote_status);
        self::assertSame(10_000, (int) $snapshot->remote_data_limit_bytes);
        self::assertSame(500, (int) $snapshot->remote_used_bytes);
        self::assertSame(hash('sha256', 'remote-clean'), $snapshot->expected_remote_id_hash);
        self::assertSame(hash('sha256', 'remote-clean'), $snapshot->remote_id_hash);
        self::assertSame(0, DB::table('service_sync_anomalies')->count());
        self::assertNotEmpty($fixture['adapter']->lookupTransactionLevels);
        self::assertSame([0], array_values(array_unique($fixture['adapter']->lookupTransactionLevels)));
    }

    public function test_provider_lookup_failure_is_unavailable_evidence_not_missing_remote_anomaly(): void
    {
        $fixture = $this->syncFixture('unavailable');
        $remote = $this->snapshot('remote-unavailable', 'sync-unavailable', PanelServiceStatus::Active, null, 0, '+10 days');
        $servicePublicId = $this->attachedService($fixture, $remote, 'unavailable');
        $fixture['adapter']->lookupUnavailable = true;

        $receipt = $this->app->make(ServiceSynchronizationService::class)->syncOne($servicePublicId);

        self::assertSame(1, $receipt->processed);
        self::assertSame(1, $receipt->failures);
        self::assertSame(0, $receipt->anomalies);
        self::assertSame('unavailable', DB::table('service_sync_snapshots')->where('service_sync_run_id', $receipt->runId)->value('remote_disposition'));
        self::assertSame(0, DB::table('service_sync_anomalies')->count());
    }

    public function test_missing_and_lifecycle_mismatch_are_durable_deterministic_anomalies(): void
    {
        $fixture = $this->syncFixture('state-anomalies');
        $remote = $this->snapshot('remote-state', 'sync-state', PanelServiceStatus::Active, 1000, 100, '+20 days');
        $servicePublicId = $this->attachedService($fixture, $remote, 'state-anomalies');
        $service = $this->app->make(ServiceSynchronizationService::class);

        $fixture['adapter']->remove('remote-state');
        $first = $service->syncOne($servicePublicId);
        self::assertSame(1, $first->anomalies);
        $missing = DB::table('service_sync_anomalies')->where('classification', 'missing_remote')->first();
        self::assertNotNull($missing);
        self::assertSame('critical', $missing->severity);
        self::assertSame(1, (int) $missing->occurrence_count);

        $second = $service->syncOne($servicePublicId);
        self::assertSame(1, $second->anomalies);
        self::assertSame(1, DB::table('service_sync_anomalies')->where('classification', 'missing_remote')->count());
        self::assertSame(2, (int) DB::table('service_sync_anomalies')->where('id', $missing->id)->value('occurrence_count'));

        $fixture['adapter']->seed($this->snapshot('remote-state', 'sync-state', PanelServiceStatus::Suspended, 1000, 100, '+20 days'));
        $third = $service->syncOne($servicePublicId);
        self::assertSame(1, $third->anomalies);
        self::assertTrue(DB::table('service_sync_anomalies')->where('classification', 'lifecycle_mismatch')->exists());
    }

    public function test_entitlement_change_in_same_authority_cycle_is_anomaly_but_usage_change_is_not(): void
    {
        $fixture = $this->syncFixture('entitlement');
        $remote = $this->snapshot('remote-entitlement', 'sync-entitlement', PanelServiceStatus::Active, 10_000, 100, '+30 days');
        $servicePublicId = $this->attachedService($fixture, $remote, 'entitlement');
        $service = $this->app->make(ServiceSynchronizationService::class);
        self::assertSame(0, $service->syncOne($servicePublicId)->anomalies);

        $fixture['adapter']->seed($this->snapshot('remote-entitlement', 'sync-entitlement', PanelServiceStatus::Active, 10_000, 900, '+30 days'));
        self::assertSame(0, $service->syncOne($servicePublicId)->anomalies, 'Normal usage growth must not be treated as entitlement drift.');

        $fixture['adapter']->seed($this->snapshot('remote-entitlement', 'sync-entitlement', PanelServiceStatus::Active, 20_000, 900, '+30 days'));
        $receipt = $service->syncOne($servicePublicId);
        self::assertSame(1, $receipt->anomalies);
        self::assertTrue(DB::table('service_sync_anomalies')->where('classification', 'unexpected_entitlement')->exists());
    }

    public function test_live_service_lease_skips_concurrent_sync_without_provider_read(): void
    {
        $fixture = $this->syncFixture('lease');
        $remote = $this->snapshot('remote-lease', 'sync-lease', PanelServiceStatus::Active, null, 0, '+5 days');
        $servicePublicId = $this->attachedService($fixture, $remote, 'lease');
        $serviceId = (int) DB::table('service_subscriptions')->where('public_id', $servicePublicId)->value('id');
        $lookupCount = count($fixture['adapter']->lookupTransactionLevels);
        $token = 'test-live-sync-lease-token';
        $connection = DB::connection();
        ServiceSyncDatabaseAuthority::lease($connection, $serviceId, $token);
        try {
            $connection->table('service_sync_leases')->insert([
                'service_subscription_id' => $serviceId,
                'lease_token_hash' => hash('sha256', $token),
                'claimed_at' => now('UTC'),
                'expires_at' => now('UTC')->addMinutes(2),
            ]);
        } finally {
            ServiceSyncDatabaseAuthority::clear($connection);
        }

        $receipt = $this->app->make(ServiceSynchronizationService::class)->syncOne($servicePublicId);

        self::assertSame(0, $receipt->processed);
        self::assertSame(1, $receipt->skipped);
        self::assertSame($lookupCount, count($fixture['adapter']->lookupTransactionLevels));
    }

    public function test_resolution_is_permission_checked_replay_safe_and_does_not_mutate_service(): void
    {
        $fixture = $this->syncFixture('resolution');
        $remote = $this->snapshot('remote-resolution', 'sync-resolution', PanelServiceStatus::Active, null, 0, '+5 days');
        $servicePublicId = $this->attachedService($fixture, $remote, 'resolution');
        $fixture['adapter']->remove('remote-resolution');
        $service = $this->app->make(ServiceSynchronizationService::class);
        $service->syncOne($servicePublicId);
        $anomalyPublicId = (string) DB::table('service_sync_anomalies')->where('classification', 'missing_remote')->value('public_id');
        $before = DB::table('service_subscriptions')->where('public_id', $servicePublicId)->first();
        self::assertNotNull($before);
        $context = new ServiceOperationalContext(
            'service-sync-resolution-request',
            'service-sync-resolution-correlation',
            'service_sync_test',
            'Ignore this expected missing remote Service for the bounded test scenario.',
            $fixture['owner_id'],
        );

        $first = $service->resolve($anomalyPublicId, ServiceSyncResolutionAction::Ignore, $context);
        $second = $service->resolve($anomalyPublicId, ServiceSyncResolutionAction::Ignore, $context);

        self::assertFalse($first->replayed);
        self::assertTrue($second->replayed);
        self::assertSame('resolved', $first->state);
        self::assertSame('resolved', DB::table('service_sync_anomalies')->where('public_id', $anomalyPublicId)->value('state'));
        $after = DB::table('service_subscriptions')->where('public_id', $servicePublicId)->first();
        self::assertNotNull($after);
        self::assertSame((array) $before, (array) $after, 'Sync anomaly resolution must not bypass Service lifecycle/mutation authority.');
    }

    /** @return array{owner_id:int,user_id:int,offering_id:int,target_id:int,adapter:ServiceOperationalPanelAdapter} */
    private function syncFixture(string $suffix): array
    {
        $offering = $this->activeBenefitOffering('service-sync-'.$suffix);
        $ownerId = $this->benefitOwner();
        $userId = $this->benefitUser();
        $targetId = (int) DB::table('plan_offerings')->where('id', $offering['id'])->value('panel_service_target_id');
        $connectionId = (int) DB::table('panel_service_targets')->where('id', $targetId)->value('panel_connection_id');
        DB::table('panel_connections')->where('id', $connectionId)->update([
            'encrypted_credentials' => Crypt::encryptString(json_encode(['token' => 'service-sync-test'], JSON_THROW_ON_ERROR)),
            'base_url' => 'https://panel.example.com',
            'state' => 'active',
            'updated_at' => now('UTC'),
        ]);
        DB::table('panel_service_targets')->where('id', $targetId)->update(['state' => 'active', 'updated_at' => now('UTC')]);

        $adapter = new ServiceOperationalPanelAdapter;
        $this->app->instance(
            PanelAdapterRegistry::class,
            new PanelAdapterRegistry(
                [new ServiceOperationalPanelAdapterFactory($adapter)],
                $this->app->make(PanelCredentialPolicy::class),
            ),
        );
        $this->app->forgetInstance(ProvisioningPanelAdapterResolver::class);
        $this->app->forgetInstance(ServiceImportService::class);
        $this->app->forgetInstance(ServiceSynchronizationService::class);

        return [
            'owner_id' => $ownerId,
            'user_id' => $userId,
            'offering_id' => $offering['id'],
            'target_id' => $targetId,
            'adapter' => $adapter,
        ];
    }

    /** @param array{owner_id:int,user_id:int,offering_id:int,target_id:int,adapter:ServiceOperationalPanelAdapter} $fixture */
    private function attachedService(array $fixture, RemoteServiceSnapshot $snapshot, string $suffix): string
    {
        $fixture['adapter']->seed($snapshot);
        $context = new ServiceOperationalContext(
            'service-sync-import-'.substr(hash('sha256', $suffix), 0, 32),
            'service-sync-import-correlation-'.substr(hash('sha256', $suffix), 0, 20),
            'service_sync_test',
            'Attach a remote Service for Service synchronization tests.',
            $fixture['owner_id'],
        );
        $imports = $this->app->make(ServiceImportService::class);
        $preview = $imports->preview(
            'https://panel.example.com/sub/'.$snapshot->remoteId,
            $fixture['target_id'],
            $fixture['user_id'],
            $fixture['offering_id'],
            $context,
        );
        $attached = $imports->attach($preview->importPublicId, $context);
        self::assertNotNull($attached->serviceSubscriptionPublicId);

        return $attached->serviceSubscriptionPublicId;
    }

    private function snapshot(
        string $remoteId,
        string $username,
        PanelServiceStatus $status,
        ?int $dataLimitBytes,
        int $usedBytes,
        string $expiryModifier,
    ): RemoteServiceSnapshot {
        $expiresAt = new \DateTimeImmutable($expiryModifier, new \DateTimeZone('UTC'));

        return new RemoteServiceSnapshot(
            $remoteId,
            $username,
            $status,
            $dataLimitBytes,
            $usedBytes,
            $expiresAt,
            hash('sha256', implode('|', [$remoteId, $username, $status->value, (string) $dataLimitBytes, (string) $usedBytes, $expiresAt->format(DATE_ATOM)])),
            hash('sha256', 'equivalence:'.$remoteId.':'.$username),
        );
    }
}
