<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/ServiceOperationalPanelAdapter.php';

use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\PanelAdapterRegistry;
use App\Modules\Panels\Application\PanelCredentialPolicy;
use App\Modules\Provisioning\Application\ProvisioningPanelAdapterResolver;
use App\Modules\Provisioning\Application\ServiceDeliveryAttemptQueueService;
use App\Modules\Provisioning\Application\ServiceImportService;
use App\Modules\Provisioning\Application\ServiceNotificationDatabaseAuthority;
use App\Modules\Provisioning\Application\ServiceNotificationThresholdService;
use App\Modules\Provisioning\Application\ServiceOperationalContext;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use DomainException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

/** @requirement SVC-013 SVC-014 WAL-002 DAT-003 QUA-004 QUA-007 QUA-010 */
final class ServiceNotificationDeliveryQueueTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config()->set('service_notifications.low_balance_irr', 500_000);

        /** @var Migration $operationalMigration */
        $operationalMigration = require database_path('migrations/2026_08_19_000140_enable_service_operational_authority.php');
        $operationalMigration->up();

        /** @var Migration $cursorMigration */
        $cursorMigration = require database_path('migrations/2026_08_23_000210_enable_service_notification_scan_cursor.php');
        $cursorMigration->down();
        $cursorMigration->up();
    }

    public function test_notification_replay_is_exact_and_next_retry_cannot_bypass_durable_backoff_authority(): void
    {
        $fixture = $this->fixture();
        $this->attachService($fixture);

        $assetId = $this->account('system.notification.queue.asset', 'asset');
        $walletId = $this->account(
            'wallet.cash.notification.queue.'.$fixture['user_id'],
            'liability',
            $fixture['user_id'],
            'cash',
        );
        $this->fundWallet($assetId, $walletId, 100_000);

        $run = $this->app->make(ServiceNotificationThresholdService::class)->processBatch(1);
        self::assertSame(1, $run->triggered);
        self::assertSame(1, $run->queued);

        $state = DB::table('service_notification_states')
            ->where('notification_type', 'low_balance')
            ->first(['id', 'public_id', 'service_subscription_id', 'latest_delivery_attempt_id', 'latest_retry_ordinal']);
        self::assertNotNull($state);
        self::assertNotNull($state->latest_delivery_attempt_id);
        self::assertSame(0, (int) $state->latest_retry_ordinal);

        $attempt = DB::table('service_delivery_attempts')
            ->where('id', (int) $state->latest_delivery_attempt_id)
            ->first(['public_id']);
        self::assertNotNull($attempt);

        $servicePublicId = (string) DB::table('service_subscriptions')
            ->where('id', (int) $state->service_subscription_id)
            ->value('public_id');
        $requestKey = 'service-notification:'.$state->public_id.':0';
        $presentation = 'Wallet balance warning: your available wallet balance is below the configured renewal threshold.';
        $queue = $this->app->make(ServiceDeliveryAttemptQueueService::class);

        $replay = $queue->queueNotification(
            (int) $state->id,
            $servicePublicId,
            0,
            $requestKey,
            $requestKey,
            $presentation,
        );
        self::assertTrue($replay->replayed);
        self::assertSame((string) $attempt->public_id, $replay->attemptPublicId);

        try {
            $queue->queueNotification(
                (int) $state->id,
                $servicePublicId,
                1,
                'service-notification:'.$state->public_id.':1',
                'service-notification:'.$state->public_id.':1',
                $presentation,
            );
            self::fail('A next notification ordinal must not bypass failed-effect and durable backoff authority.');
        } catch (DomainException $exception) {
            self::assertSame(
                'Service notification retry is missing deterministic prior-attempt evidence.',
                $exception->getMessage(),
            );
        }

        self::assertSame(1, DB::table('service_delivery_attempts')
            ->where('purpose', 'notification')
            ->count());
        self::assertSame(1, DB::table('service_notification_delivery_bindings')->count());
        self::assertSame(1, DB::table('outbox_messages')
            ->where('event_type', ServiceDeliveryAttemptQueueService::OUTBOX_EVENT_TYPE)
            ->count());
    }

    public function test_exact_notification_attempt_replays_after_notification_reaches_terminal_state(): void
    {
        $fixture = $this->fixture();
        $this->attachService($fixture);

        $assetId = $this->account('system.notification.terminal-replay.asset', 'asset');
        $walletId = $this->account(
            'wallet.cash.notification.terminal-replay.'.$fixture['user_id'],
            'liability',
            $fixture['user_id'],
            'cash',
        );
        $this->fundWallet($assetId, $walletId, 100_000);

        $run = $this->app->make(ServiceNotificationThresholdService::class)->processBatch(1);
        self::assertSame(1, $run->triggered);
        self::assertSame(1, $run->queued);

        $state = DB::table('service_notification_states')
            ->where('notification_type', 'low_balance')
            ->first([
                'id', 'public_id', 'service_subscription_id', 'latest_delivery_attempt_id', 'latest_retry_ordinal',
            ]);
        self::assertNotNull($state);
        self::assertNotNull($state->latest_delivery_attempt_id);
        self::assertSame(0, (int) $state->latest_retry_ordinal);

        $attemptPublicId = (string) DB::table('service_delivery_attempts')
            ->where('id', (int) $state->latest_delivery_attempt_id)
            ->value('public_id');
        self::assertNotSame('', $attemptPublicId);

        $this->transitionNotificationToNotified(
            (int) $state->id,
            (int) $state->service_subscription_id,
        );
        self::assertSame('notified', DB::table('service_notification_states')
            ->where('id', (int) $state->id)
            ->value('state'));

        $servicePublicId = (string) DB::table('service_subscriptions')
            ->where('id', (int) $state->service_subscription_id)
            ->value('public_id');
        $requestKey = 'service-notification:'.$state->public_id.':0';
        $presentation = 'Wallet balance warning: your available wallet balance is below the configured renewal threshold.';
        $queue = $this->app->make(ServiceDeliveryAttemptQueueService::class);

        $replay = $queue->queueNotification(
            (int) $state->id,
            $servicePublicId,
            0,
            $requestKey,
            $requestKey,
            $presentation,
        );
        self::assertTrue($replay->replayed);
        self::assertSame($attemptPublicId, $replay->attemptPublicId);

        try {
            $queue->queueNotification(
                (int) $state->id,
                $servicePublicId,
                1,
                'service-notification:'.$state->public_id.':1',
                'service-notification:'.$state->public_id.':1',
                $presentation,
            );
            self::fail('A terminal notification must not admit a new Delivery Attempt.');
        } catch (DomainException $exception) {
            self::assertSame(
                'Service notification delivery requires one triggered notification state.',
                $exception->getMessage(),
            );
        }

        self::assertSame(1, DB::table('service_delivery_attempts')
            ->where('purpose', 'notification')
            ->count());
    }

    /** @return array{owner_id:int,user_id:int,offering_id:int,target_id:int,adapter:ServiceOperationalPanelAdapter} */
    private function fixture(): array
    {
        $offering = $this->activeBenefitOffering('service-notification-delivery-queue');
        $ownerId = $this->benefitOwner();
        $userId = $this->benefitUser();
        $targetId = (int) DB::table('plan_offerings')->where('id', $offering['id'])->value('panel_service_target_id');
        $connectionId = (int) DB::table('panel_service_targets')->where('id', $targetId)->value('panel_connection_id');
        DB::table('panel_connections')->where('id', $connectionId)->update([
            'encrypted_credentials' => Crypt::encryptString(json_encode(['token' => 'notification-delivery-queue-test'], JSON_THROW_ON_ERROR)),
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
    private function attachService(array $fixture): void
    {
        $remoteId = 'notification-delivery-queue';
        $expiresAt = new \DateTimeImmutable('+30 days', new \DateTimeZone('UTC'));
        $fixture['adapter']->seed(new RemoteServiceSnapshot(
            $remoteId,
            'notification-delivery-queue-user',
            PanelServiceStatus::Active,
            10_000,
            0,
            $expiresAt,
            hash('sha256', $remoteId.'|'.$expiresAt->format(DATE_ATOM)),
            hash('sha256', 'equivalence:'.$remoteId),
        ));

        $context = new ServiceOperationalContext(
            'notification-delivery-queue-import-0001',
            'notification-delivery-queue-correlation-0001',
            'service_notification_test',
            'Attach a remote Service for notification delivery queue coverage.',
            $fixture['owner_id'],
        );
        $imports = $this->app->make(ServiceImportService::class);
        $preview = $imports->preview(
            'https://panel.example.com/sub/'.$remoteId,
            $fixture['target_id'],
            $fixture['user_id'],
            $fixture['offering_id'],
            $context,
        );
        $attached = $imports->attach($preview->importPublicId, $context);
        self::assertNotNull($attached->serviceSubscriptionPublicId);
    }

    private function transitionNotificationToNotified(int $stateId, int $serviceId): void
    {
        $connection = DB::connection();
        $timestamp = now('UTC')->format('Y-m-d H:i:s.u');
        $correlationId = 'notification-terminal-replay-0001';
        ServiceNotificationDatabaseAuthority::transition(
            $connection,
            $stateId,
            $serviceId,
            'triggered',
            'notified',
            $timestamp,
            $correlationId,
        );
        try {
            $updated = $connection->table('service_notification_states')
                ->where('id', $stateId)
                ->where('service_subscription_id', $serviceId)
                ->where('state', 'triggered')
                ->update([
                    'state' => 'notified',
                    'next_retry_at' => null,
                    'notified_at' => $timestamp,
                    'last_correlation_id' => $correlationId,
                    'updated_at' => $timestamp,
                ]);
            self::assertSame(1, $updated);

            $sequence = (int) $connection->table('service_notification_events')
                ->where('service_notification_state_id', $stateId)
                ->max('sequence') + 1;
            $connection->table('service_notification_events')->insert([
                'service_notification_state_id' => $stateId,
                'sequence' => $sequence,
                'event_type' => 'notified',
                'from_state' => 'triggered',
                'to_state' => 'notified',
                'service_delivery_attempt_id' => null,
                'retry_ordinal' => null,
                'correlation_id' => $correlationId,
                'created_at' => $timestamp,
            ]);
        } finally {
            ServiceNotificationDatabaseAuthority::clear($connection);
        }
    }

    private function account(
        string $code,
        string $class,
        ?int $userId = null,
        ?string $bucket = null,
    ): int {
        $now = now('UTC');

        return (int) DB::table('ledger_accounts')->insertGetId([
            'code' => $code,
            'account_class' => $class,
            'owner_user_id' => $userId,
            'wallet_bucket' => $bucket,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function fundWallet(int $assetId, int $walletId, int $amount): void
    {
        $this->app->make(LedgerPostingService::class)->post(
            'ledger.notification.queue.fund.0001',
            'wallet_topup_capture',
            'notification-queue-fund-0001',
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amount)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amount)),
            ],
            'payment_intent',
            'pi-notification-queue-fund-0001',
        );
    }
}
