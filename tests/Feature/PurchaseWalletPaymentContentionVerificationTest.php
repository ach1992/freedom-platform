<?php

declare(strict_types=1);

namespace {
    use App\Modules\Payments\Application\PurchaseWalletPaymentService;
    use Illuminate\Contracts\Console\Kernel;

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--purchase-wallet-contention-worker') {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

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
            $service = $app->make(PurchaseWalletPaymentService::class);
            $action = $payload['action'] ?? null;
            if (! is_string($action)) {
                throw new RuntimeException('Worker action is missing.');
            }

            $result = match ($action) {
                'reserve' => (static function () use ($service, $payload): array {
                    $receipt = $service->reserve(
                        (string) $payload['creation_key'],
                        (int) $payload['user_id'],
                        (int) $payload['wallet_account_id'],
                        (string) $payload['quote_public_id'],
                        (string) $payload['decision_public_id'],
                        (string) $payload['correlation_id'],
                    );

                    return ['intent' => $receipt->intentPublicId, 'replayed' => $receipt->replayed];
                })(),
                'capture' => (static function () use ($service, $payload): array {
                    $receipt = $service->capture(
                        (string) $payload['intent_public_id'],
                        (string) $payload['correlation_id'],
                    );

                    return ['order' => $receipt->orderPublicId, 'replayed' => $receipt->replayed];
                })(),
                'release' => (static function () use ($service, $payload): array {
                    $receipt = $service->release(
                        (string) $payload['intent_public_id'],
                        (string) $payload['reason'],
                    );

                    return ['hold' => $receipt->holdId, 'status' => $receipt->status->value, 'replayed' => $receipt->replayed];
                })(),
                default => throw new RuntimeException('Unknown purchase-wallet worker action.'),
            };

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
    use App\Modules\Orders\Application\PurchaseOrderService;
    use App\Modules\Orders\Application\QuotePricingInput;
    use App\Modules\Orders\Application\QuoteService;
    use App\Modules\Orders\Domain\QuoteOverrideSource;
    use App\Modules\Payments\Application\PurchaseWalletPaymentService;
    use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
    use App\Modules\Wallet\Application\LedgerEntryDraft;
    use App\Modules\Wallet\Application\LedgerPostingService;
    use App\Modules\Wallet\Application\WalletHoldService;
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

    /** @requirement BUY-002 PAY-001 PAY-002 PAY-003 WAL-001 WAL-002 DAT-002 DAT-003 DAT-004 QUA-001 */
    final class PurchaseWalletPaymentContentionVerificationTest extends TestCase
    {
        use AgentPricingQuoteIntegrationTestSupport;
        use DatabaseTruncation;

        protected function setUp(): void
        {
            parent::setUp();
            $this->seed(IdentityAccessFoundationSeeder::class);
            $this->seed(CatalogAccessFoundationSeeder::class);
            $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
            $this->seed(WalletFinancialFoundationSeeder::class);
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

        public function test_two_purchase_intents_cannot_concurrently_over_reserve_one_wallet(): void
        {
            $administratorId = $this->ownerAdministrator();
            $userId = $this->quoteUser('customer');
            $this->configureWalletMethod($administratorId, 'over-reserve');
            [$quoteA, $decisionA] = $this->quoteAndDecision($userId, 'over-reserve-a', 700_000);
            [$quoteB, $decisionB] = $this->quoteAndDecision($userId, 'over-reserve-b', 700_000);
            $walletId = $this->fundedWallet($userId, 1_000_000, 'over-reserve');

            $results = $this->runConcurrent([
                [
                    'action' => 'reserve',
                    'creation_key' => 'purchase.wallet.contention.reserve.a',
                    'user_id' => $userId,
                    'wallet_account_id' => $walletId,
                    'quote_public_id' => $quoteA->quotePublicId,
                    'decision_public_id' => $decisionA->publicId,
                    'correlation_id' => $this->correlation('reserve-a'),
                ],
                [
                    'action' => 'reserve',
                    'creation_key' => 'purchase.wallet.contention.reserve.b',
                    'user_id' => $userId,
                    'wallet_account_id' => $walletId,
                    'quote_public_id' => $quoteB->quotePublicId,
                    'decision_public_id' => $decisionB->publicId,
                    'correlation_id' => $this->correlation('reserve-b'),
                ],
            ]);

            self::assertSame(1, $this->successCount($results), $this->diagnostic($results));
            self::assertSame(1, $this->failureCount($results), $this->diagnostic($results));
            self::assertSame(1, DB::table('payment_intents')->where('purpose', 'purchase')->count());
            self::assertSame(1, DB::table('purchase_wallet_reservations')->count());
            self::assertSame(1, DB::table('wallet_holds')->where('source_type', 'payment_intent')->where('status', 'active')->count());
            self::assertSame(700_000, (int) DB::table('wallet_holds')->where('status', 'active')->sum('amount_irr'));
            self::assertSame(300_000, $this->app->make(WalletHoldService::class)->balance($userId, $walletId)->availableBalance->amount);
        }

        public function test_capture_and_release_race_has_one_terminal_wallet_purchase_outcome(): void
        {
            $administratorId = $this->ownerAdministrator();
            $userId = $this->quoteUser('customer');
            $this->configureWalletMethod($administratorId, 'terminal-race');
            [$quote, $decision] = $this->quoteAndDecision($userId, 'terminal-race', 400_000);
            $walletId = $this->fundedWallet($userId, 1_000_000, 'terminal-race');
            $opening = $this->app->make(PurchaseOrderService::class)->openFromQuote(
                $quote->quotePublicId,
                $userId,
                $this->correlation('order-open'),
            );
            $intent = $this->app->make(PurchaseWalletPaymentService::class)->reserve(
                'purchase.wallet.contention.terminal.000001',
                $userId,
                $walletId,
                $quote->quotePublicId,
                $decision->publicId,
                $this->correlation('terminal-reserve'),
            );
            $ledgerBefore = DB::table('ledger_transactions')->count();

            $results = $this->runConcurrent([
                [
                    'action' => 'capture',
                    'intent_public_id' => $intent->intentPublicId,
                    'correlation_id' => $this->correlation('terminal-capture'),
                ],
                [
                    'action' => 'release',
                    'intent_public_id' => $intent->intentPublicId,
                    'reason' => 'concurrent wallet purchase release',
                ],
            ]);

            self::assertSame(1, $this->successCount($results), $this->diagnostic($results));
            self::assertSame(1, $this->failureCount($results), $this->diagnostic($results));
            $holdStatus = (string) DB::table('wallet_holds')->where('source_id', $intent->intentPublicId)->value('status');
            self::assertContains($holdStatus, ['captured', 'released']);
            self::assertSame(1, DB::table('orders')->where('id', $opening->orderId)->count());

            if ($holdStatus === 'captured') {
                self::assertSame($ledgerBefore + 1, DB::table('ledger_transactions')->count());
                self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
                self::assertSame('captured', DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->value('state'));
                self::assertSame('paid', DB::table('orders')->where('id', $opening->orderId)->value('state'));
                self::assertSame(600_000, $this->app->make(WalletHoldService::class)->balance($userId, $walletId)->availableBalance->amount);
            } else {
                self::assertSame($ledgerBefore, DB::table('ledger_transactions')->count());
                self::assertSame(0, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
                self::assertSame('awaiting_user_action', DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->value('state'));
                self::assertSame('awaiting_payment', DB::table('orders')->where('id', $opening->orderId)->value('state'));
                self::assertSame(1_000_000, $this->app->make(WalletHoldService::class)->balance($userId, $walletId)->availableBalance->amount);
            }
        }

        /** @return array{0:object,1:object} */
        private function quoteAndDecision(int $userId, string $suffix, int $amountIrr): array
        {
            $offering = $this->quoteOffering($amountIrr);
            $quote = $this->app->make(QuoteService::class)->create(
                'purchase.wallet.contention.quote.'.$suffix,
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
                $this->correlation('quote-'.$suffix),
            );
            $decision = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
                'purchase.wallet.contention.eligibility.'.$suffix,
                $userId,
                $quote->quotePublicId,
            );

            return [$quote, $decision];
        }

        private function configureWalletMethod(int $administratorId, string $suffix): void
        {
            $service = $this->app->make(PaymentMethodEligibilityService::class);
            $service->configureMethod(
                'purchase.wallet.contention.method.'.$suffix,
                $administratorId,
                'wallet',
                true,
                false,
                1,
                'Wallet purchase contention configuration.',
                $this->correlation('method-'.$suffix),
            );
            $service->recordHealth(
                'purchase.wallet.contention.health.'.$suffix,
                $administratorId,
                'wallet',
                true,
                now('UTC')->addMinutes(10)->toDateTimeImmutable(),
                'Healthy wallet purchase contention observation.',
                $this->correlation('health-'.$suffix),
            );
        }

        private function fundedWallet(int $userId, int $amountIrr, string $suffix): int
        {
            $now = now('UTC');
            $assetId = (int) DB::table('ledger_accounts')->insertGetId([
                'code' => 'system.wallet.purchase.contention.asset.'.$suffix,
                'account_class' => 'asset',
                'owner_user_id' => null,
                'wallet_bucket' => null,
                'currency' => 'IRR',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $walletId = (int) DB::table('ledger_accounts')->insertGetId([
                'code' => 'wallet.cash.purchase.contention.'.$suffix.'.'.$userId,
                'account_class' => 'liability',
                'owner_user_id' => $userId,
                'wallet_bucket' => 'cash',
                'currency' => 'IRR',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->app->make(LedgerPostingService::class)->post(
                'ledger.purchase.wallet.contention.fund.'.$suffix,
                'wallet_purchase_test_funding',
                $this->correlation('fund-'.$suffix),
                [
                    new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amountIrr)),
                    new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amountIrr)),
                ],
                'test_fixture',
                'purchase-wallet-contention-'.$suffix,
            );

            return $walletId;
        }

        /** @param list<array<string, mixed>> $payloads @return list<array<string, mixed>> */
        private function runConcurrent(array $payloads): array
        {
            $workers = [];
            foreach ($payloads as $payload) {
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, __FILE__, '--purchase-wallet-contention-worker', base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    base_path(),
                );
                if (! is_resource($process)) {
                    throw new RuntimeException('Unable to start purchase-wallet contention worker.');
                }
                if (fgets($pipes[1]) !== "READY\n") {
                    $stderr = stream_get_contents($pipes[2]);
                    proc_terminate($process);
                    proc_close($process);
                    throw new RuntimeException('Purchase-wallet contention worker failed before barrier: '.trim((string) $stderr));
                }
                $workers[] = [$process, $pipes];
            }

            foreach ($workers as [, $pipes]) {
                fwrite($pipes[0], "GO\n");
                fclose($pipes[0]);
            }

            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exitCode = proc_close($process);
                if ($exitCode !== 0 || trim((string) $stderr) !== '') {
                    throw new RuntimeException(sprintf(
                        'Purchase-wallet contention worker failed (%d): %s',
                        $exitCode,
                        trim((string) $stderr),
                    ));
                }
                $decoded = json_decode(trim((string) $stdout), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($decoded) || ! isset($decoded['ok']) || ! is_bool($decoded['ok'])) {
                    throw new RuntimeException('Purchase-wallet contention worker returned malformed output.');
                }
                $results[] = $decoded;
            }

            return $results;
        }

        /** @param list<array<string, mixed>> $results */
        private function successCount(array $results): int
        {
            return count(array_filter($results, static fn (array $result): bool => $result['ok'] === true));
        }

        /** @param list<array<string, mixed>> $results */
        private function failureCount(array $results): int
        {
            return count($results) - $this->successCount($results);
        }

        /** @param list<array<string, mixed>> $results */
        private function diagnostic(array $results): string
        {
            return json_encode($results, JSON_THROW_ON_ERROR);
        }

        private function correlation(string $suffix): string
        {
            return hash('sha256', 'purchase-wallet-contention:'.$suffix);
        }
    }
}
