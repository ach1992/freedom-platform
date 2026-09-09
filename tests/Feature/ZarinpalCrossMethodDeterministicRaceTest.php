<?php

declare(strict_types=1);

namespace {
    use App\Modules\Payments\Application\PurchaseWalletPaymentService;
    use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalInquiryResult;
    use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalRequestResult;
    use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalTransport;
    use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalVerifyResult;
    use App\Modules\Payments\Zarinpal\Application\ZarinpalPaymentService;
    use Illuminate\Contracts\Console\Kernel;

    require_once dirname(__DIR__, 2).'/vendor/autoload.php';

    final class ZarinpalDeterministicRaceImmediateTransport implements ZarinpalTransport
    {
        public function request(string $merchantId, int $amountIrr, string $callbackUrl, string $description, string $orderId): ZarinpalRequestResult
        {
            return ZarinpalRequestResult::accepted('A'.str_repeat('5', 35));
        }

        public function verify(string $merchantId, int $amountIrr, string $authority): ZarinpalVerifyResult
        {
            return ZarinpalVerifyResult::verified('260002001', 100);
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

    final class ZarinpalDeterministicRaceBarrierTransport implements ZarinpalTransport
    {
        /** @param array<string,string> $payload */
        public function __construct(private array $payload) {}

        public function request(string $merchantId, int $amountIrr, string $callbackUrl, string $description, string $orderId): ZarinpalRequestResult
        {
            throw new RuntimeException('Provider request is not expected in deterministic callback worker.');
        }

        public function verify(string $merchantId, int $amountIrr, string $authority): ZarinpalVerifyResult
        {
            $result = ZarinpalVerifyResult::verified('260002002', 100);
            file_put_contents($this->payload['marker_path'], 'VERIFIED_RESULT_READY');
            $deadline = microtime(true) + 20;
            while (! is_file($this->payload['release_path'])) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Timed out waiting to release deterministic Zarinpal verification.');
                }
                usleep(20_000);
            }

            return $result;
        }

        public function inquiry(string $merchantId, string $authority): ZarinpalInquiryResult
        {
            throw new RuntimeException('Inquiry is not expected in deterministic callback worker.');
        }

        public function unverified(string $merchantId): array
        {
            return [];
        }
    }

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--zarinpal-deterministic-cross-method-worker') {
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
        $app->instance(
            ZarinpalTransport::class,
            $payload['action'] === 'zarinpal_callback'
                ? new ZarinpalDeterministicRaceBarrierTransport($payload)
                : new ZarinpalDeterministicRaceImmediateTransport,
        );

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
                $result = ['state' => $receipt->state->value, 'order_public_id' => $receipt->orderPublicId];
            } else {
                $receipt = $app->make(ZarinpalPaymentService::class)->handleCallback(
                    $payload['authority'],
                    'OK',
                    $payload['correlation_id'],
                );
                $result = [
                    'state' => $receipt->state->value,
                    'manual_review_required' => $receipt->manualReviewRequired,
                    'purchase_settlement_public_id' => $receipt->purchaseSettlementPublicId,
                    'provider_ref_id' => $receipt->providerRefId,
                ];
            }
            echo json_encode(['ok' => true, 'action' => $payload['action'], 'result' => $result], JSON_THROW_ON_ERROR)."\n";
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
    use App\Modules\Catalog\Application\CatalogChangeContext;
    use App\Modules\Catalog\Application\PlanOfferingService;
    use App\Modules\Catalog\Domain\ProductVisibility;
    use App\Modules\Orders\Application\Contracts\QuoteDiscountAuthority;
    use App\Modules\Orders\Application\PurchaseOrderService;
    use App\Modules\Orders\Application\QuoteDiscountAuthorizationRequest;
    use App\Modules\Orders\Application\QuoteDiscountConsumptionRequest;
    use App\Modules\Orders\Application\QuotePricingInput;
    use App\Modules\Orders\Application\QuoteService;
    use App\Modules\Orders\Domain\QuoteOverrideSource;
    use App\Modules\Payments\Application\PurchaseWalletPaymentService;
    use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
    use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalTransport;
    use App\Modules\Payments\Zarinpal\Application\ZarinpalPaymentService;
    use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeType;
    use App\Modules\Wallet\Application\LedgerEntryDraft;
    use App\Modules\Wallet\Application\LedgerPostingService;
    use App\Modules\Wallet\Domain\IrrMoney;
    use App\Modules\Wallet\Domain\LedgerDirection;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\Support\CreatesBenefitCodeFixtures;
    use Tests\TestCase;

    /** @requirement BUY-001 BUY-002 PAY-001 PAY-002 PAY-003 IPG-001 PRO-001 WAL-001 WAL-002 DAT-002 DAT-003 DAT-004 INT-001 INT-002 QUA-004 */
    final class ZarinpalCrossMethodDeterministicRaceTest extends TestCase
    {
        use AgentPricingQuoteIntegrationTestSupport;
        use CreatesBenefitCodeFixtures;
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 30;

        protected function setUp(): void
        {
            parent::setUp();
            $this->seed();
            config()->set('app.url', 'http://localhost');
            config()->set('services.zarinpal.enabled', true);
            config()->set('services.zarinpal.merchant_id', '00000000-0000-0000-0000-000000000000');
            config()->set('services.zarinpal.callback_url', 'http://localhost/payments/zarinpal/callback');
            $this->app->instance(ZarinpalTransport::class, new \ZarinpalDeterministicRaceImmediateTransport);
        }

        protected function tearDown(): void
        {
            try {
                DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_wallet_capture_pause_test');
                if (isset($this->app)) {
                    $this->truncateDatabaseTables();
                }
            } finally {
                parent::tearDown();
            }
        }

        public function test_wallet_wins_after_zarinpal_provider_verification_is_ready(): void
        {
            [$userId, $quotePublicId, $decisionPublicId] = $this->plainPurchaseContext('wallet-wins');
            $zarinpal = $this->app->make(ZarinpalPaymentService::class)->initiatePurchase(
                $userId,
                $quotePublicId,
                $decisionPublicId,
                $this->correlation('wallet-wins-zarinpal-initiate'),
            );
            $walletIntent = $this->reserveWallet($userId, $quotePublicId, $decisionPublicId, 'wallet-wins');

            $marker = storage_path('framework/testing/zarinpal-wallet-wins-marker-'.bin2hex(random_bytes(8)));
            $release = storage_path('framework/testing/zarinpal-wallet-wins-release-'.bin2hex(random_bytes(8)));
            $worker = null;
            try {
                $worker = $this->startWorker([
                    'action' => 'zarinpal_callback',
                    'authority' => 'A'.str_repeat('5', 35),
                    'correlation_id' => $this->correlation('wallet-wins-zarinpal-callback'),
                    'marker_path' => $marker,
                    'release_path' => $release,
                ]);
                self::assertSame("READY\n", $this->readLine($worker, 'readiness'));
                $this->releaseWorkerStart($worker);
                $this->waitForFile($marker, 'ready Zarinpal provider verification result');

                $walletPaid = $this->app->make(PurchaseWalletPaymentService::class)->capture(
                    $walletIntent->intentPublicId,
                    $this->correlation('wallet-wins-wallet-capture'),
                );
                self::assertSame('paid', $walletPaid->state->value);

                file_put_contents($release, 'RELEASE');
                $zarinpalResult = $this->finishWorker($worker);
                $worker['closed'] = true;

                self::assertTrue($zarinpalResult['ok'], json_encode($zarinpalResult, JSON_THROW_ON_ERROR));
                self::assertSame('manual_review', $zarinpalResult['result']['state']);
                self::assertTrue($zarinpalResult['result']['manual_review_required']);
                self::assertSame('260002002', $zarinpalResult['result']['provider_ref_id']);
                self::assertSame(1, DB::table('purchase_settlements')->count());
                self::assertSame('wallet', DB::table('purchase_settlements')->value('provider_code'));
                self::assertSame(1, DB::table('orders')->where('state', 'paid')->count());
                self::assertSame(1, DB::table('zarinpal_verified_unsettled_evidence')->where('reason_code', 'purchase_order_unavailable')->count());
                self::assertSame(1, DB::table('zarinpal_reconciliation_findings')->where('finding_type', 'verified_purchase_order_unavailable')->where('severity', 'critical')->count());
                self::assertSame('captured', DB::table('wallet_holds')->where('source_id', $walletIntent->intentPublicId)->value('status'));
                self::assertSame(0, DB::table('promotion_usage_reservations')->count());
            } finally {
                if (is_array($worker)) {
                    $this->terminateWorker($worker);
                }
                @unlink($marker);
                @unlink($release);
            }
        }

        public function test_discounted_zarinpal_winner_rolls_back_wallet_hold_ledger_settlement_and_promotion_work(): void
        {
            [$userId, $quotePublicId, $decisionPublicId] = $this->discountedPurchaseContext('zarinpal-wins');
            $zarinpal = $this->app->make(ZarinpalPaymentService::class)->initiatePurchase(
                $userId,
                $quotePublicId,
                $decisionPublicId,
                $this->correlation('zarinpal-wins-initiate'),
            );
            self::assertSame('redirectable', $zarinpal->state->value);
            self::assertSame(1, DB::table('promotion_usage_reservations')->count());

            $walletIntent = $this->reserveWallet($userId, $quotePublicId, $decisionPublicId, 'zarinpal-wins');
            $baselineLedgerTransactions = DB::table('ledger_transactions')->count();
            $baselineLedgerEntries = DB::table('ledger_entries')->count();
            $lockName = 'zpal_wallet_'.bin2hex(random_bytes(8));
            $triggerLockName = str_replace("'", "''", $lockName);
            $lock = DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName]);
            self::assertNotNull($lock);
            self::assertSame(1, (int) $lock->acquired);
            DB::unprepared(<<<SQL
CREATE TRIGGER zarinpal_wallet_capture_pause_test
BEFORE INSERT ON payment_provider_events
FOR EACH ROW
BEGIN
    DECLARE acquired_lock INT DEFAULT 0;
    IF NEW.provider_code = 'wallet' THEN
        SET acquired_lock = GET_LOCK('{$triggerLockName}', 20);
        IF acquired_lock <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Timed out waiting for deterministic wallet capture release.';
        END IF;
        DO RELEASE_LOCK('{$triggerLockName}');
    END IF;
END
SQL);

            $worker = null;
            try {
                $worker = $this->startWorker([
                    'action' => 'wallet_capture',
                    'payment_intent_public_id' => $walletIntent->intentPublicId,
                    'correlation_id' => $this->correlation('zarinpal-wins-wallet-capture'),
                ]);
                self::assertSame("READY\n", $this->readLine($worker, 'readiness'));
                $this->releaseWorkerStart($worker);
                $this->waitForWalletUserLock();

                $verified = $this->app->make(ZarinpalPaymentService::class)->handleCallback(
                    'A'.str_repeat('5', 35),
                    'OK',
                    $this->correlation('zarinpal-wins-zarinpal-callback'),
                );
                self::assertSame('verified', $verified->state->value);
                self::assertNotNull($verified->purchaseSettlementPublicId);
                self::assertFalse($verified->manualReviewRequired);

                $released = DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
                self::assertNotNull($released);
                self::assertSame(1, (int) $released->released);

                $walletResult = $this->finishWorker($worker);
                $worker['closed'] = true;
                self::assertFalse($walletResult['ok'], json_encode($walletResult, JSON_THROW_ON_ERROR));

                self::assertSame(1, DB::table('purchase_settlements')->count());
                self::assertSame('zarinpal', DB::table('purchase_settlements')->value('provider_code'));
                self::assertSame(1, DB::table('orders')->where('state', 'paid')->count());
                self::assertSame($verified->purchaseSettlementPublicId, DB::table('orders')->value('purchase_settlement_public_id'));
                self::assertSame('awaiting_user_action', DB::table('payment_intents')->where('public_id', $walletIntent->intentPublicId)->value('state'));

                $walletHold = DB::table('wallet_holds')->where('source_id', $walletIntent->intentPublicId)->first(['status', 'captured_ledger_transaction_id']);
                self::assertNotNull($walletHold);
                self::assertSame('active', $walletHold->status);
                self::assertNull($walletHold->captured_ledger_transaction_id);
                self::assertSame($baselineLedgerTransactions, DB::table('ledger_transactions')->count());
                self::assertSame($baselineLedgerEntries, DB::table('ledger_entries')->count());
                self::assertSame(0, DB::table('payment_provider_events')->where('provider_code', 'wallet')->count());
                self::assertSame(0, DB::table('payment_provider_transactions')->where('provider_code', 'wallet')->count());
                self::assertSame(0, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());

                self::assertSame(1, DB::table('promotion_usage_reservations')->count());
                self::assertSame(1, DB::table('promotion_usage_redemptions')->count());
                self::assertSame(0, DB::table('promotion_usage_releases')->count());
                self::assertSame(
                    DB::table('purchase_settlements')->where('provider_code', 'zarinpal')->value('id'),
                    DB::table('promotion_usage_redemptions')->value('purchase_settlement_id'),
                );
            } finally {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
                DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_wallet_capture_pause_test');
                if (is_array($worker)) {
                    $this->terminateWorker($worker);
                }
            }
        }

        /** @return array{0:int,1:string,2:string} */
        private function plainPurchaseContext(string $suffix): array
        {
            $administratorId = $this->ownerAdministrator();
            $userId = $this->quoteUser('customer');
            $offering = $this->quoteOffering();
            $quote = $this->app->make(QuoteService::class)->create(
                'zarinpal.deterministic-race.quote.'.$suffix,
                $userId,
                $offering['id'],
                new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, now('UTC')->addMinutes(30)->toDateTimeImmutable()),
                $this->correlation($suffix.'-quote'),
            );
            $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
            $this->configureHealthyMethod($eligibility, $administratorId, 'zarinpal', 1, $suffix);
            $this->configureHealthyMethod($eligibility, $administratorId, 'wallet', 2, $suffix);
            $decision = $eligibility->evaluate('zarinpal.deterministic-race.eligibility.'.$suffix, $userId, $quote->quotePublicId);
            $this->app->make(PurchaseOrderService::class)->openFromQuote($quote->quotePublicId, $userId, $this->correlation($suffix.'-order'));

            return [$userId, $quote->quotePublicId, $decision->publicId];
        }

        /** @return array{0:int,1:string,2:string} */
        private function discountedPurchaseContext(string $suffix): array
        {
            $offering = $this->activeBenefitOffering('zarinpal-deterministic-'.$suffix);
            $version = (int) DB::table('plan_offerings')->where('id', $offering['id'])->value('version');
            $this->app->make(PlanOfferingService::class)->setVisibility(
                $offering['id'],
                $version,
                ProductVisibility::Visible,
                new CatalogChangeContext(
                    'zpal-det-visible-'.substr(hash('sha256', $suffix), 0, 24),
                    'zpal-det-visible-corr-'.substr(hash('sha256', $suffix), 0, 20),
                    'zarinpal_deterministic_race_test',
                    'Expose deterministic Zarinpal race Offering.',
                    $this->benefitOwner(),
                ),
            );
            $rule = $this->usageRule(
                $offering['id'],
                'zpal.det.rule.'.substr(hash('sha256', $suffix), 0, 16),
                90_000,
                1,
                null,
            );
            $campaignCode = 'zpal.det.discount.'.substr(hash('sha256', $suffix), 0, 12);
            $this->benefitCampaign(
                $campaignCode,
                BenefitCodeType::DiscountGrant,
                $this->discountDefinition($rule->ruleCode, $offering['id'], $offering['product_id'], $offering['server_id']),
                'zpal-det-'.$suffix,
            );
            $issue = $this->benefitIssue($campaignCode, 'zpal-det-'.$suffix, 1);
            $code = (string) $issue->items[0]->fullCode;
            $userId = $this->benefitUser('customer');
            $quoteService = $this->app->make(QuoteService::class);
            $sourceQuote = $quoteService->create(
                'zpal-det-source-'.substr(hash('sha256', $suffix), 0, 24),
                $userId,
                $offering['id'],
                new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, now('UTC')->addMinutes(30)->toDateTimeImmutable()),
                $this->correlation($suffix.'-source'),
            );
            $discounts = $this->app->make(QuoteDiscountAuthority::class);
            $authorization = $discounts->authorize(new QuoteDiscountAuthorizationRequest(
                'zpal-det-auth-'.substr(hash('sha256', $suffix), 0, 24),
                $userId,
                $sourceQuote->quotePublicId,
                $sourceQuote->configurationSnapshotHash,
                $code,
                $this->correlation($suffix.'-auth'),
            ));
            $quote = $quoteService->create(
                'zpal-det-quote-'.substr(hash('sha256', $suffix), 0, 24),
                $userId,
                $offering['id'],
                new QuotePricingInput(
                    QuoteOverrideSource::None,
                    null,
                    null,
                    $authorization->ruleCode,
                    $authorization->discountIrr,
                    now('UTC')->addMinutes(30)->toDateTimeImmutable(),
                ),
                $this->correlation($suffix.'-discounted'),
            );
            $discounts->consume(new QuoteDiscountConsumptionRequest(
                'zpal-det-consume-'.substr(hash('sha256', $suffix), 0, 24),
                $userId,
                $authorization,
                $quote->quotePublicId,
                $quote->configurationSnapshotHash,
                $this->correlation($suffix.'-consume'),
            ));

            $administratorId = $this->benefitOwner();
            $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
            $this->configureHealthyMethod($eligibility, $administratorId, 'zarinpal', 1, $suffix.'-discounted');
            $this->configureHealthyMethod($eligibility, $administratorId, 'wallet', 2, $suffix.'-discounted');
            $decision = $eligibility->evaluate(
                'zpal-det-eligibility-'.substr(hash('sha256', $suffix), 0, 24),
                $userId,
                $quote->quotePublicId,
                $quote->configurationSnapshotHash,
            );
            $this->app->make(PurchaseOrderService::class)->openFromQuote($quote->quotePublicId, $userId, $this->correlation($suffix.'-order'));

            return [$userId, $quote->quotePublicId, $decision->publicId];
        }

        private function configureHealthyMethod(
            PaymentMethodEligibilityService $service,
            int $administratorId,
            string $method,
            int $routeOrder,
            string $suffix,
        ): void {
            $service->configureMethod(
                'zpal.det.method.'.$method.'.'.substr(hash('sha256', $suffix), 0, 16),
                $administratorId,
                $method,
                true,
                false,
                $routeOrder,
                'Deterministic cross-method race test configuration.',
                $this->correlation($suffix.'-method-'.$method),
            );
            $service->recordHealth(
                'zpal.det.health.'.$method.'.'.substr(hash('sha256', $suffix), 0, 16),
                $administratorId,
                $method,
                true,
                now('UTC')->addMinutes(10)->toDateTimeImmutable(),
                'Healthy deterministic cross-method race observation.',
                $this->correlation($suffix.'-health-'.$method),
            );
        }

        private function reserveWallet(int $userId, string $quotePublicId, string $decisionPublicId, string $suffix): object
        {
            $walletId = $this->fundedWallet($userId, 2_000_000, $suffix);

            return $this->app->make(PurchaseWalletPaymentService::class)->reserve(
                'purchase.wallet.zpal-deterministic-'.$suffix,
                $userId,
                $walletId,
                $quotePublicId,
                $decisionPublicId,
                $this->correlation($suffix.'-wallet-reserve'),
            );
        }

        private function fundedWallet(int $userId, int $amountIrr, string $suffix): int
        {
            $now = now('UTC');
            $assetId = (int) DB::table('ledger_accounts')->insertGetId([
                'code' => 'system.zpal.det.asset.'.$suffix,
                'account_class' => 'asset',
                'owner_user_id' => null,
                'wallet_bucket' => null,
                'currency' => 'IRR',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $walletId = (int) DB::table('ledger_accounts')->insertGetId([
                'code' => 'wallet.cash.zpal.det.'.$suffix.'.'.$userId,
                'account_class' => 'liability',
                'owner_user_id' => $userId,
                'wallet_bucket' => 'cash',
                'currency' => 'IRR',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->app->make(LedgerPostingService::class)->post(
                'ledger.zpal.det.fund.'.$suffix,
                'zarinpal_deterministic_race_funding',
                $this->correlation($suffix.'-wallet-fund'),
                [
                    new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amountIrr)),
                    new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amountIrr)),
                ],
                'test_fixture',
                'zpal-det-'.$suffix,
            );

            return $walletId;
        }

        /** @param array<string,string> $payload
         * @return array{process:resource,pipes:array{0:resource,1:resource,2:resource},closed:bool}
         */
        private function startWorker(array $payload): array
        {
            $pipes = [];
            $process = proc_open([
                PHP_BINARY,
                '-d',
                'pcov.enabled=0',
                __FILE__,
                '--zarinpal-deterministic-cross-method-worker',
                base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            ], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start deterministic cross-method worker.');
            }
            /** @var array{0:resource,1:resource,2:resource} $pipes */
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            return ['process' => $process, 'pipes' => $pipes, 'closed' => false];
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource},closed:bool} $worker */
        private function releaseWorkerStart(array $worker): void
        {
            fwrite($worker['pipes'][0], "GO\n");
            fflush($worker['pipes'][0]);
            fclose($worker['pipes'][0]);
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource},closed:bool} $worker
         * @return array<string,mixed>
         */
        private function finishWorker(array $worker): array
        {
            $line = $this->readLine($worker, 'result');
            $stderr = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            if (proc_close($worker['process']) !== 0) {
                throw new RuntimeException('Deterministic cross-method worker failed: '.$stderr);
            }
            /** @var array<string,mixed> $result */
            $result = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

            return $result;
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource},closed:bool} $worker */
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
                    throw new RuntimeException('Unable to wait for deterministic cross-method worker output.');
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
                    throw new RuntimeException('Deterministic cross-method worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException('Deterministic cross-method worker timed out during '.$phase.': '.$stderr);
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

        private function waitForWalletUserLock(): void
        {
            $deadline = microtime(true) + self::WORKER_TIMEOUT_SECONDS;
            while (microtime(true) < $deadline) {
                $row = DB::selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM information_schema.PROCESSLIST
WHERE ID <> CONNECTION_ID()
  AND DB = DATABASE()
  AND (`STATE` = 'User lock' OR `INFO` LIKE '%payment_provider_events%')
SQL);
                if ($row !== null && (int) $row->aggregate > 0) {
                    return;
                }
                usleep(20_000);
            }

            throw new RuntimeException('Wallet capture never reached the deterministic post-ledger provider-event barrier.');
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource},closed:bool} $worker */
        private function terminateWorker(array $worker): void
        {
            if ($worker['closed'] || ! is_resource($worker['process'])) {
                return;
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

        private function correlation(string $suffix): string
        {
            return hash('sha256', 'zarinpal-deterministic-race:'.$suffix);
        }
    }
}
