<?php

declare(strict_types=1);

namespace {
    use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\DatabaseManager;

    $mode = $argv[1] ?? null;
    if (PHP_SAPI === 'cli' && in_array($mode, ['--payment-eligibility-writer', '--payment-eligibility-evaluator'], true)) {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        try {
            $decoded = base64_decode((string) ($argv[2] ?? ''), true);
            if ($decoded === false) {
                throw new RuntimeException('Payment eligibility race worker payload is invalid.');
            }
            /** @var array<string,mixed> $payload */
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
            /** @var DatabaseManager $database */
            $database = $app->make(DatabaseManager::class);
            $connection = $database->connection();
            $connection->statement('SET SESSION innodb_lock_wait_timeout = 10');
            $service = $app->make(PaymentMethodEligibilityService::class);
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Payment eligibility race worker bootstrap failed: '.$exception->getMessage()."\n");
            exit(2);
        }

        try {
            if ($mode === '--payment-eligibility-writer') {
                $connection->beginTransaction();
                $method = $connection->table('payment_method_versions')
                    ->where('method_code', (string) $payload['method_code'])
                    ->lockForUpdate()
                    ->first(['id']);
                if ($method === null) {
                    throw new RuntimeException('Payment eligibility race method is missing.');
                }

                echo "LOCKED\n";
                flush();
                if (trim((string) fgets(STDIN)) !== 'STAGE') {
                    throw new RuntimeException('Payment eligibility race stage command is invalid.');
                }

                $service->configureMethod(
                    (string) $payload['mutation_key'],
                    (int) $payload['administrator_id'],
                    (string) $payload['method_code'],
                    true,
                    true,
                    1,
                    'Race-test maintenance transition.',
                    (string) $payload['correlation_id'],
                );
                echo "STAGED\n";
                flush();
                if (trim((string) fgets(STDIN)) !== 'COMMIT') {
                    throw new RuntimeException('Payment eligibility race commit command is invalid.');
                }

                $connection->commit();
                echo json_encode(['ok' => true], JSON_THROW_ON_ERROR)."\n";
                exit(0);
            }

            echo "READY\n";
            flush();
            if (trim((string) fgets(STDIN)) !== 'GO') {
                throw new RuntimeException('Payment eligibility race evaluator barrier was not released.');
            }

            $decision = $service->evaluate(
                (string) $payload['decision_key'],
                (int) $payload['user_id'],
                (string) $payload['quote_public_id'],
            );
            echo json_encode([
                'decision_id' => $decision->decisionId,
                'methods' => $decision->methods,
                'ok' => true,
                'replayed' => $decision->replayed,
            ], JSON_THROW_ON_ERROR)."\n";
        } catch (Throwable $exception) {
            while (isset($connection) && $connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            echo json_encode([
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'ok' => false,
            ], JSON_THROW_ON_ERROR)."\n";
        }

        exit(0);
    }
}

namespace Tests\Feature {
    use App\Modules\Orders\Application\QuotePricingInput;
    use App\Modules\Orders\Application\QuoteService;
    use App\Modules\Orders\Domain\QuoteOverrideSource;
    use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
    use App\Shared\Application\Clock;
    use Database\Seeders\CatalogAccessFoundationSeeder;
    use Database\Seeders\IdentityAccessFoundationSeeder;
    use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
    use DateTimeImmutable;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\TestCase;

    final class PaymentEligibilityRaceClock implements Clock
    {
        public function __construct(private readonly DateTimeImmutable $time) {}

        public function now(): DateTimeImmutable
        {
            return $this->time;
        }
    }

    /** @requirement PAY-001 DAT-003 DAT-004 QUA-001 QUA-004 */
    final class PaymentMethodEligibilityConcurrencyTest extends TestCase
    {
        use AgentPricingQuoteIntegrationTestSupport;
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 15;

        private PaymentEligibilityRaceClock $clock;

        protected function setUp(): void
        {
            parent::setUp();
            $this->seed(IdentityAccessFoundationSeeder::class);
            $this->seed(CatalogAccessFoundationSeeder::class);
            $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
            $this->clock = new PaymentEligibilityRaceClock(new DateTimeImmutable('now'));
            $this->app->instance(Clock::class, $this->clock);
        }

        protected function tearDown(): void
        {
            try {
                $this->truncateDatabaseTables();
            } finally {
                parent::tearDown();
            }
        }

        public function test_evaluator_cannot_observe_mixed_method_configuration_while_writer_holds_lock(): void
        {
            $administratorId = $this->ownerAdministrator();
            $userId = $this->quoteUser('customer');
            $quote = $this->quoteFor($userId);
            $service = $this->app->make(PaymentMethodEligibilityService::class);
            $this->configureHealthyMethod($service, $administratorId, 'race_gateway');

            $writer = $this->startWorker('--payment-eligibility-writer', [
                'administrator_id' => $administratorId,
                'correlation_id' => $this->correlation('writer'),
                'method_code' => 'race_gateway',
                'mutation_key' => 'eligibility.race.method.000002',
            ]);
            $evaluator = null;

            try {
                self::assertSame("LOCKED\n", $this->readLine($writer, 'writer configuration lock'));
                $this->sendCommand($writer, 'STAGE');
                self::assertSame("STAGED\n", $this->readLine($writer, 'writer staged configuration'));

                $evaluator = $this->startWorker('--payment-eligibility-evaluator', [
                    'decision_key' => 'eligibility.race.evaluate.'.str_repeat('0', 5).'1',
                    'quote_public_id' => $quote->quotePublicId,
                    'user_id' => $userId,
                ]);
                self::assertSame("READY\n", $this->readLine($evaluator, 'evaluator readiness'));
                $this->sendCommand($evaluator, 'GO');
                $this->assertWorkerRemainsBlocked($evaluator, 'Evaluator observed uncommitted or mixed payment policy.');

                $this->sendCommand($writer, 'COMMIT');
                $writerResult = $this->readJsonResult($writer, 'writer commit');
                self::assertTrue((bool) ($writerResult['ok'] ?? false), $this->diagnostic($writerResult));

                $result = $this->readJsonResult($evaluator, 'evaluator result');
                self::assertTrue((bool) ($result['ok'] ?? false), $this->diagnostic($result));
                self::assertSame([], $result['methods']);
                self::assertSame(2, DB::table('payment_method_versions')->where('method_code', 'race_gateway')->max('version'));
                self::assertSame('method_unavailable', DB::table('payment_method_eligibility_decision_methods')
                    ->where('payment_method_eligibility_decision_id', $result['decision_id'])
                    ->where('method_code', 'race_gateway')
                    ->value('reason_code'));
            } finally {
                $this->closeWorker($writer);
                if ($evaluator !== null) {
                    $this->closeWorker($evaluator);
                }
            }
        }

        public function test_same_key_evaluators_commit_one_decision_and_replay_the_same_receipt(): void
        {
            $administratorId = $this->ownerAdministrator();
            $userId = $this->quoteUser('customer');
            $quote = $this->quoteFor($userId);
            $service = $this->app->make(PaymentMethodEligibilityService::class);
            $this->configureHealthyMethod($service, $administratorId, 'same_key_gateway');

            $payload = [
                'decision_key' => 'eligibility.race.same-key.'.str_repeat('0', 5).'1',
                'quote_public_id' => $quote->quotePublicId,
                'user_id' => $userId,
            ];
            $first = $this->startWorker('--payment-eligibility-evaluator', $payload);
            $second = $this->startWorker('--payment-eligibility-evaluator', $payload);
            try {
                self::assertSame("READY\n", $this->readLine($first, 'first evaluator readiness'));
                self::assertSame("READY\n", $this->readLine($second, 'second evaluator readiness'));
                $this->sendCommand($first, 'GO');
                $this->sendCommand($second, 'GO');
                $firstResult = $this->readJsonResult($first, 'first same-key result');
                $secondResult = $this->readJsonResult($second, 'second same-key result');

                self::assertTrue((bool) ($firstResult['ok'] ?? false), $this->diagnostic($firstResult));
                self::assertTrue((bool) ($secondResult['ok'] ?? false), $this->diagnostic($secondResult));
                self::assertSame($firstResult['decision_id'], $secondResult['decision_id']);
                self::assertSame(1, DB::table('payment_method_eligibility_decisions')->where('decision_key', $payload['decision_key'])->count());
            } finally {
                $this->closeWorker($first);
                $this->closeWorker($second);
            }
        }

        private function configureHealthyMethod(PaymentMethodEligibilityService $service, int $administratorId, string $methodCode): void
        {
            $service->configureMethod(
                'eligibility.race.method.'.$methodCode.'.000001',
                $administratorId,
                $methodCode,
                true,
                false,
                1,
                'Race-test method.',
                $this->correlation('method-'.$methodCode),
            );
            $service->recordHealth(
                'eligibility.race.health.'.$methodCode.'.000001',
                $administratorId,
                $methodCode,
                true,
                $this->clock->now()->modify('+10 minutes'),
                'Race-test health.',
                $this->correlation('health-'.$methodCode),
            );
        }

        private function quoteFor(int $userId): object
        {
            $offering = $this->quoteOffering();

            return $this->app->make(QuoteService::class)->create(
                'eligibility.race.quote.'.substr(hash('sha256', (string) $userId), 0, 20),
                $userId,
                $offering['id'],
                new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->now()->modify('+30 minutes')),
                $this->correlation('quote-'.$userId),
            );
        }

        private function correlation(string $suffix): string
        {
            return hash('sha256', 'payment-eligibility-race:'.$suffix);
        }

        /** @param array<string,mixed> $payload
         * @return array{process:resource,pipes:array{0:resource,1:resource,2:resource}}
         */
        private function startWorker(string $mode, array $payload): array
        {
            $pipes = [];
            $process = proc_open([PHP_BINARY, '-d', 'pcov.enabled=0', __FILE__, $mode, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, base_path());
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start payment eligibility race worker.');
            }
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
        private function assertWorkerRemainsBlocked(array $worker, string $message): void
        {
            usleep(500_000);
            self::assertSame('', trim((string) stream_get_contents($worker['pipes'][1])), $message);
            self::assertSame('', trim((string) stream_get_contents($worker['pipes'][2])), $message);
            self::assertTrue((bool) proc_get_status($worker['process'])['running'], $message);
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker
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
                    throw new RuntimeException('Unable to wait for payment eligibility race worker output.');
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
                if (! proc_get_status($worker['process'])['running'] && feof($worker['pipes'][1])) {
                    throw new RuntimeException('Payment eligibility race worker exited before '.$phase.': '.trim($stderr));
                }
            }
            throw new RuntimeException('Payment eligibility race worker timed out during '.$phase.': '.trim($stderr));
        }

        /** @param array<string,mixed> $result */
        private function diagnostic(array $result): string
        {
            return json_encode($result, JSON_THROW_ON_ERROR);
        }
    }
}
