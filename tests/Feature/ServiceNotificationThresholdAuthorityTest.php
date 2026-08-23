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
use App\Modules\Provisioning\Application\ServiceNotificationDatabaseAuthority;
use App\Modules\Provisioning\Application\ServiceNotificationThresholdService;
use App\Modules\Provisioning\Application\ServiceOperationalContext;
use App\Modules\Provisioning\Application\ServiceSynchronizationService;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

/** @requirement SVC-013 SVC-014 DAT-003 DAT-004 QUA-004 QUA-007 */
final class ServiceNotificationThresholdAuthorityTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use DatabaseTruncation;

    private ServiceNotificationThresholdTestClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config()->set('service_notifications.expiry_threshold_days', [7, 3, 1, 0]);
        config()->set('service_notifications.low_balance_irr', 0);

        /** @var Migration $operationalMigration */
        $operationalMigration = require database_path('migrations/2026_08_19_000140_enable_service_operational_authority.php');
        $operationalMigration->up();

        /** @var Migration $cursorMigration */
        $cursorMigration = require database_path('migrations/2026_08_23_000210_enable_service_notification_scan_cursor.php');
        $cursorMigration->down();
        $cursorMigration->up();

        $this->clock = new ServiceNotificationThresholdTestClock(
            new DateTimeImmutable('2030-01-01 00:00:00.000000', new DateTimeZone('UTC')),
        );
        Carbon::setTestNow(Carbon::instance($this->clock->value));
        $this->app->instance(Clock::class, $this->clock);
        $this->app->forgetInstance(ServiceSynchronizationService::class);
        $this->app->forgetInstance(ServiceNotificationThresholdService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_expiry_threshold_is_inclusive_and_resets_on_new_authoritative_expiry_cycle(): void
    {
        $fixture = $this->fixture('expiry-boundary-reset');
        $remoteId = 'notification-expiry-boundary-reset';
        $servicePublicId = $this->attachService(
            $fixture,
            $this->snapshot($remoteId, 'notification-expiry-boundary-reset-user', $this->clock->value->modify('+7 days +1 second')),
            'expiry-boundary-reset',
        );
        $sync = $this->app->make(ServiceSynchronizationService::class);
        $notifications = $this->app->make(ServiceNotificationThresholdService::class);

        self::assertSame(1, $sync->syncOne($servicePublicId)->processed);
        $outside = $notifications->processBatch(1);
        self::assertSame(0, $outside->triggered);
        self::assertSame(0, DB::table('service_notification_states')->where('notification_type', 'expiry')->count());

        $fixture['adapter']->seed($this->snapshot(
            $remoteId,
            'notification-expiry-boundary-reset-user',
            $this->clock->value->modify('+7 days'),
        ));
        self::assertSame(1, $sync->syncOne($servicePublicId)->processed);
        $boundary = $notifications->processBatch(1);
        self::assertSame(1, $boundary->triggered);
        self::assertSame(1, $boundary->queued);

        $sevenDay = DB::table('service_notification_states')
            ->where('notification_type', 'expiry')
            ->where('threshold_code', 'expiry_7d')
            ->first(['id', 'state', 'source_id']);
        self::assertNotNull($sevenDay);
        self::assertSame('triggered', $sevenDay->state);
        self::assertNotNull($sevenDay->source_id);

        $fixture['adapter']->seed($this->snapshot(
            $remoteId,
            'notification-expiry-boundary-reset-user',
            $this->clock->value->modify('+3 days'),
        ));
        self::assertSame(1, $sync->syncOne($servicePublicId)->processed);
        $reset = $notifications->processBatch(1);
        self::assertSame(1, $reset->triggered);
        self::assertSame(1, $reset->expired);
        self::assertSame(1, $reset->queued);

        self::assertSame('expired', DB::table('service_notification_states')
            ->where('id', (int) $sevenDay->id)
            ->value('state'));
        $threeDay = DB::table('service_notification_states')
            ->where('notification_type', 'expiry')
            ->where('threshold_code', 'expiry_3d')
            ->first(['state', 'source_id']);
        self::assertNotNull($threeDay);
        self::assertSame('triggered', $threeDay->state);
        self::assertNotSame((int) $sevenDay->source_id, (int) $threeDay->source_id);
    }

    public function test_latest_unavailable_snapshot_suppresses_older_present_expiry_evidence(): void
    {
        $fixture = $this->fixture('expiry-unavailable');
        $remoteId = 'notification-expiry-unavailable';
        $servicePublicId = $this->attachService(
            $fixture,
            $this->snapshot($remoteId, 'notification-expiry-unavailable-user', $this->clock->value->modify('+3 days')),
            'expiry-unavailable',
        );
        $sync = $this->app->make(ServiceSynchronizationService::class);
        $notifications = $this->app->make(ServiceNotificationThresholdService::class);

        self::assertSame(1, $sync->syncOne($servicePublicId)->processed);
        $initial = $notifications->processBatch(1);
        self::assertSame(1, $initial->triggered);
        self::assertSame(1, $initial->queued);
        $state = DB::table('service_notification_states')
            ->where('notification_type', 'expiry')
            ->where('threshold_code', 'expiry_3d')
            ->first(['id', 'state', 'source_id']);
        self::assertNotNull($state);
        self::assertSame('triggered', $state->state);

        $fixture['adapter']->lookupUnavailable = true;
        $unavailable = $sync->syncOne($servicePublicId);
        self::assertSame(1, $unavailable->processed);
        self::assertSame(1, $unavailable->failures);
        self::assertSame(
            'unavailable',
            DB::table('service_sync_snapshots')
                ->where('service_subscription_id', (int) DB::table('service_subscriptions')->where('public_id', $servicePublicId)->value('id'))
                ->orderByDesc('observed_at')
                ->orderByDesc('id')
                ->value('remote_disposition'),
        );

        $suppressed = $notifications->processBatch(1);
        self::assertSame(0, $suppressed->triggered);
        self::assertSame(1, $suppressed->expired);
        self::assertSame('expired', DB::table('service_notification_states')
            ->where('id', (int) $state->id)
            ->value('state'));
        self::assertSame(1, DB::table('service_notification_states')
            ->where('notification_type', 'expiry')
            ->count());
    }

    public function test_fixed_notification_session_flags_cannot_forge_state_without_operational_capability(): void
    {
        $fixture = $this->fixture('forgery');
        $servicePublicId = $this->attachService(
            $fixture,
            $this->snapshot(
                'notification-forgery',
                'notification-forgery-user',
                $this->clock->value->modify('+30 days'),
            ),
            'forgery',
        );
        $serviceId = (int) DB::table('service_subscriptions')->where('public_id', $servicePublicId)->value('id');
        $episode = hash('sha256', 'forged-notification-episode');
        $cycle = hash('sha256', 'forged-notification-cycle');
        $timestamp = $this->clock->value->format('Y-m-d H:i:s.u');
        $correlationId = 'forged-service-notification';
        $connection = DB::connection();
        $connection->statement(
            <<<'SQL'
SET @app_service_operational_capability = 'forged',
    @app_service_notification_authority = 'service_notification_create_v1',
    @app_service_notification_service_id = ?,
    @app_service_notification_episode_key = ?,
    @app_service_notification_type = 'low_balance',
    @app_service_notification_threshold_code = 'low_balance',
    @app_service_notification_cycle_key = ?,
    @app_service_notification_source_type = 'wallet_balance',
    @app_service_notification_source_id = 500000,
    @app_service_notification_timestamp = ?,
    @app_service_notification_correlation_id = ?
SQL,
            [$serviceId, $episode, $cycle, $timestamp, $correlationId],
        );

        try {
            $connection->table('service_notification_states')->insert([
                'public_id' => (string) Str::ulid(),
                'service_subscription_id' => $serviceId,
                'episode_key_hash' => $episode,
                'notification_type' => 'low_balance',
                'threshold_code' => 'low_balance',
                'cycle_key_hash' => $cycle,
                'source_type' => 'wallet_balance',
                'source_id' => 500000,
                'state' => 'triggered',
                'latest_delivery_attempt_id' => null,
                'latest_retry_ordinal' => null,
                'next_retry_at' => null,
                'triggered_at' => $timestamp,
                'notified_at' => null,
                'acknowledged_at' => null,
                'escalated_at' => null,
                'expired_at' => null,
                'last_correlation_id' => $correlationId,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            self::fail('Fixed Service notification session flags must not forge authority without the operational capability.');
        } catch (QueryException) {
            self::assertSame(0, DB::table('service_notification_states')->count());
        } finally {
            ServiceNotificationDatabaseAuthority::clear($connection);
        }
    }

    /** @return array{owner_id:int,user_id:int,offering_id:int,target_id:int,adapter:ServiceOperationalPanelAdapter} */
    private function fixture(string $suffix): array
    {
        $offering = $this->activeBenefitOffering('service-notification-'.$suffix);
        $ownerId = $this->benefitOwner();
        $userId = $this->benefitUser();
        $targetId = (int) DB::table('plan_offerings')->where('id', $offering['id'])->value('panel_service_target_id');
        $connectionId = (int) DB::table('panel_service_targets')->where('id', $targetId)->value('panel_connection_id');
        $profileId = DB::table('panel_target_protocol_profiles')
            ->where('panel_service_target_id', $targetId)
            ->value('panel_protocol_profile_id');
        self::assertNotNull($profileId);
        DB::table('panel_protocol_profiles')->where('id', (int) $profileId)->update([
            'host' => 'panel.example.com',
            'updated_at' => now('UTC'),
        ]);
        DB::table('panel_connections')->where('id', $connectionId)->update([
            'encrypted_credentials' => Crypt::encryptString(json_encode(['token' => 'service-notification-threshold-test'], JSON_THROW_ON_ERROR)),
            'base_url' => 'https://panel.example.com',
            'state' => 'active',
            'updated_at' => now('UTC'),
        ]);
        DB::table('panel_service_targets')->where('id', $targetId)->update([
            'state' => 'active',
            'updated_at' => now('UTC'),
        ]);

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
        $this->app->forgetInstance(ServiceNotificationThresholdService::class);

        return [
            'owner_id' => $ownerId,
            'user_id' => $userId,
            'offering_id' => $offering['id'],
            'target_id' => $targetId,
            'adapter' => $adapter,
        ];
    }

    /** @param array{owner_id:int,user_id:int,offering_id:int,target_id:int,adapter:ServiceOperationalPanelAdapter} $fixture */
    private function attachService(array $fixture, RemoteServiceSnapshot $snapshot, string $suffix): string
    {
        $fixture['adapter']->seed($snapshot);
        $context = new ServiceOperationalContext(
            'service-notification-import-'.substr(hash('sha256', $suffix), 0, 32),
            'service-notification-import-correlation-'.substr(hash('sha256', $suffix), 0, 20),
            'service_notification_test',
            'Attach a remote Service for notification threshold authority coverage.',
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

    private function snapshot(string $remoteId, string $username, DateTimeImmutable $expiresAt): RemoteServiceSnapshot
    {
        return new RemoteServiceSnapshot(
            $remoteId,
            $username,
            PanelServiceStatus::Active,
            10_000,
            0,
            $expiresAt,
            hash('sha256', implode('|', [$remoteId, $username, $expiresAt->format(DATE_ATOM)])),
            hash('sha256', 'equivalence:'.$remoteId.':'.$username),
        );
    }
}

final class ServiceNotificationThresholdTestClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}
