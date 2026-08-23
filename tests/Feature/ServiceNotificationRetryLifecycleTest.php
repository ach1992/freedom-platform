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
use App\Modules\Provisioning\Application\ServiceNotificationThresholdService;
use App\Modules\Provisioning\Application\ServiceOperationalContext;
use App\Modules\Provisioning\Application\ServiceOperationalDatabaseCapability;
use App\Modules\Provisioning\Domain\ServiceDeliveryEffectState;
use App\Modules\Telegram\Application\Contracts\ProtectedTelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\ProtectedTelegramMessageSender;
use App\Modules\Telegram\Application\ProtectedTelegramPresentation;
use App\Modules\Telegram\Application\ProtectedTelegramSendOutcome;
use App\Modules\Telegram\Application\ProtectedTelegramSendResult;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

/** @requirement SVC-013 SVC-014 WAL-002 ARCH-004 DAT-003 DAT-004 SEC-008 QUA-004 QUA-007 QUA-010 */
final class ServiceNotificationRetryLifecycleTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use DatabaseTruncation;

    private const BOT_ID = 770001;

    private ServiceNotificationRetryClock $clock;

    private ServiceNotificationRetrySender $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config()->set('service_notifications.low_balance_irr', 500_000);
        config()->set('service_notifications.retry.low_balance.max_retries', 2);
        config()->set('service_notifications.retry.low_balance.base_delay_seconds', 60);
        config()->set('service_notifications.retry.low_balance.max_delay_seconds', 300);

        /** @var Migration $operationalMigration */
        $operationalMigration = require database_path('migrations/2026_08_19_000140_enable_service_operational_authority.php');
        $operationalMigration->up();

        /** @var Migration $cursorMigration */
        $cursorMigration = require database_path('migrations/2026_08_23_000210_enable_service_notification_scan_cursor.php');
        $cursorMigration->down();
        $cursorMigration->up();

        $this->clock = new ServiceNotificationRetryClock(
            new DateTimeImmutable('2030-02-01 00:00:00.000000', new DateTimeZone('UTC')),
        );
        $this->app->instance(Clock::class, $this->clock);
        $this->sender = new ServiceNotificationRetrySender(
            new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::DefinitiveFailure,
                'notification_retry_test_failure',
            ),
        );
        $this->app->instance(ProtectedTelegramDeliveryRuntime::class, new ServiceNotificationRetryRuntime(self::BOT_ID));
        $this->app->instance(ProtectedTelegramMessageSender::class, $this->sender);
        $this->forgetDeliveryServices();
    }

    public function test_failed_notification_uses_durable_backoff_then_new_ordinal_succeeds_after_restart(): void
    {
        $fixture = $this->fixture('retry-success');
        $this->attachService($fixture, 'retry-success');
        $this->fundLowWallet($fixture['user_id'], 'retry-success');
        $this->insertTelegramAccount($fixture['user_id']);

        $notifications = $this->app->make(ServiceNotificationThresholdService::class);
        $initial = $notifications->processBatch(1);
        self::assertSame(1, $initial->triggered);
        self::assertSame(1, $initial->queued);
        $state = $this->notificationState();
        self::assertSame(0, (int) $state->latest_retry_ordinal);
        $firstAttemptPublicId = $this->attemptPublicId((int) $state->latest_delivery_attempt_id);

        $failed = $this->app->make(ServiceDeliveryEffectExecutor::class)->execute($firstAttemptPublicId);
        self::assertSame(ServiceDeliveryEffectState::FailedFinal, $failed->state);
        self::assertCount(1, $this->sender->calls);

        $scheduled = $notifications->processBatch(1);
        self::assertSame(0, $scheduled->queued);
        $afterSchedule = $this->notificationState();
        self::assertNotNull($afterSchedule->next_retry_at);
        self::assertSame(0, (int) $afterSchedule->latest_retry_ordinal);

        $early = $notifications->processBatch(1);
        self::assertSame(0, $early->queued);
        self::assertSame(1, DB::table('service_delivery_attempts')->where('purpose', 'notification')->count());

        $this->clock->value = $this->clock->value->modify('+61 seconds');
        $this->forgetDeliveryServices();
        $notifications = $this->app->make(ServiceNotificationThresholdService::class);
        $due = $notifications->processBatch(1);
        self::assertSame(1, $due->queued);
        $retryState = $this->notificationState();
        self::assertSame(1, (int) $retryState->latest_retry_ordinal);
        self::assertNull($retryState->next_retry_at);
        self::assertSame(2, DB::table('service_delivery_attempts')->where('purpose', 'notification')->count());
        self::assertSame(2, DB::table('service_notification_delivery_bindings')->count());

        $retryAttemptPublicId = $this->attemptPublicId((int) $retryState->latest_delivery_attempt_id);
        self::assertNotSame($firstAttemptPublicId, $retryAttemptPublicId);
        $this->sender->result = new ProtectedTelegramSendResult(
            ProtectedTelegramSendOutcome::Success,
            'notification_retry_test_success',
            messageId: 9001,
        );
        $succeeded = $this->app->make(ServiceDeliveryEffectExecutor::class)->execute($retryAttemptPublicId);
        self::assertSame(ServiceDeliveryEffectState::Succeeded, $succeeded->state);
        self::assertCount(2, $this->sender->calls);

        $replay = $this->app->make(ServiceDeliveryEffectExecutor::class)->execute($retryAttemptPublicId);
        self::assertTrue($replay->replayed);
        self::assertCount(2, $this->sender->calls, 'Successful Delivery Attempt replay must not resend Telegram.');

        $completed = $notifications->processBatch(1);
        self::assertSame(1, $completed->notified);
        self::assertSame('notified', $this->notificationState()->state);
    }

    public function test_retry_budget_exhaustion_escalates_without_creating_another_delivery_attempt(): void
    {
        config()->set('service_notifications.retry.low_balance.max_retries', 0);
        $fixture = $this->fixture('retry-exhaustion');
        $this->attachService($fixture, 'retry-exhaustion');
        $this->fundLowWallet($fixture['user_id'], 'retry-exhaustion');
        $this->insertTelegramAccount($fixture['user_id']);

        $notifications = $this->app->make(ServiceNotificationThresholdService::class);
        self::assertSame(1, $notifications->processBatch(1)->queued);
        $state = $this->notificationState();
        $attemptPublicId = $this->attemptPublicId((int) $state->latest_delivery_attempt_id);
        self::assertSame(
            ServiceDeliveryEffectState::FailedFinal,
            $this->app->make(ServiceDeliveryEffectExecutor::class)->execute($attemptPublicId)->state,
        );

        $exhausted = $notifications->processBatch(1);
        self::assertSame(1, $exhausted->escalated);
        self::assertSame('escalated', $this->notificationState()->state);
        self::assertSame(1, DB::table('service_delivery_attempts')->where('purpose', 'notification')->count());
        self::assertCount(1, $this->sender->calls);
    }

    public function test_fixed_effect_flags_cannot_forge_notification_provider_boundary_without_operational_capability(): void
    {
        $fixture = $this->fixture('retry-effect-forgery');
        $this->attachService($fixture, 'retry-effect-forgery');
        $this->fundLowWallet($fixture['user_id'], 'retry-effect-forgery');
        $this->insertTelegramAccount($fixture['user_id']);

        $notifications = $this->app->make(ServiceNotificationThresholdService::class);
        self::assertSame(1, $notifications->processBatch(1)->queued);
        $state = $this->notificationState();
        $attemptPublicId = $this->attemptPublicId((int) $state->latest_delivery_attempt_id);
        $executor = $this->app->make(ServiceDeliveryEffectExecutor::class);
        $prepare = new ReflectionMethod($executor, 'prepare');
        /** @var array{effect:object} $context */
        $context = $prepare->invoke($executor, $attemptPublicId);
        $effect = DB::table('service_delivery_effects')->where('id', (int) $context['effect']->id)->first();
        self::assertNotNull($effect);
        self::assertSame('prepared', $effect->state);

        $connection = DB::connection();
        $connection->statement(
            <<<'SQL'
SET @app_service_operational_capability = 'forged',
    @app_service_delivery_effect_authority = 'service_delivery_effect_v1',
    @app_service_delivery_effect_attempt_id = ?,
    @app_service_delivery_effect_service_id = ?,
    @app_service_delivery_effect_public_id = ?,
    @app_service_delivery_effect_telegram_account_id = ?,
    @app_service_delivery_effect_bot_id = ?,
    @app_service_delivery_effect_telegram_user_id = ?
SQL,
            [
                (int) $effect->service_delivery_attempt_id,
                (int) $effect->service_subscription_id,
                (string) $effect->public_id,
                (int) $effect->telegram_account_id,
                (int) $effect->telegram_bot_id,
                (int) $effect->telegram_user_id,
            ],
        );

        try {
            $connection->table('service_delivery_effects')
                ->where('id', (int) $effect->id)
                ->update([
                    'state' => 'sending',
                    'state_version' => (int) $effect->state_version + 1,
                    'provider_boundary_started_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);
            self::fail('Fixed effect session flags must not forge a notification provider boundary.');
        } catch (QueryException) {
            self::assertSame('prepared', DB::table('service_delivery_effects')->where('id', (int) $effect->id)->value('state'));
        } finally {
            $connection->statement(
                <<<'SQL'
SET @app_service_delivery_effect_authority = NULL,
    @app_service_delivery_effect_attempt_id = NULL,
    @app_service_delivery_effect_service_id = NULL,
    @app_service_delivery_effect_public_id = NULL,
    @app_service_delivery_effect_telegram_account_id = NULL,
    @app_service_delivery_effect_bot_id = NULL,
    @app_service_delivery_effect_telegram_user_id = NULL
SQL,
            );
            (new ServiceOperationalDatabaseCapability)->clear($connection);
        }
    }

    public function test_provider_directed_retry_escalates_without_automatic_new_attempt(): void
    {
        $fixture = $this->fixture('retry-provider-directed');
        $this->attachService($fixture, 'retry-provider-directed');
        $this->fundLowWallet($fixture['user_id'], 'retry-provider-directed');
        $this->insertTelegramAccount($fixture['user_id']);
        $this->sender->result = new ProtectedTelegramSendResult(
            ProtectedTelegramSendOutcome::RetryAfter,
            'telegram_retry_after',
            retryAfterSeconds: 60,
        );

        $notifications = $this->app->make(ServiceNotificationThresholdService::class);
        self::assertSame(1, $notifications->processBatch(1)->queued);
        $state = $this->notificationState();
        $attemptPublicId = $this->attemptPublicId((int) $state->latest_delivery_attempt_id);

        $failed = $this->app->make(ServiceDeliveryEffectExecutor::class)->execute($attemptPublicId);
        self::assertSame(ServiceDeliveryEffectState::FailedFinal, $failed->state);
        self::assertSame(60, (int) DB::table('service_delivery_effects')->value('retry_after_seconds'));
        self::assertNotNull(DB::table('service_delivery_effects')->value('blocking_service_subscription_id'));

        $reconciled = $notifications->processBatch(1);
        self::assertSame(1, $reconciled->escalated);
        $after = $this->notificationState();
        self::assertSame('escalated', $after->state);
        self::assertNull($after->next_retry_at);
        self::assertSame(1, DB::table('service_delivery_attempts')->where('purpose', 'notification')->count());
        self::assertCount(1, $this->sender->calls);
    }

    public function test_interrupted_notification_sending_recovers_uncertain_without_blind_resend(): void
    {
        $fixture = $this->fixture('retry-interrupted');
        $this->attachService($fixture, 'retry-interrupted');
        $this->fundLowWallet($fixture['user_id'], 'retry-interrupted');
        $this->insertTelegramAccount($fixture['user_id']);
        $this->sender->result = new ProtectedTelegramSendResult(
            ProtectedTelegramSendOutcome::Success,
            'notification_retry_test_should_not_send',
            messageId: 9002,
        );

        $notifications = $this->app->make(ServiceNotificationThresholdService::class);
        self::assertSame(1, $notifications->processBatch(1)->queued);
        $state = $this->notificationState();
        $attemptPublicId = $this->attemptPublicId((int) $state->latest_delivery_attempt_id);
        $executor = $this->app->make(ServiceDeliveryEffectExecutor::class);

        $prepare = new ReflectionMethod($executor, 'prepare');
        /** @var array{effect:object} $context */
        $context = $prepare->invoke($executor, $attemptPublicId);
        $boundary = new ReflectionMethod($executor, 'enterProviderBoundary');
        $boundary->invoke($executor, $context['effect']);
        self::assertSame('sending', DB::table('service_delivery_effects')->value('state'));

        $this->forgetDeliveryServices();
        $recovered = $this->app->make(ServiceDeliveryEffectExecutor::class)->execute($attemptPublicId);
        self::assertSame(ServiceDeliveryEffectState::Uncertain, $recovered->state);
        self::assertSame('interrupted_delivery_effect', $recovered->resultCode);
        self::assertCount(0, $this->sender->calls, 'Interrupted sending must never be blindly resent.');

        $replay = $this->app->make(ServiceDeliveryEffectExecutor::class)->execute($attemptPublicId);
        self::assertTrue($replay->replayed);
        self::assertCount(0, $this->sender->calls);

        $reconciled = $this->app->make(ServiceNotificationThresholdService::class)->processBatch(1);
        self::assertSame(1, $reconciled->escalated);
        self::assertSame('escalated', $this->notificationState()->state);
        self::assertSame(1, DB::table('service_delivery_attempts')->where('purpose', 'notification')->count());
    }

    /** @return array{owner_id:int,user_id:int,offering_id:int,target_id:int,adapter:ServiceOperationalPanelAdapter} */
    private function fixture(string $suffix): array
    {
        $offering = $this->activeBenefitOffering('service-notification-retry-'.$suffix, 'panel.example.com');
        $ownerId = $this->benefitOwner();
        $userId = $this->benefitUser();
        $targetId = (int) DB::table('plan_offerings')->where('id', $offering['id'])->value('panel_service_target_id');
        $connectionId = (int) DB::table('panel_service_targets')->where('id', $targetId)->value('panel_connection_id');
        DB::table('panel_connections')->where('id', $connectionId)->update([
            'encrypted_credentials' => Crypt::encryptString(json_encode(['token' => 'notification-retry-test'], JSON_THROW_ON_ERROR)),
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
        $this->forgetDeliveryServices();

        return [
            'owner_id' => $ownerId,
            'user_id' => $userId,
            'offering_id' => $offering['id'],
            'target_id' => $targetId,
            'adapter' => $adapter,
        ];
    }

    /** @param array{owner_id:int,user_id:int,offering_id:int,target_id:int,adapter:ServiceOperationalPanelAdapter} $fixture */
    private function attachService(array $fixture, string $suffix): void
    {
        $remoteId = 'notification-retry-'.$suffix;
        $expiresAt = $this->clock->value->modify('+30 days');
        $fixture['adapter']->seed(new RemoteServiceSnapshot(
            $remoteId,
            'notification-retry-'.$suffix.'-user',
            PanelServiceStatus::Active,
            10_000,
            0,
            $expiresAt,
            hash('sha256', $remoteId.'|'.$expiresAt->format(DATE_ATOM)),
            hash('sha256', 'equivalence:'.$remoteId),
        ));

        $context = new ServiceOperationalContext(
            'notification-retry-import-'.substr(hash('sha256', $suffix), 0, 30),
            'notification-retry-correlation-'.substr(hash('sha256', $suffix), 0, 22),
            'service_notification_test',
            'Attach a remote Service for notification retry lifecycle coverage.',
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

    private function fundLowWallet(int $userId, string $suffix): void
    {
        $now = now('UTC');
        $assetId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'system.notification.retry.asset.'.$suffix,
            'account_class' => 'asset',
            'owner_user_id' => null,
            'wallet_bucket' => null,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $walletId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'wallet.cash.notification.retry.'.$suffix.'.'.$userId,
            'account_class' => 'liability',
            'owner_user_id' => $userId,
            'wallet_bucket' => 'cash',
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->app->make(LedgerPostingService::class)->post(
            'ledger.notification.retry.fund.'.$suffix.'.0001',
            'wallet_topup_capture',
            'notification-retry-fund-'.$suffix,
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive(100_000)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive(100_000)),
            ],
            'payment_intent',
            'pi-notification-retry-'.$suffix,
        );
    }

    private function insertTelegramAccount(int $userId): void
    {
        $now = now('UTC');
        DB::table('telegram_accounts')->insert([
            'user_id' => $userId,
            'bot_id' => self::BOT_ID,
            'telegram_user_id' => 880001,
            'username' => 'notification_retry_test',
            'language_code' => 'fa',
            'is_bot' => false,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return object{id:int|string,state:string,latest_delivery_attempt_id:int|string|null,latest_retry_ordinal:int|string|null,next_retry_at:?string} */
    private function notificationState(): object
    {
        $state = DB::table('service_notification_states')
            ->where('notification_type', 'low_balance')
            ->first(['id', 'state', 'latest_delivery_attempt_id', 'latest_retry_ordinal', 'next_retry_at']);
        self::assertNotNull($state);

        return $state;
    }

    private function attemptPublicId(int $attemptId): string
    {
        $publicId = DB::table('service_delivery_attempts')->where('id', $attemptId)->value('public_id');
        self::assertIsString($publicId);
        self::assertNotSame('', $publicId);

        return $publicId;
    }

    private function forgetDeliveryServices(): void
    {
        $this->app->forgetInstance(ServiceNotificationThresholdService::class);
        $this->app->forgetInstance(ServiceDeliveryEffectExecutor::class);
    }
}

final class ServiceNotificationRetryClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

final readonly class ServiceNotificationRetryRuntime implements ProtectedTelegramDeliveryRuntime
{
    public function __construct(private int $botId) {}

    public function botId(): string
    {
        return (string) $this->botId;
    }
}

final class ServiceNotificationRetrySender implements ProtectedTelegramMessageSender
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
