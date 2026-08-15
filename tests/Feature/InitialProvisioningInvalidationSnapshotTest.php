<?php

declare(strict_types=1);

namespace {
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\DatabaseManager;
    use Illuminate\Support\Str;

    require_once dirname(__DIR__, 2).'/vendor/autoload.php';

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--initial-provisioning-invalidation-snapshot-worker') {
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

        echo "READY\n";
        flush();
        if (fgets(STDIN) === false) {
            fwrite(STDERR, "Worker barrier was not released.\n");
            exit(2);
        }

        try {
            $connection = $app->make(DatabaseManager::class)->connection();
            $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $result = $connection->transaction(function () use ($connection, $payload): array {
                $order = $connection->table('orders')
                    ->where('public_id', $payload['order_public_id'])
                    ->first(['id', 'public_id', 'user_id']);
                if ($order === null) {
                    throw new RuntimeException('Snapshot worker Order is unavailable.');
                }

                $item = $connection->table('order_items')
                    ->where('order_id', $order->id)
                    ->where('line_number', 1)
                    ->first(['id', 'public_id']);
                if ($item === null) {
                    throw new RuntimeException('Snapshot worker Order Item is unavailable.');
                }

                // Establish a stale REPEATABLE READ snapshot before the parent commits a refund invalidation.
                $snapshotInvalidation = $connection->table('provisioning_financial_invalidations')
                    ->where('order_id', $order->id)
                    ->first(['id']);
                if ($snapshotInvalidation !== null) {
                    throw new RuntimeException('Snapshot worker unexpectedly observed an invalidation before the barrier.');
                }

                echo "SNAPSHOT_READY\n";
                flush();
                if (fgets(STDIN) === false) {
                    throw new RuntimeException('Snapshot worker invalidation barrier was not released.');
                }

                $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
                $mode = $payload['mode'] ?? '';

                if ($mode === 'service') {
                    $servicePublicId = (string) Str::ulid();
                    $serviceId = (int) $connection->table('service_subscriptions')->insertGetId([
                        'public_id' => $servicePublicId,
                        'order_id' => (int) $order->id,
                        'order_item_id' => (int) $item->id,
                        'user_id' => (int) $order->user_id,
                        'creation_correlation_id' => $payload['correlation_id'],
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);

                    return [
                        'service_id' => $serviceId,
                        'service_public_id' => $servicePublicId,
                    ];
                }

                if ($mode === 'operation') {
                    $service = $connection->table('service_subscriptions')
                        ->where('order_id', $order->id)
                        ->where('order_item_id', $item->id)
                        ->first(['id', 'public_id']);
                    if ($service === null) {
                        throw new RuntimeException('Snapshot worker Service Subscription is unavailable.');
                    }

                    $operationPublicId = (string) Str::ulid();
                    $operationId = (int) $connection->table('provisioning_operations')->insertGetId([
                        'public_id' => $operationPublicId,
                        'operation_key' => 'initial-provision:'.$item->public_id,
                        'operation_type' => 'initial_provision',
                        'order_id' => (int) $order->id,
                        'order_item_id' => (int) $item->id,
                        'service_subscription_id' => (int) $service->id,
                        'user_id' => (int) $order->user_id,
                        'state' => 'queued',
                        'state_version' => 1,
                        'correlation_id' => $payload['correlation_id'],
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);

                    return [
                        'operation_id' => $operationId,
                        'operation_public_id' => $operationPublicId,
                    ];
                }

                throw new RuntimeException('Unknown invalidation snapshot worker mode.');
            });

            echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR)."\n";
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
    require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
    require_once __DIR__.'/PurchaseOrderTestSupport.php';

    use App\Modules\Orders\Application\PurchaseOrderReceipt;
    use App\Modules\Orders\Application\PurchaseOrderService;
    use App\Modules\Payments\Application\PurchaseSettlementReceipt;
    use App\Modules\Payments\Domain\PaymentIntentState;
    use Database\Seeders\CatalogAccessFoundationSeeder;
    use Database\Seeders\IdentityAccessFoundationSeeder;
    use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Str;
    use RuntimeException;
    use Tests\TestCase;

    /** @requirement PAY-002 PAY-003 PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-008 QUA-004 */
    final class InitialProvisioningInvalidationSnapshotTest extends TestCase
    {
        use AgentPricingQuoteIntegrationTestSupport;
        use DatabaseTruncation;
        use PurchaseOrderTestSupport;

        private const WORKER_TIMEOUT_SECONDS = 30;

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

        public function test_stale_repeatable_read_snapshot_cannot_create_service_after_committed_direct_refund_invalidation(): void
        {
            [$settlement, $order] = $this->createPaidOrder('stale-service-invalidation');
            $worker = $this->startWorker([
                'mode' => 'service',
                'order_public_id' => $order->orderPublicId,
                'correlation_id' => $this->purchaseOrderCorrelation('stale-service-worker'),
            ]);

            try {
                $this->advanceWorkerToSnapshot($worker);
                $this->commitDirectRefundInvalidation($settlement, $order, 'stale-service-invalidation');
                $this->sendLine($worker, "GO\n");
                $result = $this->readWorkerResult($worker);

                self::assertFalse($result['ok'], json_encode($result, JSON_THROW_ON_ERROR));
                self::assertSame('Illuminate\\Database\\QueryException', $result['exception']);
                self::assertSame(0, DB::table('service_subscriptions')->count());
                self::assertSame(0, DB::table('provisioning_operations')->count());
                self::assertSame(1, DB::table('provisioning_financial_invalidations')->where('order_id', $order->orderId)->count());
                self::assertSame(
                    PaymentIntentState::Captured->value,
                    DB::table('payment_intents')->where('public_id', $settlement->intentPublicId)->value('state'),
                );
            } finally {
                $this->terminateWorker($worker);
            }
        }

        public function test_stale_repeatable_read_snapshot_cannot_create_queued_operation_after_committed_direct_refund_invalidation(): void
        {
            [$settlement, $order] = $this->createPaidOrder('stale-operation-invalidation');
            $serviceId = $this->createDirectServiceBeforeInvalidation($order, 'stale-operation-service');
            self::assertGreaterThan(0, $serviceId);

            $worker = $this->startWorker([
                'mode' => 'operation',
                'order_public_id' => $order->orderPublicId,
                'correlation_id' => $this->purchaseOrderCorrelation('stale-operation-worker'),
            ]);

            try {
                $this->advanceWorkerToSnapshot($worker);
                $this->commitDirectRefundInvalidation($settlement, $order, 'stale-operation-invalidation');
                $this->sendLine($worker, "GO\n");
                $result = $this->readWorkerResult($worker);

                self::assertFalse($result['ok'], json_encode($result, JSON_THROW_ON_ERROR));
                self::assertSame('Illuminate\\Database\\QueryException', $result['exception']);
                self::assertSame(1, DB::table('service_subscriptions')->where('id', $serviceId)->count());
                self::assertSame(0, DB::table('provisioning_operations')->count());
                self::assertSame(1, DB::table('provisioning_financial_invalidations')->where('order_id', $order->orderId)->count());
                self::assertSame(
                    PaymentIntentState::Captured->value,
                    DB::table('payment_intents')->where('public_id', $settlement->intentPublicId)->value('state'),
                );
            } finally {
                $this->terminateWorker($worker);
            }
        }

        /** @return array{0:PurchaseSettlementReceipt,1:PurchaseOrderReceipt} */
        private function createPaidOrder(string $suffix): array
        {
            $settlement = $this->createPurchaseOrderSettlement($suffix);
            $order = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
                $settlement->settlementPublicId,
                $this->purchaseOrderCorrelation('order-'.$suffix),
            );

            return [$settlement, $order];
        }

        private function createDirectServiceBeforeInvalidation(PurchaseOrderReceipt $order, string $suffix): int
        {
            $orderRow = DB::table('orders')->where('id', $order->orderId)->first(['id', 'user_id']);
            self::assertNotNull($orderRow);
            $item = DB::table('order_items')
                ->where('order_id', $order->orderId)
                ->where('line_number', 1)
                ->first(['id']);
            self::assertNotNull($item);

            return (int) DB::table('service_subscriptions')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'order_id' => (int) $orderRow->id,
                'order_item_id' => (int) $item->id,
                'user_id' => (int) $orderRow->user_id,
                'creation_correlation_id' => $this->purchaseOrderCorrelation($suffix),
                'created_at' => $this->purchaseOrderTimestamp(),
                'updated_at' => $this->purchaseOrderTimestamp(),
            ]);
        }

        private function commitDirectRefundInvalidation(
            PurchaseSettlementReceipt $settlement,
            PurchaseOrderReceipt $order,
            string $suffix,
        ): void {
            $settlementRow = DB::table('purchase_settlements')
                ->where('public_id', $settlement->settlementPublicId)
                ->first(['id', 'payment_intent_id', 'user_id']);
            self::assertNotNull($settlementRow);

            $intentId = (int) $settlementRow->payment_intent_id;
            self::assertSame(
                PaymentIntentState::Captured->value,
                DB::table('payment_intents')->where('id', $intentId)->value('state'),
            );

            $amountIrr = $settlement->amount->amount();
            $currency = $settlement->amount->currency();
            $occurredAt = $this->purchaseOrderClock->value->modify('+5 minutes')->format('Y-m-d H:i:s.u');
            $providerEventId = 'evt-direct-invalidation-'.$suffix;
            $providerRefundId = 'refund-direct-invalidation-'.$suffix;
            $eventPayloadHash = hash('sha256', 'direct-invalidation-event:'.$suffix);
            $evidencePayloadHash = hash('sha256', 'direct-invalidation-evidence:'.$suffix);
            $correlationId = $this->purchaseOrderCorrelation('refund-'.$suffix);

            $providerEventRowId = (int) DB::table('payment_provider_events')->insertGetId([
                'payment_intent_id' => $intentId,
                'provider_code' => $settlement->providerCode,
                'provider_event_id' => $providerEventId,
                'event_payload_hash' => $eventPayloadHash,
                'provider_transaction_id' => $providerRefundId,
                'evidence_payload_hash' => $evidencePayloadHash,
                'evidence_authority' => 'authoritative',
                'transaction_status' => 'refunded',
                'amount_irr' => $amountIrr,
                'currency' => $currency,
                'occurred_at' => $occurredAt,
                'settled_at' => $occurredAt,
                'safe_evidence' => json_encode(['provider_reference' => $providerRefundId], JSON_THROW_ON_ERROR),
                'created_at' => $this->purchaseOrderTimestamp(),
            ]);

            $refundId = (int) DB::table('purchase_refunds')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'refund_key' => 'direct-invalidation-'.$suffix,
                'payload_hash' => hash('sha256', 'direct-invalidation-payload:'.$suffix),
                'purchase_settlement_id' => (int) $settlementRow->id,
                'payment_intent_id' => $intentId,
                'provider_event_row_id' => $providerEventRowId,
                'user_id' => (int) $settlementRow->user_id,
                'provider_code' => $settlement->providerCode,
                'provider_refund_id' => $providerRefundId,
                'evidence_payload_hash' => $evidencePayloadHash,
                'amount_irr' => $amountIrr,
                'cumulative_refunded_irr' => $amountIrr,
                'currency' => $currency,
                'resulting_payment_state' => PaymentIntentState::Refunded->value,
                'refunded_at' => $occurredAt,
                'correlation_id' => $correlationId,
                'created_at' => $this->purchaseOrderTimestamp(),
            ]);
            self::assertGreaterThan(0, $refundId);

            $invalidation = DB::table('provisioning_financial_invalidations')
                ->where('order_id', $order->orderId)
                ->first(['purchase_refund_id', 'payment_intent_id']);
            self::assertNotNull($invalidation);
            self::assertSame($refundId, (int) $invalidation->purchase_refund_id);
            self::assertSame($intentId, (int) $invalidation->payment_intent_id);
            self::assertSame(
                PaymentIntentState::Captured->value,
                DB::table('payment_intents')->where('id', $intentId)->value('state'),
                'The direct-DB refund row deliberately leaves PaymentIntent captured so only the durable invalidation can revoke queue authority.',
            );
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker */
        private function advanceWorkerToSnapshot(array $worker): void
        {
            self::assertSame("READY\n", $this->readLine($worker, 'readiness'));
            $this->sendLine($worker, "GO\n");
            self::assertSame("SNAPSHOT_READY\n", $this->readLine($worker, 'snapshot'));
        }

        /**
         * @param  array<string, string>  $payload
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
                '--initial-provisioning-invalidation-snapshot-worker',
                base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            ], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start provisioning invalidation snapshot worker.');
            }
            /** @var array{0:resource,1:resource,2:resource} $pipes */
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            return ['process' => $process, 'pipes' => $pipes];
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker */
        private function sendLine(array $worker, string $line): void
        {
            if (fwrite($worker['pipes'][0], $line) === false) {
                throw new RuntimeException('Unable to release provisioning invalidation snapshot worker barrier.');
            }
            fflush($worker['pipes'][0]);
        }

        /**
         * @param  array{process:resource,pipes:array{0:resource,1:resource,2:resource}}  $worker
         * @return array<string, mixed>
         */
        private function readWorkerResult(array $worker): array
        {
            $line = $this->readLine($worker, 'result');
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
                    throw new RuntimeException('Unable to wait for provisioning invalidation snapshot worker output.');
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
                    throw new RuntimeException('Provisioning invalidation snapshot worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException('Provisioning invalidation snapshot worker timed out during '.$phase.': '.$stderr);
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker */
        private function terminateWorker(array $worker): void
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
