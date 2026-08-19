<?php

declare(strict_types=1);

namespace {
    use App\Modules\Orders\Application\OrderSourceAuthorizationService;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\Connection;
    use Illuminate\Database\DatabaseManager;

    $orderSourceContentionMode = PHP_SAPI === 'cli' ? ($argv[1] ?? null) : null;
    if ($orderSourceContentionMode === '--order-source-benefit-worker') {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $connection = $app->make(DatabaseManager::class)->connection();
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 5');

        $decoded = base64_decode((string) ($argv[2] ?? ''), true);
        if ($decoded === false) {
            fwrite(STDERR, "Invalid Order-source contention worker payload.\n");
            exit(2);
        }

        /** @var array<string,mixed> $payload */
        $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        $barrierReached = false;
        $connection->beforeExecuting(function (string $query, array $bindings, Connection $db) use (&$barrierReached): void {
            unset($bindings, $db);
            if ($barrierReached) {
                return;
            }
            $sql = strtolower($query);
            if (! str_contains($sql, 'benefit_code_free_service_entitlements') || ! str_contains($sql, 'for update')) {
                return;
            }

            $barrierReached = true;
            echo "BEFORE_ENTITLEMENT_LOCK\n";
            flush();
            $continue = fgets(STDIN);
            if ($continue === false || trim($continue) !== 'CONTINUE') {
                throw new RuntimeException('Order-source entitlement lock barrier was not released.');
            }
        });

        echo "READY\n";
        flush();
        $go = fgets(STDIN);
        if ($go === false || trim($go) !== 'GO') {
            fwrite(STDERR, "Order-source contention worker start barrier was not released.\n");
            exit(2);
        }

        try {
            $receipt = $app->make(OrderSourceAuthorizationService::class)->authorizeBenefitCode(
                (string) $payload['entitlement_public_id'],
                (string) $payload['correlation_id'],
            );
            echo json_encode([
                'ok' => true,
                'authorization_id' => $receipt->authorizationId,
                'public_id' => $receipt->publicId,
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
    use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeRedemptionContext;
    use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeRedemptionRequest;
    use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeService;
    use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeType;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\Support\CreatesBenefitCodeFixtures;
    use Tests\TestCase;

    /** @requirement BUY-001 BUY-002 PRO-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    final class OrderSourceAuthorizationContentionTest extends TestCase
    {
        use CreatesBenefitCodeFixtures;
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 15;

        protected function setUp(): void
        {
            parent::setUp();
            if (DB::connection()->getDriverName() !== 'mysql') {
                $this->markTestSkipped('Order-source contention requires MariaDB/MySQL.');
            }
            $this->seed();
        }

        protected function tearDown(): void
        {
            try {
                $this->truncateDatabaseTables();
            } finally {
                parent::tearDown();
            }
        }

        public function test_concurrent_benefit_authorization_converges_to_one_durable_authority(): void
        {
            $offering = $this->benefitOffering('source-contention');
            $userId = $this->benefitUser();
            $entitlementPublicId = $this->freeServiceEntitlement($userId, $offering, 'source-contention');
            $paymentIntentCount = DB::table('payment_intents')->count();
            $settlementCount = DB::table('purchase_settlements')->count();

            $first = $this->startWorker($entitlementPublicId, 'source-contention-correlation-a');
            $second = $this->startWorker($entitlementPublicId, 'source-contention-correlation-b');

            try {
                self::assertSame("READY\n", $this->readLine($first, 'first readiness'));
                self::assertSame("READY\n", $this->readLine($second, 'second readiness'));
                $this->sendCommand($first, 'GO');
                $this->sendCommand($second, 'GO');
                self::assertSame("BEFORE_ENTITLEMENT_LOCK\n", $this->readLine($first, 'first entitlement barrier'));
                self::assertSame("BEFORE_ENTITLEMENT_LOCK\n", $this->readLine($second, 'second entitlement barrier'));
                $this->sendCommand($first, 'CONTINUE');
                $this->sendCommand($second, 'CONTINUE');

                $firstResult = $this->readJsonResult($first, 'first result');
                $secondResult = $this->readJsonResult($second, 'second result');
                self::assertTrue((bool) ($firstResult['ok'] ?? false), json_encode($firstResult, JSON_THROW_ON_ERROR));
                self::assertTrue((bool) ($secondResult['ok'] ?? false), json_encode($secondResult, JSON_THROW_ON_ERROR));
                self::assertSame($firstResult['authorization_id'], $secondResult['authorization_id']);
                self::assertSame($firstResult['public_id'], $secondResult['public_id']);

                $replayed = [(bool) $firstResult['replayed'], (bool) $secondResult['replayed']];
                sort($replayed);
                self::assertSame([false, true], $replayed);
                self::assertSame(1, DB::table('order_source_authorizations')
                    ->where('benefit_entitlement_public_id', $entitlementPublicId)
                    ->count());
                self::assertSame($paymentIntentCount, DB::table('payment_intents')->count());
                self::assertSame($settlementCount, DB::table('purchase_settlements')->count());
            } finally {
                $this->closeWorker($first);
                $this->closeWorker($second);
            }
        }

        /** @param array{id:int,product_id:int,server_id:int} $offering */
        private function freeServiceEntitlement(int $userId, array $offering, string $suffix): string
        {
            $campaignCode = 'benefit.free.'.substr(hash('sha256', $suffix), 0, 12);
            $this->benefitCampaign(
                $campaignCode,
                BenefitCodeType::FreeService,
                $this->freeServiceDefinition($offering['id'], $offering['product_id'], $offering['server_id']),
                $suffix,
            );
            $issued = $this->benefitIssue($campaignCode, $suffix);
            $receipt = $this->app->make(BenefitCodeService::class)->redeem(
                new BenefitCodeRedemptionRequest(
                    'free-contention-'.substr(hash('sha256', $suffix), 0, 32),
                    (string) $issued->items[0]->fullCode,
                    $userId,
                    $offering['id'],
                    null,
                    'free-source-'.substr(hash('sha256', $suffix), 0, 32),
                ),
                new BenefitCodeRedemptionContext($userId),
            );
            self::assertNotNull($receipt->entitlementPublicId);

            return $receipt->entitlementPublicId;
        }

        /** @return array{process:resource,pipes:array{0:resource,1:resource,2:resource}} */
        private function startWorker(string $entitlementPublicId, string $correlationId): array
        {
            $pipes = [];
            $process = proc_open([
                PHP_BINARY,
                '-d',
                'pcov.enabled=0',
                __FILE__,
                '--order-source-benefit-worker',
                base64_encode(json_encode([
                    'entitlement_public_id' => $entitlementPublicId,
                    'correlation_id' => $correlationId,
                ], JSON_THROW_ON_ERROR)),
            ], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start Order-source contention worker.');
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
                    throw new RuntimeException('Unable to wait for Order-source contention worker output.');
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
                    throw new RuntimeException('Order-source contention worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException('Order-source contention worker timed out during '.$phase.': '.$stderr);
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker */
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
