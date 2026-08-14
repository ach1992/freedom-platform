<?php

declare(strict_types=1);

namespace {
    use App\Modules\Payments\Usdt\Application\Contracts\UsdtBlockchainVerificationEvidence;
    use App\Modules\Payments\Usdt\Application\UsdtBep20Asset;
    use App\Modules\Payments\Usdt\Application\UsdtVerifiedTransferService;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\Connection;
    use Illuminate\Database\DatabaseManager;

    $usdtContentionMode = PHP_SAPI === 'cli' ? ($argv[1] ?? null) : null;
    if ($usdtContentionMode === '--usdt-verified-transfer-worker') {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $database = $app->make(DatabaseManager::class);
        $connection = $database->connection();
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 5');

        $decoded = base64_decode((string) ($argv[2] ?? ''), true);
        if ($decoded === false) {
            fwrite(STDERR, "Invalid USDT contention payload encoding.\n");
            exit(2);
        }
        try {
            /** @var array<string,mixed> $payload */
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            fwrite(STDERR, 'Invalid USDT contention payload: '.$exception->getMessage()."\n");
            exit(2);
        }

        $barrierReached = false;
        $connection->beforeExecuting(function (string $query, array $bindings, Connection $db) use (&$barrierReached): void {
            unset($bindings, $db);
            if ($barrierReached) {
                return;
            }
            $sql = strtolower($query);
            if (! str_contains($sql, 'usdt_txid_submissions') || ! str_contains($sql, 'for update')) {
                return;
            }
            $barrierReached = true;
            echo "AT_TRANSFER_LOCK\n";
            flush();
            $continue = fgets(STDIN);
            if ($continue === false || trim($continue) !== 'CONTINUE') {
                throw new RuntimeException('USDT contention transfer-lock barrier was not released.');
            }
        });

        echo "READY\n";
        flush();
        $go = fgets(STDIN);
        if ($go === false || trim($go) !== 'GO') {
            fwrite(STDERR, "USDT contention start barrier was not released.\n");
            exit(2);
        }

        try {
            $evidence = new UsdtBlockchainVerificationEvidence(
                'success',
                'success',
                (string) $payload['provider_event_id'],
                (string) $payload['txid'],
                'BEP20',
                UsdtBep20Asset::CHAIN_ID,
                (string) $payload['token_contract'],
                (string) $payload['destination_address'],
                (string) $payload['amount_base_units'],
                UsdtBep20Asset::TOKEN_DECIMALS,
                (int) $payload['confirmations'],
                (int) $payload['block_number'],
                new DateTimeImmutable((string) $payload['transaction_at']),
                new DateTimeImmutable((string) $payload['observed_at']),
                (string) $payload['evidence_hash'],
                ['source' => 'usdt_contention_worker'],
            );
            $receipt = $app->make(UsdtVerifiedTransferService::class)->recordAndSettle(
                (string) $payload['submission_public_id'],
                'fake_bep20',
                $evidence,
                (string) $payload['correlation_id'],
            );
            echo json_encode([
                'ok' => true,
                'state' => $receipt->state,
                'transfer_public_id' => $receipt->verifiedTransferPublicId,
                'settlement_public_id' => $receipt->purchaseSettlementPublicId,
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
    use App\Modules\Orders\Application\QuotePricingInput;
    use App\Modules\Orders\Application\QuoteService;
    use App\Modules\Orders\Domain\QuoteOverrideSource;
    use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
    use App\Modules\Payments\Usdt\Application\Contracts\UsdtBlockchainVerificationEvidence;
    use App\Modules\Payments\Usdt\Application\UsdtAmountQuoteService;
    use App\Modules\Payments\Usdt\Application\UsdtBep20Asset;
    use App\Modules\Payments\Usdt\Application\UsdtBlockchainVerificationService;
    use App\Modules\Payments\Usdt\Application\UsdtCircuitBreaker;
    use App\Modules\Payments\Usdt\Application\UsdtDestinationWalletService;
    use App\Modules\Payments\Usdt\Application\UsdtPaymentAuthorityService;
    use App\Modules\Payments\Usdt\Application\UsdtRateResolver;
    use App\Modules\Payments\Usdt\Application\UsdtTxidSubmissionService;
    use App\Modules\Payments\Usdt\Domain\UsdtRate;
    use App\Modules\Payments\Usdt\Domain\UsdtRatePolicy;
    use App\Modules\Payments\Usdt\Domain\UsdtRateProvider;
    use App\Modules\Payments\Usdt\Domain\UsdtRateSide;
    use App\Modules\Payments\Usdt\Infrastructure\FakeBlockchainTransactionVerificationProvider;
    use App\Shared\Application\Clock;
    use Database\Seeders\CatalogAccessFoundationSeeder;
    use Database\Seeders\IdentityAccessFoundationSeeder;
    use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
    use Database\Seeders\UsdtAccessFoundationSeeder;
    use DateTimeImmutable;
    use Illuminate\Cache\ArrayStore;
    use Illuminate\Cache\Repository;
    use Illuminate\Database\DatabaseManager;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\TestCase;

    final class UsdtContentionClock implements Clock
    {
        public function __construct(public DateTimeImmutable $value) {}

        public function now(): DateTimeImmutable
        {
            return $this->value;
        }
    }

    final class UsdtContentionRateProvider implements UsdtRateProvider
    {
        public function __construct(
            private readonly string $providerCode,
            private readonly string $rateIrr,
            private readonly DateTimeImmutable $fetchedAt,
        ) {}

        public function code(): string
        {
            return $this->providerCode;
        }

        public function fetch(UsdtRateSide $side): UsdtRate
        {
            return new UsdtRate(
                $this->providerCode,
                $this->rateIrr,
                $this->fetchedAt,
                hash('sha256', $this->providerCode."\0".$this->rateIrr."\0".$side->value),
            );
        }
    }

    /** @requirement USDT-003 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 QUA-004 */
    final class UsdtVerifiedTransferContentionTest extends TestCase
    {
        use AgentPricingQuoteIntegrationTestSupport;
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 20;

        private UsdtContentionClock $clock;

        protected function setUp(): void
        {
            parent::setUp();
            if (DB::connection()->getDriverName() !== 'mysql') {
                $this->markTestSkipped('USDT verified-transfer contention requires MariaDB/MySQL.');
            }
            $this->seed(IdentityAccessFoundationSeeder::class);
            $this->seed(CatalogAccessFoundationSeeder::class);
            $this->seed(UsdtAccessFoundationSeeder::class);
            $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
            $this->clock = new UsdtContentionClock(new DateTimeImmutable('2026-08-14T06:30:00+00:00'));
            $this->app->instance(Clock::class, $this->clock);
            config()->set('payments.usdt_bep20.chain_id', 56);
            config()->set('payments.usdt_bep20.token_contract', UsdtBep20Asset::TOKEN_CONTRACT);
            config()->set('payments.usdt_bep20.minimum_confirmations', 15);
            $this->configureMethod();
        }

        protected function tearDown(): void
        {
            try {
                $this->truncateDatabaseTables();
            } finally {
                parent::tearDown();
            }
        }

        public function test_concurrent_same_verified_transfer_converges_on_one_common_settlement(): void
        {
            $setup = $this->submissionInVerifyingState();
            $payloadBase = [
                'submission_public_id' => $setup['submission_public_id'],
                'provider_event_id' => 'usdt-contention-chain-event',
                'txid' => $setup['txid'],
                'token_contract' => $setup['token_contract'],
                'destination_address' => $setup['destination_address'],
                'amount_base_units' => $setup['amount_base_units'],
                'confirmations' => 20,
                'block_number' => 12345678,
                'transaction_at' => $setup['transaction_at'],
                'observed_at' => $setup['observed_at'],
                'evidence_hash' => hash('sha256', 'usdt-contention-chain-evidence'),
            ];
            $first = $this->startWorker($payloadBase + ['correlation_id' => hash('sha256', 'usdt-contention-worker-1')]);
            $second = $this->startWorker($payloadBase + ['correlation_id' => hash('sha256', 'usdt-contention-worker-2')]);

            try {
                self::assertSame("READY\n", $this->readLine($first, 'first readiness'));
                self::assertSame("READY\n", $this->readLine($second, 'second readiness'));
                $this->sendCommand($first, 'GO');
                $this->sendCommand($second, 'GO');
                self::assertSame("AT_TRANSFER_LOCK\n", $this->readLine($first, 'first transfer lock'));
                self::assertSame("AT_TRANSFER_LOCK\n", $this->readLine($second, 'second transfer lock'));
                $this->sendCommand($first, 'CONTINUE');
                $this->sendCommand($second, 'CONTINUE');

                $firstResult = $this->readJsonResult($first, 'first result');
                $secondResult = $this->readJsonResult($second, 'second result');
                self::assertTrue((bool) ($firstResult['ok'] ?? false), json_encode($firstResult, JSON_THROW_ON_ERROR));
                self::assertTrue((bool) ($secondResult['ok'] ?? false), json_encode($secondResult, JSON_THROW_ON_ERROR));
                self::assertSame('captured', $firstResult['state']);
                self::assertSame('captured', $secondResult['state']);
                self::assertSame($firstResult['transfer_public_id'], $secondResult['transfer_public_id']);
                self::assertSame($firstResult['settlement_public_id'], $secondResult['settlement_public_id']);
                self::assertSame([false, true], collect([$firstResult['replayed'], $secondResult['replayed']])->sort()->values()->all());
                self::assertSame(1, DB::table('usdt_verified_transfers')->count());
                self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'usdt_bep20')->count());
                self::assertSame(1, DB::table('payment_provider_transactions')->where('provider_code', 'usdt_bep20')->count());
            } finally {
                $this->closeWorker($first);
                $this->closeWorker($second);
            }
        }

        /** @return array{submission_public_id:string,txid:string,token_contract:string,destination_address:string,amount_base_units:int,transaction_at:string,observed_at:string} */
        private function submissionInVerifyingState(): array
        {
            $user = $this->quoteUser('customer');
            $offering = $this->quoteOffering(1_000_000);
            $quote = $this->app->make(QuoteService::class)->create(
                'usdt.contention.quote',
                $user,
                $offering['id'],
                new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->value->modify('+30 minutes')),
                hash('sha256', 'usdt-contention-quote'),
            );
            $this->app->make(UsdtDestinationWalletService::class)->configure(
                'usdt.contention.wallet',
                $this->ownerAdministrator(),
                'primary',
                '0x'.str_repeat('bb', 20),
                true,
                'USDT contention destination.',
                hash('sha256', 'usdt-contention-wallet'),
            );
            $amountQuote = $this->amountService()->create('usdt.contention.amount', $quote->quotePublicId);
            $decision = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
                'usdt.contention.eligibility',
                $user,
                $quote->quotePublicId,
            );
            $authority = $this->app->make(UsdtPaymentAuthorityService::class)->prepare(
                'usdt.contention.authority',
                'usdt.contention.intent',
                $user,
                $quote->quotePublicId,
                $decision->publicId,
                $amountQuote->publicId,
                hash('sha256', 'usdt-contention-authority'),
            );
            $submission = $this->app->make(UsdtTxidSubmissionService::class)->submit(
                'usdt.contention.txid',
                $authority->publicId,
                $user,
                '0x'.str_repeat('ef', 32),
                null,
                null,
                hash('sha256', 'usdt-contention-txid'),
            );

            $provider = new FakeBlockchainTransactionVerificationProvider('fake_bep20');
            $provider->put(new UsdtBlockchainVerificationEvidence(
                'pending',
                'pending',
                'usdt-contention-pending-event',
                $submission->txid,
                null,
                null,
                null,
                null,
                null,
                null,
                0,
                null,
                null,
                $this->clock->value,
                hash('sha256', 'usdt-contention-pending-evidence'),
                ['source' => 'usdt_contention_setup'],
            ));
            $pending = $this->app->make(UsdtBlockchainVerificationService::class)->verify(
                $submission->publicId,
                $provider,
                hash('sha256', 'usdt-contention-pending-lookup'),
            );
            self::assertSame('verifying', $pending->state);

            $authorityRow = DB::table('usdt_payment_authorities')->where('public_id', $authority->publicId)->first();
            self::assertNotNull($authorityRow);
            $transactionAt = new DateTimeImmutable((string) $authorityRow->created_at, new \DateTimeZone('UTC'));

            return [
                'submission_public_id' => $submission->publicId,
                'txid' => $submission->txid,
                'token_contract' => (string) $authorityRow->token_contract,
                'destination_address' => (string) $authorityRow->destination_address,
                'amount_base_units' => (int) $authorityRow->expected_amount_base_units,
                'transaction_at' => $transactionAt->format(DATE_ATOM),
                'observed_at' => $transactionAt->modify('+10 seconds')->format(DATE_ATOM),
            ];
        }

        private function amountService(): UsdtAmountQuoteService
        {
            $primary = new UsdtContentionRateProvider('nobitex', '1000000', $this->clock->value);
            $secondary = new UsdtContentionRateProvider('secondary', '1005000', $this->clock->value);
            $policy = new UsdtRatePolicy(['nobitex', 'secondary'], UsdtRateSide::Buy, 120, '100000', '10000000', 500, false, 3, 60);

            return new UsdtAmountQuoteService(
                $this->app->make(DatabaseManager::class),
                $this->app->make(QuoteService::class),
                $this->app->make(UsdtDestinationWalletService::class),
                new UsdtRateResolver(
                    [$primary, $secondary],
                    $policy,
                    new UsdtCircuitBreaker(new Repository(new ArrayStore), $this->clock, 3, 60),
                    $this->clock,
                ),
                $this->clock,
                0,
                6,
                120,
                120,
                'primary',
            );
        }

        private function configureMethod(): void
        {
            $owner = $this->ownerAdministrator();
            $service = $this->app->make(PaymentMethodEligibilityService::class);
            $service->configureMethod(
                'usdt.contention.method',
                $owner,
                'usdt_bep20',
                true,
                false,
                1,
                'USDT contention method.',
                hash('sha256', 'usdt-contention-method'),
            );
            $service->recordHealth(
                'usdt.contention.health',
                $owner,
                'usdt_bep20',
                true,
                $this->clock->value->modify('+20 minutes'),
                'USDT contention provider is healthy.',
                hash('sha256', 'usdt-contention-health'),
            );
        }

        /** @return array{process:resource,pipes:array{0:resource,1:resource,2:resource}} */
        private function startWorker(array $payload): array
        {
            $pipes = [];
            $process = proc_open([
                PHP_BINARY,
                '-d',
                'pcov.enabled=0',
                __FILE__,
                '--usdt-verified-transfer-worker',
                base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            ], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start USDT verified-transfer contention worker.');
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
                    throw new RuntimeException('Unable to wait for USDT contention worker output.');
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
                    throw new RuntimeException('USDT contention worker exited before '.$phase.' output: '.$stderr);
                }
            }
            throw new RuntimeException('USDT contention worker timed out waiting for '.$phase.'. STDERR: '.$stderr);
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker @return array<string,mixed> */
        private function readJsonResult(array $worker, string $phase): array
        {
            $line = $this->readLine($worker, $phase);
            try {
                $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new RuntimeException('Invalid USDT contention JSON result: '.$line, 0, $exception);
            }
            if (! is_array($decoded)) {
                throw new RuntimeException('USDT contention result is not an object.');
            }

            return $decoded;
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
                proc_terminate($worker['process']);
                proc_close($worker['process']);
            }
        }
    }
}
