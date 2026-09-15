<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/ServiceOperationalPanelAdapter.php';

use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\PanelAdapterRegistry;
use App\Modules\Panels\Application\PanelCredentialPolicy;
use App\Modules\Provisioning\Application\ProvisioningPanelAdapterResolver;
use App\Modules\Provisioning\Application\ServiceDeliveryEffectExecutor;
use App\Modules\Provisioning\Application\ServiceImportService;
use App\Modules\Provisioning\Application\ServiceNotificationCandidateInvalidatedException;
use App\Modules\Provisioning\Application\ServiceNotificationDatabaseAuthority;
use App\Modules\Provisioning\Application\ServiceNotificationThresholdService;
use App\Modules\Provisioning\Application\ServiceOperationalContext;
use App\Modules\Provisioning\Application\ServiceSynchronizationService;
use App\Modules\Provisioning\Domain\ServiceDeliveryEffectState;
use App\Modules\Telegram\Application\Contracts\ProtectedTelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\ProtectedTelegramMessageSender;
use App\Modules\Telegram\Application\ProtectedTelegramPresentation;
use App\Modules\Telegram\Application\ProtectedTelegramSendOutcome;
use App\Modules\Telegram\Application\ProtectedTelegramSendResult;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\Support\RestoresServiceOperationalCapability;
use Tests\TestCase;

/** @requirement SVC-013 SVC-014 DAT-003 DAT-004 SEC-002 QUA-004 QUA-007 */
final class ServiceNotificationExpiryFreshnessAuthorityTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use DatabaseTruncation;
    use RestoresServiceOperationalCapability;

    private const BOT_ID = 770021;

    private ServiceNotificationExpiryFreshnessTestClock $clock;

    private ServiceNotificationExpiryFreshnessTestSender $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config()->set('service_notifications.expiry_threshold_days', [7, 3, 1, 0]);
        config()->set('service_notifications.expiry_snapshot_max_age_seconds', 1800);
        config()->set('service_notifications.low_balance_irr', 0);

        $this->restoreServiceOperationalCapabilitySingleton();

        /** @var Migration $cursorMigration */
        $cursorMigration = require database_path('migrations/2026_08_23_000210_enable_service_notification_scan_cursor.php');
        $cursorMigration->down();
        $cursorMigration->up();

        $this->clock = new ServiceNotificationExpiryFreshnessTestClock(
            new DateTimeImmutable('2030-03-01 00:00:00.000000', new DateTimeZone('UTC')),
        );
        Carbon::setTestNow(Carbon::instance($this->clock->value));
        $this->app->instance(Clock::class, $this->clock);

        $this->sender = new ServiceNotificationExpiryFreshnessTestSender(
            new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::Success,
                'telegram_success',
                messageId: 990021,
            ),
        );
        $this->app->instance(
            ProtectedTelegramDeliveryRuntime::class,
            new ServiceNotificationExpiryFreshnessTestRuntime(self::BOT_ID),
        );
        $this->app->instance(ProtectedTelegramMessageSender::class, $this->sender);
        $this->forgetRuntimeServices();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_stale_snapshot_cannot_create_a_new_expiry_episode(): void
    {
        $fixture = $this->fixture('stale-new');
        $snapshot = $this->snapshot('stale-new', $this->clock->value->modify('+3 days'));
        $servicePublicId = $this->attachService($fixture, $snapshot, 'stale-new');
        self::assertSame(1, $this->sync()->syncOne($servicePublicId)->processed);

        $this->advance(1801);
        $receipt = $this->notifications()->processBatch(1);

        self::assertSame(0, $receipt->triggered);
        self::assertSame(0, $receipt->queued);
        self::assertSame(0, DB::table('service_notification_states')->where('notification_type', 'expiry')->count());
        self::assertSame(0, DB::table('service_delivery_attempts')->where('purpose', 'notification')->count());
    }

    public function test_triggered_episode_is_preserved_while_stale_and_fresh_equivalent_snapshot_reauthorizes_queue(): void
    {
        $fixture = $this->fixture('stale-queue');
        $snapshot = $this->snapshot('stale-queue', $this->clock->value->modify('+3 days'));
        $servicePublicId = $this->attachService($fixture, $snapshot, 'stale-queue');
        $sync = $this->sync();
        self::assertSame(1, $sync->syncOne($servicePublicId)->processed);

        $agedAfterTrigger = false;
        DB::listen(function (QueryExecuted $query) use (&$agedAfterTrigger): void {
            if ($agedAfterTrigger
                || ! str_contains(strtolower($query->sql), 'service_notification_states')
                || ! str_contains(strtolower($query->sql), 'insert')) {
                return;
            }

            $agedAfterTrigger = true;
            $this->advance(1801);
        });

        $initial = $this->notifications()->processBatch(1);
        self::assertTrue($agedAfterTrigger);
        self::assertSame(1, $initial->triggered);
        self::assertSame(0, $initial->queued);

        $state = DB::table('service_notification_states')
            ->where('notification_type', 'expiry')
            ->first(['id', 'state', 'episode_key_hash', 'source_id', 'expiry_snapshot_max_age_seconds', 'latest_delivery_attempt_id']);
        self::assertNotNull($state);
        self::assertSame('triggered', $state->state);
        self::assertSame(1800, (int) $state->expiry_snapshot_max_age_seconds);
        self::assertNull($state->latest_delivery_attempt_id);
        $originalSourceId = (int) $state->source_id;

        $waiting = $this->notifications()->processBatch(1);
        self::assertSame(0, $waiting->expired);
        self::assertSame(0, $waiting->queued);
        self::assertSame('triggered', DB::table('service_notification_states')->where('id', (int) $state->id)->value('state'));

        $fixture['adapter']->seed($snapshot);
        self::assertSame(1, $sync->syncOne($servicePublicId)->processed);
        $latestSnapshotId = (int) DB::table('service_sync_snapshots')
            ->where('service_subscription_id', $this->serviceId($servicePublicId))
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->value('id');
        self::assertNotSame($originalSourceId, $latestSnapshotId);

        $recovered = $this->notifications()->processBatch(1);
        self::assertSame(0, $recovered->triggered);
        self::assertSame(0, $recovered->expired);
        self::assertSame(1, $recovered->queued);
        self::assertSame(1, DB::table('service_notification_states')->where('notification_type', 'expiry')->count());
        self::assertSame(
            $state->episode_key_hash,
            DB::table('service_notification_states')->where('id', (int) $state->id)->value('episode_key_hash'),
        );
        self::assertNotNull(
            DB::table('service_notification_states')->where('id', (int) $state->id)->value('latest_delivery_attempt_id'),
        );
    }

    public function test_existing_episode_uses_persisted_freshness_when_runtime_config_changes_and_new_episode_uses_new_policy(): void
    {
        $fixture = $this->fixture('drift-old');
        $snapshot = $this->snapshot('drift-old', $this->clock->value->modify('+3 days'));
        $servicePublicId = $this->attachService($fixture, $snapshot, 'drift-old');
        self::assertSame(1, $this->sync()->syncOne($servicePublicId)->processed);

        $driftedAfterTrigger = false;
        DB::listen(function (QueryExecuted $query) use (&$driftedAfterTrigger): void {
            if ($driftedAfterTrigger
                || ! str_contains(strtolower($query->sql), 'service_notification_states')
                || ! str_contains(strtolower($query->sql), 'insert')) {
                return;
            }

            $driftedAfterTrigger = true;
            config()->set('service_notifications.expiry_snapshot_max_age_seconds', 60);
            $this->advance(120);
        });

        $existing = $this->notifications()->processBatch(1);
        self::assertTrue($driftedAfterTrigger);
        self::assertSame(1, $existing->triggered);
        self::assertSame(
            1,
            $existing->queued,
            'Stored 1800-second authority must survive later runtime config drift to 60 seconds.',
        );

        $existingState = DB::table('service_notification_states')
            ->where('service_subscription_id', $this->serviceId($servicePublicId))
            ->where('notification_type', 'expiry')
            ->first(['id', 'expiry_snapshot_max_age_seconds']);
        self::assertNotNull($existingState);
        self::assertSame(1800, (int) $existingState->expiry_snapshot_max_age_seconds);
        self::assertSame(60, (int) config('service_notifications.expiry_snapshot_max_age_seconds'));

        $newFixture = $this->fixture('drift-new');
        $newSnapshot = $this->snapshot('drift-new', $this->clock->value->modify('+3 days'));
        $newServicePublicId = $this->attachService($newFixture, $newSnapshot, 'drift-new');
        self::assertSame(1, $this->sync()->syncOne($newServicePublicId)->processed);

        $newReceipt = $this->notifications()->processBatch(10);
        self::assertGreaterThanOrEqual(1, $newReceipt->triggered);
        $newState = DB::table('service_notification_states')
            ->where('service_subscription_id', $this->serviceId($newServicePublicId))
            ->where('notification_type', 'expiry')
            ->first(['expiry_snapshot_max_age_seconds']);
        self::assertNotNull($newState);
        self::assertSame(60, (int) $newState->expiry_snapshot_max_age_seconds);
        self::assertSame(1800, (int) DB::table('service_notification_states')
            ->where('id', (int) $existingState->id)
            ->value('expiry_snapshot_max_age_seconds'));
    }

    public function test_stale_queued_expiry_is_rejected_before_provider_send_and_fresh_equivalent_snapshot_recovers_same_attempt(): void
    {
        $fixture = $this->fixture('stale-send');
        $snapshot = $this->snapshot('stale-send', $this->clock->value->modify('+3 days'));
        $servicePublicId = $this->attachService($fixture, $snapshot, 'stale-send');
        $sync = $this->sync();
        self::assertSame(1, $sync->syncOne($servicePublicId)->processed);
        $this->insertTelegramAccount($fixture['user_id']);

        $initial = $this->notifications()->processBatch(1);
        self::assertSame(1, $initial->queued);
        $state = DB::table('service_notification_states')
            ->where('notification_type', 'expiry')
            ->first(['id', 'state', 'latest_delivery_attempt_id']);
        self::assertNotNull($state);
        $attemptId = (int) $state->latest_delivery_attempt_id;
        self::assertGreaterThan(0, $attemptId);
        $attemptPublicId = $this->attemptPublicId($attemptId);

        $this->advance(1801);
        try {
            $this->app->make(ServiceDeliveryEffectExecutor::class)->execute($attemptPublicId);
            self::fail('A stale expiry snapshot must fail closed before the Telegram provider send boundary.');
        } catch (ServiceNotificationCandidateInvalidatedException $exception) {
            self::assertStringContainsString('temporally stale', $exception->getMessage());
        }
        self::assertCount(0, $this->sender->calls);
        self::assertSame('triggered', DB::table('service_notification_states')->where('id', (int) $state->id)->value('state'));
        self::assertSame(
            'prepared',
            DB::table('service_delivery_effects')->where('service_delivery_attempt_id', $attemptId)->value('state'),
        );
        self::assertNull(
            DB::table('service_delivery_effects')
                ->where('service_delivery_attempt_id', $attemptId)
                ->value('provider_boundary_started_at'),
        );

        $fixture['adapter']->seed($snapshot);
        self::assertSame(1, $sync->syncOne($servicePublicId)->processed);
        $succeeded = $this->app->make(ServiceDeliveryEffectExecutor::class)->execute($attemptPublicId);
        self::assertSame(ServiceDeliveryEffectState::Succeeded, $succeeded->state);
        self::assertCount(1, $this->sender->calls);
        self::assertSame('telegram_success', DB::table('service_delivery_effects')
            ->where('service_delivery_attempt_id', $attemptId)
            ->value('result_code'));
        self::assertSame(1, DB::table('service_delivery_attempts')->where('id', $attemptId)->count());
    }

    public function test_database_guards_reject_stale_expiry_creation_and_direct_provider_boundary_transition(): void
    {
        $fixture = $this->fixture('db-fence');
        $snapshot = $this->snapshot('db-fence', $this->clock->value->modify('+3 days'));
        $servicePublicId = $this->attachService($fixture, $snapshot, 'db-fence');
        self::assertSame(1, $this->sync()->syncOne($servicePublicId)->processed);
        $serviceId = $this->serviceId($servicePublicId);
        $source = DB::table('service_sync_snapshots')
            ->where('service_subscription_id', $serviceId)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first(['id', 'remote_expires_at']);
        $service = DB::table('service_subscriptions')->where('id', $serviceId)->first([
            'id', 'remote_identity_generation', 'mutation_generation', 'lifecycle_version',
        ]);
        self::assertNotNull($source);
        self::assertNotNull($service);

        $this->advance(1801);
        $expiresAt = new DateTimeImmutable((string) $source->remote_expires_at, new DateTimeZone('UTC'));
        $cycle = hash('sha256', implode('|', [
            'service-notification-expiry-cycle-v1',
            (string) $service->id,
            (string) $service->remote_identity_generation,
            (string) $service->mutation_generation,
            (string) $service->lifecycle_version,
            $expiresAt->format('Y-m-d H:i:s.u'),
        ]));
        $episode = hash('sha256', implode('|', [
            'service-notification-episode-v1',
            (string) $service->id,
            'expiry',
            'expiry_3d',
            $cycle,
        ]));
        $timestamp = $this->timestamp();
        $connection = DB::connection();
        ServiceNotificationDatabaseAuthority::create(
            $connection,
            $serviceId,
            $episode,
            'expiry',
            'expiry_3d',
            $cycle,
            'service_sync_snapshot',
            (int) $source->id,
            $timestamp,
            'freshness-stale-create-fence',
            2,
            null,
            1800,
        );
        try {
            $connection->table('service_notification_states')->insert([
                'public_id' => (string) Str::ulid(),
                'service_subscription_id' => $serviceId,
                'episode_key_hash' => $episode,
                'notification_type' => 'expiry',
                'threshold_code' => 'expiry_3d',
                'cycle_key_hash' => $cycle,
                'source_type' => 'service_sync_snapshot',
                'source_id' => (int) $source->id,
                'low_balance_threshold_irr' => null,
                'expiry_snapshot_max_age_seconds' => 1800,
                'max_retries' => 2,
                'state' => 'triggered',
                'latest_delivery_attempt_id' => null,
                'latest_retry_ordinal' => null,
                'next_retry_at' => null,
                'triggered_at' => $timestamp,
                'notified_at' => null,
                'acknowledged_at' => null,
                'escalated_at' => null,
                'expired_at' => null,
                'last_correlation_id' => 'freshness-stale-create-fence',
                'updated_at' => $timestamp,
            ]);
            self::fail('Database creation guard must reject stale expiry snapshot evidence independently of application checks.');
        } catch (QueryException) {
            self::assertSame(0, DB::table('service_notification_states')->count());
        } finally {
            ServiceNotificationDatabaseAuthority::clear($connection);
        }

        $fixture['adapter']->seed($snapshot);
        self::assertSame(1, $this->sync()->syncOne($servicePublicId)->processed);
        self::assertSame(1, $this->notifications()->processBatch(1)->queued);
        $this->insertTelegramAccount($fixture['user_id']);
        $attemptId = (int) DB::table('service_notification_states')
            ->where('notification_type', 'expiry')
            ->value('latest_delivery_attempt_id');
        $attemptPublicId = $this->attemptPublicId($attemptId);

        $this->advance(1801);
        $executor = $this->app->make(ServiceDeliveryEffectExecutor::class);
        $prepare = new ReflectionMethod($executor, 'prepare');
        /** @var array{effect:object{id:int|string,state_version:int|string}} $context */
        $context = $prepare->invoke($executor, $attemptPublicId);
        $effect = $context['effect'];
        $setAuthority = new ReflectionMethod($executor, 'setEffectAuthority');
        $clearAuthority = new ReflectionMethod($executor, 'clearEffectAuthority');
        $setAuthority->invoke($executor, $connection, $effect);
        try {
            $connection->table('service_delivery_effects')
                ->where('id', (int) $effect->id)
                ->where('state', 'prepared')
                ->where('state_version', (int) $effect->state_version)
                ->update([
                    'state' => 'sending',
                    'state_version' => (int) $effect->state_version + 1,
                    'provider_boundary_started_at' => $this->timestamp(),
                    'updated_at' => $this->timestamp(),
                ]);
            self::fail('Database provider-boundary guard must independently reject temporally stale expiry evidence.');
        } catch (QueryException) {
            self::assertSame(
                'prepared',
                DB::table('service_delivery_effects')->where('id', (int) $effect->id)->value('state'),
            );
        } finally {
            $clearAuthority->invoke($executor, $connection);
        }
    }

    /** @return array{owner_id:int,user_id:int,offering_id:int,target_id:int,adapter:ServiceOperationalPanelAdapter} */
    private function fixture(string $suffix): array
    {
        $offering = $this->activeBenefitOffering('sn-fresh-'.$suffix, 'panel.example.com');
        $ownerId = $this->benefitOwner();
        $userId = $this->benefitUser();
        $targetId = (int) DB::table('plan_offerings')->where('id', $offering['id'])->value('panel_service_target_id');
        $connectionId = (int) DB::table('panel_service_targets')->where('id', $targetId)->value('panel_connection_id');
        DB::table('panel_connections')->where('id', $connectionId)->update([
            'encrypted_credentials' => Crypt::encryptString(json_encode(['token' => 'notification-freshness-test'], JSON_THROW_ON_ERROR)),
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
        $this->forgetRuntimeServices();

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
            'notification-fresh-import-'.substr(hash('sha256', $suffix), 0, 32),
            'notification-fresh-correlation-'.substr(hash('sha256', $suffix), 0, 20),
            'service_notification_test',
            'Attach a remote Service for expiry freshness authority coverage.',
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

    private function snapshot(string $suffix, DateTimeImmutable $expiresAt): RemoteServiceSnapshot
    {
        $remoteId = 'notification-fresh-'.$suffix;
        $username = 'notification-fresh-'.$suffix.'-user';

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

    private function insertTelegramAccount(int $userId): void
    {
        $now = now('UTC');
        DB::table('telegram_accounts')->insert([
            'user_id' => $userId,
            'bot_id' => self::BOT_ID,
            'telegram_user_id' => 880021,
            'username' => 'notification_freshness_test',
            'language_code' => 'fa',
            'is_bot' => false,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function sync(): ServiceSynchronizationService
    {
        return $this->app->make(ServiceSynchronizationService::class);
    }

    private function notifications(): ServiceNotificationThresholdService
    {
        return $this->app->make(ServiceNotificationThresholdService::class);
    }

    private function serviceId(string $servicePublicId): int
    {
        return (int) DB::table('service_subscriptions')->where('public_id', $servicePublicId)->value('id');
    }

    private function attemptPublicId(int $attemptId): string
    {
        $publicId = DB::table('service_delivery_attempts')->where('id', $attemptId)->value('public_id');
        self::assertIsString($publicId);
        self::assertNotSame('', $publicId);

        return $publicId;
    }

    private function advance(int $seconds): void
    {
        $this->clock->value = $this->clock->value->modify('+'.$seconds.' seconds');
        Carbon::setTestNow(Carbon::instance($this->clock->value));
    }

    private function timestamp(): string
    {
        return $this->clock->value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function forgetRuntimeServices(): void
    {
        $this->app->forgetInstance(ServiceSynchronizationService::class);
        $this->app->forgetInstance(ServiceNotificationThresholdService::class);
        $this->app->forgetInstance(ServiceDeliveryEffectExecutor::class);
    }
}

final class ServiceNotificationExpiryFreshnessTestClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

final readonly class ServiceNotificationExpiryFreshnessTestRuntime implements ProtectedTelegramDeliveryRuntime
{
    public function __construct(private int $botId) {}

    public function botId(): string
    {
        return (string) $this->botId;
    }
}

final class ServiceNotificationExpiryFreshnessTestSender implements ProtectedTelegramMessageSender
{
    /** @var list<array{telegram_user_id:int,text:string}> */
    public array $calls = [];

    public function __construct(public ProtectedTelegramSendResult $result) {}

    public function send(int $telegramUserId, ProtectedTelegramPresentation $presentation): ProtectedTelegramSendResult
    {
        $this->calls[] = [
            'telegram_user_id' => $telegramUserId,
            'text' => $presentation->text(),
        ];

        return $this->result;
    }
}
