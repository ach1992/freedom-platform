<?php

declare(strict_types=1);

namespace {
    use App\Modules\Payments\Application\PurchaseWalletPaymentService;
    use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalInquiryResult;
    use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalRequestResult;
    use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalTransport;
    use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalUnverifiedCandidate;
    use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalVerifyResult;
    use App\Modules\Payments\Zarinpal\Application\ZarinpalPaymentService;
    use Illuminate\Contracts\Console\Kernel;

    require_once dirname(__DIR__, 2).'/vendor/autoload.php';

    final class ZarinpalCrossMethodRaceTransport implements ZarinpalTransport
    {
        public function request(string $merchantId, int $amountIrr, string $callbackUrl, string $description, string $orderId): ZarinpalRequestResult
        {
            return ZarinpalRequestResult::accepted('A'.str_repeat('6', 35));
        }

        public function verify(string $merchantId, int $amountIrr, string $authority): ZarinpalVerifyResult
        {
            usleep(150_000);

            return ZarinpalVerifyResult::verified('260000004', 100);
        }

        public function inquiry(string $merchantId, string $authority): ZarinpalInquiryResult
        {
            return ZarinpalInquiryResult::available('PAID');
        }

        public function unverified(string $merchantId): array
        {
            /** @var list<ZarinpalUnverifiedCandidate> */
            return [];
        }
    }

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--zarinpal-cross-method-race-worker') {
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
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Invalid worker payload: '.$exception->getMessage()."\n");
            exit(2);
        }

        config()->set('app.url', 'http://localhost');
        config()->set('services.zarinpal.enabled', true);
        config()->set('services.zarinpal.merchant_id', '00000000-0000-0000-0000-000000000000');
        config()->set('services.zarinpal.callback_url', 'http://localhost/payments/zarinpal/callback');
        $app->instance(ZarinpalTransport::class, new ZarinpalCrossMethodRaceTransport);

        echo "READY\n";
        flush();
        if (fgets(STDIN) === false) {
            fwrite(STDERR, "Worker barrier was not released.\n");
            exit(2);
        }

        try {
            if ($payload['action'] === 'wallet_capture') {
                $receipt = $app->make(PurchaseWalletPaymentService::class)->capture(
                    $payload['payment_intent_public_id'],
                    $payload['correlation_id'],
                );
                $result = [
                    'state' => $receipt->state->value,
                    'order_public_id' => $receipt->orderPublicId,
                ];
            } else {
                $receipt = $app->make(ZarinpalPaymentService::class)->handleCallback(
                    $payload['authority'],
                    'OK',
                    $payload['correlation_id'],
                );
                $result = [
                    'state' => $receipt->state->value,
                    'request_id' => $receipt->requestId,
                    'manual_review_required' => $receipt->manualReviewRequired,
                ];
            }

            echo json_encode([
                'ok' => true,
                'action' => $payload['action'],
                'result' => $result,
            ], JSON_THROW_ON_ERROR)."\n";
        } catch (Throwable $exception) {
            echo json_encode([
                'ok' => false,
                'action' => $payload['action'],
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
    use App\Modules\Payments\Application\PurchaseWalletPaymentService;
    use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
    use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalTransport;
    use App\Modules\Payments\Zarinpal\Application\ZarinpalPaymentService;
    use App\Modules\Wallet\Application\LedgerEntryDraft;
    use App\Modules\Wallet\Application\LedgerPostingService;
    use App\Modules\Wallet\Domain\IrrMoney;
    use App\Modules\Wallet\Domain\LedgerDirection;
    use Database\Seeders\CatalogAccessFoundationSeeder;
    use Database\Seeders\IdentityAccessFoundationSeeder;
    use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
    use Database\Seeders\WalletFinancialFoundationSeeder;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\TestCase;

    /** @requirement BUY-001 BUY-002 PAY-001 PAY-002 PAY-003 IPG-001 WAL-001 WAL-002 DAT-002 DAT-003 DAT-004 INT-001 INT-002 QUA-004 */
    final class ZarinpalCrossMethodSettlementRaceTest extends TestCase
    {
        use AgentPricingQuoteIntegrationTestSupport;
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 30;

        protected function setUp(): void
        {
            parent::setUp();
            $this->seed(IdentityAccessFoundationSeeder::class);
            $this->seed(CatalogAccessFoundationSeeder::class);
            $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
            $this->seed(WalletFinancialFoundationSeeder::class);

            config()->set('app.url', 'http://localhost');
            config()->set('services.zarinpal.enabled', true);
            config()->set('services.zarinpal.merchant_id', '00000000-0000-0000-0000-000000000000');
            config()->set('services.zarinpal.callback_url', 'http://localhost/payments/zarinpal/callback');
            $this->app->instance(ZarinpalTransport::class, new \ZarinpalCrossMethodRaceTransport);
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

        public function test_wallet_and_verified_zarinpal_race_has_exactly_one_order_settlement_winner(): void
        {
            [$userId, $quotePublicId, $decisionPublicId] = $this->purchaseContext();

            $zarinpal = $this->app->make(ZarinpalPaymentService::class)->initiatePurchase(
                $userId,
                $quotePublicId,
                $decisionPublicId,
                $this->correlation('zarinpal-initiate'),
            );
            self::assertSame('redirectable', $zarinpal->state->value);

            $walletId = $this->fundedWallet($userId, 2_000_000);
            $walletIntent = $this->app->make(PurchaseWalletPaymentService::class)->reserve(
                'purchase.wallet.zarinpal-cross-method-race.000001',
                $userId,
                $walletId,
                $quotePublicId,
                $decisionPublicId,
                $this->correlation('wallet-reserve'),
            );

            $results = $this->runConcurrent([
                [
                    'action' => 'wallet_capture',
                    'payment_intent_public_id' => $walletIntent->intentPublicId,
                    'correlation_id' => $this->correlation('wallet-capture'),
                ],
                [
                    'action' => 'zarinpal_callback',
                    'authority' => 'A'.str_repeat('6', 35),
                    'correlation_id' => $this->correlation('zarinpal-callback'),
                ],
            ]);

            self::assertSame(1, DB::table('purchase_settlements')->count());
            self::assertSame(1, DB::table('orders')->where('state', 'paid')->count());
            self::assertNotNull(DB::table('orders')->value('purchase_settlement_public_id'));
            self::assertSame(0, DB::table('promotion_usage_reservations')->count());

            $winner = (string) DB::table('purchase_settlements')->value('provider_code');
            self::assertContains($winner, ['wallet', 'zarinpal']);

            $walletResult = $this->resultFor($results, 'wallet_capture');
            $zarinpalResult = $this->resultFor($results, 'zarinpal_callback');

            if ($winner === 'wallet') {
                self::assertTrue($walletResult['ok'], json_encode($walletResult, JSON_THROW_ON_ERROR));
                self::assertTrue($zarinpalResult['ok'], json_encode($zarinpalResult, JSON_THROW_ON_ERROR));
                self::assertSame('manual_review', $zarinpalResult['result']['state']);
                self::assertTrue($zarinpalResult['result']['manual_review_required']);
                self::assertSame(1, DB::table('zarinpal_verified_unsettled_evidence')->count());
                self::assertSame(1, DB::table('zarinpal_reconciliation_findings')->where('severity', 'critical')->count());
                self::assertSame(0, DB::table('zarinpal_payment_verifications')->count());
                self::assertSame('pending_manual_review', DB::table('payment_intents')->where('provider_code', 'zarinpal')->value('state'));
                self::assertSame(
                    'captured',
                    DB::table('wallet_holds')->where('source_id', $walletIntent->intentPublicId)->value('status'),
                );
            } else {
                self::assertTrue($zarinpalResult['ok'], json_encode($zarinpalResult, JSON_THROW_ON_ERROR));
                self::assertSame('verified', $zarinpalResult['result']['state']);
                self::assertFalse($zarinpalResult['result']['manual_review_required']);
                self::assertFalse($walletResult['ok'], json_encode($walletResult, JSON_THROW_ON_ERROR));
                self::assertSame(1, DB::table('zarinpal_payment_verifications')->count());
                self::assertSame(0, DB::table('zarinpal_verified_unsettled_evidence')->count());
                self::assertSame(0, DB::table('zarinpal_reconciliation_findings')->count());
                self::assertSame('awaiting_user_action', DB::table('payment_intents')->where('provider_code', 'wallet')->value('state'));
                $walletHold = DB::table('wallet_holds')
                    ->where('source_id', $walletIntent->intentPublicId)
                    ->first(['status', 'captured_ledger_transaction_id']);
                self::assertNotNull($walletHold);
                self::assertSame('active', $walletHold->status);
                self::assertNull($walletHold->captured_ledger_transaction_id);
            }
        }

        /** @return array{0:int,1:string,2:string} */
        private function purchaseContext(): array
        {
            $administratorId = $this->ownerAdministrator();
            $userId = $this->quoteUser('customer');
            $offering = $this->quoteOffering();
            $quote = $this->app->make(QuoteService::class)->create(
                'zarinpal.cross-method-race.quote.000001',
                $userId,
                $offering['id'],
                new QuotePricingInput(
                    QuoteOverrideSource::None,
                    null,
                    null,
                    null,
                    0,
                    now('UTC')->addMinutes(30)->toDateTimeImmutable(),
                ),
                $this->correlation('quote'),
            );

            $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
            $this->configureHealthyMethod($eligibility, $administratorId, 'zarinpal', 1);
            $this->configureHealthyMethod($eligibility, $administratorId, 'wallet', 2);
            $decision = $eligibility->evaluate(
                'zarinpal.cross-method-race.eligibility.000001',
                $userId,
                $quote->quotePublicId,
            );

            $this->app->make(PurchaseOrderService::class)->openFromQuote(
                $quote->quotePublicId,
                $userId,
                $this->correlation('order'),
            );

            return [$userId, $quote->quotePublicId, $decision->publicId];
        }

        private function configureHealthyMethod(
            PaymentMethodEligibilityService $service,
            int $administratorId,
            string $method,
            int $routeOrder,
        ): void {
            $service->configureMethod(
                'zarinpal.cross-method-race.method.'.$method.'.000001',
                $administratorId,
                $method,
                true,
                false,
                $routeOrder,
                'Cross-method settlement race test configuration.',
                $this->correlation('method-'.$method),
            );
            $service->recordHealth(
                'zarinpal.cross-method-race.health.'.$method.'.000001',
                $administratorId,
                $method,
                true,
                now('UTC')->addMinutes(10)->toDateTimeImmutable(),
                'Healthy cross-method settlement race observation.',
                $this->correlation('health-'.$method),
            );
        }

        private function fundedWallet(int $userId, int $amountIrr): int
        {
            $now = now('UTC');
            $assetId = (int) DB::table('ledger_accounts')->insertGetId([
                'code' => 'system.zarinpal.cross-method-race.asset',
                'account_class' => 'asset',
                'owner_user_id' => null,
                'wallet_bucket' => null,
                'currency' => 'IRR',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $walletId = (int) DB::table('ledger_accounts')->insertGetId([
                'code' => 'wallet.cash.zarinpal.cross-method-race.'.$userId,
                'account_class' => 'liability',
                'owner_user_id' => $userId,
                'wallet_bucket' => 'cash',
                'currency' => 'IRR',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->app->make(LedgerPostingService::class)->post(
                'ledger.zarinpal.cross-method-race.fund.000001',
                'zarinpal_cross_method_race_funding',
                $this->correlation('wallet-fund'),
                [
                    new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amountIrr)),
                    new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amountIrr)),
                ],
                'test_fixture',
                'zarinpal-cross-method-race',
            );

            return $walletId;
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
                        '--zarinpal-cross-method-race-worker',
                        base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                    ], [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ], $pipes, dirname(__DIR__, 2));
                    if (! is_resource($process)) {
                        throw new RuntimeException('Unable to start cross-method settlement race worker.');
                    }
                    /** @var array{0:resource,1:resource,2:resource} $pipes */
                    stream_set_blocking($pipes[1], false);
                    stream_set_blocking($pipes[2], false);
                    $workers[] = ['process' => $process, 'pipes' => $pipes];
                }

                foreach ($workers as $index => $worker) {
                    if ($this->readLine($worker, 'readiness', $index) !== "READY\n") {
                        throw new RuntimeException('Cross-method settlement race worker returned an invalid readiness marker.');
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
                        throw new RuntimeException('Cross-method settlement race worker failed: '.$stderr);
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

        /**
         * @param  list<array<string, mixed>>  $results
         * @return array<string, mixed>
         */
        private function resultFor(array $results, string $action): array
        {
            foreach ($results as $result) {
                if (($result['action'] ?? null) === $action) {
                    return $result;
                }
            }

            throw new RuntimeException('Cross-method settlement race result is missing action '.$action.'.');
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
                    throw new RuntimeException('Unable to wait for cross-method settlement race worker output.');
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
                    throw new RuntimeException('Cross-method settlement race worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException(sprintf(
                'Cross-method settlement race worker %d timed out during %s after %d seconds: %s',
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

        private function correlation(string $suffix): string
        {
            return hash('sha256', 'zarinpal-cross-method-race:'.$suffix);
        }
    }
}
