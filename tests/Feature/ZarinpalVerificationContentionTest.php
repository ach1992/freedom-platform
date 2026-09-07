<?php

declare(strict_types=1);

namespace {
    use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalInquiryResult;
    use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalRequestResult;
    use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalTransport;
    use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalVerifyResult;
    use App\Modules\Payments\Zarinpal\Application\ZarinpalPaymentService;
    use Illuminate\Contracts\Console\Kernel;

    require_once dirname(__DIR__, 2).'/vendor/autoload.php';

    final class ZarinpalVerificationContentionSetupTransport implements ZarinpalTransport
    {
        public function request(string $merchantId, int $amountIrr, string $callbackUrl, string $description, string $orderId): ZarinpalRequestResult
        {
            return ZarinpalRequestResult::accepted('A'.str_repeat('9', 35));
        }

        public function verify(string $merchantId, int $amountIrr, string $authority): ZarinpalVerifyResult
        {
            throw new RuntimeException('Parent verification transport must not verify provider state.');
        }

        public function inquiry(string $merchantId, string $authority): ZarinpalInquiryResult
        {
            return ZarinpalInquiryResult::available('PAID');
        }

        public function unverified(string $merchantId): array
        {
            return [];
        }
    }

    final class ZarinpalVerificationContentionWorkerTransport implements ZarinpalTransport
    {
        /** @param array<string,string> $payload */
        public function __construct(private array $payload) {}

        public function request(string $merchantId, int $amountIrr, string $callbackUrl, string $description, string $orderId): ZarinpalRequestResult
        {
            throw new RuntimeException('Provider request is not expected in verification contention worker.');
        }

        public function verify(string $merchantId, int $amountIrr, string $authority): ZarinpalVerifyResult
        {
            file_put_contents($this->payload['marker_path'], 'VERIFY_REACHED');
            $deadline = microtime(true) + 15;
            while (! is_file($this->payload['release_path'])) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Timed out waiting for verification-result release.');
                }
                usleep(20_000);
            }

            return match ($this->payload['verify_result']) {
                'verified' => ZarinpalVerifyResult::verified(
                    $this->payload['provider_ref_id'],
                    (int) $this->payload['provider_code'],
                ),
                'rejected' => ZarinpalVerifyResult::rejected((int) $this->payload['provider_code']),
                'uncertain' => ZarinpalVerifyResult::uncertain(),
                default => throw new RuntimeException('Unsupported verification contention result.'),
            };
        }

        public function inquiry(string $merchantId, string $authority): ZarinpalInquiryResult
        {
            throw new RuntimeException('Inquiry is not expected in verification contention worker.');
        }

        public function unverified(string $merchantId): array
        {
            return [];
        }
    }

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--zarinpal-verification-contention-worker') {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $decoded = base64_decode($argv[2] ?? '', true);
        if ($decoded === false) {
            fwrite(STDERR, "Invalid worker payload encoding.\n");
            exit(2);
        }

        try {
            /** @var array<string,string> $payload */
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Invalid worker payload: '.$exception->getMessage()."\n");
            exit(2);
        }

        config()->set('app.url', 'http://localhost');
        config()->set('services.zarinpal.enabled', true);
        config()->set('services.zarinpal.merchant_id', '00000000-0000-0000-0000-000000000000');
        config()->set('services.zarinpal.callback_url', 'http://localhost/payments/zarinpal/callback');
        $app->instance(ZarinpalTransport::class, new ZarinpalVerificationContentionWorkerTransport($payload));

        echo "READY\n";
        flush();
        if (fgets(STDIN) === false) {
            fwrite(STDERR, "Worker barrier was not released.\n");
            exit(2);
        }

        try {
            $receipt = $app->make(ZarinpalPaymentService::class)->handleCallback(
                $payload['authority'],
                'OK',
                $payload['correlation_id'],
            );
            echo json_encode([
                'ok' => true,
                'result' => [
                    'state' => $receipt->state->value,
                    'replayed' => $receipt->replayed,
                    'manual_review_required' => $receipt->manualReviewRequired,
                    'purchase_settlement_public_id' => $receipt->purchaseSettlementPublicId,
                    'provider_ref_id' => $receipt->providerRefId,
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
    use App\Modules\Orders\Application\PurchaseOrderService;
    use App\Modules\Orders\Application\QuotePricingInput;
    use App\Modules\Orders\Application\QuoteService;
    use App\Modules\Orders\Domain\QuoteOverrideSource;
    use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
    use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalTransport;
    use App\Modules\Payments\Zarinpal\Application\ZarinpalPaymentService;
    use Database\Seeders\CatalogAccessFoundationSeeder;
    use Database\Seeders\IdentityAccessFoundationSeeder;
    use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\TestCase;

    /** @requirement IPG-001 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 SEC-002 INT-001 INT-002 QUA-004 */
    final class ZarinpalVerificationContentionTest extends TestCase
    {
        use AgentPricingQuoteIntegrationTestSupport;
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 25;

        protected function setUp(): void
        {
            parent::setUp();
            $this->seed(IdentityAccessFoundationSeeder::class);
            $this->seed(CatalogAccessFoundationSeeder::class);
            $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
            config()->set('app.url', 'http://localhost');
            config()->set('services.zarinpal.enabled', true);
            config()->set('services.zarinpal.merchant_id', '00000000-0000-0000-0000-000000000000');
            config()->set('services.zarinpal.callback_url', 'http://localhost/payments/zarinpal/callback');
            $this->app->instance(ZarinpalTransport::class, new \ZarinpalVerificationContentionSetupTransport);
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

        public function test_concurrent_compatible_100_and_101_verification_replays_one_settlement(): void
        {
            $this->initiatePurchase('compatible');
            $results = $this->runScenario([
                ['verify_result' => 'verified', 'provider_ref_id' => '260001001', 'provider_code' => '100'],
                ['verify_result' => 'verified', 'provider_ref_id' => '260001001', 'provider_code' => '101'],
            ], [0, 1], false);

            self::assertTrue($results[0]['ok'], json_encode($results[0], JSON_THROW_ON_ERROR));
            self::assertTrue($results[1]['ok'], json_encode($results[1], JSON_THROW_ON_ERROR));
            self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'zarinpal')->count());
            self::assertSame(1, DB::table('zarinpal_payment_verifications')->count());
            self::assertSame(0, DB::table('zarinpal_verified_unsettled_evidence')->count());
            self::assertSame(0, DB::table('zarinpal_reconciliation_findings')->count());
            self::assertSame('paid', DB::table('orders')->value('state'));
        }

        public function test_concurrent_conflicting_verified_refs_keep_one_settlement_and_create_critical_finding(): void
        {
            $this->initiatePurchase('conflicting-ref');
            $results = $this->runScenario([
                ['verify_result' => 'verified', 'provider_ref_id' => '260001101', 'provider_code' => '100'],
                ['verify_result' => 'verified', 'provider_ref_id' => '260001102', 'provider_code' => '100'],
            ], [0, 1], false);

            self::assertTrue($results[0]['ok'], json_encode($results[0], JSON_THROW_ON_ERROR));
            self::assertTrue($results[1]['ok'], json_encode($results[1], JSON_THROW_ON_ERROR));
            self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'zarinpal')->count());
            self::assertSame(1, DB::table('zarinpal_payment_verifications')->count());
            self::assertSame(0, DB::table('zarinpal_verified_unsettled_evidence')->count());
            self::assertSame(1, DB::table('zarinpal_reconciliation_findings')->where('finding_type', 'provider_verification_conflict')->where('observed_result', 'verified')->where('severity', 'critical')->count());
            self::assertSame('manual_review', DB::table('zarinpal_payment_requests')->value('state'));
            self::assertSame('captured', DB::table('payment_intents')->where('provider_code', 'zarinpal')->value('state'));
        }

        public function test_concurrent_rejection_first_preserves_later_verified_evidence_without_auto_settlement(): void
        {
            $this->initiatePurchase('rejected-first');
            $results = $this->runScenario([
                ['verify_result' => 'verified', 'provider_ref_id' => '260001201', 'provider_code' => '100'],
                ['verify_result' => 'rejected', 'provider_ref_id' => '-', 'provider_code' => '-51'],
            ], [1, 0], true);

            self::assertTrue($results[0]['ok'], json_encode($results[0], JSON_THROW_ON_ERROR));
            self::assertTrue($results[1]['ok'], json_encode($results[1], JSON_THROW_ON_ERROR));
            self::assertSame(0, DB::table('purchase_settlements')->where('provider_code', 'zarinpal')->count());
            self::assertSame(0, DB::table('zarinpal_payment_verifications')->count());
            self::assertSame(1, DB::table('zarinpal_verified_unsettled_evidence')->where('reason_code', 'provider_result_conflict')->where('provider_ref_id', '260001201')->count());
            self::assertSame(1, DB::table('zarinpal_reconciliation_findings')->where('finding_type', 'provider_verification_conflict')->where('observed_result', 'rejected')->where('severity', 'critical')->count());
            self::assertSame('failed', DB::table('zarinpal_payment_requests')->value('state'));
            self::assertSame('failed', DB::table('payment_intents')->where('provider_code', 'zarinpal')->value('state'));
            self::assertTrue($results[0]['result']['manual_review_required']);
            self::assertSame('260001201', $results[0]['result']['provider_ref_id']);
        }

        public function test_concurrent_uncertainty_first_allows_verified_authority_but_surfaces_conflict(): void
        {
            $this->initiatePurchase('uncertain-first');
            $results = $this->runScenario([
                ['verify_result' => 'verified', 'provider_ref_id' => '260001301', 'provider_code' => '100'],
                ['verify_result' => 'uncertain', 'provider_ref_id' => '-', 'provider_code' => '0'],
            ], [1, 0], true);

            self::assertTrue($results[0]['ok'], json_encode($results[0], JSON_THROW_ON_ERROR));
            self::assertTrue($results[1]['ok'], json_encode($results[1], JSON_THROW_ON_ERROR));
            self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'zarinpal')->count());
            self::assertSame(1, DB::table('zarinpal_payment_verifications')->where('provider_ref_id', '260001301')->count());
            self::assertSame(0, DB::table('zarinpal_verified_unsettled_evidence')->count());
            self::assertSame(1, DB::table('zarinpal_reconciliation_findings')->where('finding_type', 'provider_verification_conflict')->where('observed_result', 'uncertain')->where('severity', 'critical')->count());
            self::assertSame('manual_review', DB::table('zarinpal_payment_requests')->value('state'));
            self::assertSame('captured', DB::table('payment_intents')->where('provider_code', 'zarinpal')->value('state'));
            self::assertTrue($results[0]['result']['manual_review_required']);
        }

        private function initiatePurchase(string $suffix): void
        {
            [$userId, $quotePublicId, $decisionPublicId] = $this->purchaseContext($suffix);
            $receipt = $this->app->make(ZarinpalPaymentService::class)->initiatePurchase(
                $userId,
                $quotePublicId,
                $decisionPublicId,
                $this->correlation($suffix.'-initiate'),
            );
            self::assertSame('redirectable', $receipt->state->value);
        }

        /** @return array{0:int,1:string,2:string} */
        private function purchaseContext(string $suffix): array
        {
            $administratorId = $this->ownerAdministrator();
            $userId = $this->quoteUser('customer');
            $offering = $this->quoteOffering();
            $quote = $this->app->make(QuoteService::class)->create(
                'zarinpal.verification-contention.quote.'.$suffix,
                $userId,
                $offering['id'],
                new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, now('UTC')->addMinutes(30)->toDateTimeImmutable()),
                $this->correlation($suffix.'-quote'),
            );
            $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
            $eligibility->configureMethod(
                'zarinpal.verification-contention.method.'.$suffix,
                $administratorId,
                'zarinpal',
                true,
                false,
                1,
                'Zarinpal verification contention test configuration.',
                $this->correlation($suffix.'-method'),
            );
            $eligibility->recordHealth(
                'zarinpal.verification-contention.health.'.$suffix,
                $administratorId,
                'zarinpal',
                true,
                now('UTC')->addMinutes(10)->toDateTimeImmutable(),
                'Healthy verification contention observation.',
                $this->correlation($suffix.'-health'),
            );
            $decision = $eligibility->evaluate(
                'zarinpal.verification-contention.eligibility.'.$suffix,
                $userId,
                $quote->quotePublicId,
            );
            $this->app->make(PurchaseOrderService::class)->openFromQuote(
                $quote->quotePublicId,
                $userId,
                $this->correlation($suffix.'-order'),
            );

            return [$userId, $quote->quotePublicId, $decision->publicId];
        }

        /**
         * @param list<array{verify_result:string,provider_ref_id:string,provider_code:string}> $providerResults
         * @param list<int> $releaseOrder
         * @return list<array<string,mixed>>
         */
        private function runScenario(array $providerResults, array $releaseOrder, bool $waitAfterEachRelease): array
        {
            $workers = [];
            $paths = [];
            try {
                foreach ($providerResults as $index => $providerResult) {
                    $marker = storage_path('framework/testing/zarinpal-verify-marker-'.bin2hex(random_bytes(8)));
                    $release = storage_path('framework/testing/zarinpal-verify-release-'.bin2hex(random_bytes(8)));
                    if (! is_dir(dirname($marker))) {
                        mkdir(dirname($marker), 0777, true);
                    }
                    $paths[] = $marker;
                    $paths[] = $release;
                    $payload = array_merge($providerResult, [
                        'authority' => 'A'.str_repeat('9', 35),
                        'correlation_id' => $this->correlation('worker-'.$index.'-'.bin2hex(random_bytes(4))),
                        'marker_path' => $marker,
                        'release_path' => $release,
                    ]);
                    $workers[$index] = $this->startWorker($payload);
                }

                foreach ($workers as $index => $worker) {
                    if ($this->readLine($worker, 'readiness', $index) !== "READY\n") {
                        throw new RuntimeException('Verification contention worker returned an invalid readiness marker.');
                    }
                    fwrite($worker['pipes'][0], "GO\n");
                    fflush($worker['pipes'][0]);
                    fclose($worker['pipes'][0]);
                }
                foreach ($workers as $index => $worker) {
                    $this->waitForFile((string) $worker['payload']['marker_path'], 'provider verify marker '.$index);
                }

                $results = [];
                if ($waitAfterEachRelease) {
                    foreach ($releaseOrder as $index) {
                        file_put_contents((string) $workers[$index]['payload']['release_path'], 'RELEASE');
                        $results[$index] = $this->finishWorker($workers[$index], $index);
                        $workers[$index]['closed'] = true;
                    }
                } else {
                    foreach ($releaseOrder as $index) {
                        file_put_contents((string) $workers[$index]['payload']['release_path'], 'RELEASE');
                    }
                    foreach ($workers as $index => $worker) {
                        $results[$index] = $this->finishWorker($worker, $index);
                        $workers[$index]['closed'] = true;
                    }
                }
                ksort($results);

                return array_values($results);
            } finally {
                $this->terminateWorkers($workers);
                foreach ($paths as $path) {
                    @unlink($path);
                }
            }
        }

        /** @param array<string,string> $payload
         * @return array{process:resource,pipes:array{0:resource,1:resource,2:resource},payload:array<string,string>,closed:bool}
         */
        private function startWorker(array $payload): array
        {
            $pipes = [];
            $process = proc_open([
                PHP_BINARY,
                '-d',
                'pcov.enabled=0',
                __FILE__,
                '--zarinpal-verification-contention-worker',
                base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            ], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start Zarinpal verification contention worker.');
            }
            /** @var array{0:resource,1:resource,2:resource} $pipes */
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            return ['process' => $process, 'pipes' => $pipes, 'payload' => $payload, 'closed' => false];
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource},payload:array<string,string>,closed:bool} $worker
         * @return array<string,mixed>
         */
        private function finishWorker(array $worker, int $index): array
        {
            $line = $this->readLine($worker, 'result', $index);
            $stderr = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            if (proc_close($worker['process']) !== 0) {
                throw new RuntimeException('Verification contention worker failed: '.$stderr);
            }
            /** @var array<string,mixed> $result */
            $result = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

            return $result;
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource},payload:array<string,string>,closed:bool} $worker */
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
                    throw new RuntimeException('Unable to wait for verification contention worker output.');
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
                    throw new RuntimeException('Verification contention worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException(sprintf(
                'Verification contention worker %d timed out during %s after %d seconds: %s',
                $index,
                $phase,
                self::WORKER_TIMEOUT_SECONDS,
                $stderr,
            ));
        }

        private function waitForFile(string $path, string $label): void
        {
            $deadline = microtime(true) + self::WORKER_TIMEOUT_SECONDS;
            while (microtime(true) < $deadline) {
                if (is_file($path)) {
                    return;
                }
                usleep(20_000);
            }

            throw new RuntimeException('Timed out waiting for '.$label.'.');
        }

        /** @param array<int,array{process:resource,pipes:array{0:resource,1:resource,2:resource},payload:array<string,string>,closed:bool}> $workers */
        private function terminateWorkers(array $workers): void
        {
            foreach ($workers as $worker) {
                if ($worker['closed'] || ! is_resource($worker['process'])) {
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

        private function correlation(string $suffix): string
        {
            return hash('sha256', 'zarinpal-verification-contention:'.$suffix);
        }
    }
}
