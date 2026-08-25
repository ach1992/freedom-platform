<?php

declare(strict_types=1);

namespace {
    use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
    use App\Modules\Telegram\Application\NonRestrictedTelegramPresentation;
    use App\Modules\Telegram\Application\TelegramDeliveryDatabaseCapability;
    use App\Modules\Telegram\Application\TelegramDeliveryQueueService;
    use App\Modules\Telegram\Domain\TelegramDeliveryAction;
    use App\Shared\Application\Clock;
    use App\Shared\Infrastructure\DatabaseOutboxPublisher;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\Connection;
    use Illuminate\Database\DatabaseManager;

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--telegram-outbound-queue-contention-worker') {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $database = $app->make(DatabaseManager::class);
        $database->connection()->statement('SET SESSION innodb_lock_wait_timeout = 10');
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
        $queue = new TelegramDeliveryQueueService(
            $database,
            $clock,
            new DatabaseOutboxPublisher($database, $clock),
            $runtime,
            new TelegramDeliveryDatabaseCapability,
        );

        echo "READY\n";
        flush();
        $go = fgets(STDIN);
        if ($go === false || trim($go) !== 'GO') {
            fwrite(STDERR, "Telegram outbound contention worker start barrier was not released.\n");
            exit(2);
        }

        $barrierReached = false;
        $database->connection()->beforeExecuting(function (string $query, array $bindings, Connection $connection) use (&$barrierReached): void {
            unset($bindings, $connection);
            $normalized = strtolower($query);
            if ($barrierReached
                || ! str_contains($normalized, 'insert into `telegram_delivery_operations`')) {
                return;
            }

            $barrierReached = true;
            echo "OPERATION_READY\n";
            flush();
            $continue = fgets(STDIN);
            if ($continue === false || trim($continue) !== 'CONTINUE') {
                throw new RuntimeException('Telegram outbound operation barrier was not released.');
            }
        });

        try {
            $receipt = $queue->queue(
                TelegramDeliveryAction::Send,
                900030,
                null,
                NonRestrictedTelegramPresentation::plainText('concurrent delivery'),
                'telegram-concurrent-request-179',
                'correlation-concurrent-179',
            );
            echo json_encode([
                'ok' => true,
                'public_id' => $receipt->publicId,
                'outbox_event_id' => $receipt->outboxEventId,
                'replayed' => $receipt->replayed,
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

    /** @requirement ARCH-004 DAT-003 OPS-003 QUA-004 QUA-007 */
    final class TelegramOutboundDeliveryConcurrencyTest extends TestCase
    {
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 20;

        protected function setUp(): void
        {
            parent::setUp();

            if (DB::connection()->getDriverName() !== 'mysql') {
                $this->markTestSkipped('Telegram outbound queue contention verification requires MariaDB/MySQL.');
            }

            $migration = require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php');
            $migration->up();
        }

        protected function tearDown(): void
        {
            try {
                $this->truncateTablesForAllConnections();
            } finally {
                parent::tearDown();
            }
        }

        public function test_two_concurrent_producers_converge_to_one_operation_and_one_outbox_command(): void
        {
            $results = $this->runConcurrentWorkers();

            self::assertCount(2, $results);
            self::assertTrue($results[0]['ok']);
            self::assertTrue($results[1]['ok']);
            self::assertSame($results[0]['public_id'], $results[1]['public_id']);
            self::assertSame($results[0]['outbox_event_id'], $results[1]['outbox_event_id']);
            $replayFlags = [$results[0]['replayed'], $results[1]['replayed']];
            sort($replayFlags);
            self::assertSame([false, true], $replayFlags);
            self::assertSame(1, DB::table('telegram_delivery_operations')->count());
            self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'telegram.delivery.requested')->count());
            self::assertSame(0, DB::table('outbox_messages')->where('dispatch_state', 'authority_pending')->count());
        }

        /** @return list<array{ok: bool, public_id: string, outbox_event_id: string, replayed: bool}> */
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
                        '--telegram-outbound-queue-contention-worker',
                    ], [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ], $pipes, dirname(__DIR__, 2));
                    if (! is_resource($process)) {
                        throw new RuntimeException('Unable to start Telegram outbound contention worker.');
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
                    self::assertSame("OPERATION_READY\n", $this->readLine($worker, 'operation barrier', $index));
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
                        throw new RuntimeException('Telegram outbound contention worker failed: '.$stderr);
                    }
                    $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    if (! is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
                        throw new RuntimeException('Telegram outbound contention worker returned failure: '.$line.$stderr);
                    }
                    /** @var array{ok: bool, public_id: string, outbox_event_id: string, replayed: bool} $decoded */
                    $results[] = $decoded;
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
                    throw new RuntimeException('Unable to wait for Telegram outbound contention worker output.');
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
                    throw new RuntimeException('Telegram outbound contention worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException(sprintf(
                'Telegram outbound contention worker %d timed out during %s: %s',
                $index,
                $phase,
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
