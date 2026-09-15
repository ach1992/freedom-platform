<?php

declare(strict_types=1);

namespace {
    use Illuminate\Contracts\Console\Kernel;

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--telegram-outbound-migration-contender') {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        try {
            $migration = require dirname(__DIR__, 2).'/database/migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php';
            (new ReflectionClass($migration))->getMethod('up')->invoke($migration);
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
}

namespace Tests\Feature {
    use App\Modules\Telegram\Application\TelegramDeliveryDatabaseAuthoritySurfaceV1;
    use App\Modules\Telegram\Application\TelegramDeliveryDatabaseCapability;
    use Illuminate\Database\Connection;
    use Illuminate\Database\DatabaseManager;
    use Illuminate\Database\QueryException;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use ReflectionClass;
    use RuntimeException;
    use Tests\TestCase;
    use Throwable;

    /** @requirement ARCH-003 ARCH-004 DAT-003 SEC-008 OPS-003 QUA-004 QUA-007 QUA-010 */
    final class TelegramOutboundDeliveryMigrationConcurrencyTest extends TestCase
    {
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 20;

        protected function setUp(): void
        {
            parent::setUp();

            if (DB::connection()->getDriverName() !== 'mysql') {
                $this->markTestSkipped('Telegram outbound migration concurrency verification requires MariaDB/MySQL.');
            }

            $this->runMigrationUp();
        }

        protected function tearDown(): void
        {
            try {
                $this->restoreAuthoritySurfaceForTestIsolation();
                $this->truncateTablesForAllConnections();
            } finally {
                parent::tearDown();
            }
        }

        public function test_independent_migration_process_cannot_cross_installation_lock_or_make_a_stale_reset_decision(): void
        {
            $this->dropDeliveryGuards();
            DB::table('telegram_delivery_authority_capability')->where('id', 1)->update([
                'schema_version' => 0,
                'activated_at' => null,
            ]);
            self::assertSame(0, $this->deliveryTriggerCount());
            self::assertTrue(Schema::hasTable('telegram_delivery_operations'));
            self::assertSame(0, DB::table('telegram_delivery_operations')->count());
            self::assertSame(0, DB::table('outbox_messages')->whereRaw('LOWER(event_type) = ?', ['telegram.delivery.requested'])->count());

            $connection = DB::connection();
            $lockName = TelegramDeliveryDatabaseAuthoritySurfaceV1::installationLockName($connection);
            $acquired = $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName], false);
            self::assertNotNull($acquired);
            self::assertSame(1, (int) $acquired->acquired);

            try {
                $result = $this->runContenderProcess();
                self::assertFalse($result['ok']);
                self::assertSame(RuntimeException::class, $result['exception']);
                self::assertStringContainsString('installation lock is already held', $result['message']);

                // The contender never reached authorityReady/reset/install DDL.
                self::assertSame(0, (int) DB::table('telegram_delivery_authority_capability')->where('id', 1)->value('schema_version'));
                self::assertSame(0, $this->deliveryTriggerCount());
                self::assertTrue(Schema::hasTable('telegram_delivery_operations'));
            } finally {
                $released = $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName], false);
                self::assertNotNull($released);
                self::assertSame(1, (int) $released->released);
            }

            $this->runMigrationUp();
            self::assertSame(1, (int) DB::table('telegram_delivery_authority_capability')->where('id', 1)->value('schema_version'));
            self::assertSame(9, $this->deliveryTriggerCount());
        }

        public function test_installation_lock_owner_session_loss_aborts_without_unlocked_reconnect(): void
        {
            $database = app(DatabaseManager::class);
            $defaultConnection = config('database.default');
            self::assertIsString($defaultConnection);
            $connectionConfig = config('database.connections.'.$defaultConnection);
            self::assertIsArray($connectionConfig);
            config(['database.connections.telegram_migration_killer' => $connectionConfig]);

            $connection = DB::connection();
            $killer = $database->connection('telegram_migration_killer');
            $lockName = TelegramDeliveryDatabaseAuthoritySurfaceV1::installationLockName($connection);
            $migration = $this->migration();
            $withInstallationLock = (new ReflectionClass($migration))->getMethod('withInstallationLock');
            $killedConnectionId = null;
            $probeExisted = false;

            try {
                try {
                    $withInstallationLock->invoke($migration, $connection, function () use ($connection, $killer, &$killedConnectionId): void {
                        $session = $connection->selectOne('SELECT CONNECTION_ID() AS connection_id', [], false);
                        self::assertNotNull($session);
                        $killedConnectionId = (int) $session->connection_id;
                        $killer->getPdo()->exec('KILL CONNECTION '.$killedConnectionId);

                        // Without the migration's fail-closed reconnector this
                        // statement would transparently reconnect and execute
                        // after the advisory lock had already been released.
                        DB::statement('CREATE TABLE telegram_delivery_reconnect_probe (`id` INT NOT NULL)');
                    });
                    self::fail('Losing the advisory-lock owner session must abort the migration critical section.');
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString('installation lock cleanup failed', $exception->getMessage());
                }

                self::assertIsInt($killedConnectionId);
                $probeExisted = Schema::hasTable('telegram_delivery_reconnect_probe');

                // The lock is released when the killed session is fully torn down.
                // MariaDB may complete that teardown asynchronously after KILL
                // returns, so use a bounded acquisition timeout rather than a
                // racy zero-timeout probe. This still proves there is no stale
                // lock hiding an unlocked continuation.
                $acquired = $killer->selectOne('SELECT GET_LOCK(?, 5) AS acquired', [$lockName], false);
                self::assertNotNull($acquired);
                self::assertSame(1, (int) $acquired->acquired);
                $released = $killer->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName], false);
                self::assertNotNull($released);
                self::assertSame(1, (int) $released->released);

                $restoredSession = DB::connection()->selectOne('SELECT CONNECTION_ID() AS connection_id', [], false);
                self::assertNotNull($restoredSession);
                self::assertNotSame($killedConnectionId, (int) $restoredSession->connection_id);
            } finally {
                Schema::dropIfExists('telegram_delivery_reconnect_probe');
                $database->purge('telegram_migration_killer');
            }

            self::assertFalse($probeExisted);
            $this->runMigrationUp();
            self::assertSame(9, $this->deliveryTriggerCount());
        }

        public function test_runtime_authority_requires_transaction_and_pins_schema_against_concurrent_ddl(): void
        {
            $capability = new TelegramDeliveryDatabaseCapability;
            $connection = DB::connection();

            try {
                $capability->runEffect(
                    $connection,
                    'telegram_delivery_effect_v1',
                    '01J00000000000000000000001',
                    1,
                    static fn (): null => null,
                );
                self::fail('Telegram delivery database authority must not be armed outside a transaction.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('must be armed inside a database transaction', $exception->getMessage());
            }

            $database = app(DatabaseManager::class);
            $defaultConnection = config('database.default');
            self::assertIsString($defaultConnection);
            $connectionConfig = config('database.connections.'.$defaultConnection);
            self::assertIsArray($connectionConfig);
            config(['database.connections.telegram_metadata_ddl_contender' => $connectionConfig]);
            $contender = $database->connection('telegram_metadata_ddl_contender');
            $contender->statement('SET SESSION lock_wait_timeout = 1');

            try {
                $connection->transaction(function (Connection $transaction) use ($capability, $contender): void {
                    $capability->runEffect(
                        $transaction,
                        'telegram_delivery_effect_v1',
                        '01J00000000000000000000001',
                        1,
                        function () use ($contender): void {
                            try {
                                $contender->unprepared('DROP TRIGGER telegram_delivery_operations_update_guard');
                                self::fail('Concurrent DDL must not cross the runtime semantic-attestation metadata lock.');
                            } catch (QueryException $exception) {
                                self::assertSame(1205, (int) ($exception->errorInfo[1] ?? 0));
                            }
                        },
                    );
                });

                self::assertSame(9, $this->deliveryTriggerCount());
            } finally {
                $database->purge('telegram_metadata_ddl_contender');
            }
        }

        /** @return array{ok: bool, exception: string, message: string} */
        private function runContenderProcess(): array
        {
            $pipes = [];
            $process = proc_open([
                PHP_BINARY,
                '-d',
                'pcov.enabled=0',
                __FILE__,
                '--telegram-outbound-migration-contender',
            ], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start Telegram outbound migration contender.');
            }
            /** @var array{0: resource, 1: resource, 2: resource} $pipes */
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            try {
                $deadline = microtime(true) + self::WORKER_TIMEOUT_SECONDS;
                $stdout = '';
                $stderr = '';
                while (microtime(true) < $deadline) {
                    $stdout .= stream_get_contents($pipes[1]);
                    $stderr .= stream_get_contents($pipes[2]);
                    $status = proc_get_status($process);
                    if (! $status['running']) {
                        break;
                    }
                    usleep(100_000);
                }

                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process);
                    throw new RuntimeException('Telegram outbound migration contender timed out: '.$stderr);
                }
                $decoded = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($decoded)
                    || ! array_key_exists('ok', $decoded)
                    || ! is_bool($decoded['ok'])
                    || ! is_string($decoded['exception'] ?? null)
                    || ! is_string($decoded['message'] ?? null)) {
                    throw new RuntimeException('Telegram outbound migration contender returned an invalid result: '.$stdout.$stderr);
                }

                /** @var array{ok: bool, exception: string, message: string} $decoded */
                return $decoded;
            } finally {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process);
                }
                proc_close($process);
            }
        }

        private function runMigrationUp(): void
        {
            $migration = $this->migration();
            (new ReflectionClass($migration))->getMethod('up')->invoke($migration);
        }

        private function migration(): object
        {
            return require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php');
        }

        private function restoreAuthoritySurfaceForTestIsolation(): void
        {
            try {
                if (Schema::hasTable('telegram_delivery_operations')) {
                    DB::table('telegram_delivery_operations')->delete();
                }
                DB::table('outbox_messages')->whereRaw('LOWER(event_type) = ?', ['telegram.delivery.requested'])->delete();
                $this->runMigrationUp();
            } catch (Throwable) {
                // A failed test must not hide its original failure behind best-effort cleanup.
            }
        }

        private function dropDeliveryGuards(): void
        {
            DB::unprepared('DROP TRIGGER IF EXISTS outbox_telegram_delivery_envelope_delete_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS outbox_telegram_delivery_envelope_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_operations_delete_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_operations_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_operations_insert_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS outbox_telegram_delivery_envelope_insert_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_capability_delete_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_capability_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_capability_insert_guard');
        }

        private function deliveryTriggerCount(): int
        {
            return (int) DB::table('information_schema.TRIGGERS')
                ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
                ->whereIn('TRIGGER_NAME', TelegramDeliveryDatabaseAuthoritySurfaceV1::REQUIRED_TRIGGERS)
                ->count();
        }
    }
}
