<?php

declare(strict_types=1);

namespace {
    use App\Modules\Promotions\Application\ReferralAttributionService;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\DatabaseManager;

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--referral-attribution-contention-worker') {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app->make(DatabaseManager::class)->connection()->statement('SET SESSION innodb_lock_wait_timeout = 5');

        $decoded = base64_decode($argv[2] ?? '', true);
        if ($decoded === false) {
            fwrite(STDERR, "Invalid worker payload encoding.\n");
            exit(2);
        }

        try {
            /** @var array<string, mixed> $payload */
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
            $receipt = $app->make(ReferralAttributionService::class)->bind(
                (int) $payload['referred_user_id'],
                (string) $payload['inviter_token'],
            );
            echo json_encode([
                'ok' => true,
                'result' => [
                    'relationship_id' => $receipt->relationshipId,
                    'inviter_user_id' => $receipt->inviterUserId,
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
    use DomainException;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Str;
    use RuntimeException;
    use Tests\TestCase;

    /** @requirement REF-001 ONB-002 DAT-003 DAT-004 QUA-001 QUA-004 */
    final class ReferralAttributionContentionVerificationTest extends TestCase
    {
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 15;

        public function test_competing_first_inviter_bindings_serialize_to_one_accepted_relationship(): void
        {
            $firstInviter = $this->user();
            $secondInviter = $this->user();
            $referred = $this->user();

            $results = $this->runConcurrent([
                ['referred_user_id' => $referred, 'inviter_token' => $this->token($firstInviter)],
                ['referred_user_id' => $referred, 'inviter_token' => $this->token($secondInviter)],
            ]);

            self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['ok'] === true));
            self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['ok'] === false));
            self::assertSame(1, DB::table('referral_relationships')->where('referred_user_id', $referred)->count());
            self::assertSame(1, DB::table('referral_attribution_events')->where('event_type', 'bound')->count());

            $failure = array_values(array_filter($results, static fn (array $result): bool => $result['ok'] === false))[0];
            self::assertSame(DomainException::class, $failure['exception']);
            self::assertSame('Referral inviter is already bound.', $failure['message']);
        }

        public function test_concurrent_exact_duplicate_binding_returns_one_create_and_one_replay(): void
        {
            $inviter = $this->user();
            $referred = $this->user();
            $payload = ['referred_user_id' => $referred, 'inviter_token' => $this->token($inviter)];

            $results = $this->runConcurrent([$payload, $payload]);

            self::assertTrue($results[0]['ok']);
            self::assertTrue($results[1]['ok']);
            self::assertSame($results[0]['result']['relationship_id'], $results[1]['result']['relationship_id']);
            $replayed = [(bool) $results[0]['result']['replayed'], (bool) $results[1]['result']['replayed']];
            sort($replayed);
            self::assertSame([false, true], $replayed);
            self::assertSame(1, DB::table('referral_relationships')->where('referred_user_id', $referred)->count());
            self::assertSame(1, DB::table('referral_attribution_events')->where('event_type', 'bound')->count());
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

        private function token(int $userId): string
        {
            $token = DB::table('referral_identities')->where('user_id', $userId)->value('token');
            if (! is_string($token)) {
                throw new RuntimeException('Referral identity fixture was not created.');
            }

            return $token;
        }

        /**
         * @param list<array{referred_user_id:int,inviter_token:string}> $payloads
         * @return list<array<string, mixed>>
         */
        private function runConcurrent(array $payloads): array
        {
            /** @var list<array{process:resource,pipes:array{0:resource,1:resource,2:resource}}> $workers */
            $workers = [];
            try {
                foreach ($payloads as $payload) {
                    $pipes = [];
                    $process = proc_open([
                        PHP_BINARY,
                        '-d',
                        'pcov.enabled=0',
                        __FILE__,
                        '--referral-attribution-contention-worker',
                        base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                    ], [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ], $pipes, dirname(__DIR__, 2));
                    if (! is_resource($process)) {
                        throw new RuntimeException('Unable to start referral attribution contention worker.');
                    }
                    /** @var array{0:resource,1:resource,2:resource} $pipes */
                    stream_set_blocking($pipes[1], false);
                    stream_set_blocking($pipes[2], false);
                    $workers[] = ['process' => $process, 'pipes' => $pipes];
                }

                foreach ($workers as $index => $worker) {
                    if ($this->readLine($worker, 'readiness', $index) !== "READY\n") {
                        throw new RuntimeException('Referral attribution contention worker returned an invalid readiness marker.');
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
                        throw new RuntimeException('Referral attribution contention worker failed: '.$stderr);
                    }
                    /** @var array<string, mixed> $result */
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
                    throw new RuntimeException('Unable to wait for referral attribution contention worker output.');
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
                    throw new RuntimeException('Referral attribution contention worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException(sprintf(
                'Referral attribution contention worker %d timed out during %s after %d seconds: %s',
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
}
