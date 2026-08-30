<?php

declare(strict_types=1);

namespace {
    use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
    use App\Modules\Telegram\Application\TelegramDeliveryDatabaseCapability;
    use App\Modules\Telegram\Application\TelegramDeliveryQueueService;
    use App\Modules\Telegram\Domain\TelegramDeliveryAction;
    use App\Shared\Application\Clock;
    use App\Shared\Application\SafeOutboxPayload;
    use App\Shared\Infrastructure\DatabaseOutboxPublisher;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\Connection;
    use Illuminate\Database\DatabaseManager;
    use Illuminate\Support\Str;
    use Tests\Support\NonRestrictedTelegramPresentationTestFactory;

    $rollbackRuntimeMode = PHP_SAPI === 'cli' ? ($argv[1] ?? null) : null;
    if (in_array($rollbackRuntimeMode, [
        '--telegram-rollback-runtime-queue',
        '--telegram-rollback-runtime-queue-hold-fence',
        '--telegram-rollback-runtime-trigger-hold-fence',
        '--telegram-rollback-runtime-down',
    ], true)) {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        /** @var DatabaseManager $database */
        $database = $app->make(DatabaseManager::class);
        $connection = $database->connection();
        $lifecycleConnection = $rollbackRuntimeMode === '--telegram-rollback-runtime-down'
            ? $database->connection('telegram_lifecycle')
            : null;
        $identityConnection = $lifecycleConnection ?? $connection;
        $identityConnection->statement('SET SESSION innodb_lock_wait_timeout = 10');
        $connectionId = $identityConnection->selectOne('SELECT CONNECTION_ID() AS connection_id', [], false);
        echo 'READY:'.(int) ($connectionId->connection_id ?? 0)."\n";
        flush();

        $go = fgets(STDIN);
        if ($go === false || trim($go) !== 'GO') {
            fwrite(STDERR, "Telegram rollback runtime worker start barrier was not released.\n");
            exit(2);
        }

        if ($rollbackRuntimeMode === '--telegram-rollback-runtime-down') {
            try {
                $migration = require dirname(__DIR__, 2).'/database/migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php';
                $rollback = new ReflectionMethod($migration, 'rollbackMysql');
                $rollback->setAccessible(true);
                $withInstallationLock = new ReflectionMethod($migration, 'withInstallationLock');
                $withInstallationLock->setAccessible(true);
                $beforeRuntimeFence = static function (): void {
                    echo "ROLLBACK_FENCE_READY\n";
                    flush();
                };
                if (! $lifecycleConnection instanceof Connection) {
                    throw new RuntimeException('Lifecycle connection was not initialized for rollback worker.');
                }
                $withInstallationLock->invoke($migration, $lifecycleConnection, static function () use (
                    $rollback,
                    $migration,
                    $connection,
                    $beforeRuntimeFence,
                ): void {
                    $rollback->invoke(
                        $migration,
                        $connection,
                        null,
                        null,
                        $beforeRuntimeFence,
                    );
                });
                echo json_encode(['ok' => true], JSON_THROW_ON_ERROR)."\n";
            } catch (Throwable $exception) {
                echo json_encode([
                    'ok' => false,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ], JSON_THROW_ON_ERROR)."\n";
            }

            exit(0);
        }

        $clock = new class(new DateTimeImmutable('2026-08-25T12:00:00+00:00')) implements Clock
        {
            public function __construct(private readonly DateTimeImmutable $now) {}

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
        $runtime = new class implements TelegramDeliveryRuntime
        {
            public function botId(): string
            {
                return '123456';
            }
        };
        $capability = new TelegramDeliveryDatabaseCapability;
        $outbox = new DatabaseOutboxPublisher($database, $clock);
        $queue = new TelegramDeliveryQueueService(
            $database,
            $clock,
            $outbox,
            $runtime,
            $capability,
        );

        if ($rollbackRuntimeMode === '--telegram-rollback-runtime-trigger-hold-fence') {
            $connection->beginTransaction();
            try {
                $publicId = (string) Str::ulid();
                $outboxEventId = (string) Str::uuid();
                $correlationId = 'correlation-trigger-fence-179';
                $payload = new SafeOutboxPayload([
                    'telegram_delivery_operation_public_id' => $publicId,
                ]);
                $connection->statement(<<<'SQL'
SET @app_telegram_delivery_capability = ?,
    @app_telegram_delivery_authority = 'telegram_delivery_queue_v1',
    @app_telegram_delivery_public_id = ?,
    @app_telegram_delivery_correlation_id = ?,
    @app_telegram_delivery_outbox_event_id = ?
SQL, [$capability->value(), $publicId, $correlationId, $outboxEventId]);

                // Do not call acquireRuntimeLifecycleFence(): this proves the
                // database trigger itself takes and holds the shared row fence.
                $outbox->publish(
                    $outboxEventId,
                    'telegram-delivery-requested:'.$publicId,
                    'telegram.delivery.requested',
                    'telegram_delivery_operation',
                    $publicId,
                    $payload,
                    $correlationId,
                    TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION,
                );
                echo "TRIGGER_FENCE_HELD\n";
                flush();

                $continue = fgets(STDIN);
                if ($continue === false || trim($continue) !== 'CONTINUE') {
                    throw new RuntimeException('Telegram trigger lifecycle fence barrier was not released.');
                }

                $connection->rollBack();
                $connection->statement(<<<'SQL'
SET @app_telegram_delivery_outbox_event_id = NULL,
    @app_telegram_delivery_correlation_id = NULL,
    @app_telegram_delivery_public_id = NULL,
    @app_telegram_delivery_authority = NULL,
    @app_telegram_delivery_capability = NULL
SQL);
                echo json_encode(['ok' => true], JSON_THROW_ON_ERROR)."\n";
            } catch (Throwable $exception) {
                if ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
                echo json_encode([
                    'ok' => false,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ], JSON_THROW_ON_ERROR)."\n";
            }

            exit(0);
        }

        $outerTransaction = false;
        try {
            if ($rollbackRuntimeMode === '--telegram-rollback-runtime-queue-hold-fence') {
                $connection->beginTransaction();
                $outerTransaction = true;
                $capability->acquireRuntimeLifecycleFence($connection);
                echo "RUNTIME_FENCE_HELD\n";
                flush();

                $continue = fgets(STDIN);
                if ($continue === false || trim($continue) !== 'CONTINUE') {
                    throw new RuntimeException('Telegram rollback runtime fence barrier was not released.');
                }
            }

            $requestKey = $rollbackRuntimeMode === '--telegram-rollback-runtime-queue-hold-fence'
                ? 'rollback-race-entered-producer-179'
                : 'rollback-race-late-producer-179';
            $correlationId = $rollbackRuntimeMode === '--telegram-rollback-runtime-queue-hold-fence'
                ? 'correlation-rollback-entered-179'
                : 'correlation-rollback-late-179';

            $receipt = NonRestrictedTelegramPresentationTestFactory::queue($queue,
                TelegramDeliveryAction::Send,
                900031,
                null,
                NonRestrictedTelegramPresentationTestFactory::plainText('rollback runtime race'),
                $requestKey,
                $correlationId,
            );

            if ($outerTransaction) {
                $connection->commit();
                $outerTransaction = false;
            }

            echo json_encode([
                'ok' => true,
                'public_id' => $receipt->publicId,
                'outbox_event_id' => $receipt->outboxEventId,
                'replayed' => $receipt->replayed,
            ], JSON_THROW_ON_ERROR)."\n";
        } catch (Throwable $exception) {
            if ($outerTransaction && $connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
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
    use App\Modules\Telegram\Application\TelegramDeliveryDatabaseAuthoritySurfaceV1;
    use App\Modules\Telegram\Application\TelegramDeliveryDatabaseCapability;
    use Illuminate\Database\DatabaseManager;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use ReflectionMethod;
    use RuntimeException;
    use Tests\TestCase;

    /** @requirement ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 OPS-003 QUA-004 QUA-007 QUA-010 */
    final class TelegramOutboundDeliveryRollbackRuntimeRaceTest extends TestCase
    {
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 20;

        protected function setUp(): void
        {
            parent::setUp();

            if (DB::connection()->getDriverName() !== 'mysql') {
                $this->markTestSkipped('Telegram rollback/runtime serialization regression requires MariaDB/MySQL.');
            }

            $this->migration()->up();
        }

        protected function tearDown(): void
        {
            try {
                $this->truncateTablesForAllConnections();
            } finally {
                parent::tearDown();
            }
        }

        public function test_entered_runtime_queue_drains_before_rollback_and_forces_durable_refusal_without_data_loss(): void
        {
            $queueWorker = $this->startWorker('--telegram-rollback-runtime-queue-hold-fence');
            $rollbackWorker = null;

            try {
                $queueConnectionId = $this->readyConnectionId($queueWorker, 'queue');
                $this->send($queueWorker, 'GO');
                self::assertSame("RUNTIME_FENCE_HELD\n", $this->readLine($queueWorker, 'runtime fence'));

                $rollbackWorker = $this->startWorker('--telegram-rollback-runtime-down');
                $rollbackConnectionId = $this->readyConnectionId($rollbackWorker, 'rollback');
                self::assertNotSame($queueConnectionId, $rollbackConnectionId);
                $this->send($rollbackWorker, 'GO');
                self::assertSame("ROLLBACK_FENCE_READY\n", $this->readLine($rollbackWorker, 'rollback fence request'));

                $this->assertWorkerHasNoStdout($rollbackWorker, 350_000, 'rollback crossed the runtime lifecycle fence before the producer committed');

                $this->send($queueWorker, 'CONTINUE');
                $queueResult = $this->readJsonResult($queueWorker, 'queue result');
                self::assertTrue($queueResult['ok'] ?? false, json_encode($queueResult));

                $rollbackResult = $this->readJsonResult($rollbackWorker, 'rollback result');
                self::assertFalse($rollbackResult['ok'] ?? true, json_encode($rollbackResult));
                self::assertStringContainsString(
                    'Cannot roll back Telegram outbound delivery authority while durable authority exists.',
                    (string) ($rollbackResult['message'] ?? ''),
                );

                $publicId = (string) ($queueResult['public_id'] ?? '');
                $outboxEventId = (string) ($queueResult['outbox_event_id'] ?? '');
                self::assertNotSame('', $publicId);
                self::assertNotSame('', $outboxEventId);
                self::assertSame(1, DB::table('telegram_delivery_operations')->where('public_id', $publicId)->count());
                self::assertSame(1, DB::table('outbox_messages')->where('id', $outboxEventId)->count());

                $capability = DB::table('telegram_delivery_authority_capability')->where('id', 1)->first();
                self::assertNotNull($capability);
                self::assertSame(1, (int) $capability->schema_version);
                self::assertNotNull($capability->activated_at);
                self::assertTrue((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->isReady(
                    DB::connection(),
                    (new TelegramDeliveryDatabaseCapability)->expectedHash(),
                ));
            } finally {
                $this->terminateWorker($queueWorker);
                if ($rollbackWorker !== null) {
                    $this->terminateWorker($rollbackWorker);
                }
            }
        }

        public function test_database_outbox_trigger_holds_shared_lifecycle_fence_even_without_application_prelock(): void
        {
            $triggerWorker = $this->startWorker('--telegram-rollback-runtime-trigger-hold-fence');
            $rollbackWorker = null;

            try {
                $triggerConnectionId = $this->readyConnectionId($triggerWorker, 'trigger-only producer');
                $this->send($triggerWorker, 'GO');
                self::assertSame("TRIGGER_FENCE_HELD\n", $this->readLine($triggerWorker, 'trigger lifecycle fence'));

                $rollbackWorker = $this->startWorker('--telegram-rollback-runtime-down');
                $rollbackConnectionId = $this->readyConnectionId($rollbackWorker, 'trigger-fence rollback');
                self::assertNotSame($triggerConnectionId, $rollbackConnectionId);
                $this->send($rollbackWorker, 'GO');
                self::assertSame("ROLLBACK_FENCE_READY\n", $this->readLine($rollbackWorker, 'trigger-fence rollback request'));
                $this->assertWorkerHasNoStdout(
                    $rollbackWorker,
                    350_000,
                    'Rollback crossed the database trigger lifecycle fence before the producer transaction ended',
                );

                $this->send($triggerWorker, 'CONTINUE');
                $triggerResult = $this->readJsonResult($triggerWorker, 'trigger-only producer result');
                self::assertTrue($triggerResult['ok'] ?? false, json_encode($triggerResult));

                $rollbackResult = $this->readJsonResult($rollbackWorker, 'trigger-fence rollback result');
                self::assertTrue($rollbackResult['ok'] ?? false, json_encode($rollbackResult));
                self::assertSame(0, DB::table('outbox_messages')
                    ->whereRaw('LOWER(event_type) = ?', ['telegram.delivery.requested'])
                    ->count());
                self::assertFalse(Schema::hasTable('telegram_delivery_operations'));
                self::assertFalse(Schema::hasTable('telegram_delivery_authority_capability'));

                $this->migration()->up();
                self::assertTrue((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->isReady(
                    DB::connection(),
                    (new TelegramDeliveryDatabaseCapability)->expectedHash(),
                ));
            } finally {
                $this->terminateWorker($triggerWorker);
                if ($rollbackWorker !== null) {
                    $this->terminateWorker($rollbackWorker);
                }
            }
        }

        public function test_queue_released_after_persistent_rollback_fence_is_rejected_before_operation_drop(): void
        {
            $queueWorker = $this->startWorker('--telegram-rollback-runtime-queue');

            try {
                $queueConnectionId = $this->readyConnectionId($queueWorker, 'late queue');
                $runtimeConnectionId = DB::connection()->selectOne('SELECT CONNECTION_ID() AS connection_id', [], false);
                self::assertNotNull($runtimeConnectionId);
                self::assertNotSame((int) $runtimeConnectionId->connection_id, $queueConnectionId);

                $migration = $this->migration();
                $rollback = new ReflectionMethod($migration, 'rollbackMysql');
                $rollback->setAccessible(true);
                $withInstallationLock = new ReflectionMethod($migration, 'withInstallationLock');
                $withInstallationLock->setAccessible(true);
                $connection = DB::connection();
                $database = app(DatabaseManager::class);
                $lifecycleConnection = $database->connection('telegram_lifecycle');
                $lateQueueResult = null;

                $afterFinalPreflight = function () use ($queueWorker, &$lateQueueResult): void {
                    $this->send($queueWorker, 'GO');
                    $lateQueueResult = $this->readJsonResult($queueWorker, 'late queue result');
                    self::assertFalse($lateQueueResult['ok'] ?? true, json_encode($lateQueueResult));
                    $message = (string) ($lateQueueResult['message'] ?? '');
                    self::assertTrue(
                        str_contains($message, 'not accepting runtime work')
                        || str_contains($message, 'not fully activated'),
                        'Late runtime queue was rejected for an unexpected reason: '.$message,
                    );
                };

                $withInstallationLock->invoke($migration, $lifecycleConnection, function () use (
                    $rollback,
                    $migration,
                    $connection,
                    $afterFinalPreflight,
                ): void {
                    $rollback->invoke(
                        $migration,
                        $connection,
                        $afterFinalPreflight,
                        null,
                        null,
                    );
                });

                self::assertIsArray($lateQueueResult);
                self::assertSame(0, DB::table('outbox_messages')
                    ->whereRaw('LOWER(event_type) = ?', ['telegram.delivery.requested'])
                    ->count());
                self::assertFalse(Schema::hasTable('telegram_delivery_operations'));
                self::assertFalse(Schema::hasTable('telegram_delivery_authority_capability'));
                self::assertSame([], (new TelegramDeliveryDatabaseAuthoritySurfaceV1)
                    ->presentRequiredTriggers(DB::connection()));

                $migration->up();
                self::assertTrue((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->isReady(
                    DB::connection(),
                    (new TelegramDeliveryDatabaseCapability)->expectedHash(),
                ));
            } finally {
                $this->terminateWorker($queueWorker);
            }
        }

        /** @return array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}} */
        private function startWorker(string $mode): array
        {
            $pipes = [];
            $process = proc_open([
                PHP_BINARY,
                '-d',
                'pcov.enabled=0',
                __FILE__,
                $mode,
            ], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start Telegram rollback runtime worker.');
            }
            /** @var array{0: resource, 1: resource, 2: resource} $pipes */
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            return ['process' => $process, 'pipes' => $pipes];
        }

        /** @param array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}} $worker */
        private function readyConnectionId(array $worker, string $phase): int
        {
            $line = trim($this->readLine($worker, $phase.' readiness'));
            if (preg_match('/\AREADY:([1-9][0-9]*)\z/', $line, $matches) !== 1) {
                throw new RuntimeException('Unexpected Telegram rollback runtime worker readiness: '.$line);
            }

            return (int) $matches[1];
        }

        /** @param array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}} $worker */
        private function send(array $worker, string $message): void
        {
            if (fwrite($worker['pipes'][0], $message."\n") === false) {
                throw new RuntimeException('Unable to release Telegram rollback runtime worker barrier.');
            }
            fflush($worker['pipes'][0]);
        }

        /**
         * @param  array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}}  $worker
         * @return array<string, mixed>
         */
        private function readJsonResult(array $worker, string $phase): array
        {
            $line = $this->readLine($worker, $phase);
            $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new RuntimeException('Telegram rollback runtime worker returned a non-object result: '.$line);
            }

            return $decoded;
        }

        /** @param array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}} $worker */
        private function readLine(array $worker, string $phase): string
        {
            $deadline = microtime(true) + self::WORKER_TIMEOUT_SECONDS;
            $stderr = '';
            while (microtime(true) < $deadline) {
                $read = [$worker['pipes'][1], $worker['pipes'][2]];
                $write = null;
                $except = null;
                $selected = stream_select($read, $write, $except, 0, 200_000);
                if ($selected === false) {
                    throw new RuntimeException('Unable to wait for Telegram rollback runtime worker output.');
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
                    throw new RuntimeException('Telegram rollback runtime worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException('Telegram rollback runtime worker timed out during '.$phase.': '.$stderr);
        }

        /** @param array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}} $worker */
        private function assertWorkerHasNoStdout(array $worker, int $microseconds, string $message): void
        {
            $read = [$worker['pipes'][1]];
            $write = null;
            $except = null;
            $selected = stream_select($read, $write, $except, 0, $microseconds);
            if ($selected === false) {
                throw new RuntimeException('Unable to observe Telegram rollback runtime worker fence wait.');
            }
            if ($selected > 0) {
                $line = fgets($worker['pipes'][1]);
                if ($line !== false && trim($line) !== '') {
                    self::fail($message.': '.trim($line));
                }
            }

            $status = proc_get_status($worker['process']);
            self::assertTrue($status['running'], $message.'; rollback worker exited instead of waiting.');
        }

        /** @param array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}} $worker */
        private function terminateWorker(array $worker): void
        {
            if (! is_resource($worker['process'])) {
                return;
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

        private function migration(): object
        {
            return require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php');
        }
    }
}
