<?php

declare(strict_types=1);

namespace {
    use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalInquiryResult;
    use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalRequestResult;
    use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalTransport;
    use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalVerifyResult;
    use App\Modules\Payments\Zarinpal\Application\ZarinpalPaymentService;
    use Illuminate\Contracts\Console\Kernel;

    final class ZarinpalRequestContentionTransport implements ZarinpalTransport
    {
        public function __construct(private string $counterPath) {}

        public function request(string $merchantId, int $amountIrr, string $callbackUrl, string $description, string $orderId): ZarinpalRequestResult
        {
            $handle = fopen($this->counterPath, 'c+');
            if ($handle === false || ! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock Zarinpal contention counter.');
            }
            rewind($handle);
            $count = (int) trim((string) stream_get_contents($handle));
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) ($count + 1));
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);
            usleep(300_000);

            return ZarinpalRequestResult::accepted('A'.str_repeat('7', 35));
        }

        public function verify(string $merchantId, int $amountIrr, string $authority): ZarinpalVerifyResult
        {
            throw new RuntimeException('Verify is not expected in request contention test.');
        }

        public function inquiry(string $merchantId, string $authority): ZarinpalInquiryResult
        {
            throw new RuntimeException('Inquiry is not expected in request contention test.');
        }

        public function unverified(string $merchantId): array
        {
            return [];
        }
    }

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--zarinpal-request-contention-worker') {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
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

        config()->set('app.url', 'http://localhost');
        config()->set('services.zarinpal.enabled', true);
        config()->set('services.zarinpal.merchant_id', '00000000-0000-0000-0000-000000000000');
        config()->set('services.zarinpal.callback_url', 'http://localhost/payments/zarinpal/callback');
        $app->instance(ZarinpalTransport::class, new ZarinpalRequestContentionTransport($payload['counter_path']));

        echo "READY\n";
        flush();
        if (fgets(STDIN) === false) {
            fwrite(STDERR, "Worker barrier was not released.\n");
            exit(2);
        }

        try {
            $receipt = $app->make(ZarinpalPaymentService::class)->initiate(
                $payload['request_key'],
                $payload['intent_public_id'],
                $payload['correlation_id'],
            );
            echo json_encode([
                'ok' => true,
                'result' => [
                    'request_id' => $receipt->requestId,
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
    use App\Modules\Payments\Application\PurchasePaymentIntentService;
    use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
    use Database\Seeders\CatalogAccessFoundationSeeder;
    use Database\Seeders\IdentityAccessFoundationSeeder;
    use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\TestCase;

    /** @requirement IPG-001 PAY-002 PAY-003 DAT-003 INT-001 INT-002 QUA-004 */
    final class ZarinpalRequestContentionVerificationTest extends TestCase
    {
        use AgentPricingQuoteIntegrationTestSupport;
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 20;

        protected function setUp(): void
        {
            parent::setUp();
            $this->seed(IdentityAccessFoundationSeeder::class);
            $this->seed(CatalogAccessFoundationSeeder::class);
            $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        }

        public function test_concurrent_initiation_claims_one_external_request_mutation(): void
        {
            $intentPublicId = $this->purchaseIntent();
            $counterPath = storage_path('framework/testing/zarinpal-request-counter-'.bin2hex(random_bytes(8)));
            if (! is_dir(dirname($counterPath))) {
                mkdir(dirname($counterPath), 0777, true);
            }
            file_put_contents($counterPath, '0');
            $payload = [
                'request_key' => 'zarinpal.request.contention.000001',
                'intent_public_id' => $intentPublicId,
                'correlation_id' => hash('sha256', 'zarinpal-request-contention'),
                'counter_path' => $counterPath,
            ];

            try {
                $results = $this->runConcurrent([$payload, $payload]);
                self::assertTrue($results[0]['ok'], json_encode($results[0], JSON_THROW_ON_ERROR));
                self::assertTrue($results[1]['ok'], json_encode($results[1], JSON_THROW_ON_ERROR));
                self::assertSame($results[0]['result']['request_id'], $results[1]['result']['request_id']);
                self::assertSame(1, (int) trim((string) file_get_contents($counterPath)));
                self::assertSame(1, DB::table('zarinpal_payment_requests')->count());
                self::assertSame('redirectable', DB::table('zarinpal_payment_requests')->value('state'));
                self::assertSame('awaiting_user_action', DB::table('payment_intents')->where('public_id', $intentPublicId)->value('state'));
            } finally {
                @unlink($counterPath);
            }
        }

        private function purchaseIntent(): string
        {
            $userId = $this->quoteUser('customer');
            $administratorId = $this->ownerAdministrator();
            $offering = $this->quoteOffering();
            $quote = $this->app->make(QuoteService::class)->create(
                'zarinpal.contention.quote',
                $userId,
                $offering['id'],
                new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, now('UTC')->addMinutes(30)->toDateTimeImmutable()),
                hash('sha256', 'zarinpal-contention-quote'),
            );
            $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
            $eligibility->configureMethod(
                'zarinpal.contention.method',
                $administratorId,
                'zarinpal',
                true,
                false,
                1,
                'Zarinpal contention test configuration.',
                hash('sha256', 'zarinpal-contention-method'),
            );
            $eligibility->recordHealth(
                'zarinpal.contention.health',
                $administratorId,
                'zarinpal',
                true,
                now('UTC')->addMinutes(10)->toDateTimeImmutable(),
                'Healthy Zarinpal contention observation.',
                hash('sha256', 'zarinpal-contention-health'),
            );
            $decision = $eligibility->evaluate('zarinpal.contention.eligibility', $userId, $quote->quotePublicId);
            $intent = $this->app->make(PurchasePaymentIntentService::class)->create(
                'zarinpal.contention.intent',
                $userId,
                $quote->quotePublicId,
                $decision->publicId,
                'zarinpal',
                hash('sha256', 'zarinpal-contention-intent'),
            );

            return $intent->intentPublicId;
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
                        '--zarinpal-request-contention-worker',
                        base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                    ], [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ], $pipes, dirname(__DIR__, 2));
                    if (! is_resource($process)) {
                        throw new RuntimeException('Unable to start Zarinpal request contention worker.');
                    }
                    /** @var array{0:resource,1:resource,2:resource} $pipes */
                    stream_set_blocking($pipes[1], false);
                    stream_set_blocking($pipes[2], false);
                    $workers[] = ['process' => $process, 'pipes' => $pipes];
                }

                foreach ($workers as $index => $worker) {
                    if ($this->readLine($worker, 'readiness', $index) !== "READY\n") {
                        throw new RuntimeException('Zarinpal request contention worker returned an invalid readiness marker.');
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
                        throw new RuntimeException('Zarinpal request contention worker failed: '.$stderr);
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
                    throw new RuntimeException('Unable to wait for Zarinpal request contention worker output.');
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
                    throw new RuntimeException('Zarinpal request contention worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException(sprintf(
                'Zarinpal request contention worker %d timed out during %s after %d seconds: %s',
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
