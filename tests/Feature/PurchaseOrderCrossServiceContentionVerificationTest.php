<?php

declare(strict_types=1);

namespace {
    use App\Modules\Orders\Application\PurchaseOrderService;
    use App\Modules\Payments\Application\Contracts\PaymentEvidence;
    use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
    use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
    use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
    use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
    use App\Modules\Payments\Application\PurchaseSettlementService;
    use App\Shared\Domain\Money;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\Events\QueryExecuted;
    use Illuminate\Support\Facades\DB;

    require_once dirname(__DIR__, 2).'/vendor/autoload.php';

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--purchase-order-cross-service-worker') {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $decoded = base64_decode($argv[2] ?? '', true);
        if ($decoded === false) {
            fwrite(STDERR, "Invalid worker payload encoding.\n");
            exit(2);
        }
        try {
            /** @var array<string, string> $payload */
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            fwrite(STDERR, 'Invalid worker payload: '.$exception->getMessage()."\n");
            exit(2);
        }

        $operation = $payload['operation'] ?? '';
        if (! in_array($operation, ['settlement_replay', 'order_create'], true)) {
            fwrite(STDERR, "Invalid worker operation.\n");
            exit(2);
        }

        $targetTable = $operation === 'settlement_replay' ? 'payment_intents' : 'purchase_settlements';
        $pauseOnFirstTargetLock = true;
        $targetLockQueryCount = 0;
        DB::listen(function (QueryExecuted $query) use (
            &$pauseOnFirstTargetLock,
            &$targetLockQueryCount,
            $operation,
            $targetTable,
            $payload,
        ): void {
            $sql = strtolower($query->sql);
            if (! str_contains($sql, $targetTable) || ! str_contains($sql, 'for update')) {
                return;
            }

            $targetLockQueryCount++;
            if (! $pauseOnFirstTargetLock) {
                return;
            }
            $pauseOnFirstTargetLock = false;

            if ($operation === 'settlement_replay') {
                for ($index = 0; $index < 5; $index++) {
                    DB::table('audit_logs')->insert([
                        'actor_type' => 'system',
                        'actor_id' => null,
                        'action' => 'test.purchase_order.deadlock_weight',
                        'target_type' => 'test',
                        'target_id' => 'purchase-order-deadlock-weight-'.$index,
                        'before_safe_data' => null,
                        'after_safe_data' => null,
                        'reason_code' => 'test_deadlock_weight',
                        'reason' => null,
                        'correlation_id' => hash('sha256', 'purchase-order-deadlock-weight-'.$index),
                        'request_fingerprint' => null,
                        'created_at' => $payload['created_at'],
                    ]);
                }
            }

            echo "LOCKED\n";
            flush();
            if (fgets(STDIN) === false) {
                throw new RuntimeException('Worker lock barrier was not released.');
            }
        });

        echo "READY\n";
        flush();
        if (fgets(STDIN) === false) {
            fwrite(STDERR, "Worker start barrier was not released.\n");
            exit(2);
        }

        try {
            if ($operation === 'settlement_replay') {
                $settledAt = new DateTimeImmutable($payload['settled_at']);
                $receipt = $app->make(PurchaseSettlementService::class)->capture(
                    $payload['intent_public_id'],
                    $payload['provider_code'],
                    new VerifiedPaymentEvent(
                        $payload['provider_event_id'],
                        $payload['provider_event_payload_hash'],
                        new PaymentEvidence(
                            ProviderOperationOutcome::Success,
                            PaymentEvidenceAuthority::Authoritative,
                            PaymentTransactionStatus::Settled,
                            $payload['provider_transaction_id'],
                            $payload['provider_event_id'],
                            Money::irr((int) $payload['amount_irr']),
                            $settledAt,
                            $settledAt,
                            $payload['evidence_payload_hash'],
                            ['provider_reference' => $payload['provider_transaction_id']],
                        ),
                    ),
                    $payload['correlation_id'],
                );
                $result = [
                    'settlement_id' => $receipt->settlementId,
                    'settlement_public_id' => $receipt->settlementPublicId,
                    'replayed' => $receipt->replayed,
                ];
            } else {
                $receipt = $app->make(PurchaseOrderService::class)->createFromSettlement(
                    $payload['settlement_public_id'],
                    $payload['correlation_id'],
                );
                $result = [
                    'order_id' => $receipt->orderId,
                    'order_public_id' => $receipt->orderPublicId,
                    'item_public_id' => $receipt->orderItemPublicId,
                    'replayed' => $receipt->replayed,
                ];
            }

            echo json_encode([
                'ok' => true,
                'operation' => $operation,
                'target_lock_query_count' => $targetLockQueryCount,
                'result' => $result,
            ], JSON_THROW_ON_ERROR)."\n";
        } catch (Throwable $exception) {
            echo json_encode([
                'ok' => false,
                'operation' => $operation,
                'target_lock_query_count' => $targetLockQueryCount,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR)."\n";
        }
        exit(0);
    }
}

namespace Tests\Feature {
    use Database\Seeders\CatalogAccessFoundationSeeder;
    use Database\Seeders\IdentityAccessFoundationSeeder;
    use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\TestCase;

    /** @requirement BUY-001 PAY-002 DAT-003 DAT-004 QUA-004 */
    final class PurchaseOrderCrossServiceContentionVerificationTest extends TestCase
    {
        use AgentPricingQuoteIntegrationTestSupport;
        use DatabaseTruncation;
        use PurchaseOrderTestSupport;

        private const WORKER_TIMEOUT_SECONDS = 20;

        protected function setUp(): void
        {
            parent::setUp();
            $this->seed(IdentityAccessFoundationSeeder::class);
            $this->seed(CatalogAccessFoundationSeeder::class);
            $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
            $this->bootPurchaseOrderClock();
        }

        protected function tearDown(): void
        {
            try {
                if (isset($this->app)) {
                    $this->truncateDatabaseTables();
                }
            } finally {
                parent::tearDown();
            }
        }

        public function test_order_creation_retries_when_purchase_settlement_replay_deadlocks_against_inverse_lock_order(): void
        {
            $suffix = 'cross_service_deadlock';
            $settlement = $this->createPurchaseOrderSettlement($suffix);
            $basePayload = [
                'settlement_public_id' => $settlement->settlementPublicId,
                'intent_public_id' => $settlement->intentPublicId,
                'provider_code' => $settlement->providerCode,
                'provider_event_id' => $settlement->providerEventId,
                'provider_transaction_id' => $settlement->providerTransactionId,
                'provider_event_payload_hash' => hash('sha256', 'purchase-order-provider-event:'.$suffix),
                'evidence_payload_hash' => hash('sha256', 'purchase-order-provider-evidence:'.$suffix),
                'amount_irr' => (string) $settlement->amount->amount(),
                'settled_at' => $settlement->settledAt->format(DATE_ATOM),
                'created_at' => $this->purchaseOrderTimestamp(),
            ];

            $settlementWorker = $this->startWorker($basePayload + [
                'operation' => 'settlement_replay',
                'correlation_id' => $this->purchaseOrderCorrelation('cross-service-settlement-replay'),
            ]);
            $orderWorker = $this->startWorker($basePayload + [
                'operation' => 'order_create',
                'correlation_id' => $this->purchaseOrderCorrelation('cross-service-order-create'),
            ]);

            try {
                self::assertSame("READY\n", $this->readLine($settlementWorker, 'settlement readiness'));
                self::assertSame("READY\n", $this->readLine($orderWorker, 'order readiness'));

                $this->signal($settlementWorker, "GO\n");
                self::assertSame("LOCKED\n", $this->readLine($settlementWorker, 'payment-intent lock'));

                $this->signal($orderWorker, "GO\n");
                self::assertSame("LOCKED\n", $this->readLine($orderWorker, 'purchase-settlement lock'));

                $this->signal($orderWorker, "CONTINUE\n");
                usleep(100_000);
                $this->signal($settlementWorker, "CONTINUE\n");

                $settlementResult = $this->readResult($settlementWorker, 'settlement result');
                $orderResult = $this->readResult($orderWorker, 'order result');

                self::assertTrue($settlementResult['ok'], json_encode($settlementResult, JSON_THROW_ON_ERROR));
                self::assertTrue($orderResult['ok'], json_encode($orderResult, JSON_THROW_ON_ERROR));
                self::assertTrue($settlementResult['result']['replayed']);
                self::assertFalse($orderResult['result']['replayed']);
                self::assertSame($settlement->settlementId, $settlementResult['result']['settlement_id']);
                self::assertGreaterThanOrEqual(
                    2,
                    $orderResult['target_lock_query_count'],
                    'The Order transaction must be selected as the deadlock victim at least once and retry its settlement lock.',
                );

                self::assertSame(1, DB::table('purchase_settlements')->count());
                self::assertSame('captured', DB::table('payment_intents')->where('public_id', $settlement->intentPublicId)->value('state'));
                self::assertSame(1, DB::table('orders')->count());
                self::assertSame(1, DB::table('order_items')->count());
                self::assertSame(1, DB::table('order_state_histories')->count());
                self::assertSame(1, DB::table('audit_logs')->where('action', 'order.purchase.created')->count());
            } finally {
                $this->terminateWorker($settlementWorker);
                $this->terminateWorker($orderWorker);
            }
        }

        /** @param array<string, string> $payload */
        private function startWorker(array $payload): array
        {
            $pipes = [];
            $process = proc_open([
                PHP_BINARY,
                '-d',
                'pcov.enabled=0',
                __FILE__,
                '--purchase-order-cross-service-worker',
                base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            ], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start Purchase Order cross-service contention worker.');
            }
            /** @var array{0:resource,1:resource,2:resource} $pipes */
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            return ['process' => $process, 'pipes' => $pipes];
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker */
        private function signal(array $worker, string $signal): void
        {
            if (fwrite($worker['pipes'][0], $signal) === false) {
                throw new RuntimeException('Unable to signal Purchase Order cross-service contention worker.');
            }
            fflush($worker['pipes'][0]);
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker */
        private function readResult(array $worker, string $phase): array
        {
            $line = $this->readLine($worker, $phase);
            /** @var array<string, mixed> $result */
            $result = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

            return $result;
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
                    throw new RuntimeException('Unable to wait for Purchase Order cross-service contention worker output.');
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
                    throw new RuntimeException('Purchase Order cross-service contention worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException('Purchase Order cross-service contention worker timed out during '.$phase.': '.$stderr);
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker */
        private function terminateWorker(array $worker): void
        {
            if (is_resource($worker['pipes'][0])) {
                fclose($worker['pipes'][0]);
            }
            if (is_resource($worker['pipes'][1])) {
                fclose($worker['pipes'][1]);
            }
            if (is_resource($worker['pipes'][2])) {
                fclose($worker['pipes'][2]);
            }
            if (! is_resource($worker['process'])) {
                return;
            }
            $status = proc_get_status($worker['process']);
            if ($status['running']) {
                proc_terminate($worker['process']);
            }
            proc_close($worker['process']);
        }
    }
}
