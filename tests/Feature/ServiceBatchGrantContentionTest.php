<?php

declare(strict_types=1);

namespace {
    use App\Modules\Provisioning\Application\ServiceBatchGrantService;
    use App\Modules\Provisioning\Application\ServiceOperationalContext;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\Connection;
    use Illuminate\Database\DatabaseManager;

    $serviceBatchContentionMode = PHP_SAPI === 'cli' ? ($argv[1] ?? null) : null;
    if ($serviceBatchContentionMode === '--service-batch-worker') {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $connection = $app->make(DatabaseManager::class)->connection();
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 5');

        $decoded = base64_decode((string) ($argv[2] ?? ''), true);
        if ($decoded === false) {
            fwrite(STDERR, "Invalid Service-batch contention worker payload.\n");
            exit(2);
        }
        /** @var array{batch_public_id:string,request_key:string,correlation_id:string,reason_code:string,reason:string,actor_administrator_id:int} $payload */
        $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        $barrierReached = false;
        $connection->beforeExecuting(function (string $query, array $bindings, Connection $db) use (&$barrierReached): void {
            unset($bindings, $db);
            if ($barrierReached) {
                return;
            }
            $sql = strtolower($query);
            if (! str_contains($sql, 'service_batch_grants') || ! str_contains($sql, 'for update')) {
                return;
            }

            $barrierReached = true;
            echo "BEFORE_BATCH_PARENT_LOCK\n";
            flush();
            $continue = fgets(STDIN);
            if ($continue === false || trim($continue) !== 'CONTINUE') {
                throw new RuntimeException('Service-batch parent-lock barrier was not released.');
            }
        });

        echo "READY\n";
        flush();
        $go = fgets(STDIN);
        if ($go === false || trim($go) !== 'GO') {
            fwrite(STDERR, "Service-batch contention worker start barrier was not released.\n");
            exit(2);
        }

        try {
            $receipt = $app->make(ServiceBatchGrantService::class)->resume(
                $payload['batch_public_id'],
                new ServiceOperationalContext(
                    $payload['request_key'],
                    $payload['correlation_id'],
                    $payload['reason_code'],
                    $payload['reason'],
                    $payload['actor_administrator_id'],
                ),
            );
            echo json_encode([
                'ok' => true,
                'batch_id' => $receipt->batchId,
                'state' => $receipt->state,
                'succeeded_count' => $receipt->succeededCount,
                'failed_count' => $receipt->failedCount,
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
    use App\Modules\Provisioning\Application\ServiceBatchGrantService;
    use App\Modules\Provisioning\Application\ServiceOperationalContext;
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\Support\CreatesBenefitCodeFixtures;
    use Tests\TestCase;

    /** @requirement SVC-011 SVC-012 DAT-002 DAT-003 DAT-004 QUA-004 */
    final class ServiceBatchGrantContentionTest extends TestCase
    {
        use CreatesBenefitCodeFixtures;
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 20;

        protected function setUp(): void
        {
            parent::setUp();
            if (DB::connection()->getDriverName() !== 'mysql') {
                $this->markTestSkipped('Service batch contention requires MariaDB/MySQL.');
            }
            $this->seed();
            /** @var Migration $migration */
            $migration = require database_path('migrations/2026_08_19_000140_enable_service_operational_authority.php');
            $migration->up();
        }

        protected function tearDown(): void
        {
            try {
                $this->truncateDatabaseTables();
            } finally {
                parent::tearDown();
            }
        }

        public function test_concurrent_resume_claims_one_item_once_and_converges_without_duplicate_authority(): void
        {
            $offering = $this->activeBenefitOffering('service-batch-contention');
            $ownerId = $this->benefitOwner();
            $userId = $this->benefitUser();
            $context = $this->context($ownerId);
            $batch = $this->app->make(ServiceBatchGrantService::class)->create($context, [[
                'user_id' => $userId,
                'plan_offering_id' => $offering['id'],
            ]]);

            $first = $this->startWorker($batch->batchPublicId, $context);
            $second = $this->startWorker($batch->batchPublicId, $context);
            try {
                self::assertSame("READY\n", $this->readLine($first, 'first readiness'));
                self::assertSame("READY\n", $this->readLine($second, 'second readiness'));
                $this->sendCommand($first, 'GO');
                $this->sendCommand($second, 'GO');
                self::assertSame("BEFORE_BATCH_PARENT_LOCK\n", $this->readLine($first, 'first parent-lock barrier'));
                self::assertSame("BEFORE_BATCH_PARENT_LOCK\n", $this->readLine($second, 'second parent-lock barrier'));
                $this->sendCommand($first, 'CONTINUE');
                $this->sendCommand($second, 'CONTINUE');

                $firstResult = $this->readJsonResult($first, 'first result');
                $secondResult = $this->readJsonResult($second, 'second result');
                self::assertTrue((bool) ($firstResult['ok'] ?? false), json_encode($firstResult, JSON_THROW_ON_ERROR));
                self::assertTrue((bool) ($secondResult['ok'] ?? false), json_encode($secondResult, JSON_THROW_ON_ERROR));
                self::assertSame($firstResult['batch_id'], $secondResult['batch_id']);
                $replayed = [(bool) $firstResult['replayed'], (bool) $secondResult['replayed']];
                sort($replayed);
                self::assertSame([false, true], $replayed);

                $row = DB::table('service_batch_grants')->where('id', $batch->batchId)->first();
                self::assertNotNull($row);
                self::assertSame('completed', $row->state);
                self::assertSame(1, (int) $row->succeeded_count);
                self::assertSame(0, (int) $row->failed_count);
                self::assertSame(1, (int) DB::table('service_batch_grant_items')->where('service_batch_grant_id', $batch->batchId)->value('attempt_count'));
                self::assertSame(1, DB::table('order_source_authorizations')->where('source_type', 'admin_grant')->count());
                self::assertSame(1, DB::table('orders')->where('source_type', 'admin_grant')->count());
                self::assertSame(1, DB::table('service_subscriptions')->count());
                self::assertSame(1, DB::table('provisioning_operations')->where('operation_type', 'initial_provision')->count());
                self::assertSame(0, DB::table('payment_intents')->count());
                self::assertSame(0, DB::table('purchase_settlements')->count());
            } finally {
                $this->closeWorker($first);
                $this->closeWorker($second);
            }
        }

        private function context(int $ownerId): ServiceOperationalContext
        {
            return new ServiceOperationalContext(
                'service-batch-contention',
                'svc-batch-'.substr(hash('sha256', 'service-batch-contention'), 0, 32),
                'service_batch_contention',
                'Service batch contention test reason.',
                $ownerId,
            );
        }

        /** @return array{process:resource,pipes:array{0:resource,1:resource,2:resource}} */
        private function startWorker(string $batchPublicId, ServiceOperationalContext $context): array
        {
            $pipes = [];
            $process = proc_open([
                PHP_BINARY,
                '-d',
                'pcov.enabled=0',
                __FILE__,
                '--service-batch-worker',
                base64_encode(json_encode([
                    'batch_public_id' => $batchPublicId,
                    'request_key' => $context->requestKey,
                    'correlation_id' => $context->correlationId,
                    'reason_code' => $context->reasonCode,
                    'reason' => $context->reason,
                    'actor_administrator_id' => $context->actorAdministratorId,
                ], JSON_THROW_ON_ERROR)),
            ], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start Service-batch contention worker.');
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
                    throw new RuntimeException('Unable to wait for Service-batch contention worker output.');
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
                    throw new RuntimeException('Service-batch contention worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException('Service-batch contention worker timed out during '.$phase.': '.$stderr);
        }

        /** @return array<string,mixed> @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker */
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
