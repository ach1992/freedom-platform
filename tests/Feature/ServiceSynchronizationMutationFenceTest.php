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
use App\Modules\Provisioning\Application\ServiceMutationQueueService;
use App\Modules\Provisioning\Application\ServiceOperationalContext;
use App\Modules\Provisioning\Application\ServiceSynchronizationService;
use App\Modules\Provisioning\Domain\ServiceMutationType;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

/** @requirement SVC-010 SVC-013 ARCH-004 DAT-003 QUA-004 */
final class ServiceSynchronizationMutationFenceTest extends TestCase
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

    public function test_existing_unresolved_mutation_skips_sync_before_provider_read(): void
    {
        $fixture = $this->syncFixture('unresolved-before-read');
        $remote = $this->snapshot(
            'remote-unresolved-before-read',
            'sync-unresolved-before-read',
            PanelServiceStatus::Active,
        );
        $servicePublicId = $this->attachedService($fixture, $remote, 'unresolved-before-read');
        $lookupCount = count($fixture['adapter']->lookupTransactionLevels);

        $this->app->make(ServiceMutationQueueService::class)->queue(
            $servicePublicId,
            ServiceMutationType::ResetUsage,
            'service-sync-unresolved-request',
            'service-sync-unresolved-correlation',
        );

        $receipt = $this->app->make(ServiceSynchronizationService::class)->syncOne($servicePublicId);

        self::assertSame(0, $receipt->processed);
        self::assertSame(1, $receipt->skipped);
        self::assertSame(0, $receipt->anomalies);
        self::assertSame(0, $receipt->failures);
        self::assertSame($lookupCount, count($fixture['adapter']->lookupTransactionLevels));
        self::assertSame(0, DB::table('service_sync_snapshots')->where('service_sync_run_id', $receipt->runId)->count());
    }

    public function test_mutation_started_during_provider_read_discards_stale_sync_observation(): void
    {
        $fixture = $this->syncFixture('mutation-during-read');
        $remote = $this->snapshot(
            'remote-mutation-during-read',
            'sync-mutation-during-read',
            PanelServiceStatus::Active,
        );
        $servicePublicId = $this->attachedService($fixture, $remote, 'mutation-during-read');
        $snapshotCount = DB::table('service_sync_snapshots')->count();
        $anomalyCount = DB::table('service_sync_anomalies')->count();

        $fixture['adapter']->afterLookup = function () use ($servicePublicId): void {
            $this->app->make(ServiceMutationQueueService::class)->queue(
                $servicePublicId,
                ServiceMutationType::ResetUsage,
                'service-sync-race-request',
                'service-sync-race-correlation',
            );
        };

        $receipt = $this->app->make(ServiceSynchronizationService::class)->syncOne($servicePublicId);

        self::assertSame(0, $receipt->processed);
        self::assertSame(1, $receipt->skipped);
        self::assertSame(0, $receipt->anomalies);
        self::assertSame(0, $receipt->failures);
        self::assertSame($snapshotCount, DB::table('service_sync_snapshots')->count());
        self::assertSame($anomalyCount, DB::table('service_sync_anomalies')->count());
    }

    public function test_batch_selection_does_not_starve_healthy_service_behind_unresolved_mutation(): void
    {
        $fixture = $this->syncFixture('batch-fairness');
        $blockedServicePublicId = $this->attachedService(
            $fixture,
            $this->snapshot('remote-batch-blocked', 'sync-batch-blocked', PanelServiceStatus::Active),
            'batch-blocked',
        );
        $healthyServicePublicId = $this->attachedService(
            $fixture,
            $this->snapshot('remote-batch-healthy', 'sync-batch-healthy', PanelServiceStatus::Active),
            'batch-healthy',
        );

        $this->app->make(ServiceMutationQueueService::class)->queue(
            $blockedServicePublicId,
            ServiceMutationType::ResetUsage,
            'service-sync-batch-blocked-request',
            'service-sync-batch-blocked-correlation',
        );

        $receipt = $this->app->make(ServiceSynchronizationService::class)->processBatch(1);
        $healthyServiceId = (int) DB::table('service_subscriptions')->where('public_id', $healthyServicePublicId)->value('id');

        self::assertSame(1, $receipt->candidates);
        self::assertSame(1, $receipt->processed);
        self::assertSame(0, $receipt->skipped);
        self::assertSame(0, $receipt->anomalies);
        self::assertSame(0, $receipt->failures);
        self::assertTrue(DB::table('service_sync_snapshots')
            ->where('service_sync_run_id', $receipt->runId)
            ->where('service_subscription_id', $healthyServiceId)
            ->exists());
    }

    /** @return array{owner_id:int,user_id:int,offering_id:int,target_id:int,adapter:ServiceOperationalPanelAdapter} */
    private function syncFixture(string $suffix): array
    {
        $offering = $this->activeBenefitOffering('service-sync-mutation-fence-'.$suffix);
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
            'service-sync-mutation-import-'.substr(hash('sha256', $suffix), 0, 24),
            'service-sync-mutation-correlation-'.substr(hash('sha256', $suffix), 0, 16),
            'service_sync_test',
            'Attach a remote Service for synchronization mutation fence tests.',
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
    ): RemoteServiceSnapshot {
        $expiresAt = new \DateTimeImmutable('+30 days', new \DateTimeZone('UTC'));

        return new RemoteServiceSnapshot(
            $remoteId,
            $username,
            $status,
            10_000,
            500,
            $expiresAt,
            hash('sha256', implode('|', [$remoteId, $username, $status->value, '10000', '500', $expiresAt->format(DATE_ATOM)])),
            hash('sha256', 'equivalence:'.$remoteId.':'.$username),
        );
    }
}
