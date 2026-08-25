<?php

declare(strict_types=1);

namespace {
    use App\Modules\Telegram\Application\TelegramInteractionSessionService;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\Connection;
    use Illuminate\Database\DatabaseManager;

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--telegram-interaction-worker') {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $connection = $app->make(DatabaseManager::class)->connection();
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 5');

        $decoded = base64_decode((string) ($argv[2] ?? ''), true);
        if ($decoded === false) {
            fwrite(STDERR, "Invalid Telegram interaction contention payload.\n");
            exit(2);
        }
        /** @var array{session_public_id:string,next_state:string,request_key:string} $payload */
        $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        $barrierReached = false;
        $connection->beforeExecuting(function (string $query, array $bindings, Connection $db) use (&$barrierReached): void {
            unset($bindings, $db);
            if ($barrierReached) {
                return;
            }
            $sql = strtolower($query);
            if (! str_contains($sql, 'telegram_interaction_sessions') || ! str_contains($sql, 'for update')) {
                return;
            }

            $barrierReached = true;
            echo "BEFORE_SESSION_LOCK\n";
            flush();
            $continue = fgets(STDIN);
            if ($continue === false || trim($continue) !== 'CONTINUE') {
                throw new RuntimeException('Telegram interaction contention barrier was not released.');
            }
        });

        echo "READY\n";
        flush();
        $go = fgets(STDIN);
        if ($go === false || trim($go) !== 'GO') {
            fwrite(STDERR, "Telegram interaction contention start barrier was not released.\n");
            exit(2);
        }

        try {
            $receipt = $app->make(TelegramInteractionSessionService::class)->transition(
                $payload['session_public_id'],
                1,
                $payload['next_state'],
                [],
                $payload['request_key'],
                3600,
            );
            echo json_encode([
                'ok' => true,
                'state' => $receipt->state,
                'version' => $receipt->version,
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
    use App\Modules\Telegram\Application\TelegramInteractionSessionService;
    use App\Shared\Application\Clock;
    use App\Shared\Infrastructure\SystemClock;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Str;
    use RuntimeException;
    use Tests\TestCase;

    /** @requirement ARCH-003 DAT-003 SEC-002 SEC-003 QUA-004 */
    final class TelegramInteractionContentionTest extends TestCase
    {
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 20;

        protected function setUp(): void
        {
            parent::setUp();
            if (DB::connection()->getDriverName() !== 'mysql') {
                $this->markTestSkipped('Telegram interaction contention requires MariaDB/MySQL.');
            }

            $migration = require database_path('migrations/2026_08_25_000100_enable_telegram_interaction_authority.php');
            $migration->up();
            $this->app->instance(Clock::class, new SystemClock);
            $this->app->forgetInstance(TelegramInteractionSessionService::class);
        }

        protected function tearDown(): void
        {
            try {
                $this->truncateTablesForAllConnections();
            } finally {
                parent::tearDown();
            }
        }

        public function test_concurrent_transitions_serialize_to_one_version_and_one_stale_rejection(): void
        {
            $accountId = $this->account();
            $session = $this->app->make(TelegramInteractionSessionService::class)->start(
                $accountId,
                'customer.concurrent',
                'start',
                [],
                'telegram-contention-start',
                3600,
            );

            $first = $this->startWorker($session->publicId, 'first', 'telegram-contention-first');
            $second = $this->startWorker($session->publicId, 'second', 'telegram-contention-second');
            try {
                self::assertSame("READY\n", $this->readLine($first, 'first readiness'));
                self::assertSame("READY\n", $this->readLine($second, 'second readiness'));
                $this->sendCommand($first, 'GO');
                $this->sendCommand($second, 'GO');
                self::assertSame("BEFORE_SESSION_LOCK\n", $this->readLine($first, 'first lock barrier'));
                self::assertSame("BEFORE_SESSION_LOCK\n", $this->readLine($second, 'second lock barrier'));
                $this->sendCommand($first, 'CONTINUE');
                $this->sendCommand($second, 'CONTINUE');

                $results = [
                    $this->readJsonResult($first, 'first result'),
                    $this->readJsonResult($second, 'second result'),
                ];
                $successes = array_values(array_filter($results, static fn (array $result): bool => ($result['ok'] ?? false) === true));
                $failures = array_values(array_filter($results, static fn (array $result): bool => ($result['ok'] ?? true) === false));
                self::assertCount(1, $successes, json_encode($results, JSON_THROW_ON_ERROR));
                self::assertCount(1, $failures, json_encode($results, JSON_THROW_ON_ERROR));
                $success = $successes[0] ?? null;
                $failure = $failures[0] ?? null;
                self::assertIsArray($success);
                self::assertIsArray($failure);
                self::assertSame(2, (int) $success['version']);
                self::assertSame(\DomainException::class, $failure['exception']);
                self::assertStringContainsString('version is stale', (string) $failure['message']);

                $row = DB::table('telegram_interaction_sessions')->where('public_id', $session->publicId)->first(['state', 'version']);
                self::assertNotNull($row);
                self::assertContains((string) $row->state, ['first', 'second']);
                self::assertSame(2, (int) $row->version);
                self::assertSame(2, DB::table('telegram_interaction_transitions')
                    ->where('telegram_interaction_session_id', (int) DB::table('telegram_interaction_sessions')->where('public_id', $session->publicId)->value('id'))
                    ->count());
            } finally {
                $this->closeWorker($first);
                $this->closeWorker($second);
            }
        }

        private function account(): int
        {
            $now = now('UTC')->format('Y-m-d H:i:s.u');
            $userId = (int) DB::table('users')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'account_type' => 'customer',
                'account_status' => 'active',
                'locale' => 'fa',
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return (int) DB::table('telegram_accounts')->insertGetId([
                'user_id' => $userId,
                'bot_id' => 123456,
                'telegram_user_id' => 920001,
                'username' => 'interaction_contention',
                'language_code' => 'fa',
                'is_bot' => false,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        /** @return array{process:resource,pipes:array{0:resource,1:resource,2:resource}} */
        private function startWorker(string $sessionPublicId, string $nextState, string $requestKey): array
        {
            $pipes = [];
            $process = proc_open([
                PHP_BINARY,
                '-d',
                'pcov.enabled=0',
                __FILE__,
                '--telegram-interaction-worker',
                base64_encode(json_encode([
                    'session_public_id' => $sessionPublicId,
                    'next_state' => $nextState,
                    'request_key' => $requestKey,
                ], JSON_THROW_ON_ERROR)),
            ], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start Telegram interaction contention worker.');
            }
            /** @var array{0:resource,1:resource,2:resource} $pipes */
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            return ['process' => $process, 'pipes' => $pipes];
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker */
        private function sendCommand(array $worker, string $command): void
        {
            fwrite($worker['pipes'][0], $command."\n");
            fflush($worker['pipes'][0]);
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker */
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
                    throw new RuntimeException('Unable to wait for Telegram interaction contention worker output.');
                }
                foreach ($read as $stream) {
                    if ($stream === $worker['pipes'][2]) {
                        $stderr .= stream_get_contents($stream);

                        continue;
                    }
                    $line = fgets($stream);
                    if ($line !== false) {
                        return $line;
                    }
                }
                $status = proc_get_status($worker['process']);
                if (! $status['running'] && feof($worker['pipes'][1])) {
                    $stderr .= stream_get_contents($worker['pipes'][2]);
                    throw new RuntimeException('Telegram interaction worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException('Telegram interaction worker timed out during '.$phase.': '.$stderr);
        }

        /**
         * @param  array{process:resource,pipes:array{0:resource,1:resource,2:resource}}  $worker
         * @return array<string,mixed>
         */
        private function readJsonResult(array $worker, string $phase): array
        {
            /** @var array<string,mixed> $result */
            $result = json_decode($this->readLine($worker, $phase), true, flags: JSON_THROW_ON_ERROR);

            return $result;
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker */
        private function closeWorker(array $worker): void
        {
            foreach ($worker['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($worker['process'])) {
                $status = proc_get_status($worker['process']);
                if ($status['running']) {
                    proc_terminate($worker['process']);
                }
                proc_close($worker['process']);
            }
        }
    }
}
