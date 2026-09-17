<?php

declare(strict_types=1);

namespace {
    use App\Modules\Support\Application\SupportTicketRatingService;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\Connection;
    use Illuminate\Database\DatabaseManager;

    $supportRatingContentionMode = PHP_SAPI === 'cli' ? ($argv[1] ?? null) : null;
    if ($supportRatingContentionMode === '--support-rating-worker') {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $connection = $app->make(DatabaseManager::class)->connection();
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 10');

        $decoded = base64_decode((string) ($argv[2] ?? ''), true);
        if ($decoded === false) {
            fwrite(STDERR, "Invalid Support rating contention payload encoding.\n");
            exit(2);
        }
        try {
            /** @var array<string,mixed> $payload */
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            fwrite(STDERR, 'Invalid Support rating contention payload: '.$exception->getMessage()."\n");
            exit(2);
        }

        $barrierReached = false;
        $connection->beforeExecuting(function (string $query, array $bindings, Connection $db) use (&$barrierReached): void {
            unset($bindings, $db);
            if ($barrierReached) {
                return;
            }
            $sql = strtolower($query);
            if (! str_contains($sql, 'support_tickets') || ! str_contains($sql, 'for update')) {
                return;
            }
            $barrierReached = true;
            echo "AT_TICKET_LOCK\n";
            flush();
            $continue = fgets(STDIN);
            if ($continue === false || trim($continue) !== 'CONTINUE') {
                throw new RuntimeException('Support rating contention lock barrier was not released.');
            }
        });

        echo "READY\n";
        flush();
        $go = fgets(STDIN);
        if ($go === false || trim($go) !== 'GO') {
            fwrite(STDERR, "Support rating contention start barrier was not released.\n");
            exit(2);
        }

        try {
            $receipt = $app->make(SupportTicketRatingService::class)->rate(
                (int) $payload['ticket_id'],
                (int) $payload['requester_user_id'],
                (int) $payload['score'],
            );
            echo json_encode([
                'ok' => true,
                'rating_id' => $receipt->rating->id,
                'score' => $receipt->rating->score,
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
    use App\Modules\Support\Application\SupportTicketCreateRequest;
    use App\Modules\Support\Application\SupportTicketService;
    use Database\Seeders\SupportTicketCategorySeeder;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Str;
    use RuntimeException;
    use Tests\TestCase;

    /** @requirement SUP-002 SEC-002 DAT-003 QUA-004 QUA-008 */
    final class SupportTicketRatingContentionTest extends TestCase
    {
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 20;

        protected function setUp(): void
        {
            parent::setUp();
            if (DB::connection()->getDriverName() !== 'mysql') {
                $this->markTestSkipped('Support ticket rating contention requires MariaDB/MySQL.');
            }
            $this->seed(SupportTicketCategorySeeder::class);
        }

        public function test_concurrent_same_score_attempts_converge_on_one_rating(): void
        {
            $customer = $this->user();
            $tickets = $this->app->make(SupportTicketService::class);
            $ticket = $tickets->create(new SupportTicketCreateRequest(
                $customer,
                'other',
                'Concurrent rating',
                'Concurrent duplicate ratings must converge on one durable row.',
                'support-rating-contention:create',
            ));
            $tickets->closeForCustomer($ticket->id, $customer, 'Closed for concurrent rating');

            $payload = [
                'ticket_id' => $ticket->id,
                'requester_user_id' => $customer,
                'score' => 5,
            ];
            $first = $this->startWorker($payload);
            $second = $this->startWorker($payload);

            try {
                self::assertSame("READY\n", $this->readLine($first, 'first readiness'));
                self::assertSame("READY\n", $this->readLine($second, 'second readiness'));
                $this->sendCommand($first, 'GO');
                $this->sendCommand($second, 'GO');
                self::assertSame("AT_TICKET_LOCK\n", $this->readLine($first, 'first ticket lock'));
                self::assertSame("AT_TICKET_LOCK\n", $this->readLine($second, 'second ticket lock'));
                $this->sendCommand($first, 'CONTINUE');
                $this->sendCommand($second, 'CONTINUE');

                $firstResult = $this->readJsonResult($first, 'first result');
                $secondResult = $this->readJsonResult($second, 'second result');
                self::assertTrue((bool) ($firstResult['ok'] ?? false), json_encode($firstResult, JSON_THROW_ON_ERROR));
                self::assertTrue((bool) ($secondResult['ok'] ?? false), json_encode($secondResult, JSON_THROW_ON_ERROR));
                self::assertSame(5, $firstResult['score']);
                self::assertSame(5, $secondResult['score']);
                self::assertSame($firstResult['rating_id'], $secondResult['rating_id']);
                self::assertSame([false, true], collect([$firstResult['replayed'], $secondResult['replayed']])->sort()->values()->all());
                self::assertSame(1, DB::table('support_ticket_ratings')->where('ticket_id', $ticket->id)->count());
            } finally {
                $this->closeWorker($first);
                $this->closeWorker($second);
            }
        }

        private function user(): int
        {
            $now = now('UTC');

            return (int) DB::table('users')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'account_type' => 'customer',
                'account_status' => 'active',
                'locale' => 'fa',
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        /**
         * @param  array<string,int>  $payload
         * @return array{process:resource,pipes:array{0:resource,1:resource,2:resource}}
         */
        private function startWorker(array $payload): array
        {
            $pipes = [];
            $process = proc_open([
                PHP_BINARY,
                '-d',
                'pcov.enabled=0',
                __FILE__,
                '--support-rating-worker',
                base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            ], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start Support rating contention worker.');
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
                    throw new RuntimeException('Unable to wait for Support rating contention worker output.');
                }
                foreach ($read as $stream) {
                    if ($stream === $worker['pipes'][2]) {
                        $stderr .= stream_get_contents($stream);

                        continue;
                    }
                    $line = fgets($stream);
                    if ($line !== false) {
                        if (trim($line) === '') {
                            continue;
                        }

                        return $line;
                    }
                }
                $status = proc_get_status($worker['process']);
                if (! $status['running'] && feof($worker['pipes'][1])) {
                    $stderr .= stream_get_contents($worker['pipes'][2]);
                    throw new RuntimeException('Support rating contention worker exited before '.$phase.' output: '.$stderr);
                }
            }
            throw new RuntimeException('Support rating contention worker timed out during '.$phase.': '.$stderr);
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
