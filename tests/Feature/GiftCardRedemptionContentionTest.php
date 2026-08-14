<?php

declare(strict_types=1);

namespace {
    use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderEvidence;
    use App\Modules\Payments\GiftCard\Application\GiftCardRedemptionService;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\Connection;
    use Illuminate\Database\DatabaseManager;

    $giftCardContentionMode = PHP_SAPI === 'cli' ? ($argv[1] ?? null) : null;
    if ($giftCardContentionMode === '--gift-card-redemption-worker') {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $database = $app->make(DatabaseManager::class);
        $connection = $database->connection();
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 5');

        $decoded = base64_decode((string) ($argv[2] ?? ''), true);
        if ($decoded === false) {
            fwrite(STDERR, "Invalid gift-card contention payload encoding.\n");
            exit(2);
        }
        try {
            /** @var array<string,mixed> $payload */
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            fwrite(STDERR, 'Invalid gift-card contention payload: '.$exception->getMessage()."\n");
            exit(2);
        }

        $barrierReached = false;
        $connection->beforeExecuting(function (string $query, array $bindings, Connection $db) use (&$barrierReached): void {
            unset($bindings, $db);
            if ($barrierReached) {
                return;
            }
            $sql = strtolower($query);
            if (! str_contains($sql, 'gift_card_submissions') || ! str_contains($sql, 'for update')) {
                return;
            }
            $barrierReached = true;
            echo "AT_REDEMPTION_LOCK\n";
            flush();
            $continue = fgets(STDIN);
            if ($continue === false || trim($continue) !== 'CONTINUE') {
                throw new RuntimeException('Gift-card contention lock barrier was not released.');
            }
        });

        echo "READY\n";
        flush();
        $go = fgets(STDIN);
        if ($go === false || trim($go) !== 'GO') {
            fwrite(STDERR, "Gift-card contention start barrier was not released.\n");
            exit(2);
        }

        try {
            $evidence = new GiftCardProviderEvidence(
                'redeem',
                'success',
                'redeemed',
                (string) $payload['provider_event_id'],
                (string) $payload['provider_transaction_id'],
                (int) $payload['amount_irr'],
                'IRR',
                'Steam',
                'GLOBAL',
                new DateTimeImmutable((string) $payload['occurred_at']),
                (string) $payload['evidence_hash'],
                ['source' => 'gift_card_contention_worker'],
            );
            $receipt = $app->make(GiftCardRedemptionService::class)->recordAndSettle(
                (string) $payload['submission_public_id'],
                'fake_gift_card',
                $evidence,
                (string) $payload['correlation_id'],
            );
            echo json_encode([
                'ok' => true,
                'state' => $receipt->state,
                'redemption_public_id' => $receipt->redemptionPublicId,
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
    use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderCapabilities;
    use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderEvidence;
    use App\Modules\Payments\GiftCard\Application\GiftCardPaymentService;
    use App\Modules\Payments\GiftCard\Application\GiftCardSubmissionReceipt;
    use App\Modules\Payments\GiftCard\Application\GiftCardSubmissionService;
    use App\Modules\Payments\GiftCard\Application\GiftCardTypeService;
    use App\Modules\Payments\GiftCard\Infrastructure\FakeGiftCardVerificationProvider;
    use Database\Seeders\CatalogAccessFoundationSeeder;
    use Database\Seeders\IdentityAccessFoundationSeeder;
    use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\TestCase;

    /** @requirement GFT-002 GFT-003 GFT-004 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 QUA-004 */
    final class GiftCardRedemptionContentionTest extends TestCase
    {
        use AgentPricingQuoteIntegrationTestSupport;
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 20;

        protected function setUp(): void
        {
            parent::setUp();
            if (DB::connection()->getDriverName() !== 'mysql') {
                $this->markTestSkipped('Gift-card redemption contention requires MariaDB/MySQL.');
            }
            $this->seed(IdentityAccessFoundationSeeder::class);
            $this->seed(CatalogAccessFoundationSeeder::class);
            $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
            config()->set('payments.gift_card.code_lookup_key', str_repeat('c', 32));
            config()->set('payments.gift_card.code_lookup_key_version', 1);
            $this->configureMethod();
            $this->app->make(GiftCardTypeService::class)->register(
                'gift-contention',
                'Gift contention type',
                'Steam',
                'GLOBAL',
                'IRR',
                'code_only',
                'automatic_only',
                null,
                'fake_gift_card',
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

        public function test_concurrent_same_authoritative_redemption_converges_on_one_settlement(): void
        {
            [$submission, $amount] = $this->submissionInRedeemingState();
            $payloadBase = [
                'submission_public_id' => $submission->publicId,
                'provider_event_id' => 'gift-contention-redeem-event',
                'provider_transaction_id' => 'gift-contention-redeem-tx',
                'amount_irr' => $amount,
                'occurred_at' => now('UTC')->addMinute()->toIso8601String(),
                'evidence_hash' => hash('sha256', 'gift-contention-redeem-evidence'),
            ];
            $first = $this->startWorker($payloadBase + ['correlation_id' => hash('sha256', 'gift-contention-worker-1')]);
            $second = $this->startWorker($payloadBase + ['correlation_id' => hash('sha256', 'gift-contention-worker-2')]);

            try {
                self::assertSame("READY\n", $this->readLine($first, 'first readiness'));
                self::assertSame("READY\n", $this->readLine($second, 'second readiness'));
                $this->sendCommand($first, 'GO');
                $this->sendCommand($second, 'GO');
                self::assertSame("AT_REDEMPTION_LOCK\n", $this->readLine($first, 'first redemption lock'));
                self::assertSame("AT_REDEMPTION_LOCK\n", $this->readLine($second, 'second redemption lock'));
                $this->sendCommand($first, 'CONTINUE');
                $this->sendCommand($second, 'CONTINUE');

                $firstResult = $this->readJsonResult($first, 'first result');
                $secondResult = $this->readJsonResult($second, 'second result');
                self::assertTrue((bool) ($firstResult['ok'] ?? false), json_encode($firstResult, JSON_THROW_ON_ERROR));
                self::assertTrue((bool) ($secondResult['ok'] ?? false), json_encode($secondResult, JSON_THROW_ON_ERROR));
                self::assertSame('captured', $firstResult['state']);
                self::assertSame('captured', $secondResult['state']);
                self::assertSame($firstResult['redemption_public_id'], $secondResult['redemption_public_id']);
                self::assertSame($firstResult['settlement_public_id'], $secondResult['settlement_public_id']);
                self::assertSame([false, true], collect([$firstResult['replayed'], $secondResult['replayed']])->sort()->values()->all());
                self::assertSame(1, DB::table('gift_card_redemptions')->count());
                self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'gift_card')->count());
                self::assertSame(1, DB::table('payment_provider_transactions')->where('provider_code', 'gift_card')->count());
            } finally {
                $this->closeWorker($first);
                $this->closeWorker($second);
            }
        }

        /** @return array{0:GiftCardSubmissionReceipt,1:int} */
        private function submissionInRedeemingState(): array
        {
            $user = $this->quoteUser('customer');
            $offering = $this->quoteOffering(1_000_000);
            $quote = $this->app->make(QuoteService::class)->create(
                'gift.contention.quote',
                $user,
                $offering['id'],
                new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, now('UTC')->addMinutes(30)->toDateTimeImmutable()),
                hash('sha256', 'gift-contention-quote'),
            );
            $decision = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
                'gift.contention.eligibility',
                $user,
                $quote->quotePublicId,
            );
            $submission = $this->app->make(GiftCardSubmissionService::class)->submit(
                'gift.contention.submission',
                'gift.contention.intent',
                $user,
                $quote->quotePublicId,
                $decision->publicId,
                'gift-contention',
                $quote->finalPriceIrr,
                'IRR',
                'Steam',
                'GLOBAL',
                'CONTENTION-CARD-0001',
                null,
                null,
                null,
                null,
                hash('sha256', 'gift-contention-submit'),
            );

            $provider = new FakeGiftCardVerificationProvider(
                'fake_gift_card',
                new GiftCardProviderCapabilities(true, false, true, false, false),
            );
            $provider->put('validate', hash('sha256', 'gift-card:'.$submission->publicId.':validate'), new GiftCardProviderEvidence(
                'validate',
                'success',
                'valid',
                'gift-contention-validate-event',
                null,
                $quote->finalPriceIrr,
                'IRR',
                'Steam',
                'GLOBAL',
                now('UTC'),
                hash('sha256', 'gift-contention-validate-evidence'),
                ['source' => 'gift_contention_setup'],
            ));
            $provider->put('redeem', hash('sha256', 'gift-card:'.$submission->publicId.':redeem'), new GiftCardProviderEvidence(
                'redeem',
                'uncertain',
                'pending',
                'gift-contention-uncertain-event',
                null,
                $quote->finalPriceIrr,
                'IRR',
                'Steam',
                'GLOBAL',
                now('UTC'),
                hash('sha256', 'gift-contention-uncertain-evidence'),
                ['source' => 'gift_contention_setup'],
            ));
            $pending = $this->app->make(GiftCardPaymentService::class)->process(
                $submission->publicId,
                $provider,
                hash('sha256', 'gift-contention-process'),
            );
            self::assertSame('redeeming', $pending->state);

            return [$submission, $quote->finalPriceIrr];
        }

        private function configureMethod(): void
        {
            $administratorId = $this->ownerAdministrator();
            $service = $this->app->make(PaymentMethodEligibilityService::class);
            $service->configureMethod(
                'gift.contention.method',
                $administratorId,
                'gift_card',
                true,
                false,
                1,
                'Gift-card contention method.',
                hash('sha256', 'gift-contention-method'),
            );
            $service->recordHealth(
                'gift.contention.health',
                $administratorId,
                'gift_card',
                true,
                now('UTC')->addMinutes(20)->toDateTimeImmutable(),
                'Gift-card contention provider is healthy.',
                hash('sha256', 'gift-contention-health'),
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
                '--gift-card-redemption-worker',
                base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            ], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start gift-card redemption contention worker.');
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
                    throw new RuntimeException('Unable to wait for gift-card contention worker output.');
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
                    throw new RuntimeException('Gift-card contention worker exited before '.$phase.' output: '.$stderr);
                }
            }
            throw new RuntimeException('Gift-card contention worker timed out during '.$phase.': '.$stderr);
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
    }
}
