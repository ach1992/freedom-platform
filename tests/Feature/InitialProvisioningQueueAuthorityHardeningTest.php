<?php

declare(strict_types=1);

namespace {
    use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
    use App\Shared\Application\SafeOutboxPayload;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\DatabaseManager;
    use Illuminate\Support\Str;

    require_once dirname(__DIR__, 2).'/vendor/autoload.php';

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--initial-provisioning-hardening-worker') {
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
            if (($payload['mode'] ?? '') === 'repeatable_replay') {
                $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $result = $connection->transaction(function () use ($app, $connection, $payload): array {
                    $snapshot = $connection->table('orders')
                        ->where('public_id', $payload['order_public_id'])
                        ->first(['id', 'state', 'state_version']);
                    if ($snapshot === null) {
                        throw new RuntimeException('Replay snapshot Order is unavailable.');
                    }

                    echo "SNAPSHOT_READY\n";
                    flush();
                    if (fgets(STDIN) === false) {
                        throw new RuntimeException('Replay snapshot barrier was not released.');
                    }

                    $receipt = $app->make(InitialProvisioningQueueService::class)->queueInitial(
                        $payload['order_public_id'],
                        $payload['correlation_id'],
                    );

                    return [
                        'service_public_id' => $receipt->serviceSubscriptionPublicId,
                        'operation_public_id' => $receipt->provisioningOperationPublicId,
                        'outbox_event_id' => $receipt->outboxEventId,
                        'replayed' => $receipt->replayed,
                    ];
                });
            } elseif (($payload['mode'] ?? '') === 'direct_queue') {
                $result = $connection->transaction(function () use ($connection, $payload): array {
                    $order = $connection->table('orders')
                        ->where('public_id', $payload['order_public_id'])
                        ->first(['id', 'public_id', 'user_id', 'state', 'state_version']);
                    if ($order === null) {
                        throw new RuntimeException('Direct queue Order is unavailable.');
                    }
                    $item = $connection->table('order_items')
                        ->where('order_id', $order->id)
                        ->where('line_number', 1)
                        ->first(['id', 'public_id']);
                    if ($item === null) {
                        throw new RuntimeException('Direct queue Order Item is unavailable.');
                    }

                    $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
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

                    $operationPublicId = (string) Str::ulid();
                    $operationId = (int) $connection->table('provisioning_operations')->insertGetId([
                        'public_id' => $operationPublicId,
                        'operation_key' => 'initial-provision:'.$item->public_id,
                        'operation_type' => 'initial_provision',
                        'order_id' => (int) $order->id,
                        'order_item_id' => (int) $item->id,
                        'service_subscription_id' => $serviceId,
                        'user_id' => (int) $order->user_id,
                        'state' => 'queued',
                        'state_version' => 1,
                        'correlation_id' => $payload['correlation_id'],
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);

                    $outbox = new SafeOutboxPayload([
                        'order_public_id' => $order->public_id,
                        'order_item_public_id' => $item->public_id,
                        'provisioning_operation_public_id' => $operationPublicId,
                        'service_subscription_public_id' => $servicePublicId,
                    ]);
                    $eventId = (string) Str::uuid();
                    $connection->table('outbox_messages')->insert([
                        'id' => $eventId,
                        'event_key' => 'provisioning.initial.requested:'.$operationPublicId,
                        'event_type' => 'provisioning.initial.requested',
                        'aggregate_type' => 'provisioning_operation',
                        'aggregate_id' => $operationPublicId,
                        'payload' => $outbox->json(),
                        'payload_hash' => $outbox->hash(),
                        'correlation_id' => $payload['correlation_id'],
                        'available_at' => $timestamp,
                        'processed_at' => null,
                        'attempts' => 0,
                        'last_error_class' => null,
                        'last_error_code' => null,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);

                    $updated = $connection->table('orders')
                        ->where('id', $order->id)
                        ->where('state', 'paid')
                        ->where('state_version', 1)
                        ->update([
                            'state' => 'provisioning_queued',
                            'state_version' => 2,
                            'updated_at' => $timestamp,
                        ]);
                    if ($updated !== 1) {
                        throw new RuntimeException('Direct queue Order transition failed.');
                    }

                    return [
                        'service_id' => $serviceId,
                        'operation_id' => $operationId,
                        'event_id' => $eventId,
                    ];
                });
            } else {
                throw new RuntimeException('Unknown hardening worker mode.');
            }

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
    use App\Modules\Orders\Domain\OrderState;
    use App\Modules\Payments\Application\Contracts\PaymentEvidence;
    use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
    use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
    use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
    use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
    use App\Modules\Payments\Application\PurchaseRefundReceipt;
    use App\Modules\Payments\Application\PurchaseRefundService;
    use App\Modules\Payments\Application\PurchaseSettlementReceipt;
    use App\Modules\Payments\Domain\PaymentIntentState;
    use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
    use App\Shared\Domain\Money;
    use Database\Seeders\CatalogAccessFoundationSeeder;
    use Database\Seeders\IdentityAccessFoundationSeeder;
    use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\Support\RestoresDatabaseTrigger;
    use Tests\TestCase;

    /** @requirement BUY-001 PAY-002 PAY-003 PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-008 QUA-004 */
    final class InitialProvisioningQueueAuthorityHardeningTest extends TestCase
    {
        use AgentPricingQuoteIntegrationTestSupport;
        use DatabaseTruncation;
        use PurchaseOrderTestSupport;
        use RestoresDatabaseTrigger;

        private const WORKER_TIMEOUT_SECONDS = 30;

        protected function setUp(): void
        {
            parent::setUp();
            $this->seed(IdentityAccessFoundationSeeder::class);
            $this->seed(CatalogAccessFoundationSeeder::class);
            $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
            $this->bootPurchaseOrderClock();
        }

        public function test_repeatable_read_replay_uses_current_reads_after_snapshot_barrier(): void
        {
            [, $order] = $this->createPaidOrder('repeatable-replay');
            $worker = $this->startWorker([
                'mode' => 'repeatable_replay',
                'order_public_id' => $order->orderPublicId,
                'correlation_id' => $this->purchaseOrderCorrelation('repeatable-replay-worker'),
            ]);

            try {
                self::assertSame("READY\n", $this->readLine($worker, 'readiness'));
                $this->sendLine($worker, "GO\n");
                self::assertSame("SNAPSHOT_READY\n", $this->readLine($worker, 'snapshot'));

                $first = $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
                    $order->orderPublicId,
                    $this->purchaseOrderCorrelation('repeatable-replay-parent'),
                );

                $this->sendLine($worker, "GO\n");
                $result = $this->readWorkerResult($worker);

                self::assertTrue($result['ok'], json_encode($result, JSON_THROW_ON_ERROR));
                self::assertTrue($result['result']['replayed']);
                self::assertSame($first->serviceSubscriptionPublicId, $result['result']['service_public_id']);
                self::assertSame($first->provisioningOperationPublicId, $result['result']['operation_public_id']);
                self::assertSame($first->outboxEventId, $result['result']['outbox_event_id']);
            } finally {
                $this->terminateWorker($worker);
            }
        }

        public function test_direct_database_queue_waits_for_refund_financial_lock_and_fails_after_refund_commit(): void
        {
            [$settlement, $order] = $this->createPaidOrder('direct-race');
            $connection = DB::connection();
            $worker = null;
            $connection->beginTransaction();

            try {
                $refund = $this->recordFullRefund($settlement, 'direct-race');
                self::assertSame(PaymentIntentState::Refunded, $refund->state);
                self::assertSame(PaymentIntentState::Refunded->value, $connection->table('payment_intents')->where('public_id', $settlement->intentPublicId)->value('state'));

                $worker = $this->startWorker([
                    'mode' => 'direct_queue',
                    'order_public_id' => $order->orderPublicId,
                    'correlation_id' => $this->purchaseOrderCorrelation('direct-race-worker'),
                ]);
                self::assertSame("READY\n", $this->readLine($worker, 'readiness'));
                $this->sendLine($worker, "GO\n");

                self::assertNull(
                    $this->tryReadLine($worker, 0.75),
                    'Direct DB queue must remain blocked behind the authoritative settlement/refund lock.',
                );

                $connection->commit();
                $result = $this->readWorkerResult($worker);

                self::assertFalse($result['ok'], json_encode($result, JSON_THROW_ON_ERROR));
                self::assertSame('Illuminate\\Database\\QueryException', $result['exception']);
                self::assertSame(0, DB::table('service_subscriptions')->count());
                self::assertSame(0, DB::table('provisioning_operations')->count());
                self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'provisioning.initial.requested')->count());
                self::assertSame(OrderState::Paid->value, DB::table('orders')->where('id', $order->orderId)->value('state'));
                self::assertSame(PaymentIntentState::Refunded->value, DB::table('payment_intents')->where('public_id', $settlement->intentPublicId)->value('state'));
            } finally {
                if ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
                if ($worker !== null) {
                    $this->terminateWorker($worker);
                }
            }
        }

        public function test_replay_rejects_outbox_payload_body_corruption_with_stale_hash(): void
        {
            [, $order] = $this->createPaidOrder('payload-corruption');
            $service = $this->app->make(InitialProvisioningQueueService::class);
            $first = $service->queueInitial(
                $order->orderPublicId,
                $this->purchaseOrderCorrelation('payload-corruption-first'),
            );

            $this->withDatabaseTriggerDisabled('outbox_initial_provision_envelope_update_guard', function () use ($first): void {
                $updated = DB::table('outbox_messages')->where('id', $first->outboxEventId)->update([
                    'payload' => json_encode(['tampered' => true], JSON_THROW_ON_ERROR),
                ]);
                self::assertSame(1, $updated);
            });

            try {
                $service->queueInitial(
                    $order->orderPublicId,
                    $this->purchaseOrderCorrelation('payload-corruption-replay'),
                );
                self::fail('Replay must reject Outbox payload bytes that no longer match the persisted hash and expected command.');
            } catch (RuntimeException $exception) {
                self::assertSame('Stored initial provisioning queue authority is inconsistent.', $exception->getMessage());
            }

            self::assertSame(1, DB::table('service_subscriptions')->count());
            self::assertSame(1, DB::table('provisioning_operations')->count());
            self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'provisioning.initial.requested')->count());
        }

        public function test_migration_up_repairs_partial_guard_state_and_preserves_successor_order_authority_on_reentry(): void
        {
            /** @var Migration $migration */
            $migration = require database_path('migrations/2026_08_14_001162_create_provisioning_queue_authority.php');

            try {
                DB::unprepared('DROP TRIGGER IF EXISTS service_subscriptions_insert_guard');
                self::assertSame(0, $this->constraintCount('orders', 'orders_purchase_shape_chk'));

                $migration->up();
                $migration->up();

                self::assertSame(1, $this->triggerCount('service_subscriptions_insert_guard'));
                self::assertSame(1, $this->triggerCount('orders_update_guard'));
                self::assertSame(0, $this->constraintCount('orders', 'orders_purchase_shape_chk'));
                self::assertSame(1, $this->constraintCount('orders', 'orders_purchase_quote_identity_chk'));
                self::assertSame(1, $this->constraintCount('orders', 'orders_purchase_financial_shape_chk'));
                self::assertSame(1, $this->constraintCount('orders', 'orders_purchase_captured_shape_chk'));
                self::assertSame(1, $this->constraintCount('provisioning_operations', 'provisioning_operations_state_chk'));

                $prePaymentGuard = DB::selectOne(
                    'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ? AND LOCATE(?, ACTION_STATEMENT) > 0',
                    ['orders_update_guard', 'Only pre-payment/v0 to paid/v1 or paid/v1 to provisioning_queued/v2'],
                );
                self::assertNotNull($prePaymentGuard);
                self::assertSame(1, (int) $prePaymentGuard->aggregate);
            } finally {
                $migration->up();
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

        private function recordFullRefund(PurchaseSettlementReceipt $settlement, string $suffix): PurchaseRefundReceipt
        {
            $occurredAt = $this->purchaseOrderClock->value->modify('+5 minutes');

            return $this->app->make(PurchaseRefundService::class)->record(
                'provisioning-hardening-refund-'.$suffix,
                $settlement->settlementPublicId,
                $settlement->providerCode,
                new VerifiedPaymentEvent(
                    'evt-provisioning-hardening-refund-'.$suffix,
                    hash('sha256', 'provisioning-hardening-refund-event:'.$suffix),
                    new PaymentEvidence(
                        ProviderOperationOutcome::Success,
                        PaymentEvidenceAuthority::Authoritative,
                        PaymentTransactionStatus::Refunded,
                        'refund-hardening-txn-'.$suffix,
                        'evt-provisioning-hardening-refund-'.$suffix,
                        Money::irr($settlement->amount->amount()),
                        $occurredAt,
                        $occurredAt,
                        hash('sha256', 'provisioning-hardening-refund-evidence:'.$suffix),
                        ['provider_reference' => 'refund-hardening-txn-'.$suffix],
                    ),
                ),
                $this->purchaseOrderCorrelation('refund-'.$suffix),
            );
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
                '--initial-provisioning-hardening-worker',
                base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            ], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start initial provisioning hardening worker.');
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
                throw new RuntimeException('Unable to release initial provisioning hardening worker barrier.');
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
                    throw new RuntimeException('Unable to wait for initial provisioning hardening worker output.');
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
                    throw new RuntimeException('Initial provisioning hardening worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException('Initial provisioning hardening worker timed out during '.$phase.': '.$stderr);
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker */
        private function tryReadLine(array $worker, float $timeoutSeconds): ?string
        {
            $deadline = microtime(true) + $timeoutSeconds;
            while (microtime(true) < $deadline) {
                $read = [$worker['pipes'][1]];
                $write = null;
                $except = null;
                $remaining = max(0.0, $deadline - microtime(true));
                $seconds = (int) floor($remaining);
                $microseconds = (int) (($remaining - $seconds) * 1_000_000);
                $selected = stream_select($read, $write, $except, $seconds, $microseconds);
                if ($selected === false) {
                    throw new RuntimeException('Unable to probe initial provisioning hardening worker output.');
                }
                if ($selected === 0) {
                    return null;
                }
                $line = fgets($worker['pipes'][1]);
                if ($line !== false && trim($line) !== '') {
                    return $line;
                }
            }

            return null;
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

        private function triggerCount(string $trigger): int
        {
            $row = DB::selectOne(
                'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
                [$trigger],
            );

            return $row === null ? 0 : (int) $row->aggregate;
        }

        private function constraintCount(string $table, string $constraint): int
        {
            $row = DB::selectOne(
                'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                [$table, $constraint],
            );

            return $row === null ? 0 : (int) $row->aggregate;
        }
    }
}
