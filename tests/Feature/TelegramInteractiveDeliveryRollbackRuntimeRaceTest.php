<?php

declare(strict_types=1);

namespace {
    use App\Modules\Telegram\Application\TelegramDeliveryDatabaseCapability;
    use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
    use App\Modules\Telegram\Application\TelegramDeliveryLifecycleDatabaseAuthority;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\DatabaseManager;

    $interactiveRollbackMode = PHP_SAPI === 'cli' ? ($argv[1] ?? null) : null;
    if (in_array($interactiveRollbackMode, [
        '--telegram-interactive-trigger-hold',
        '--telegram-interactive-trigger-late',
        '--telegram-interactive-rollback',
    ], true)) {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        /** @var DatabaseManager $database */
        $database = $app->make(DatabaseManager::class);
        $connection = $database->connection();
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 10');

        if ($interactiveRollbackMode === '--telegram-interactive-rollback') {
            $lifecycleAuthority = new TelegramDeliveryLifecycleDatabaseAuthority($database);
            $lifecycleConnection = $lifecycleAuthority->requireConnection($connection);
            $identity = $lifecycleConnection->selectOne('SELECT CONNECTION_ID() AS connection_id', [], false);
            echo 'READY:'.(int) ($identity->connection_id ?? 0)."\n";
            flush();

            $go = fgets(STDIN);
            if ($go === false || trim($go) !== 'GO') {
                fwrite(STDERR, "Telegram interactive rollback worker start barrier was not released.\n");
                exit(2);
            }

            try {
                $migration = require dirname(__DIR__, 2).'/database/migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php';
                $rollback = new ReflectionMethod($migration, 'rollbackMysql');
                $rollback->setAccessible(true);
                $withInstallationLock = new ReflectionMethod($migration, 'withInstallationLock');
                $withInstallationLock->setAccessible(true);
                $afterRuntimeFence = static function (): void {
                    echo "ROLLBACK_FENCE_READY\n";
                    flush();

                    $continue = fgets(STDIN);
                    if ($continue === false || trim($continue) !== 'CONTINUE') {
                        throw new RuntimeException('Telegram interactive rollback completion barrier was not released.');
                    }
                };

                $withInstallationLock->invoke($migration, $lifecycleConnection, static function () use (
                    $rollback,
                    $migration,
                    $connection,
                    $lifecycleConnection,
                    $afterRuntimeFence,
                ): void {
                    $rollback->invoke(
                        $migration,
                        $connection,
                        $lifecycleConnection,
                        $afterRuntimeFence,
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

        $operationPublicId = (string) ($argv[2] ?? '');
        $snapshotJson = base64_decode((string) ($argv[3] ?? ''), true);
        $snapshotHash = (string) ($argv[4] ?? '');
        if ($operationPublicId === '' || ! is_string($snapshotJson) || $snapshotHash === '') {
            fwrite(STDERR, "Telegram interactive trigger worker fixture arguments are invalid.\n");
            exit(2);
        }

        $identity = $connection->selectOne('SELECT CONNECTION_ID() AS connection_id', [], false);
        echo 'READY:'.(int) ($identity->connection_id ?? 0)."\n";
        flush();

        $go = fgets(STDIN);
        if ($go === false || trim($go) !== 'GO') {
            fwrite(STDERR, "Telegram interactive trigger worker start barrier was not released.\n");
            exit(2);
        }

        $transaction = false;
        try {
            $connection->beginTransaction();
            $transaction = true;
            $connection->statement(<<<'SQL'
SET @app_telegram_delivery_capability = ?,
    @app_telegram_delivery_interactive_authority = 'telegram_delivery_interactive_queue_v1',
    @app_telegram_delivery_interactive_public_id = ?,
    @app_telegram_delivery_interactive_snapshot_hash = ?
SQL, [
                (new TelegramDeliveryDatabaseCapability)->value(),
                $operationPublicId,
                $snapshotHash,
            ]);
            $connection->table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)->insert([
                'delivery_operation_public_id' => $operationPublicId,
                'keyboard_snapshot' => $snapshotJson,
                'keyboard_snapshot_hash' => $snapshotHash,
                'created_at' => '2026-08-31 00:00:00.000000',
            ]);

            if ($interactiveRollbackMode === '--telegram-interactive-trigger-hold') {
                echo "TRIGGER_INSERTED\n";
                flush();

                $continue = fgets(STDIN);
                if ($continue === false || trim($continue) !== 'CONTINUE') {
                    throw new RuntimeException('Telegram interactive trigger hold completion barrier was not released.');
                }

                $connection->commit();
                $transaction = false;
                echo json_encode(['ok' => true], JSON_THROW_ON_ERROR)."\n";
            } else {
                // A late trigger-only producer must never succeed after the
                // persistent rollback fence. Roll back even if a regression lets
                // the INSERT through so this worker cannot leave test data behind.
                $connection->rollBack();
                $transaction = false;
                echo json_encode([
                    'ok' => true,
                    'message' => 'Late trigger-only interactive INSERT unexpectedly succeeded.',
                ], JSON_THROW_ON_ERROR)."\n";
            }
        } catch (Throwable $exception) {
            if ($transaction && $connection->transactionLevel() > 0) {
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
    use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
    use App\Modules\Telegram\Application\TelegramInlineButtonStyle;
    use App\Modules\Telegram\Application\TelegramInlineCallbackButton;
    use App\Modules\Telegram\Application\TelegramInlineKeyboardSnapshot;
    use App\Shared\Application\Clock;
    use Illuminate\Database\DatabaseManager;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Str;
    use RuntimeException;
    use Tests\Support\NonRestrictedTelegramPresentationTestFactory;
    use Tests\TestCase;

    /** @requirement ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 OPS-003 QUA-004 QUA-007 */
    final class TelegramInteractiveDeliveryRollbackRuntimeRaceTest extends TestCase
    {
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 20;

        protected function setUp(): void
        {
            parent::setUp();

            if (DB::connection()->getDriverName() !== 'mysql') {
                $this->markTestSkipped('Telegram interactive rollback/runtime serialization requires MariaDB/MySQL.');
            }

            (require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php'))->up();
            (require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php'))->up();
        }

        protected function tearDown(): void
        {
            try {
                if (! Schema::hasTable('telegram_delivery_interactive_presentations')) {
                    (require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php'))->up();
                }
                $this->truncateTablesForAllConnections();
            } finally {
                parent::tearDown();
            }
        }

        public function test_entered_trigger_only_writer_drains_before_persistent_rollback_fence_and_forces_durable_refusal(): void
        {
            [$fixture, $keyboard] = $this->prepareTriggerFixture('entered');
            $triggerWorker = $this->startTriggerWorker(
                '--telegram-interactive-trigger-hold',
                $fixture['public_id'],
                $keyboard,
            );
            $rollbackWorker = $this->startRollbackWorker();

            try {
                $triggerConnectionId = $this->readyConnectionId($triggerWorker, 'trigger writer');
                $rollbackConnectionId = $this->readyConnectionId($rollbackWorker, 'rollback');
                self::assertNotSame($triggerConnectionId, $rollbackConnectionId);

                $this->send($triggerWorker, 'GO');
                self::assertSame("TRIGGER_INSERTED\n", $this->readLine($triggerWorker, 'trigger insert'));

                $this->send($rollbackWorker, 'GO');
                $this->assertWorkerHasNoStdout(
                    $rollbackWorker,
                    350_000,
                    'Rollback crossed an already-entered trigger-only lifecycle fence before that producer drained.',
                );

                $this->send($triggerWorker, 'CONTINUE');
                $triggerResult = $this->readJsonResult($triggerWorker, 'trigger writer result');
                self::assertTrue($triggerResult['ok'] ?? false, (string) json_encode($triggerResult));

                self::assertSame("ROLLBACK_FENCE_READY\n", $this->readLine($rollbackWorker, 'persistent rollback fence'));
                $this->send($rollbackWorker, 'CONTINUE');
                $rollbackResult = $this->readJsonResult($rollbackWorker, 'rollback result');
                self::assertFalse($rollbackResult['ok'] ?? true, (string) json_encode($rollbackResult));
                self::assertStringContainsString(
                    'cannot be removed while durable snapshots exist',
                    strtolower((string) ($rollbackResult['message'] ?? '')),
                );

                self::assertTrue(Schema::hasTable(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE));
                self::assertSame(1, DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
                    ->where('delivery_operation_public_id', $fixture['public_id'])
                    ->count());
                self::assertTrue((new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1)->isReady(DB::connection()));
                self::assertTrue($this->baseAuthorityIsReady());
            } finally {
                $this->terminateWorker($triggerWorker);
                $this->terminateWorker($rollbackWorker);
            }
        }

        public function test_late_trigger_only_writer_fails_closed_after_persistent_fence_without_deadlock_or_row_loss(): void
        {
            [$fixture, $keyboard] = $this->prepareTriggerFixture('late');
            $triggerWorker = $this->startTriggerWorker(
                '--telegram-interactive-trigger-late',
                $fixture['public_id'],
                $keyboard,
            );
            $rollbackWorker = $this->startRollbackWorker();

            try {
                $triggerConnectionId = $this->readyConnectionId($triggerWorker, 'late trigger writer');
                $rollbackConnectionId = $this->readyConnectionId($rollbackWorker, 'late rollback');
                self::assertNotSame($triggerConnectionId, $rollbackConnectionId);

                $this->send($rollbackWorker, 'GO');
                self::assertSame("ROLLBACK_FENCE_READY\n", $this->readLine($rollbackWorker, 'persistent rollback fence'));

                $this->send($triggerWorker, 'GO');
                $triggerResult = $this->readJsonResult($triggerWorker, 'late trigger writer result');
                self::assertFalse($triggerResult['ok'] ?? true, (string) json_encode($triggerResult));
                self::assertStringContainsString(
                    'Telegram interactive presentation creation authority is invalid.',
                    (string) ($triggerResult['message'] ?? ''),
                );

                $this->send($rollbackWorker, 'CONTINUE');
                $rollbackResult = $this->readJsonResult($rollbackWorker, 'late rollback result');
                self::assertTrue($rollbackResult['ok'] ?? false, (string) json_encode($rollbackResult));

                self::assertFalse(Schema::hasTable(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE));
                self::assertSame(1, DB::table('telegram_delivery_operations')
                    ->where('public_id', $fixture['public_id'])
                    ->count());
                self::assertSame(1, DB::table('outbox_messages')
                    ->where('id', $fixture['outbox_event_id'])
                    ->count());
                self::assertTrue($this->baseAuthorityIsReady());

                (require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php'))->up();
                self::assertTrue((new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1)->isReady(DB::connection()));
            } finally {
                $this->terminateWorker($triggerWorker);
                $this->terminateWorker($rollbackWorker);
            }
        }

        /**
         * @return array{0:array{public_id:string,outbox_event_id:string},1:TelegramInlineKeyboardSnapshot}
         */
        private function prepareTriggerFixture(string $suffix): array
        {
            $keyboard = new TelegramInlineKeyboardSnapshot([
                [new TelegramInlineCallbackButton('Trigger fence', (string) Str::ulid(), TelegramInlineButtonStyle::Primary)],
            ]);
            $fixture = NonRestrictedTelegramPresentationTestFactory::prepareInteractiveV2OperationWithoutSnapshot(
                app(DatabaseManager::class),
                app(Clock::class),
                900901,
                'interactive-trigger-'.$suffix,
                'correlation-interactive-trigger-'.$suffix,
                $keyboard,
            );

            self::assertSame(0, DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)->count());
            self::assertSame(1, DB::table('telegram_delivery_operations')->where('public_id', $fixture['public_id'])->count());
            self::assertSame(1, DB::table('outbox_messages')
                ->where('id', $fixture['outbox_event_id'])
                ->where('contract_version', 2)
                ->where('dispatch_state', 'authority_pending')
                ->count());

            return [$fixture, $keyboard];
        }

        private function baseAuthorityIsReady(): bool
        {
            return (new TelegramDeliveryDatabaseAuthoritySurfaceV1)->isReady(
                DB::connection(),
                (new TelegramDeliveryDatabaseCapability)->expectedHash(),
            );
        }

        /** @return array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}} */
        private function startTriggerWorker(
            string $mode,
            string $operationPublicId,
            TelegramInlineKeyboardSnapshot $keyboard,
        ): array {
            return $this->startWorker([
                $mode,
                $operationPublicId,
                base64_encode($keyboard->json()),
                $keyboard->hash(),
            ]);
        }

        /** @return array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}} */
        private function startRollbackWorker(): array
        {
            return $this->startWorker(['--telegram-interactive-rollback']);
        }

        /**
         * @param  list<string>  $arguments
         * @return array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}}
         */
        private function startWorker(array $arguments): array
        {
            $pipes = [];
            $process = proc_open([
                PHP_BINARY,
                '-d',
                'pcov.enabled=0',
                __FILE__,
                ...$arguments,
            ], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start Telegram interactive rollback concurrency worker.');
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
                throw new RuntimeException('Unexpected Telegram interactive concurrency worker readiness: '.$line);
            }

            return (int) $matches[1];
        }

        /** @param array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}} $worker */
        private function send(array $worker, string $message): void
        {
            if (fwrite($worker['pipes'][0], $message."\n") === false) {
                throw new RuntimeException('Unable to release Telegram interactive concurrency worker barrier.');
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
                throw new RuntimeException('Telegram interactive concurrency worker returned a non-object result: '.$line);
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
                    throw new RuntimeException('Unable to wait for Telegram interactive concurrency worker output.');
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
                    throw new RuntimeException('Telegram interactive concurrency worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException('Timed out waiting for Telegram interactive concurrency '.$phase.'. '.$stderr);
        }

        /** @param array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}} $worker */
        private function assertWorkerHasNoStdout(array $worker, int $microseconds, string $message): void
        {
            $read = [$worker['pipes'][1]];
            $write = null;
            $except = null;
            $seconds = intdiv($microseconds, 1_000_000);
            $remainingMicroseconds = $microseconds % 1_000_000;
            $selected = stream_select($read, $write, $except, $seconds, $remainingMicroseconds);
            if ($selected === false) {
                throw new RuntimeException('Unable to observe Telegram interactive concurrency worker blocking state.');
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
    }
}
