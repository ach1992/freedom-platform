<?php

declare(strict_types=1);

namespace {
    use App\Modules\Payments\Application\Contracts\PaymentEvidence;
    use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
    use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
    use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
    use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
    use App\Modules\Payments\Application\PurchaseRefundService;
    use App\Shared\Domain\Money;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\DatabaseManager;

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--purchase-refund-contention-worker') {
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
            $eventId = (string) $payload['provider_event_id'];
            $event = new VerifiedPaymentEvent(
                $eventId,
                (string) $payload['event_payload_hash'],
                new PaymentEvidence(
                    ProviderOperationOutcome::Success,
                    PaymentEvidenceAuthority::Authoritative,
                    PaymentTransactionStatus::Refunded,
                    (string) $payload['provider_refund_id'],
                    $eventId,
                    Money::irr((int) $payload['amount_irr']),
                    new DateTimeImmutable((string) $payload['occurred_at']),
                    null,
                    (string) $payload['evidence_payload_hash'],
                    ['provider_reference' => (string) $payload['provider_refund_id']],
                ),
            );
            $receipt = $app->make(PurchaseRefundService::class)->record(
                (string) $payload['refund_key'],
                (string) $payload['settlement_public_id'],
                (string) $payload['provider_code'],
                $event,
                (string) $payload['correlation_id'],
            );
            echo json_encode([
                'ok' => true,
                'result' => [
                    'refund_id' => $receipt->refundId,
                    'cumulative_refunded_irr' => $receipt->cumulativeRefunded->amount(),
                    'state' => $receipt->state->value,
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
    use App\Modules\Orders\Application\QuotePricingInput;
    use App\Modules\Orders\Application\QuoteService;
    use App\Modules\Orders\Domain\QuoteOverrideSource;
    use App\Modules\Payments\Application\Contracts\PaymentEvidence;
    use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
    use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
    use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
    use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
    use App\Modules\Payments\Application\PurchasePaymentIntentService;
    use App\Modules\Payments\Application\PurchaseSettlementReceipt;
    use App\Modules\Payments\Application\PurchaseSettlementService;
    use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
    use App\Shared\Application\Clock;
    use App\Shared\Domain\Money;
    use Database\Seeders\CatalogAccessFoundationSeeder;
    use Database\Seeders\IdentityAccessFoundationSeeder;
    use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
    use DateTimeImmutable;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\TestCase;

    final class PurchaseRefundContentionClock implements Clock
    {
        public function __construct(public DateTimeImmutable $value) {}

        public function now(): DateTimeImmutable
        {
            return $this->value;
        }
    }

    /** @requirement PAY-002 PAY-003 WAL-004 DAT-002 DAT-003 QUA-004 */
    final class PurchaseRefundContentionVerificationTest extends TestCase
    {
        use AgentPricingQuoteIntegrationTestSupport;
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 20;

        private PurchaseRefundContentionClock $clock;

        protected function setUp(): void
        {
            parent::setUp();
            $this->seed(IdentityAccessFoundationSeeder::class);
            $this->seed(CatalogAccessFoundationSeeder::class);
            $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
            $this->clock = new PurchaseRefundContentionClock(new DateTimeImmutable('2026-08-14T05:00:00+00:00'));
            $this->app->instance(Clock::class, $this->clock);
        }

        public function test_concurrent_duplicate_refund_creates_once_and_replays_once(): void
        {
            [$settlement, $providerCode] = $this->capturePurchase('duplicate');
            $amount = intdiv($settlement->amount->amount(), 2);
            self::assertGreaterThan(0, $amount);
            $payload = $this->payload('duplicate', $settlement, $providerCode, $amount, 'purchase.refund.contention.duplicate.000001');

            $results = $this->runConcurrent([$payload, $payload]);

            self::assertTrue($results[0]['ok']);
            self::assertTrue($results[1]['ok']);
            self::assertSame($results[0]['result']['refund_id'], $results[1]['result']['refund_id']);
            $replayed = [(bool) $results[0]['result']['replayed'], (bool) $results[1]['result']['replayed']];
            sort($replayed);
            self::assertSame([false, true], $replayed);
            self::assertSame(1, DB::table('purchase_refunds')->count());
            self::assertSame('partially_refunded', DB::table('payment_intents')->where('public_id', $settlement->intentPublicId)->value('state'));
        }

        public function test_competing_partial_refunds_serialize_at_captured_amount_boundary(): void
        {
            [$settlement, $providerCode] = $this->capturePurchase('cap');
            $total = $settlement->amount->amount();
            $amount = intdiv($total * 7, 10);
            self::assertGreaterThan(0, $amount);
            self::assertLessThan($total, $amount);

            $results = $this->runConcurrent([
                $this->payload('cap-a', $settlement, $providerCode, $amount, 'purchase.refund.contention.cap.000001'),
                $this->payload('cap-b', $settlement, $providerCode, $amount, 'purchase.refund.contention.cap.000002'),
            ]);

            $successes = array_values(array_filter($results, static fn (array $result): bool => $result['ok'] === true));
            $failures = array_values(array_filter($results, static fn (array $result): bool => $result['ok'] === false));
            self::assertCount(1, $successes);
            self::assertCount(1, $failures);
            self::assertSame('Purchase refund would exceed authoritative refundable captured amount.', $failures[0]['message']);
            self::assertSame(1, DB::table('purchase_refunds')->count());
            self::assertSame($amount, (int) DB::table('purchase_refunds')->sum('amount_irr'));
            self::assertSame('partially_refunded', DB::table('payment_intents')->where('public_id', $settlement->intentPublicId)->value('state'));
        }

        /** @return array<string, mixed> */
        private function payload(
            string $suffix,
            PurchaseSettlementReceipt $settlement,
            string $providerCode,
            int $amountIrr,
            string $refundKey,
        ): array {
            $eventId = 'purchase-refund-contention-event-'.$suffix;

            return [
                'refund_key' => $refundKey,
                'settlement_public_id' => $settlement->settlementPublicId,
                'provider_code' => $providerCode,
                'provider_event_id' => $eventId,
                'event_payload_hash' => hash('sha256', 'purchase-refund-contention-event-payload:'.$suffix),
                'provider_refund_id' => 'purchase-refund-contention-transaction-'.$suffix,
                'amount_irr' => $amountIrr,
                'occurred_at' => $this->clock->value->modify('+1 hour')->format(DATE_ATOM),
                'evidence_payload_hash' => hash('sha256', 'purchase-refund-contention-evidence:'.$suffix),
                'correlation_id' => hash('sha256', 'purchase-refund-contention-correlation:'.$suffix),
            ];
        }

        /** @return array{0:PurchaseSettlementReceipt,1:string} */
        private function capturePurchase(string $suffix): array
        {
            $userId = $this->quoteUser('customer');
            $administratorId = $this->ownerAdministrator();
            $offering = $this->quoteOffering();
            $quote = $this->app->make(QuoteService::class)->create(
                'purchase.refund.contention.quote.'.$suffix,
                $userId,
                $offering['id'],
                new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->value->modify('+30 minutes')),
                hash('sha256', 'purchase-refund-contention:quote:'.$suffix),
            );
            $methodCode = 'purchase_refund_contention_gateway_'.$suffix;
            $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
            $eligibility->configureMethod(
                'purchase.refund.contention.method.'.$suffix,
                $administratorId,
                $methodCode,
                true,
                false,
                1,
                'Purchase refund contention test configuration.',
                hash('sha256', 'purchase-refund-contention:method:'.$suffix),
            );
            $eligibility->recordHealth(
                'purchase.refund.contention.health.'.$suffix,
                $administratorId,
                $methodCode,
                true,
                $this->clock->value->modify('+10 minutes'),
                'Healthy purchase refund contention observation.',
                hash('sha256', 'purchase-refund-contention:health:'.$suffix),
            );
            $decision = $eligibility->evaluate(
                'purchase.refund.contention.eligibility.'.$suffix,
                $userId,
                $quote->quotePublicId,
            );
            $purchase = $this->app->make(PurchasePaymentIntentService::class)->create(
                'purchase.refund.contention.intent.'.$suffix,
                $userId,
                $quote->quotePublicId,
                $decision->publicId,
                $methodCode,
                hash('sha256', 'purchase-refund-contention:intent:'.$suffix),
            );
            DB::table('payment_intents')->where('public_id', $purchase->intentPublicId)->update([
                'state' => 'submitted',
                'updated_at' => $this->clock->value->format('Y-m-d H:i:s.u'),
            ]);
            $captureEventId = 'purchase-refund-contention-capture-event-'.$suffix;
            $settlement = $this->app->make(PurchaseSettlementService::class)->capture(
                $purchase->intentPublicId,
                $methodCode,
                new VerifiedPaymentEvent(
                    $captureEventId,
                    hash('sha256', 'purchase-refund-contention-capture-event-payload:'.$suffix),
                    new PaymentEvidence(
                        ProviderOperationOutcome::Success,
                        PaymentEvidenceAuthority::Authoritative,
                        PaymentTransactionStatus::Settled,
                        'purchase-refund-contention-capture-transaction-'.$suffix,
                        $captureEventId,
                        Money::irr($purchase->amount->amount()),
                        $this->clock->value,
                        $this->clock->value,
                        hash('sha256', 'purchase-refund-contention-capture-evidence:'.$suffix),
                        ['provider_reference' => 'purchase-refund-contention-capture-transaction-'.$suffix],
                    ),
                ),
                hash('sha256', 'purchase-refund-contention:capture:'.$suffix),
            );

            return [$settlement, $methodCode];
        }

        /**
         * @param  list<array<string, mixed>>  $payloads
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
                        '--purchase-refund-contention-worker',
                        base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                    ], [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ], $pipes, dirname(__DIR__, 2));
                    if (! is_resource($process)) {
                        throw new RuntimeException('Unable to start purchase refund contention worker.');
                    }
                    /** @var array{0:resource,1:resource,2:resource} $pipes */
                    stream_set_blocking($pipes[1], false);
                    stream_set_blocking($pipes[2], false);
                    $workers[] = ['process' => $process, 'pipes' => $pipes];
                }

                foreach ($workers as $index => $worker) {
                    if ($this->readLine($worker, 'readiness', $index) !== "READY\n") {
                        throw new RuntimeException('Purchase refund contention worker returned an invalid readiness marker.');
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
                        throw new RuntimeException('Purchase refund contention worker failed: '.$stderr);
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
                    throw new RuntimeException('Unable to wait for purchase refund contention worker output.');
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
                    throw new RuntimeException('Purchase refund contention worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException(sprintf(
                'Purchase refund contention worker %d timed out during %s after %d seconds: %s',
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
