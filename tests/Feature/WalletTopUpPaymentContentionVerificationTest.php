<?php

declare(strict_types=1);

namespace {
    use App\Modules\Payments\Application\Contracts\PaymentEvidence;
    use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
    use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
    use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
    use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
    use App\Modules\Payments\Application\WalletTopUpPaymentService;
    use App\Shared\Domain\Money;
    use DateTimeImmutable;
    use Illuminate\Contracts\Console\Kernel;

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--wallet-top-up-contention-worker') {
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
            $eventId = (string) $payload['provider_event_id'];
            $transactionId = (string) $payload['provider_transaction_id'];
            $amountIrr = (int) $payload['amount_irr'];
            $event = new VerifiedPaymentEvent(
                $eventId,
                hash('sha256', 'event:'.$eventId.':'.$transactionId.':'.$amountIrr),
                new PaymentEvidence(
                    ProviderOperationOutcome::Success,
                    PaymentEvidenceAuthority::Authoritative,
                    PaymentTransactionStatus::Settled,
                    $transactionId,
                    $eventId,
                    Money::irr($amountIrr),
                    new DateTimeImmutable('2026-08-08T10:00:00+00:00'),
                    new DateTimeImmutable('2026-08-08T10:00:01+00:00'),
                    hash('sha256', 'evidence:'.$eventId.':'.$transactionId.':'.$amountIrr),
                    ['bank_reference' => 'SAFE-CONTENTION-REFERENCE'],
                ),
            );
            $receipt = $app->make(WalletTopUpPaymentService::class)->capture(
                (string) $payload['intent_public_id'],
                (string) $payload['provider_code'],
                $event,
                (string) $payload['correlation_id'],
            );

            echo json_encode([
                'ok' => true,
                'result' => [
                    'settlement_id' => $receipt->settlementId,
                    'ledger_id' => $receipt->ledgerTransactionId,
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
    use App\Modules\Payments\Application\WalletTopUpPaymentService;
    use App\Modules\Wallet\Application\WalletHoldService;
    use App\Shared\Domain\Money;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Str;
    use RuntimeException;
    use Tests\TestCase;

    /** @requirement PAY-002 PAY-003 WAL-001 DAT-002 DAT-003 DAT-004 QUA-001 */
    final class WalletTopUpPaymentContentionVerificationTest extends TestCase
    {
        use DatabaseTruncation;

        protected function setUp(): void
        {
            parent::setUp();
            $this->seed();
        }

        public function test_concurrent_duplicate_authoritative_capture_creates_one_top_up_effect_and_one_replay(): void
        {
            $userId = $this->user();
            $walletId = $this->wallet($userId, 'duplicate-capture');
            $intent = $this->app->make(WalletTopUpPaymentService::class)->create(
                'topup.contention.duplicate.000001',
                $userId,
                $walletId,
                'fake_gateway',
                Money::irr(600_000),
                $this->correlation('create-duplicate-capture'),
            );
            $payload = $this->payload(
                $intent->intentPublicId,
                'evt-contention-duplicate',
                'txn-contention-duplicate',
                600_000,
                'duplicate-capture',
            );

            $results = $this->runConcurrent([$payload, $payload]);

            self::assertTrue($results[0]['ok']);
            self::assertTrue($results[1]['ok']);
            self::assertSame($results[0]['result']['settlement_id'], $results[1]['result']['settlement_id']);
            self::assertSame($results[0]['result']['ledger_id'], $results[1]['result']['ledger_id']);
            $replayed = [(bool) $results[0]['result']['replayed'], (bool) $results[1]['result']['replayed']];
            sort($replayed);
            self::assertSame([false, true], $replayed);
            self::assertSame(1, DB::table('wallet_top_up_settlements')->count());
            self::assertSame(1, DB::table('payment_provider_events')->count());
            self::assertSame(1, DB::table('payment_provider_transactions')->count());
            self::assertSame(1, DB::table('payment_attempts')->count());
            self::assertSame(1, DB::table('ledger_transactions')->where('transaction_type', 'wallet_external_top_up')->count());
            self::assertSame(1, DB::table('audit_logs')->where('action', 'payment.wallet_top_up.captured')->count());
            self::assertSame(600_000, $this->app->make(WalletHoldService::class)->balance($userId, $walletId)->ledgerBalance->amount);
        }

        public function test_same_provider_event_cannot_capture_two_different_payment_intents_concurrently(): void
        {
            $userId = $this->user();
            $firstWalletId = $this->wallet($userId, 'cross-intent-first');
            $secondWalletId = $this->wallet($userId, 'cross-intent-second');
            $service = $this->app->make(WalletTopUpPaymentService::class);
            $firstIntent = $service->create(
                'topup.contention.cross.000001',
                $userId,
                $firstWalletId,
                'fake_gateway',
                Money::irr(400_000),
                $this->correlation('create-cross-first'),
            );
            $secondIntent = $service->create(
                'topup.contention.cross.000002',
                $userId,
                $secondWalletId,
                'fake_gateway',
                Money::irr(400_000),
                $this->correlation('create-cross-second'),
            );
            $eventId = 'evt-contention-cross-intent';
            $transactionId = 'txn-contention-cross-intent';

            $results = $this->runConcurrent([
                $this->payload($firstIntent->intentPublicId, $eventId, $transactionId, 400_000, 'cross-first'),
                $this->payload($secondIntent->intentPublicId, $eventId, $transactionId, 400_000, 'cross-second'),
            ]);
            $successes = array_values(array_filter($results, static fn (array $result): bool => $result['ok'] === true));
            $failures = array_values(array_filter($results, static fn (array $result): bool => $result['ok'] === false));

            self::assertCount(1, $successes);
            self::assertCount(1, $failures);
            self::assertSame(1, DB::table('wallet_top_up_settlements')->count());
            self::assertSame(1, DB::table('payment_provider_events')->where('provider_event_id', $eventId)->count());
            self::assertSame(1, DB::table('payment_provider_transactions')->where('provider_transaction_id', $transactionId)->count());
            self::assertSame(1, DB::table('ledger_transactions')->where('transaction_type', 'wallet_external_top_up')->count());
            $totalWalletBalance = $this->app->make(WalletHoldService::class)->balance($userId, $firstWalletId)->ledgerBalance->amount
                + $this->app->make(WalletHoldService::class)->balance($userId, $secondWalletId)->ledgerBalance->amount;
            self::assertSame(400_000, $totalWalletBalance);
        }

        /** @return array<string, mixed> */
        private function payload(
            string $intentPublicId,
            string $providerEventId,
            string $providerTransactionId,
            int $amountIrr,
            string $suffix,
        ): array {
            return [
                'intent_public_id' => $intentPublicId,
                'provider_code' => 'fake_gateway',
                'provider_event_id' => $providerEventId,
                'provider_transaction_id' => $providerTransactionId,
                'amount_irr' => $amountIrr,
                'correlation_id' => $this->correlation('worker-'.$suffix),
            ];
        }

        /**
         * @param  list<array<string, mixed>>  $payloads
         * @return list<array<string, mixed>>
         */
        private function runConcurrent(array $payloads): array
        {
            $workers = [];
            foreach ($payloads as $payload) {
                $encoded = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
                $command = [PHP_BINARY, __FILE__, '--wallet-top-up-contention-worker', $encoded];
                $pipes = [];
                $process = proc_open($command, [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes, dirname(__DIR__, 2));
                if (! is_resource($process)) {
                    throw new RuntimeException('Unable to start wallet top-up contention worker.');
                }
                /** @var array{0:resource,1:resource,2:resource} $pipes */
                $workers[] = ['process' => $process, 'pipes' => $pipes];
            }

            foreach ($workers as $worker) {
                $ready = fgets($worker['pipes'][1]);
                if ($ready !== "READY\n") {
                    $stderr = stream_get_contents($worker['pipes'][2]);
                    throw new RuntimeException('Wallet top-up contention worker failed readiness barrier: '.$stderr);
                }
            }
            foreach ($workers as $worker) {
                fwrite($worker['pipes'][0], "GO\n");
                fflush($worker['pipes'][0]);
                fclose($worker['pipes'][0]);
            }

            $results = [];
            foreach ($workers as $worker) {
                $line = fgets($worker['pipes'][1]);
                $stderr = stream_get_contents($worker['pipes'][2]);
                fclose($worker['pipes'][1]);
                fclose($worker['pipes'][2]);
                $exitCode = proc_close($worker['process']);
                if ($exitCode !== 0 || $line === false) {
                    throw new RuntimeException('Wallet top-up contention worker failed: '.$stderr);
                }
                /** @var array<string, mixed> $result */
                $result = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $results[] = $result;
            }

            return $results;
        }

        private function wallet(int $userId, string $suffix): int
        {
            $now = now('UTC');

            return (int) DB::table('ledger_accounts')->insertGetId([
                'code' => 'wallet.cash.topup.contention.'.$suffix.'.'.$userId,
                'account_class' => 'liability',
                'owner_user_id' => $userId,
                'wallet_bucket' => 'cash',
                'currency' => 'IRR',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        private function user(): int
        {
            $now = now('UTC');

            return (int) DB::table('users')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'account_type' => 'customer',
                'account_status' => 'active',
                'locale' => 'fa',
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        private function correlation(string $suffix): string
        {
            return substr(hash('sha256', 'wallet-top-up-contention:'.$suffix), 0, 64);
        }
    }
}
