<?php

declare(strict_types=1);

namespace {
    use App\Modules\Payments\Application\Contracts\PaymentEvidence;
    use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
    use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
    use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
    use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
    use App\Modules\Payments\Application\PurchaseRefundService;
    use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
    use App\Shared\Domain\Money;
    use Illuminate\Contracts\Console\Kernel;

    require_once dirname(__DIR__, 2).'/vendor/autoload.php';

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--initial-provisioning-contention-worker') {
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
            if (($payload['mode'] ?? '') === 'queue') {
                $receipt = $app->make(InitialProvisioningQueueService::class)->queueInitial(
                    $payload['order_public_id'],
                    $payload['correlation_id'],
                );
                $result = [
                    'order_public_id' => $receipt->orderPublicId,
                    'service_public_id' => $receipt->serviceSubscriptionPublicId,
                    'operation_public_id' => $receipt->provisioningOperationPublicId,
                    'outbox_event_id' => $receipt->outboxEventId,
                    'replayed' => $receipt->replayed,
                ];
            } elseif (($payload['mode'] ?? '') === 'refund') {
                $occurredAt = new DateTimeImmutable($payload['occurred_at']);
                $receipt = $app->make(PurchaseRefundService::class)->record(
                    $payload['refund_key'],
                    $payload['settlement_public_id'],
                    $payload['provider_code'],
                    new VerifiedPaymentEvent(
                        $payload['provider_event_id'],
                        $payload['event_payload_hash'],
                        new PaymentEvidence(
                            ProviderOperationOutcome::Success,
                            PaymentEvidenceAuthority::Authoritative,
                            PaymentTransactionStatus::Refunded,
                            $payload['provider_refund_id'],
                            $payload['provider_event_id'],
                            Money::irr((int) $payload['amount_irr']),
                            $occurredAt,
                            $occurredAt,
                            $payload['evidence_payload_hash'],
                            ['provider_reference' => $payload['provider_refund_id']],
                        ),
                    ),
                    $payload['correlation_id'],
                );
                $result = [
                    'refund_public_id' => $receipt->publicId,
                    'state' => $receipt->state->value,
                ];
            } else {
                throw new RuntimeException('Unknown contention worker mode.');
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

    use App\Modules\Orders\Application\PurchaseOrderService;
    use App\Modules\Orders\Domain\OrderState;
    use App\Modules\Payments\Domain\PaymentIntentState;
    use Database\Seeders\CatalogAccessFoundationSeeder;
    use Database\Seeders\IdentityAccessFoundationSeeder;
    use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
    use DomainException;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\TestCase;

    /** @requirement BUY-001 PAY-002 PAY-003 PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 QUA-004 */
    final class InitialProvisioningQueueContentionVerificationTest extends TestCase
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

        public function test_concurrent_duplicate_queueing_converges_to_one_service_operation_and_outbox_command(): void
        {
            $settlement = $this->createPurchaseOrderSettlement('queue-contention');
            $order = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
                $settlement->settlementPublicId,
                $this->purchaseOrderCorrelation('queue-contention-order'),
            );
            $payload = [
                'mode' => 'queue',
                'order_public_id' => $order->orderPublicId,
                'correlation_id' => $this->purchaseOrderCorrelation('queue-contention-worker'),
            ];

            $results = $this->runConcurrent([$payload, $payload]);

            self::assertTrue($results[0]['ok'], json_encode($results[0], JSON_THROW_ON_ERROR));
            self::assertTrue($results[1]['ok'], json_encode($results[1], JSON_THROW_ON_ERROR));
            self::assertSame($results[0]['result']['service_public_id'], $results[1]['result']['service_public_id']);
            self::assertSame($results[0]['result']['operation_public_id'], $results[1]['result']['operation_public_id']);
            self::assertSame($results[0]['result']['outbox_event_id'], $results[1]['result']['outbox_event_id']);
            self::assertContains(true, [$results[0]['result']['replayed'], $results[1]['result']['replayed']]);
            self::assertSame(1, DB::table('service_subscriptions')->count());
            self::assertSame(1, DB::table('provisioning_operations')->count());
            self::assertSame(1, DB::table('provisioning_operation_histories')->count());
            self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'provisioning.initial.requested')->count());
            self::assertSame(OrderState::ProvisioningQueued->value, DB::table('orders')->where('id', $order->orderId)->value('state'));
            self::assertSame(2, (int) DB::table('orders')->where('id', $order->orderId)->value('state_version'));
        }

        public function test_queue_and_refund_contention_serializes_to_a_safe_authoritative_result_without_duplicate_effect(): void
        {
            $settlement = $this->createPurchaseOrderSettlement('queue-refund-contention');
            $order = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
                $settlement->settlementPublicId,
                $this->purchaseOrderCorrelation('queue-refund-contention-order'),
            );
            $occurredAt = $this->purchaseOrderClock->value->modify('+5 minutes');

            $queuePayload = [
                'mode' => 'queue',
                'order_public_id' => $order->orderPublicId,
                'correlation_id' => $this->purchaseOrderCorrelation('queue-race'),
            ];
            $refundPayload = [
                'mode' => 'refund',
                'refund_key' => 'provisioning-refund-race',
                'settlement_public_id' => $settlement->settlementPublicId,
                'provider_code' => $settlement->providerCode,
                'provider_event_id' => 'evt-provisioning-refund-race',
                'event_payload_hash' => hash('sha256', 'provisioning-refund-race-event'),
                'provider_refund_id' => 'refund-txn-race',
                'evidence_payload_hash' => hash('sha256', 'provisioning-refund-race-evidence'),
                'amount_irr' => (string) $settlement->amount->amount(),
                'occurred_at' => $occurredAt->format(DATE_ATOM),
                'correlation_id' => $this->purchaseOrderCorrelation('refund-race'),
            ];

            $results = $this->runConcurrent([$queuePayload, $refundPayload]);
            $queue = $results[0];
            $refund = $results[1];

            self::assertTrue($refund['ok'], json_encode($refund, JSON_THROW_ON_ERROR));
            self::assertSame(PaymentIntentState::Refunded->value, $refund['result']['state']);
            self::assertSame(PaymentIntentState::Refunded->value, DB::table('payment_intents')->where('public_id', $settlement->intentPublicId)->value('state'));

            if ($queue['ok']) {
                self::assertSame(1, DB::table('service_subscriptions')->count());
                self::assertSame(1, DB::table('provisioning_operations')->count());
                self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'provisioning.initial.requested')->count());
                self::assertSame(OrderState::ProvisioningQueued->value, DB::table('orders')->where('id', $order->orderId)->value('state'));
            } else {
                self::assertSame(DomainException::class, $queue['exception'], json_encode($queue, JSON_THROW_ON_ERROR));
                self::assertSame(0, DB::table('service_subscriptions')->count());
                self::assertSame(0, DB::table('provisioning_operations')->count());
                self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'provisioning.initial.requested')->count());
                self::assertSame(OrderState::Paid->value, DB::table('orders')->where('id', $order->orderId)->value('state'));
            }

            self::assertLessThanOrEqual(1, DB::table('service_subscriptions')->count());
            self::assertLessThanOrEqual(1, DB::table('provisioning_operations')->count());
            self::assertSame(1, DB::table('purchase_refunds')->count());
        }

        /**
         * @param  list<array<string, string>>  $payloads
         * @return list<array<string, mixed>>
         */
        private function runConcurrent(array $payloads): array
        {
            $workers = [];
            try {
                foreach ($payloads as $payload) {
                    $pipes = [];
                    $process = proc_open([
                        PHP_BINARY,
                        '-d',
                        'pcov.enabled=0',
                        __FILE__,
                        '--initial-provisioning-contention-worker',
                        base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                    ], [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ], $pipes, dirname(__DIR__, 2));
                    if (! is_resource($process)) {
                        throw new RuntimeException('Unable to start initial provisioning contention worker.');
                    }
                    /** @var array{0:resource,1:resource,2:resource} $pipes */
                    stream_set_blocking($pipes[1], false);
                    stream_set_blocking($pipes[2], false);
                    $workers[] = ['process' => $process, 'pipes' => $pipes];
                }

                foreach ($workers as $index => $worker) {
                    if ($this->readLine($worker, 'readiness', $index) !== "READY\n") {
                        throw new RuntimeException('Initial provisioning contention worker returned an invalid readiness marker.');
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
                        throw new RuntimeException('Initial provisioning contention worker failed: '.$stderr);
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
                    throw new RuntimeException('Unable to wait for initial provisioning contention worker output.');
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
                    throw new RuntimeException('Initial provisioning contention worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException(sprintf(
                'Initial provisioning contention worker %d timed out during %s after %d seconds: %s',
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
