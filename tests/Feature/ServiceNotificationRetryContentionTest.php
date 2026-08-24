<?php

declare(strict_types=1);

namespace {
    use App\Modules\Provisioning\Application\ServiceDeliveryAttemptQueueService;
    use Illuminate\Contracts\Console\Kernel;

    require_once dirname(__DIR__, 2).'/vendor/autoload.php';

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--service-notification-retry-contention-worker') {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $decoded = base64_decode($argv[2] ?? '', true);
        if ($decoded === false) {
            fwrite(STDERR, "Invalid worker payload encoding.\n");
            exit(2);
        }

        try {
            /** @var array{notification_state_id:int,service_public_id:string,retry_ordinal:int,request_key:string,correlation_id:string,presentation_text:string} $payload */
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            fwrite(STDERR, 'Invalid worker payload: '.$exception->getMessage()."\n");
            exit(2);
        }

        echo "READY\n";
        flush();
        if (fgets(STDIN) === false) {
            fwrite(STDERR, "Worker barrier was not released.\n");
            exit(2);
        }

        try {
            $receipt = $app->make(ServiceDeliveryAttemptQueueService::class)->queueNotification(
                $payload['notification_state_id'],
                $payload['service_public_id'],
                $payload['retry_ordinal'],
                $payload['request_key'],
                $payload['correlation_id'],
                $payload['presentation_text'],
            );
            echo json_encode([
                'ok' => true,
                'result' => [
                    'attempt_public_id' => $receipt->attemptPublicId,
                    'outbox_event_id' => $receipt->outboxEventId,
                    'replayed' => $receipt->replayed,
                ],
            ], JSON_THROW_ON_ERROR)."\n";
        } catch (Throwable $exception) {
            echo json_encode([
                'ok' => false,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR)."\n";
        }
        exit(0);
    }
}

namespace Tests\Feature {
    require_once __DIR__.'/ServiceOperationalPanelAdapter.php';

    use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
    use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
    use App\Modules\Panels\Application\PanelAdapterRegistry;
    use App\Modules\Panels\Application\PanelCredentialPolicy;
    use App\Modules\Provisioning\Application\ProvisioningPanelAdapterResolver;
    use App\Modules\Provisioning\Application\ServiceDeliveryAttemptQueueService;
    use App\Modules\Provisioning\Application\ServiceDeliveryEffectExecutor;
    use App\Modules\Provisioning\Application\ServiceImportService;
    use App\Modules\Provisioning\Application\ServiceNotificationDatabaseAuthority;
    use App\Modules\Provisioning\Application\ServiceNotificationThresholdService;
    use App\Modules\Provisioning\Application\ServiceOperationalContext;
    use App\Modules\Provisioning\Domain\ServiceDeliveryEffectState;
    use App\Modules\Telegram\Application\Contracts\ProtectedTelegramDeliveryRuntime;
    use App\Modules\Telegram\Application\Contracts\ProtectedTelegramMessageSender;
    use App\Modules\Telegram\Application\ProtectedTelegramPresentation;
    use App\Modules\Telegram\Application\ProtectedTelegramSendOutcome;
    use App\Modules\Telegram\Application\ProtectedTelegramSendResult;
    use DateTimeImmutable;
    use DateTimeZone;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\Crypt;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Str;
    use ReflectionMethod;
    use RuntimeException;
    use Tests\Support\CreatesBenefitCodeFixtures;
    use Tests\Support\RestoresServiceOperationalCapability;
    use Tests\TestCase;

    /** @requirement SVC-013 SVC-014 ARCH-004 DAT-003 DAT-004 SEC-008 QUA-004 QUA-007 QUA-010 */
    final class ServiceNotificationRetryContentionTest extends TestCase
    {
        use CreatesBenefitCodeFixtures;
        use DatabaseTruncation;
        use RestoresServiceOperationalCapability;

        private const BOT_ID = 770101;

        private const WORKER_TIMEOUT_SECONDS = 30;

        private const PRESENTATION = 'Wallet balance warning: your available wallet balance is below the configured renewal threshold.';

        protected function setUp(): void
        {
            parent::setUp();
            $this->seed();

            $this->restoreServiceOperationalCapabilitySingleton();
        }

        public function test_concurrent_retry_ordinal_converges_to_one_delivery_attempt_and_binding(): void
        {
            $fixture = $this->fixture();
            $servicePublicId = $this->attachService($fixture);
            $service = DB::table('service_subscriptions')->where('public_id', $servicePublicId)->first([
                'id', 'user_id', 'lifecycle_version', 'remote_identity_generation', 'mutation_generation',
            ]);
            self::assertNotNull($service);
            $stateId = $this->createTriggeredLowBalanceState($service);

            $this->insertTelegramAccount($fixture['user_id']);
            $this->app->instance(ProtectedTelegramDeliveryRuntime::class, new ServiceNotificationContentionRuntime(self::BOT_ID));
            $this->app->instance(
                ProtectedTelegramMessageSender::class,
                new ServiceNotificationContentionSender(new ProtectedTelegramSendResult(
                    ProtectedTelegramSendOutcome::DefinitiveFailure,
                    'notification_retry_contention_failure',
                )),
            );
            $this->app->forgetInstance(ServiceDeliveryEffectExecutor::class);

            $initialRequest = 'service-notification-contention-initial';
            $initial = $this->app->make(ServiceDeliveryAttemptQueueService::class)->queueNotification(
                $stateId,
                $servicePublicId,
                0,
                $initialRequest,
                $initialRequest,
                self::PRESENTATION,
            );
            $failed = $this->app->make(ServiceDeliveryEffectExecutor::class)->execute($initial->attemptPublicId);
            self::assertSame(ServiceDeliveryEffectState::FailedFinal, $failed->state);

            $notifications = $this->app->make(ServiceNotificationThresholdService::class);
            $scheduleRetry = new ReflectionMethod($notifications, 'scheduleRetry');
            $scheduleRetry->invoke(
                $notifications,
                $stateId,
                (int) $service->id,
                (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1 second'),
                'service-notification-contention-schedule',
            );

            $retryRequest = 'service-notification-contention-retry-1';
            $payload = [
                'notification_state_id' => $stateId,
                'service_public_id' => $servicePublicId,
                'retry_ordinal' => 1,
                'request_key' => $retryRequest,
                'correlation_id' => $retryRequest,
                'presentation_text' => self::PRESENTATION,
            ];
            $results = $this->runConcurrent([$payload, $payload]);

            self::assertTrue((bool) ($results[0]['ok'] ?? false), json_encode($results[0], JSON_THROW_ON_ERROR));
            self::assertTrue((bool) ($results[1]['ok'] ?? false), json_encode($results[1], JSON_THROW_ON_ERROR));
            self::assertSame($results[0]['result']['attempt_public_id'], $results[1]['result']['attempt_public_id']);
            self::assertSame($results[0]['result']['outbox_event_id'], $results[1]['result']['outbox_event_id']);
            $replayStatuses = [
                (bool) $results[0]['result']['replayed'],
                (bool) $results[1]['result']['replayed'],
            ];
            sort($replayStatuses);
            self::assertSame([false, true], $replayStatuses);
            self::assertSame(2, DB::table('service_delivery_attempts')->where('purpose', 'notification')->count());
            self::assertSame(2, DB::table('service_notification_delivery_bindings')->where('service_notification_state_id', $stateId)->count());
            self::assertSame(1, DB::table('service_notification_delivery_bindings')
                ->where('service_notification_state_id', $stateId)
                ->where('retry_ordinal', 1)
                ->count());
            self::assertSame(1, (int) DB::table('service_notification_states')->where('id', $stateId)->value('latest_retry_ordinal'));
        }

        /** @param object{id:int|string,user_id:int|string,lifecycle_version:int|string,remote_identity_generation:int|string,mutation_generation:int|string} $service */
        private function createTriggeredLowBalanceState(object $service): int
        {
            $threshold = 500_000;
            $now = now('UTC');
            $walletId = (int) DB::table('ledger_accounts')->insertGetId([
                'code' => 'wallet.cash.notification.retry-contention.'.(int) $service->user_id,
                'account_class' => 'liability',
                'owner_user_id' => (int) $service->user_id,
                'wallet_bucket' => 'cash',
                'currency' => 'IRR',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $cycle = hash('sha256', implode('|', [
                'service-notification-low-balance-cycle-v1',
                (string) $service->id,
                (string) $service->remote_identity_generation,
                (string) $service->mutation_generation,
                (string) $service->lifecycle_version,
                (string) $threshold,
            ]));
            $episode = hash('sha256', implode('|', [
                'service-notification-episode-v1',
                (string) $service->id,
                'low_balance',
                'low_balance',
                $cycle,
            ]));
            $timestamp = now('UTC')->format('Y-m-d H:i:s.u');
            $correlationId = 'service-notification-contention-trigger';
            $connection = DB::connection();

            ServiceNotificationDatabaseAuthority::create(
                $connection,
                (int) $service->id,
                $episode,
                'low_balance',
                'low_balance',
                $cycle,
                'wallet_balance',
                $walletId,
                $timestamp,
                $correlationId,
                2,
                $threshold,
            );
            try {
                $stateId = (int) $connection->table('service_notification_states')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'service_subscription_id' => (int) $service->id,
                    'episode_key_hash' => $episode,
                    'notification_type' => 'low_balance',
                    'threshold_code' => 'low_balance',
                    'cycle_key_hash' => $cycle,
                    'source_type' => 'wallet_balance',
                    'source_id' => $walletId,
                    'low_balance_threshold_irr' => $threshold,
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
                    'last_correlation_id' => $correlationId,
                    'updated_at' => $timestamp,
                ]);
                $connection->table('service_notification_events')->insert([
                    'service_notification_state_id' => $stateId,
                    'sequence' => 1,
                    'event_type' => 'triggered',
                    'from_state' => null,
                    'to_state' => 'triggered',
                    'service_delivery_attempt_id' => null,
                    'retry_ordinal' => null,
                    'correlation_id' => $correlationId,
                    'created_at' => $timestamp,
                ]);

                return $stateId;
            } finally {
                ServiceNotificationDatabaseAuthority::clear($connection);
            }
        }

        /** @return array{owner_id:int,user_id:int,offering_id:int,target_id:int,adapter:ServiceOperationalPanelAdapter} */
        private function fixture(): array
        {
            $offering = $this->activeBenefitOffering('service-notification-retry-contention', 'panel.example.com');
            $ownerId = $this->benefitOwner();
            $userId = $this->benefitUser();
            $targetId = (int) DB::table('plan_offerings')->where('id', $offering['id'])->value('panel_service_target_id');
            $connectionId = (int) DB::table('panel_service_targets')->where('id', $targetId)->value('panel_connection_id');
            DB::table('panel_connections')->where('id', $connectionId)->update([
                'encrypted_credentials' => Crypt::encryptString(json_encode(['token' => 'notification-contention-test'], JSON_THROW_ON_ERROR)),
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
            $this->app->forgetInstance(ServiceDeliveryEffectExecutor::class);
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
        private function attachService(array $fixture): string
        {
            $remoteId = 'notification-retry-contention';
            $expiresAt = new DateTimeImmutable('+30 days', new DateTimeZone('UTC'));
            $fixture['adapter']->seed(new RemoteServiceSnapshot(
                $remoteId,
                'notification-retry-contention-user',
                PanelServiceStatus::Active,
                10_000,
                0,
                $expiresAt,
                hash('sha256', $remoteId.'|'.$expiresAt->format(DATE_ATOM)),
                hash('sha256', 'equivalence:'.$remoteId),
            ));

            $context = new ServiceOperationalContext(
                'notification-retry-contention-import',
                'notification-retry-contention-correlation',
                'service_notification_test',
                'Attach a remote Service for notification retry contention coverage.',
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

            return $attached->serviceSubscriptionPublicId;
        }

        private function insertTelegramAccount(int $userId): void
        {
            $now = now('UTC');
            DB::table('telegram_accounts')->insert([
                'user_id' => $userId,
                'bot_id' => self::BOT_ID,
                'telegram_user_id' => 880101,
                'username' => 'notification_contention_test',
                'language_code' => 'fa',
                'is_bot' => false,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        /**
         * @param  list<array{notification_state_id:int,service_public_id:string,retry_ordinal:int,request_key:string,correlation_id:string,presentation_text:string}>  $payloads
         * @return list<array<string,mixed>>
         */
        private function runConcurrent(array $payloads): array
        {
            $workers = [];
            try {
                foreach ($payloads as $payload) {
                    $pipes = [];
                    $process = proc_open([
                        PHP_BINARY,
                        '-d',
                        'pcov.enabled=0',
                        __FILE__,
                        '--service-notification-retry-contention-worker',
                        base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                    ], [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ], $pipes, dirname(__DIR__, 2));
                    if (! is_resource($process)) {
                        throw new RuntimeException('Unable to start Service notification retry contention worker.');
                    }
                    /** @var array{0:resource,1:resource,2:resource} $pipes */
                    stream_set_blocking($pipes[1], false);
                    stream_set_blocking($pipes[2], false);
                    $workers[] = ['process' => $process, 'pipes' => $pipes];
                }

                foreach ($workers as $index => $worker) {
                    if ($this->readLine($worker, 'readiness', $index) !== "READY\n") {
                        throw new RuntimeException('Service notification retry contention worker returned an invalid readiness marker.');
                    }
                }
                foreach ($workers as $worker) {
                    fwrite($worker['pipes'][0], "GO\n");
                    fflush($worker['pipes'][0]);
                    fclose($worker['pipes'][0]);
                }

                $results = [];
                foreach ($workers as $index => $worker) {
                    $line = $this->readLine($worker, 'result', $index);
                    $stderr = stream_get_contents($worker['pipes'][2]);
                    fclose($worker['pipes'][1]);
                    fclose($worker['pipes'][2]);
                    if (proc_close($worker['process']) !== 0) {
                        throw new RuntimeException('Service notification retry contention worker failed: '.$stderr);
                    }
                    /** @var array<string,mixed> $result */
                    $result = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    $results[] = $result;
                }

                return $results;
            } finally {
                $this->terminateWorkers($workers);
            }
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker */
        private function readLine(array $worker, string $phase, int $index): string
        {
            $deadline = microtime(true) + self::WORKER_TIMEOUT_SECONDS;
            $stderr = '';
            while (microtime(true) < $deadline) {
                $read = [$worker['pipes'][1], $worker['pipes'][2]];
                $write = null;
                $except = null;
                $selected = stream_select($read, $write, $except, 0, 200_000);
                if ($selected === false) {
                    throw new RuntimeException('Unable to wait for Service notification retry contention worker output.');
                }
                foreach ($read as $stream) {
                    if ($stream === $worker['pipes'][2]) {
                        $stderr .= stream_get_contents($stream);

                        continue;
                    }
                    $line = fgets($stream);
                    if ($line !== false && trim($line) !== '') {
                        return $line;
                    }
                }
                $status = proc_get_status($worker['process']);
                if (! $status['running'] && feof($worker['pipes'][1])) {
                    $stderr .= stream_get_contents($worker['pipes'][2]);
                    throw new RuntimeException('Service notification retry contention worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException(sprintf(
                'Service notification retry contention worker %d timed out during %s after %d seconds: %s',
                $index,
                $phase,
                self::WORKER_TIMEOUT_SECONDS,
                $stderr,
            ));
        }

        /** @param list<array{process:resource,pipes:array{0:resource,1:resource,2:resource}}> $workers */
        private function terminateWorkers(array $workers): void
        {
            foreach ($workers as $worker) {
                if (! is_resource($worker['process'])) {
                    continue;
                }
                $status = proc_get_status($worker['process']);
                if ($status['running']) {
                    proc_terminate($worker['process']);
                }
                foreach ($worker['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($worker['process']);
            }
        }
    }

    final readonly class ServiceNotificationContentionRuntime implements ProtectedTelegramDeliveryRuntime
    {
        public function __construct(private int $botId) {}

        public function botId(): string
        {
            return (string) $this->botId;
        }
    }

    final class ServiceNotificationContentionSender implements ProtectedTelegramMessageSender
    {
        public function __construct(private ProtectedTelegramSendResult $result) {}

        public function send(int $telegramUserId, ProtectedTelegramPresentation $presentation): ProtectedTelegramSendResult
        {
            return $this->result;
        }
    }
}
