<?php

declare(strict_types=1);

namespace {
    use App\Modules\Panels\Application\PanelAdapterRegistry;
    use App\Modules\Panels\Application\PanelCredentialPolicy;
    use App\Modules\Provisioning\Application\ServiceAutoRenewalProcessor;
    use App\Shared\Application\Clock;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Support\Facades\DB;
    use Tests\Feature\AutoRenewTestPanelAdapter;
    use Tests\Feature\AutoRenewTestPanelAdapterFactory;
    use Tests\Feature\PurchaseOrderTestClock;

    require_once dirname(__DIR__, 2).'/vendor/autoload.php';
    require_once __DIR__.'/PurchaseOrderTestSupport.php';
    require_once __DIR__.'/ServiceAutoRenewalRuntimeTestSupport.php';

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--service-auto-renew-contention-worker') {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $decoded = base64_decode($argv[2] ?? '', true);
        if ($decoded === false) {
            fwrite(STDERR, "Invalid worker payload encoding.\n");
            exit(2);
        }
        try {
            /** @var array{clock:string,remote_id:string,remote_expires_at:string,data_limit_bytes:int,limit:int,seed_remote:bool} $payload */
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            fwrite(STDERR, 'Invalid worker payload: '.$exception->getMessage()."\n");
            exit(2);
        }

        $clock = new PurchaseOrderTestClock(new DateTimeImmutable($payload['clock']));
        $app->instance(Clock::class, $clock);
        config()->set('auto_renew.window_hours', 24);
        config()->set('auto_renew.quote_ttl_minutes', 15);
        config()->set('auto_renew.batch_limit', 50);
        config()->set('auto_renew.max_retry_count', 5);
        config()->set('auto_renew.retry_initial_delay_minutes', 15);
        config()->set('auto_renew.retry_max_delay_minutes', 240);

        $adapter = new AutoRenewTestPanelAdapter;
        if ($payload['seed_remote']) {
            $adapter->seedKnownEntitlements(
                $payload['remote_id'],
                (int) $payload['data_limit_bytes'],
                new DateTimeImmutable($payload['remote_expires_at']),
            );
        }
        $app->instance(
            PanelAdapterRegistry::class,
            new PanelAdapterRegistry(
                [new AutoRenewTestPanelAdapterFactory($adapter)],
                $app->make(PanelCredentialPolicy::class),
            ),
        );
        $app->forgetInstance(ServiceAutoRenewalProcessor::class);
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET timestamp = '.$clock->value->getTimestamp());
        }

        echo "READY\n";
        flush();
        if (fgets(STDIN) === false) {
            fwrite(STDERR, "Worker barrier was not released.\n");
            exit(2);
        }

        try {
            $receipt = $app->make(ServiceAutoRenewalProcessor::class)->processDue((int) $payload['limit']);
            echo json_encode([
                'ok' => true,
                'result' => [
                    'queued' => $receipt->queued,
                    'failed' => $receipt->failed,
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
    require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
    require_once __DIR__.'/PurchaseOrderTestSupport.php';
    require_once __DIR__.'/ServiceAutoRenewalRuntimeTestSupport.php';
    require_once __DIR__.'/ServiceAutoRenewalRuntimeTestHelpers.php';

    use App\Modules\Orders\Application\QuotePricingInput;
    use App\Modules\Orders\Application\QuoteService;
    use App\Modules\Orders\Application\ServicePackageQuoteContext;
    use App\Modules\Orders\Domain\QuoteOverrideSource;
    use App\Modules\Payments\Application\PurchaseWalletPaymentService;
    use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
    use App\Modules\Provisioning\Domain\AutoRenewAttemptState;
    use Database\Seeders\CatalogAccessFoundationSeeder;
    use Database\Seeders\IdentityAccessFoundationSeeder;
    use Database\Seeders\PanelsAccessFoundationSeeder;
    use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
    use Database\Seeders\WalletFinancialFoundationSeeder;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Str;
    use RuntimeException;
    use Tests\TestCase;

    /** @requirement SVC-007 BUY-002 PAY-002 WAL-002 DAT-002 DAT-003 QUA-004 */
    final class ServiceAutoRenewalContentionVerificationTest extends TestCase
    {
        use AgentPricingQuoteIntegrationTestSupport;
        use DatabaseTruncation;
        use PurchaseOrderTestSupport;
        use ServiceAutoRenewalRuntimeTestHelpers;

        private const WORKER_TIMEOUT_SECONDS = 30;

        protected function setUp(): void
        {
            parent::setUp();
            $this->seed(IdentityAccessFoundationSeeder::class);
            $this->seed(CatalogAccessFoundationSeeder::class);
            $this->seed(PanelsAccessFoundationSeeder::class);
            $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
            $this->bootPurchaseOrderClock();
            config()->set('auto_renew.window_hours', 24);
            config()->set('auto_renew.quote_ttl_minutes', 15);
            config()->set('auto_renew.batch_limit', 50);
            config()->set('auto_renew.max_retry_count', 5);
            config()->set('auto_renew.retry_initial_delay_minutes', 15);
            config()->set('auto_renew.retry_max_delay_minutes', 240);
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

        public function test_concurrent_scheduler_resume_captures_reserved_attempt_once(): void
        {
            if (DB::connection()->getDriverName() !== 'mysql') {
                self::markTestSkipped('Scheduler contention verification requires MariaDB/MySQL process concurrency.');
            }

            $prepared = $this->prepareReservedAttempt('scheduler-contention');
            $ledgerCountBefore = DB::table('ledger_transactions')->count();
            $payload = $this->workerPayload($prepared, true);
            $results = $this->runConcurrent([$payload, $payload]);

            self::assertTrue((bool) ($results[0]['ok'] ?? false), json_encode($results[0], JSON_THROW_ON_ERROR));
            self::assertTrue((bool) ($results[1]['ok'] ?? false), json_encode($results[1], JSON_THROW_ON_ERROR));
            $attempt = DB::table('service_auto_renew_attempts')
                ->where('id', $prepared['attempt_id'])
                ->first(['state', 'purchase_settlement_id', 'provisioning_operation_id']);
            self::assertNotNull($attempt);
            self::assertSame(AutoRenewAttemptState::MutationQueued->value, $attempt->state);
            self::assertNotNull($attempt->purchase_settlement_id);
            self::assertNotNull($attempt->provisioning_operation_id);
            self::assertSame($ledgerCountBefore + 1, DB::table('ledger_transactions')->count());
            self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
            self::assertSame(1, DB::table('service_paid_mutation_authorities')->count());
            self::assertSame(1, DB::table('provisioning_operations')->where('operation_type', 'renew')->count());
            self::assertSame('captured', DB::table('payment_intents')->where('id', $prepared['intent_id'])->value('state'));
            self::assertSame(
                'captured',
                DB::table('purchase_wallet_reservations as reservation')
                    ->join('wallet_holds as hold', 'hold.id', '=', 'reservation.wallet_hold_id')
                    ->where('reservation.payment_intent_id', $prepared['intent_id'])
                    ->value('hold.status'),
            );
        }

        public function test_concurrent_scheduler_retry_counts_same_failure_once(): void
        {
            if (DB::connection()->getDriverName() !== 'mysql') {
                self::markTestSkipped('Scheduler contention verification requires MariaDB/MySQL process concurrency.');
            }

            $prepared = $this->prepareReservedAttempt('scheduler-retry-contention');
            $ledgerCountBefore = DB::table('ledger_transactions')->count();
            $payload = $this->workerPayload($prepared, false);
            $results = $this->runConcurrent([$payload, $payload]);

            self::assertTrue((bool) ($results[0]['ok'] ?? false), json_encode($results[0], JSON_THROW_ON_ERROR));
            self::assertTrue((bool) ($results[1]['ok'] ?? false), json_encode($results[1], JSON_THROW_ON_ERROR));
            $attempt = DB::table('service_auto_renew_attempts')
                ->where('id', $prepared['attempt_id'])
                ->first(['state', 'reason_code', 'retry_count', 'next_retry_at']);
            self::assertNotNull($attempt);
            self::assertSame(AutoRenewAttemptState::RetryPending->value, $attempt->state);
            self::assertSame('reserved_attempt_recheck_deferred', $attempt->reason_code);
            self::assertSame(1, (int) $attempt->retry_count);
            self::assertNotNull($attempt->next_retry_at);
            self::assertSame($ledgerCountBefore, DB::table('ledger_transactions')->count());
            self::assertSame(0, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
            self::assertSame(0, DB::table('service_paid_mutation_authorities')->count());
            self::assertSame('awaiting_user_action', DB::table('payment_intents')->where('id', $prepared['intent_id'])->value('state'));
            self::assertSame(
                'active',
                DB::table('purchase_wallet_reservations as reservation')
                    ->join('wallet_holds as hold', 'hold.id', '=', 'reservation.wallet_hold_id')
                    ->where('reservation.payment_intent_id', $prepared['intent_id'])
                    ->value('hold.status'),
            );
            self::assertSame(
                1,
                DB::table('service_auto_renew_notification_intents')
                    ->where('auto_renew_attempt_id', $prepared['attempt_id'])
                    ->where('outcome', 'failure')
                    ->count(),
            );
        }

        /**
         * @param  array{attempt_id:int,intent_id:int,remote_id:string,remote_expires_at:string}  $prepared
         * @return array{clock:string,remote_id:string,remote_expires_at:string,data_limit_bytes:int,limit:int,seed_remote:bool}
         */
        private function workerPayload(array $prepared, bool $seedRemote): array
        {
            return [
                'clock' => $this->purchaseOrderClock->value->format(DATE_ATOM),
                'remote_id' => $prepared['remote_id'],
                'remote_expires_at' => $prepared['remote_expires_at'],
                'data_limit_bytes' => 20 * 1024 * 1024 * 1024,
                'limit' => 10,
                'seed_remote' => $seedRemote,
            ];
        }

        /** @return array{attempt_id:int,intent_id:int,remote_id:string,remote_expires_at:string} */
        private function prepareReservedAttempt(string $suffix): array
        {
            $scenario = $this->scenario($suffix);
            $this->enableWalletMethod($suffix);
            $this->seed(WalletFinancialFoundationSeeder::class);
            $walletAccountId = $this->fundWallet($scenario['user_id'], 600_000, $suffix);
            $configuration = $this->enableAutoRenew($scenario, $suffix);

            $quote = $this->app->make(QuoteService::class)->create(
                'service.auto-renew.'.$suffix.'.quote.000001',
                $scenario['user_id'],
                $scenario['offering_id'],
                new QuotePricingInput(
                    QuoteOverrideSource::None,
                    null,
                    null,
                    null,
                    0,
                    $this->purchaseOrderClock->value->modify('+15 minutes'),
                ),
                $this->purchaseOrderCorrelation('auto-renew-'.$suffix.'-quote'),
                null,
                new ServicePackageQuoteContext($scenario['service_public_id'], 'aq-renew-30d'),
            );
            $eligibility = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
                'service.auto-renew.'.$suffix.'.eligibility.000001',
                $scenario['user_id'],
                $quote->quotePublicId,
            );
            $intent = $this->app->make(PurchaseWalletPaymentService::class)->reserve(
                'service.auto-renew.'.$suffix.'.intent.000001',
                $scenario['user_id'],
                $walletAccountId,
                $quote->quotePublicId,
                $eligibility->publicId,
                $this->purchaseOrderCorrelation('auto-renew-'.$suffix.'-reserve'),
            );

            $configRow = DB::table('service_auto_renew_configurations')
                ->where('id', $configuration->configurationId)
                ->first();
            self::assertNotNull($configRow);
            $service = DB::table('service_subscriptions')
                ->where('id', $scenario['service_id'])
                ->first(['remote_service_id']);
            self::assertNotNull($service);
            self::assertIsString($service->remote_service_id);
            $cycleKey = hash('sha256', implode('|', [
                (string) $configuration->configurationId,
                (string) $scenario['service_id'],
                (string) $configuration->configurationVersion,
                (string) $configRow->observed_remote_identity_generation,
                (string) $configRow->observed_expires_at,
            ]));
            $attemptId = (int) DB::table('service_auto_renew_attempts')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'cycle_key' => $cycleKey,
                'auto_renew_configuration_id' => $configuration->configurationId,
                'service_subscription_id' => $scenario['service_id'],
                'configuration_version' => $configuration->configurationVersion,
                'remote_identity_generation' => (int) $configRow->observed_remote_identity_generation,
                'observed_expires_at' => (string) $configRow->observed_expires_at,
                'observed_expiry_evidence_hash' => (string) $configRow->observed_expiry_evidence_hash,
                'observed_expiry_source' => (string) $configRow->observed_expiry_source,
                'state' => AutoRenewAttemptState::Pending->value,
                'reason_code' => null,
                'baseline_price_irr' => $configuration->acceptedPriceIrr,
                'current_price_irr' => null,
                'quote_id' => null,
                'payment_eligibility_decision_id' => null,
                'payment_intent_id' => null,
                'purchase_settlement_id' => null,
                'provisioning_operation_id' => null,
                'correlation_id' => $this->purchaseOrderCorrelation('auto-renew-'.$suffix.'-attempt'),
                'completed_at' => null,
                'created_at' => $this->purchaseOrderTimestamp(),
                'updated_at' => $this->purchaseOrderTimestamp(),
            ]);
            $intentId = (int) DB::table('payment_intents')
                ->where('public_id', $intent->intentPublicId)
                ->value('id');
            DB::table('service_auto_renew_attempts')->where('id', $attemptId)->update([
                'quote_id' => $quote->quoteId,
                'payment_eligibility_decision_id' => $eligibility->decisionId,
                'payment_intent_id' => $intentId,
                'current_price_irr' => $quote->finalPriceIrr,
                'reason_code' => 'wallet_reserved',
                'updated_at' => $this->purchaseOrderTimestamp(),
            ]);

            return [
                'attempt_id' => $attemptId,
                'intent_id' => $intentId,
                'remote_id' => (string) $service->remote_service_id,
                'remote_expires_at' => (new \DateTimeImmutable((string) $configRow->observed_expires_at))->format(DATE_ATOM),
            ];
        }

        /**
         * @param  list<array{clock:string,remote_id:string,remote_expires_at:string,data_limit_bytes:int,limit:int,seed_remote:bool}>  $payloads
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
                        '--service-auto-renew-contention-worker',
                        base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                    ], [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ], $pipes, dirname(__DIR__, 2));
                    if (! is_resource($process)) {
                        throw new RuntimeException('Unable to start Service auto-renew contention worker.');
                    }
                    /** @var array{0:resource,1:resource,2:resource} $pipes */
                    stream_set_blocking($pipes[1], false);
                    stream_set_blocking($pipes[2], false);
                    $workers[] = ['process' => $process, 'pipes' => $pipes];
                }

                foreach ($workers as $index => $worker) {
                    if ($this->readWorkerLine($worker, 'readiness', $index) !== "READY\n") {
                        throw new RuntimeException('Service auto-renew contention worker returned an invalid readiness marker.');
                    }
                }
                foreach ($workers as $worker) {
                    fwrite($worker['pipes'][0], "GO\n");
                    fflush($worker['pipes'][0]);
                    fclose($worker['pipes'][0]);
                }

                $results = [];
                foreach ($workers as $index => $worker) {
                    $line = $this->readWorkerLine($worker, 'result', $index);
                    $stderr = stream_get_contents($worker['pipes'][2]);
                    fclose($worker['pipes'][1]);
                    fclose($worker['pipes'][2]);
                    if (proc_close($worker['process']) !== 0) {
                        throw new RuntimeException('Service auto-renew contention worker failed: '.$stderr);
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
        private function readWorkerLine(array $worker, string $phase, int $index): string
        {
            $deadline = microtime(true) + self::WORKER_TIMEOUT_SECONDS;
            $stderr = '';
            while (microtime(true) < $deadline) {
                $read = [$worker['pipes'][1], $worker['pipes'][2]];
                $write = null;
                $except = null;
                $selected = stream_select($read, $write, $except, 0, 200_000);
                if ($selected === false) {
                    throw new RuntimeException('Unable to wait for Service auto-renew contention worker output.');
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
                    throw new RuntimeException('Service auto-renew contention worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException(sprintf(
                'Service auto-renew contention worker %d timed out during %s after %d seconds: %s',
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
