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

    require_once dirname(__DIR__, 2).'/vendor/autoload.php';

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--purchase-order-prepayment-contention-worker') {
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
            if ($payload['operation'] === 'open') {
                $receipt = $app->make(PurchaseOrderService::class)->openFromQuote(
                    $payload['quote_public_id'],
                    (int) $payload['user_id'],
                    $payload['correlation_id'],
                );
                $result = [
                    'order_id' => $receipt->orderId,
                    'order_public_id' => $receipt->orderPublicId,
                    'item_public_id' => $receipt->orderItemPublicId,
                    'state' => $receipt->state->value,
                    'state_version' => $receipt->stateVersion,
                    'replayed' => $receipt->replayed,
                ];
            } elseif ($payload['operation'] === 'materialize') {
                $receipt = $app->make(PurchaseOrderService::class)->createFromSettlement(
                    $payload['settlement_public_id'],
                    $payload['correlation_id'],
                );
                $result = [
                    'order_id' => $receipt->orderId,
                    'order_public_id' => $receipt->orderPublicId,
                    'item_public_id' => $receipt->orderItemPublicId,
                    'state' => $receipt->state->value,
                    'state_version' => $receipt->stateVersion,
                    'replayed' => $receipt->replayed,
                ];
            } elseif ($payload['operation'] === 'capture') {
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
                    'intent_public_id' => $receipt->intentPublicId,
                    'replayed' => $receipt->replayed,
                ];
            } else {
                throw new RuntimeException('Unknown contention worker operation.');
            }

            echo json_encode([
                'ok' => true,
                'operation' => $payload['operation'],
                'result' => $result,
            ], JSON_THROW_ON_ERROR)."\n";
        } catch (Throwable $exception) {
            echo json_encode([
                'ok' => false,
                'operation' => $payload['operation'] ?? null,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR)."\n";
        }
        exit(0);
    }
}

namespace Tests\Feature {
    use App\Modules\Orders\Application\QuotePricingInput;
    use App\Modules\Orders\Application\QuoteService;
    use App\Modules\Orders\Domain\OrderState;
    use App\Modules\Orders\Domain\QuoteOverrideSource;
    use App\Modules\Panels\Application\TargetCapacityAllocator;
    use App\Modules\Payments\Application\PurchasePaymentIntentService;
    use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
    use Database\Seeders\CatalogAccessFoundationSeeder;
    use Database\Seeders\IdentityAccessFoundationSeeder;
    use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
    use DateTimeImmutable;
    use DateTimeZone;
    use Illuminate\Database\QueryException;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\TestCase;

    /** @requirement BUY-001 BUY-002 PAY-001 PAY-002 DAT-002 DAT-003 DAT-004 QUA-001 QUA-004 */
    final class PurchaseOrderPrePaymentContentionVerificationTest extends TestCase
    {
        use AgentPricingQuoteIntegrationTestSupport;
        use DatabaseTruncation;
        use PurchaseOrderTestSupport;

        private const WORKER_TIMEOUT_SECONDS = 30;

        protected function setUp(): void
        {
            parent::setUp();
            if (DB::connection()->getDriverName() !== 'mysql') {
                $this->markTestSkipped('Real-process pre-payment contention verification requires MariaDB/MySQL.');
            }

            $this->seed(IdentityAccessFoundationSeeder::class);
            $this->seed(CatalogAccessFoundationSeeder::class);
            $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
            $this->bootPurchaseOrderClock();

            // Child workers use the production SystemClock and independent DB sessions. Align the
            // parent fixture with wall clock so every process observes the same current Quote window.
            $this->purchaseOrderClock->value = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            DB::statement('SET timestamp = '.$this->purchaseOrderClock->value->getTimestamp());
            $this->app->forgetInstance(TargetCapacityAllocator::class);
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

        public function test_concurrent_open_from_quote_converges_to_one_identity(): void
        {
            [$userId, $quotePublicId] = $this->preparePurchaseQuote('open-race');
            $payload = [
                'operation' => 'open',
                'quote_public_id' => $quotePublicId,
                'user_id' => (string) $userId,
            ];

            $results = $this->runConcurrent([
                $payload + ['correlation_id' => $this->purchaseOrderCorrelation('open-race-a')],
                $payload + ['correlation_id' => $this->purchaseOrderCorrelation('open-race-b')],
            ]);

            self::assertTrue($results[0]['ok'], json_encode($results[0], JSON_THROW_ON_ERROR));
            self::assertTrue($results[1]['ok'], json_encode($results[1], JSON_THROW_ON_ERROR));
            self::assertSame($results[0]['result']['order_id'], $results[1]['result']['order_id']);
            self::assertSame($results[0]['result']['order_public_id'], $results[1]['result']['order_public_id']);
            self::assertSame($results[0]['result']['item_public_id'], $results[1]['result']['item_public_id']);
            self::assertContains(true, [$results[0]['result']['replayed'], $results[1]['result']['replayed']]);
            self::assertSame(1, DB::table('orders')->count());
            self::assertSame(1, DB::table('order_items')->count());
            self::assertSame(1, DB::table('order_state_histories')->count());
            self::assertSame(1, DB::table('audit_logs')->where('action', 'order.purchase.opened')->count());
            self::assertSame(OrderState::AwaitingPayment->value, DB::table('orders')->value('state'));
            self::assertSame(0, (int) DB::table('orders')->value('state_version'));
        }

        public function test_open_from_quote_racing_settlement_materialization_converges_to_one_valid_order(): void
        {
            $settlement = $this->createPurchaseOrderSettlement('open-settlement-race');
            $settlementRow = DB::table('purchase_settlements')->where('id', $settlement->settlementId)->first();
            self::assertNotNull($settlementRow);

            $results = $this->runConcurrent([
                [
                    'operation' => 'open',
                    'quote_public_id' => $settlement->sourceQuotePublicId,
                    'user_id' => (string) $settlementRow->user_id,
                    'correlation_id' => $this->purchaseOrderCorrelation('open-settlement-race-open'),
                ],
                [
                    'operation' => 'materialize',
                    'settlement_public_id' => $settlement->settlementPublicId,
                    'correlation_id' => $this->purchaseOrderCorrelation('open-settlement-race-materialize'),
                ],
            ]);

            self::assertTrue($results[0]['ok'], json_encode($results[0], JSON_THROW_ON_ERROR));
            self::assertTrue($results[1]['ok'], json_encode($results[1], JSON_THROW_ON_ERROR));
            self::assertSame($results[0]['result']['order_id'], $results[1]['result']['order_id']);
            self::assertSame($results[0]['result']['order_public_id'], $results[1]['result']['order_public_id']);
            self::assertSame($results[0]['result']['item_public_id'], $results[1]['result']['item_public_id']);

            $order = DB::table('orders')->first();
            self::assertNotNull($order);
            self::assertSame(OrderState::Paid->value, $order->state);
            self::assertSame(1, (int) $order->state_version);
            self::assertSame($settlement->settlementId, (int) $order->purchase_settlement_id);
            self::assertSame(1, DB::table('orders')->count());
            self::assertSame(1, DB::table('order_items')->count());
            self::assertContains(DB::table('order_state_histories')->count(), [1, 2]);
            self::assertSame(
                DB::table('order_state_histories')->where('order_id', $order->id)->count(),
                DB::table('order_state_histories')->where('order_id', $order->id)->distinct()->count('to_version'),
            );
        }

        public function test_two_intents_racing_authoritative_capture_produce_one_winner_and_fail_closed_loser(): void
        {
            [$userId, $quotePublicId, $methodCode, $eligibilityPublicId] = $this->preparePurchaseQuote('capture-race');
            $payments = $this->app->make(PurchasePaymentIntentService::class);
            $first = $payments->create(
                'purchase.order.contention.intent.first',
                $userId,
                $quotePublicId,
                $eligibilityPublicId,
                $methodCode,
                $this->purchaseOrderCorrelation('capture-race-intent-first'),
            );
            $second = $payments->create(
                'purchase.order.contention.intent.second',
                $userId,
                $quotePublicId,
                $eligibilityPublicId,
                $methodCode,
                $this->purchaseOrderCorrelation('capture-race-intent-second'),
            );
            DB::table('payment_intents')->whereIn('public_id', [$first->intentPublicId, $second->intentPublicId])->update([
                'state' => 'submitted',
                'updated_at' => $this->purchaseOrderTimestamp(),
            ]);

            $results = $this->runConcurrent([
                $this->capturePayload($first->intentPublicId, $methodCode, $first->amount->amount(), 'capture-race-first'),
                $this->capturePayload($second->intentPublicId, $methodCode, $second->amount->amount(), 'capture-race-second'),
            ]);

            $successes = array_values(array_filter($results, static fn (array $result): bool => $result['ok'] === true));
            $failures = array_values(array_filter($results, static fn (array $result): bool => $result['ok'] === false));
            self::assertCount(1, $successes, json_encode($results, JSON_THROW_ON_ERROR));
            self::assertCount(1, $failures, json_encode($results, JSON_THROW_ON_ERROR));
            self::assertTrue(
                is_a((string) $failures[0]['exception'], QueryException::class, true),
                json_encode($failures[0], JSON_THROW_ON_ERROR),
            );

            $quoteId = (int) DB::table('quotes')->where('public_id', $quotePublicId)->value('id');
            self::assertSame(1, DB::table('purchase_settlements')->where('source_quote_id', $quoteId)->count());
            self::assertSame(1, DB::table('payment_intents')
                ->whereIn('public_id', [$first->intentPublicId, $second->intentPublicId])
                ->where('state', 'captured')
                ->count());
        }

        /** @return array{0:int,1:string,2:string,3:string} */
        private function preparePurchaseQuote(string $suffix): array
        {
            $methodCode = 'order_contention_'.$suffix;
            $administratorId = $this->ownerAdministrator();
            $userId = $this->quoteUser('customer');
            $offering = $this->quoteOffering();
            $quote = $this->app->make(QuoteService::class)->create(
                'purchase.order.contention.quote.'.$suffix,
                $userId,
                $offering['id'],
                new QuotePricingInput(
                    QuoteOverrideSource::None,
                    null,
                    null,
                    null,
                    0,
                    $this->purchaseOrderClock->value->modify('+30 minutes'),
                ),
                $this->purchaseOrderCorrelation('contention-quote-'.$suffix),
            );

            $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
            $eligibility->configureMethod(
                'purchase.order.contention.method.'.$suffix,
                $administratorId,
                $methodCode,
                true,
                false,
                1,
                'Purchase Order contention authority test method.',
                $this->purchaseOrderCorrelation('contention-method-'.$suffix),
            );
            $eligibility->recordHealth(
                'purchase.order.contention.health.'.$suffix,
                $administratorId,
                $methodCode,
                true,
                $this->purchaseOrderClock->value->modify('+10 minutes'),
                'Healthy Purchase Order contention observation.',
                $this->purchaseOrderCorrelation('contention-health-'.$suffix),
            );
            $decision = $eligibility->evaluate(
                'purchase.order.contention.eligibility.'.$suffix,
                $userId,
                $quote->quotePublicId,
            );

            return [$userId, $quote->quotePublicId, $methodCode, $decision->publicId];
        }

        /** @return array<string, string> */
        private function capturePayload(string $intentPublicId, string $providerCode, int $amountIrr, string $suffix): array
        {
            return [
                'operation' => 'capture',
                'intent_public_id' => $intentPublicId,
                'provider_code' => $providerCode,
                'provider_event_id' => 'evt-order-contention-'.$suffix,
                'provider_transaction_id' => 'txn-order-contention-'.$suffix,
                'provider_event_payload_hash' => hash('sha256', 'purchase-order-contention-event:'.$suffix),
                'evidence_payload_hash' => hash('sha256', 'purchase-order-contention-evidence:'.$suffix),
                'amount_irr' => (string) $amountIrr,
                'settled_at' => $this->purchaseOrderClock->value->format(DATE_ATOM),
                'correlation_id' => $this->purchaseOrderCorrelation('contention-settlement-'.$suffix),
            ];
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
                        '--purchase-order-prepayment-contention-worker',
                        base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                    ], [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ], $pipes, dirname(__DIR__, 2));
                    if (! is_resource($process)) {
                        throw new RuntimeException('Unable to start Purchase Order pre-payment contention worker.');
                    }
                    /** @var array{0:resource,1:resource,2:resource} $pipes */
                    stream_set_blocking($pipes[1], false);
                    stream_set_blocking($pipes[2], false);
                    $workers[] = ['process' => $process, 'pipes' => $pipes];
                }

                foreach ($workers as $index => $worker) {
                    self::assertSame("READY\n", $this->readLine($worker, 'readiness', $index));
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
                        throw new RuntimeException('Purchase Order pre-payment contention worker failed: '.$stderr);
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
                    throw new RuntimeException('Unable to wait for Purchase Order pre-payment contention worker output.');
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
                    throw new RuntimeException('Purchase Order pre-payment contention worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException(sprintf(
                'Purchase Order pre-payment contention worker %d timed out during %s after %d seconds: %s',
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
