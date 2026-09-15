<?php

declare(strict_types=1);

namespace {
    use App\Shared\Application\Clock;
    use App\Shared\Application\OutboxDispatchOutcome;
    use App\Shared\Application\OutboxMessage;
    use App\Shared\Application\OutboxMessageHandler;
    use App\Shared\Infrastructure\DatabaseOutboxDispatcher;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\Connection;
    use Illuminate\Database\DatabaseManager;

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--outbox-dispatcher-contention-worker') {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $database = $app->make(DatabaseManager::class);
        $database->connection()->statement('SET SESSION innodb_lock_wait_timeout = 5');

        echo "READY\n";
        flush();
        $go = fgets(STDIN);
        if ($go === false || trim($go) !== 'GO') {
            fwrite(STDERR, "Outbox dispatcher worker start barrier was not released.\n");
            exit(2);
        }

        $claimBarrierReached = false;
        $database->connection()->beforeExecuting(function (string $query, array $bindings, Connection $connection) use (&$claimBarrierReached): void {
            unset($bindings, $connection);
            $normalizedQuery = strtolower($query);
            if ($claimBarrierReached || ! str_contains($normalizedQuery, 'outbox_messages') || ! str_contains($normalizedQuery, 'for update')) {
                return;
            }

            $claimBarrierReached = true;
            echo "CLAIM_READY\n";
            flush();
            $continue = fgets(STDIN);
            if ($continue === false || trim($continue) !== 'CONTINUE') {
                throw new RuntimeException('Outbox dispatcher claim barrier was not released.');
            }
        });

        try {
            $handler = new class implements OutboxMessageHandler
            {
                /** @var list<string> */
                public array $handledEventKeys = [];

                public function handle(OutboxMessage $message): OutboxDispatchOutcome
                {
                    $this->handledEventKeys[] = $message->eventKey;

                    return OutboxDispatchOutcome::Success;
                }
            };
            $clock = new class(new DateTimeImmutable('2026-08-12T00:00:00+00:00')) implements Clock
            {
                public function __construct(private readonly DateTimeImmutable $now) {}

                public function now(): DateTimeImmutable
                {
                    return $this->now;
                }
            };
            $dispatcher = new DatabaseOutboxDispatcher($database, $clock, 60);
            $result = $dispatcher->dispatchOne($handler);

            echo json_encode([
                'ok' => true,
                'claimed' => $result !== null,
                'handled_event_keys' => $handler->handledEventKeys,
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
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\TestCase;

    /** @requirement PAY-003 ARCH-004 OPS-003 QUA-004 */
    final class DatabaseOutboxDispatcherConcurrencyTest extends TestCase
    {
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 15;

        protected function setUp(): void
        {
            parent::setUp();

            if (DB::connection()->getDriverName() !== 'mysql') {
                $this->markTestSkipped('Outbox dispatcher contention verification requires MariaDB/MySQL.');
            }
        }

        protected function tearDown(): void
        {
            try {
                $this->truncateTablesForAllConnections();
            } finally {
                parent::tearDown();
            }
        }

        public function test_two_concurrent_mariadb_workers_dispatch_the_same_message_at_most_once(): void
        {
            $id = '0198a4c7-ff31-7bb9-8222-000000000401';
            $this->insertPendingMessage($id);

            $results = $this->runConcurrentWorkers();

            self::assertCount(2, $results);
            self::assertTrue($results[0]['ok']);
            self::assertTrue($results[1]['ok']);
            self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['claimed'] === true)));
            self::assertSame(1, array_sum(array_map(static fn (array $result): int => count($result['handled_event_keys']), $results)));
            $handledEventKeys = array_merge(...array_map(static fn (array $result): array => $result['handled_event_keys'], $results));
            self::assertSame(['outbox:concurrent-dispatch:v1'], $handledEventKeys);
            $this->assertDatabaseHas('outbox_messages', [
                'id' => $id,
                'dispatch_state' => 'processed',
                'attempts' => 1,
                'lease_token' => null,
            ]);
        }

        private function insertPendingMessage(string $id): void
        {
            DB::table('outbox_messages')->insert([
                'id' => $id,
                'event_key' => 'outbox:concurrent-dispatch:v1',
                'event_type' => 'order.paid',
                'aggregate_type' => 'order',
                'aggregate_id' => '1',
                'payload' => json_encode(['order_id' => '1'], JSON_THROW_ON_ERROR),
                'payload_hash' => hash('sha256', '{"order_id":"1"}'),
                'correlation_id' => '0198a4c7-ff31-7bb9-8222-000000000499',
                'available_at' => '2026-08-12 00:00:00.000000',
                'attempts' => 0,
                'created_at' => '2026-08-12 00:00:00.000000',
                'updated_at' => '2026-08-12 00:00:00.000000',
            ]);
        }

        /** @return list<array{ok: bool, claimed: bool, handled_event_keys: list<string>}> */
        private function runConcurrentWorkers(): array
        {
            /** @var list<array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}}> $workers */
            $workers = [];

            try {
                for ($index = 0; $index < 2; $index++) {
                    $pipes = [];
                    $process = proc_open([
                        PHP_BINARY,
                        '-d',
                        'pcov.enabled=0',
                        __FILE__,
                        '--outbox-dispatcher-contention-worker',
                    ], [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ], $pipes, dirname(__DIR__, 2));
                    if (! is_resource($process)) {
                        throw new RuntimeException('Unable to start outbox dispatcher contention worker.');
                    }
                    /** @var array{0: resource, 1: resource, 2: resource} $pipes */
                    stream_set_blocking($pipes[1], false);
                    stream_set_blocking($pipes[2], false);
                    $workers[] = ['process' => $process, 'pipes' => $pipes];
                }

                foreach ($workers as $index => $worker) {
                    self::assertSame("READY\n", $this->readLine($worker, 'readiness', $index));
                }
                foreach ($workers as $worker) {
                    fwrite($worker['pipes'][0], "GO\n");
                    fflush($worker['pipes'][0]);
                }
                foreach ($workers as $index => $worker) {
                    self::assertSame("CLAIM_READY\n", $this->readLine($worker, 'claim barrier', $index));
                }
                foreach ($workers as $worker) {
                    fwrite($worker['pipes'][0], "CONTINUE\n");
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
                        throw new RuntimeException('Outbox dispatcher contention worker failed: '.$stderr);
                    }
                    /** @var array{ok: bool, claimed: bool, handled_event_keys: list<string>} $result */
                    $result = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    $results[] = $result;
                }

                return $results;
            } finally {
                $this->terminateWorkers($workers);
            }
        }

        /** @param array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}} $worker */
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
                    throw new RuntimeException('Unable to wait for outbox dispatcher contention worker output.');
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
                    throw new RuntimeException('Outbox dispatcher contention worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException(sprintf(
                'Outbox dispatcher contention worker %d timed out during %s after %d seconds: %s',
                $index,
                $phase,
                self::WORKER_TIMEOUT_SECONDS,
                $stderr,
            ));
        }

        /** @param list<array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}}> $workers */
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
}
