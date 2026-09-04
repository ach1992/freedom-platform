<?php

declare(strict_types=1);

namespace {
    use App\Modules\Payments\CardToCard\Application\CardToCardPaymentService;
    use App\Modules\Payments\CardToCard\Application\CardToCardSettlementService;
    use App\Modules\Payments\CardToCard\Application\Contracts\CardToCardAdjustmentGenerator;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\Connection;
    use Illuminate\Database\DatabaseManager;

    require_once dirname(__DIR__, 2).'/vendor/autoload.php';

    final class C2cContentionFixedAdjustmentGenerator implements CardToCardAdjustmentGenerator
    {
        public function generate(int $minimumIrr, int $maximumIrr): int
        {
            return $minimumIrr;
        }
    }

    $c2cContentionMode = PHP_SAPI === 'cli' ? ($argv[1] ?? null) : null;
    if (in_array($c2cContentionMode, ['--c2c-create-worker', '--c2c-capture-worker'], true)) {
        require_once dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app->instance(CardToCardAdjustmentGenerator::class, new C2cContentionFixedAdjustmentGenerator);
        $database = $app->make(DatabaseManager::class);
        $connection = $database->connection();
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 5');

        $decoded = base64_decode((string) ($argv[2] ?? ''), true);
        if ($decoded === false) {
            fwrite(STDERR, "Invalid C2C contention worker payload encoding.\n");
            exit(2);
        }
        try {
            /** @var array<string,mixed> $payload */
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            fwrite(STDERR, 'Invalid C2C contention worker payload: '.$exception->getMessage()."\n");
            exit(2);
        }

        $barrierReached = false;
        $connection->beforeExecuting(function (string $query, array $bindings, Connection $db) use ($c2cContentionMode, &$barrierReached): void {
            unset($bindings, $db);
            if ($barrierReached) {
                return;
            }
            $sql = strtolower($query);
            $target = $c2cContentionMode === '--c2c-create-worker'
                ? str_contains($sql, 'c2c_destination_accounts') && str_contains($sql, 'for update')
                : str_contains($sql, 'c2c_transaction_matches') && str_contains($sql, 'for update');
            if (! $target) {
                return;
            }
            $barrierReached = true;
            echo "AT_LOCK\n";
            flush();
            $continue = fgets(STDIN);
            if ($continue === false || trim($continue) !== 'CONTINUE') {
                throw new RuntimeException('C2C contention lock barrier was not released.');
            }
        });

        echo "READY\n";
        flush();
        $go = fgets(STDIN);
        if ($go === false || trim($go) !== 'GO') {
            fwrite(STDERR, "C2C contention worker start barrier was not released.\n");
            exit(2);
        }

        try {
            if ($c2cContentionMode === '--c2c-create-worker') {
                $receipt = $app->make(CardToCardPaymentService::class)->create(
                    (string) $payload['creation_key'],
                    (int) $payload['user_id'],
                    (string) $payload['quote_public_id'],
                    (string) $payload['eligibility_public_id'],
                    (string) $payload['correlation_id'],
                );
                echo json_encode([
                    'ok' => true,
                    'reservation_id' => $receipt->reservationId,
                    'base_amount_irr' => $receipt->baseAmountIrr,
                    'adjustment_amount_irr' => $receipt->adjustmentAmountIrr,
                    'payable_amount_irr' => $receipt->payableAmountIrr,
                ], JSON_THROW_ON_ERROR)."\n";
                exit(0);
            }

            $receipt = $app->make(CardToCardSettlementService::class)->capture(
                (string) $payload['match_public_id'],
                (string) $payload['correlation_id'],
            );
            echo json_encode([
                'ok' => true,
                'settlement_id' => $receipt->purchaseSettlementId,
                'replayed' => $receipt->replayed,
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
    use App\Modules\Payments\CardToCard\Application\CardToCardBankTransactionService;
    use App\Modules\Payments\CardToCard\Application\CardToCardDestinationService;
    use App\Modules\Payments\CardToCard\Application\CardToCardMatchingService;
    use App\Modules\Payments\CardToCard\Application\CardToCardPaymentService;
    use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionObservation;
    use App\Modules\Payments\CardToCard\Application\Contracts\CardToCardAdjustmentGenerator;
    use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
    use Database\Seeders\CatalogAccessFoundationSeeder;
    use Database\Seeders\IdentityAccessFoundationSeeder;
    use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
    use DateTimeImmutable;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\TestCase;

    /** @requirement C2C-002 C2C-004 C2C-005 DAT-002 DAT-003 DAT-004 QUA-004 */
    final class CardToCardContentionTest extends TestCase
    {
        use AgentPricingQuoteIntegrationTestSupport;
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 20;

        protected function setUp(): void
        {
            parent::setUp();
            if (DB::connection()->getDriverName() !== 'mysql') {
                $this->markTestSkipped('C2C contention requires MariaDB/MySQL.');
            }
            $this->seed(IdentityAccessFoundationSeeder::class);
            $this->seed(CatalogAccessFoundationSeeder::class);
            $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
            $this->app->instance(CardToCardAdjustmentGenerator::class, new \C2cContentionFixedAdjustmentGenerator);
            config()->set('payments.card_to_card.lookup_key', str_repeat('c', 32));
            $this->configureMethod();
            $this->app->make(CardToCardDestinationService::class)->register(
                'contention-primary',
                '4242424242424242',
                'Contention Account',
                true,
                1000,
                9990,
                30,
                120,
                null,
                10,
                'fake',
                'C2C contention destination.',
                $this->correlation('destination'),
            );
        }

        protected function tearDown(): void
        {
            try {
                $this->truncateDatabaseTables();
            } finally {
                parent::tearDown();
            }
        }

        public function test_two_concurrent_equal_base_intents_receive_distinct_active_payable_amounts(): void
        {
            [$userA, $quoteA, $decisionA] = $this->preparedPayment('create-a');
            [$userB, $quoteB, $decisionB] = $this->preparedPayment('create-b');

            $workerA = $this->startWorker('--c2c-create-worker', [
                'creation_key' => 'c2c.contention.intent.a',
                'user_id' => $userA,
                'quote_public_id' => $quoteA,
                'eligibility_public_id' => $decisionA,
                'correlation_id' => $this->correlation('create-a'),
            ]);
            $workerB = $this->startWorker('--c2c-create-worker', [
                'creation_key' => 'c2c.contention.intent.b',
                'user_id' => $userB,
                'quote_public_id' => $quoteB,
                'eligibility_public_id' => $decisionB,
                'correlation_id' => $this->correlation('create-b'),
            ]);

            try {
                self::assertSame("READY\n", $this->readLine($workerA, 'create A readiness'));
                self::assertSame("READY\n", $this->readLine($workerB, 'create B readiness'));
                $this->sendCommand($workerA, 'GO');
                $this->sendCommand($workerB, 'GO');
                self::assertSame("AT_LOCK\n", $this->readLine($workerA, 'create A lock barrier'));
                self::assertSame("AT_LOCK\n", $this->readLine($workerB, 'create B lock barrier'));
                $this->sendCommand($workerA, 'CONTINUE');
                $this->sendCommand($workerB, 'CONTINUE');

                $resultA = $this->readJsonResult($workerA, 'create A result');
                $resultB = $this->readJsonResult($workerB, 'create B result');
                self::assertTrue((bool) ($resultA['ok'] ?? false), json_encode($resultA, JSON_THROW_ON_ERROR));
                self::assertTrue((bool) ($resultB['ok'] ?? false), json_encode($resultB, JSON_THROW_ON_ERROR));
                self::assertSame((int) $resultA['base_amount_irr'], (int) $resultB['base_amount_irr']);
                self::assertNotSame((int) $resultA['payable_amount_irr'], (int) $resultB['payable_amount_irr']);
                self::assertEqualsCanonicalizing([1000, 1001], [(int) $resultA['adjustment_amount_irr'], (int) $resultB['adjustment_amount_irr']]);
                self::assertSame(2, DB::table('c2c_amount_reservations')->where('active_lock', 1)->count());
            } finally {
                $this->closeWorker($workerA);
                $this->closeWorker($workerB);
            }
        }

        public function test_duplicate_concurrent_capture_creates_one_purchase_settlement_and_one_replay(): void
        {
            [$user, $quote, $decision] = $this->preparedPayment('capture');
            $opening = $this->app->make(PurchaseOrderService::class)->openFromQuote(
                $quote,
                $user,
                $this->correlation('capture-order'),
            );
            $payment = $this->app->make(CardToCardPaymentService::class)->create(
                'c2c.contention.capture.intent',
                $user,
                $quote,
                $decision,
                $this->correlation('capture-payment'),
            );
            $bank = $this->app->make(CardToCardBankTransactionService::class)->ingest(
                'fake',
                new BankTransactionObservation(
                    'capture-race-tx',
                    'capture-race-event',
                    '4242424242424242',
                    $payment->payableAmountIrr,
                    'settled',
                    new DateTimeImmutable('now', new \DateTimeZone('UTC')),
                    null,
                    null,
                    'capture-race-ref',
                    hash('sha256', 'capture-race-evidence'),
                ),
                'fake',
                $this->correlation('capture-bank'),
            );
            $match = $this->app->make(CardToCardMatchingService::class)->match(
                $bank->publicId,
                $this->correlation('capture-match'),
            );
            self::assertNotNull($match->matchPublicId);

            $workerA = $this->startWorker('--c2c-capture-worker', [
                'match_public_id' => $match->matchPublicId,
                'correlation_id' => $this->correlation('capture-a'),
            ]);
            $workerB = $this->startWorker('--c2c-capture-worker', [
                'match_public_id' => $match->matchPublicId,
                'correlation_id' => $this->correlation('capture-b'),
            ]);

            try {
                self::assertSame("READY\n", $this->readLine($workerA, 'capture A readiness'));
                self::assertSame("READY\n", $this->readLine($workerB, 'capture B readiness'));
                $this->sendCommand($workerA, 'GO');
                $this->sendCommand($workerB, 'GO');
                self::assertSame("AT_LOCK\n", $this->readLine($workerA, 'capture A lock barrier'));
                self::assertSame("AT_LOCK\n", $this->readLine($workerB, 'capture B lock barrier'));
                $this->sendCommand($workerA, 'CONTINUE');
                $this->sendCommand($workerB, 'CONTINUE');

                $resultA = $this->readJsonResult($workerA, 'capture A result');
                $resultB = $this->readJsonResult($workerB, 'capture B result');
                self::assertTrue((bool) ($resultA['ok'] ?? false), json_encode($resultA, JSON_THROW_ON_ERROR));
                self::assertTrue((bool) ($resultB['ok'] ?? false), json_encode($resultB, JSON_THROW_ON_ERROR));
                self::assertSame((int) $resultA['settlement_id'], (int) $resultB['settlement_id']);
                self::assertEqualsCanonicalizing([false, true], [(bool) $resultA['replayed'], (bool) $resultB['replayed']]);
                self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'card_to_card')->count());
                self::assertSame(1, DB::table('payment_provider_transactions')->where('provider_code', 'card_to_card')->count());
                self::assertSame(1, DB::table('c2c_transaction_matches')->where('state', 'captured')->count());
                self::assertSame(1, DB::table('orders')->count());
                $order = DB::table('orders')->where('public_id', $opening->orderPublicId)->first();
                self::assertNotNull($order);
                self::assertSame('paid', $order->state);
                self::assertSame((int) $resultA['settlement_id'], (int) $order->purchase_settlement_id);
                self::assertSame($payment->paymentIntent->intentPublicId, $order->payment_intent_public_id);
            } finally {
                $this->closeWorker($workerA);
                $this->closeWorker($workerB);
            }
        }

        /** @return array{0:int,1:string,2:string} */
        private function preparedPayment(string $suffix): array
        {
            $user = $this->quoteUser('customer');
            $offering = $this->quoteOffering();
            $quote = $this->app->make(QuoteService::class)->create(
                'c2c.contention.quote.'.$suffix,
                $user,
                $offering['id'],
                new QuotePricingInput(
                    QuoteOverrideSource::None,
                    null,
                    null,
                    null,
                    0,
                    new DateTimeImmutable('+30 minutes', new \DateTimeZone('UTC')),
                ),
                $this->correlation('quote-'.$suffix),
            );
            $decision = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
                'c2c.contention.eligibility.'.$suffix,
                $user,
                $quote->quotePublicId,
            );

            return [$user, $quote->quotePublicId, $decision->publicId];
        }

        private function configureMethod(): void
        {
            $administratorId = $this->ownerAdministrator();
            $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
            $eligibility->configureMethod(
                'c2c.contention.method',
                $administratorId,
                'card_to_card',
                true,
                false,
                1,
                'C2C contention method.',
                $this->correlation('method'),
            );
            $eligibility->recordHealth(
                'c2c.contention.health',
                $administratorId,
                'card_to_card',
                true,
                new DateTimeImmutable('+20 minutes', new \DateTimeZone('UTC')),
                'Healthy C2C contention provider.',
                $this->correlation('health'),
            );
        }

        /** @return array{process:resource,pipes:array{0:resource,1:resource,2:resource}} */
        private function startWorker(string $mode, array $payload): array
        {
            $pipes = [];
            $process = proc_open([
                PHP_BINARY,
                '-d',
                'pcov.enabled=0',
                __FILE__,
                $mode,
                base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            ], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start C2C contention worker.');
            }
            /** @var array{0:resource,1:resource,2:resource} $pipes */
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            return ['process' => $process, 'pipes' => $pipes];
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker */
        private function sendCommand(array $worker, string $command): void
        {
            fwrite($worker['pipes'][0], $command."\n");
            fflush($worker['pipes'][0]);
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
                    throw new RuntimeException('Unable to wait for C2C contention worker output.');
                }
                foreach ($read as $stream) {
                    if ($stream === $worker['pipes'][2]) {
                        $stderr .= stream_get_contents($stream);

                        continue;
                    }
                    $line = fgets($stream);
                    if ($line !== false) {
                        if (trim($line) === '') {
                            continue;
                        }

                        return $line;
                    }
                }
                $status = proc_get_status($worker['process']);
                if (! $status['running'] && feof($worker['pipes'][1])) {
                    $stderr .= stream_get_contents($worker['pipes'][2]);
                    throw new RuntimeException('C2C contention worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException('C2C contention worker timed out during '.$phase.': '.$stderr);
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker */
        private function readJsonResult(array $worker, string $phase): array
        {
            /** @var array<string,mixed> $result */
            $result = json_decode($this->readLine($worker, $phase), true, flags: JSON_THROW_ON_ERROR);

            return $result;
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker */
        private function closeWorker(array $worker): void
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

        private function correlation(string $suffix): string
        {
            return hash('sha256', 'c2c-contention:'.$suffix);
        }
    }
}
