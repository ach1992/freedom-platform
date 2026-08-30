<?php

declare(strict_types=1);

namespace {
    use App\Modules\Telegram\Application\TelegramDeliveryDatabaseCapability;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\DatabaseManager;

    $interactiveRollbackMode = PHP_SAPI === 'cli' ? ($argv[1] ?? null) : null;
    if ($interactiveRollbackMode === '--telegram-interactive-runtime-fence') {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        /** @var DatabaseManager $database */
        $database = $app->make(DatabaseManager::class);
        $connection = $database->connection();
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 10');
        $identity = $connection->selectOne('SELECT CONNECTION_ID() AS connection_id', [], false);
        echo 'READY:'.(int) ($identity->connection_id ?? 0)."\n";
        flush();

        $go = fgets(STDIN);
        if ($go === false || trim($go) !== 'GO') {
            fwrite(STDERR, "Telegram interactive runtime-fence start barrier was not released.\n");
            exit(2);
        }

        $transaction = false;
        try {
            $connection->beginTransaction();
            $transaction = true;
            (new TelegramDeliveryDatabaseCapability)->acquireRuntimeLifecycleFence($connection);
            echo "RUNTIME_FENCE_ACQUIRED\n";
            flush();

            $continue = fgets(STDIN);
            if ($continue === false || trim($continue) !== 'CONTINUE') {
                throw new RuntimeException('Telegram interactive runtime-fence completion barrier was not released.');
            }

            $connection->rollBack();
            $transaction = false;
            echo json_encode(['ok' => true], JSON_THROW_ON_ERROR)."\n";
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
    use App\Modules\Telegram\Application\TelegramDeliveryLifecycleDatabaseAuthority;
    use Illuminate\Database\DatabaseManager;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use ReflectionMethod;
    use RuntimeException;
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

        public function test_interactive_rollback_excludes_late_runtime_work_between_empty_preflight_and_drop(): void
        {
            self::assertSame(0, DB::table('telegram_delivery_interactive_presentations')->count());

            $worker = $this->startWorker();
            try {
                $workerConnectionId = $this->readyConnectionId($worker);
                $runtimeConnection = DB::connection();
                $runtimeIdentity = $runtimeConnection->selectOne('SELECT CONNECTION_ID() AS connection_id', [], false);
                self::assertNotNull($runtimeIdentity);
                self::assertNotSame((int) $runtimeIdentity->connection_id, $workerConnectionId);

                $migration = require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php');
                $rollback = new ReflectionMethod($migration, 'rollbackMysql');
                $rollback->setAccessible(true);
                $withInstallationLock = new ReflectionMethod($migration, 'withInstallationLock');
                $withInstallationLock->setAccessible(true);

                $database = app(DatabaseManager::class);
                $lifecycleAuthority = new TelegramDeliveryLifecycleDatabaseAuthority($database);
                $lifecycleConnection = $lifecycleAuthority->requireConnection($runtimeConnection);
                $fenceWasObserved = false;

                $afterRuntimeFence = function () use ($worker, &$fenceWasObserved): void {
                    $this->send($worker, 'GO');
                    $this->assertWorkerHasNoStdout(
                        $worker,
                        350_000,
                        'Late Telegram runtime work crossed the interactive rollback lifecycle fence.',
                    );
                    $fenceWasObserved = true;
                };

                $withInstallationLock->invoke($migration, $lifecycleConnection, function () use (
                    $rollback,
                    $migration,
                    $runtimeConnection,
                    $lifecycleConnection,
                    $afterRuntimeFence,
                ): void {
                    $rollback->invoke(
                        $migration,
                        $runtimeConnection,
                        $lifecycleConnection,
                        $afterRuntimeFence,
                    );
                });

                self::assertTrue($fenceWasObserved);
                self::assertFalse(Schema::hasTable('telegram_delivery_interactive_presentations'));
                self::assertSame("RUNTIME_FENCE_ACQUIRED\n", $this->readLine($worker, 'runtime fence acquisition'));
                $this->send($worker, 'CONTINUE');
                $result = $this->readJsonResult($worker, 'runtime fence result');
                self::assertTrue($result['ok'] ?? false, (string) json_encode($result));

                $migration->up();
                self::assertTrue((new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1)->isReady(DB::connection()));
                self::assertTrue((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->isReady(
                    DB::connection(),
                    (new TelegramDeliveryDatabaseCapability)->expectedHash(),
                ));
            } finally {
                $this->terminateWorker($worker);
            }
        }

        /** @return array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}} */
        private function startWorker(): array
        {
            $pipes = [];
            $process = proc_open([
                PHP_BINARY,
                '-d',
                'pcov.enabled=0',
                __FILE__,
                '--telegram-interactive-runtime-fence',
            ], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start Telegram interactive runtime-fence worker.');
            }
            /** @var array{0: resource, 1: resource, 2: resource} $pipes */
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            return ['process' => $process, 'pipes' => $pipes];
        }

        /** @param array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}} $worker */
        private function readyConnectionId(array $worker): int
        {
            $line = trim($this->readLine($worker, 'worker readiness'));
            if (preg_match('/\AREADY:([1-9][0-9]*)\z/', $line, $matches) !== 1) {
                throw new RuntimeException('Unexpected Telegram interactive runtime-fence worker readiness: '.$line);
            }

            return (int) $matches[1];
        }

        /** @param array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}} $worker */
        private function send(array $worker, string $message): void
        {
            if (fwrite($worker['pipes'][0], $message."\n") === false) {
                throw new RuntimeException('Unable to release Telegram interactive runtime-fence worker barrier.');
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
                throw new RuntimeException('Telegram interactive runtime-fence worker returned a non-object result: '.$line);
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
                    throw new RuntimeException('Unable to wait for Telegram interactive runtime-fence worker output.');
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
                    throw new RuntimeException('Telegram interactive runtime-fence worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException('Timed out waiting for Telegram interactive runtime-fence '.$phase.'. '.$stderr);
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
                throw new RuntimeException('Unable to observe Telegram interactive runtime-fence worker blocking state.');
            }
            self::assertSame(0, $selected, $message);
        }

        /** @param array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}} $worker */
        private function terminateWorker(array $worker): void
        {
            foreach ($worker['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $status = proc_get_status($worker['process']);
            if ($status['running']) {
                proc_terminate($worker['process']);
            }
            proc_close($worker['process']);
        }
    }
}
