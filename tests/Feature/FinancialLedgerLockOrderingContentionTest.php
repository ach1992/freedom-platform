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
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\Connection;
    use Illuminate\Database\DatabaseManager;

    $financialLockOrderMode = PHP_SAPI === 'cli' ? ($argv[1] ?? null) : null;
    if (in_array($financialLockOrderMode, [
        '--financial-lock-order-canonical-worker',
        '--financial-lock-order-top-up-worker',
    ], true)) {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $database = $app->make(DatabaseManager::class);
        $connection = $database->connection();
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 5');

        $decoded = base64_decode($argv[2] ?? '', true);
        if ($decoded === false) {
            fwrite(STDERR, "Invalid financial lock-order worker payload encoding.\n");
            exit(2);
        }

        try {
            /** @var array<string,mixed> $payload */
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            fwrite(STDERR, 'Invalid financial lock-order worker payload: '.$exception->getMessage()."\n");
            exit(2);
        }

        echo "READY\n";
        flush();
        $go = fgets(STDIN);
        if ($go === false || trim($go) !== 'GO') {
            fwrite(STDERR, "Financial lock-order worker start barrier was not released.\n");
            exit(2);
        }

        try {
            if ($financialLockOrderMode === '--financial-lock-order-canonical-worker') {
                $firstAccountId = (int) $payload['first_account_id'];
                $secondAccountId = (int) $payload['second_account_id'];

                $connection->transaction(function (Connection $db) use ($firstAccountId, $secondAccountId): void {
                    $first = $db->table('ledger_accounts')
                        ->where('id', $firstAccountId)
                        ->lockForUpdate()
                        ->first(['id']);
                    if ($first === null) {
                        throw new RuntimeException('Canonical financial lock worker first account is missing.');
                    }

                    echo "LOCKED_FIRST\n";
                    flush();
                    $goSecond = fgets(STDIN);
                    if ($goSecond === false || trim($goSecond) !== 'GO_SECOND') {
                        throw new RuntimeException('Canonical financial lock worker second barrier was not released.');
                    }

                    $second = $db->table('ledger_accounts')
                        ->where('id', $secondAccountId)
                        ->lockForUpdate()
                        ->first(['id']);
                    if ($second === null) {
                        throw new RuntimeException('Canonical financial lock worker second account is missing.');
                    }
                });

                echo json_encode(['ok' => true], JSON_THROW_ON_ERROR)."\n";
                exit(0);
            }

            $walletId = (int) $payload['wallet_account_id'];
            $clearingId = (int) $payload['clearing_account_id'];
            $connection->beforeExecuting(function (string $query, array $bindings, Connection $db) use ($walletId, $clearingId): void {
                unset($db);
                $sql = strtolower($query);
                if (! str_contains($sql, 'ledger_accounts') || ! str_contains($sql, 'for update')) {
                    return;
                }

                $numericBindings = [];
                foreach ($bindings as $binding) {
                    if (is_int($binding)) {
                        $numericBindings[] = $binding;
                    } elseif (is_string($binding) && ctype_digit($binding)) {
                        $numericBindings[] = (int) $binding;
                    }
                }

                $hasWallet = in_array($walletId, $numericBindings, true);
                $hasClearing = in_array($clearingId, $numericBindings, true);
                $kind = $hasWallet && $hasClearing
                    ? 'complete'
                    : ($hasWallet ? 'wallet' : 'other');

                echo 'ABOUT_TO_LOCK:'.$kind."\n";
                flush();
                $continue = fgets(STDIN);
                if ($continue === false || trim($continue) !== 'CONTINUE') {
                    throw new RuntimeException('Top-up financial account lock barrier was not released.');
                }
            });

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
                    ['bank_reference' => 'SAFE-LOCK-ORDER-REFERENCE'],
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
    use App\Modules\Wallet\Domain\WalletSystemAccountCode;
    use App\Shared\Domain\Money;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Str;
    use RuntimeException;
    use Tests\TestCase;

    /** @requirement PAY-002 PAY-003 WAL-001 WAL-002 DAT-002 DAT-003 DAT-004 QUA-001 */
    final class FinancialLedgerLockOrderingContentionTest extends TestCase
    {
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 15;

        protected function setUp(): void
        {
            parent::setUp();
            $this->seed();
        }

        protected function tearDown(): void
        {
            try {
                $this->truncateTablesForAllConnections();
            } finally {
                parent::tearDown();
            }
        }

        public function test_top_up_and_canonical_ledger_account_acquisition_complete_without_lock_order_deadlock(): void
        {
            $amountIrr = 730_000;
            $userId = $this->user();
            $walletId = $this->wallet($userId);
            $clearingId = (int) DB::table('ledger_accounts')
                ->where('code', WalletSystemAccountCode::EXTERNAL_TOP_UP_CLEARING)
                ->value('id');
            self::assertGreaterThan(0, $clearingId);
            self::assertLessThan($walletId, $clearingId, 'Fixture must place clearing before wallet in canonical numeric order.');

            $intent = $this->app->make(WalletTopUpPaymentService::class)->create(
                'topup.lock-order.intent.000001',
                $userId,
                $walletId,
                'fake_gateway',
                Money::irr($amountIrr),
                $this->correlation('create'),
            );

            $canonical = $this->startWorker('--financial-lock-order-canonical-worker', [
                'first_account_id' => $clearingId,
                'second_account_id' => $walletId,
            ]);
            $topUp = $this->startWorker('--financial-lock-order-top-up-worker', [
                'intent_public_id' => $intent->intentPublicId,
                'provider_code' => 'fake_gateway',
                'provider_event_id' => 'evt-lock-order-top-up',
                'provider_transaction_id' => 'txn-lock-order-top-up',
                'amount_irr' => $amountIrr,
                'correlation_id' => $this->correlation('capture'),
                'wallet_account_id' => $walletId,
                'clearing_account_id' => $clearingId,
            ]);

            try {
                self::assertSame("READY\n", $this->readLine($canonical, 'canonical readiness'));
                self::assertSame("READY\n", $this->readLine($topUp, 'top-up readiness'));

                $this->sendCommand($canonical, 'GO');
                self::assertSame("LOCKED_FIRST\n", $this->readLine($canonical, 'canonical first account lock'));

                $this->sendCommand($topUp, 'GO');
                $firstAttempt = trim($this->readLine($topUp, 'top-up first account lock attempt'));
                self::assertContains($firstAttempt, [
                    'ABOUT_TO_LOCK:wallet',
                    'ABOUT_TO_LOCK:other',
                    'ABOUT_TO_LOCK:complete',
                ]);

                if ($firstAttempt === 'ABOUT_TO_LOCK:wallet') {
                    $this->sendCommand($topUp, 'CONTINUE');
                    self::assertSame(
                        'ABOUT_TO_LOCK:other',
                        trim($this->readLine($topUp, 'top-up second account lock attempt')),
                        'A wallet-first caller must expose the structural inverse of canonical clearing-first ordering.',
                    );
                    $this->sendCommand($canonical, 'GO_SECOND');
                    $this->sendCommand($topUp, 'CONTINUE');
                } else {
                    $this->sendCommand($topUp, 'CONTINUE');
                    $this->sendCommand($canonical, 'GO_SECOND');
                }

                $canonicalResult = $this->readJsonResult($canonical, 'canonical result');
                $topUpResult = $this->readTopUpResult($topUp);

                self::assertTrue((bool) ($canonicalResult['ok'] ?? false), json_encode($canonicalResult, JSON_THROW_ON_ERROR));
                self::assertTrue((bool) ($topUpResult['ok'] ?? false), json_encode($topUpResult, JSON_THROW_ON_ERROR));

                self::assertSame(1, DB::table('wallet_top_up_settlements')->count());
                self::assertSame(1, DB::table('payment_provider_events')->count());
                self::assertSame(1, DB::table('payment_provider_transactions')->count());
                self::assertSame(1, DB::table('payment_attempts')->count());
                self::assertSame(1, DB::table('ledger_transactions')->where('transaction_type', 'wallet_external_top_up')->count());

                $ledger = DB::table('ledger_transactions')
                    ->where('transaction_type', 'wallet_external_top_up')
                    ->first(['id', 'expected_total_irr', 'posted_debit_irr', 'posted_credit_irr', 'entry_count', 'finalized_at']);
                self::assertNotNull($ledger);
                self::assertSame($amountIrr, (int) $ledger->expected_total_irr);
                self::assertSame($amountIrr, (int) $ledger->posted_debit_irr);
                self::assertSame($amountIrr, (int) $ledger->posted_credit_irr);
                self::assertSame(2, (int) $ledger->entry_count);
                self::assertNotNull($ledger->finalized_at);
                self::assertSame(2, DB::table('ledger_entries')->where('ledger_transaction_id', $ledger->id)->count());
                self::assertSame(
                    $amountIrr,
                    $this->app->make(WalletHoldService::class)->balance($userId, $walletId)->ledgerBalance->amount,
                );
            } finally {
                $this->closeWorker($canonical);
                $this->closeWorker($topUp);
            }
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
                throw new RuntimeException('Unable to start financial lock-order contention worker.');
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
                    throw new RuntimeException('Unable to wait for financial lock-order worker output.');
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
                    throw new RuntimeException('Financial lock-order worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException('Financial lock-order worker timed out during '.$phase.': '.$stderr);
        }

        /**
         * @param  array{process:resource,pipes:array{0:resource,1:resource,2:resource}}  $worker
         * @return array<string,mixed>
         */
        private function readJsonResult(array $worker, string $phase): array
        {
            /** @var array<string,mixed> $result */
            $result = json_decode($this->readLine($worker, $phase), true, flags: JSON_THROW_ON_ERROR);

            return $result;
        }

        /**
         * @param  array{process:resource,pipes:array{0:resource,1:resource,2:resource}}  $worker
         * @return array<string,mixed>
         */
        private function readTopUpResult(array $worker): array
        {
            while (true) {
                $line = trim($this->readLine($worker, 'top-up result'));
                if (str_starts_with($line, 'ABOUT_TO_LOCK:')) {
                    $this->sendCommand($worker, 'CONTINUE');

                    continue;
                }

                /** @var array<string,mixed> $result */
                $result = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

                return $result;
            }
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

        private function wallet(int $userId): int
        {
            $now = now('UTC');

            return (int) DB::table('ledger_accounts')->insertGetId([
                'code' => 'wallet.cash.financial-lock-order.'.$userId,
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
            return substr(hash('sha256', 'financial-ledger-lock-order:'.$suffix), 0, 64);
        }
    }
}
