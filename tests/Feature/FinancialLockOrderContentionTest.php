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
    use Illuminate\Database\DatabaseManager;
    use Illuminate\Database\Events\QueryExecuted;
    use Illuminate\Support\Facades\DB;

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--financial-lock-order-top-up-worker') {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $database = $app->make(DatabaseManager::class);
        $database->connection()->statement('SET SESSION innodb_lock_wait_timeout = 5');

        try {
            $decoded = base64_decode($argv[2] ?? '', true);
            if ($decoded === false) {
                throw new RuntimeException('Invalid top-up worker payload encoding.');
            }
            /** @var array<string,mixed> $payload */
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
            $targetAccountIds = [(int) $payload['clearing_id'], (int) $payload['wallet_id']];
            sort($targetAccountIds, SORT_NUMERIC);
            $reported = false;

            DB::listen(function (QueryExecuted $query) use (&$reported, $targetAccountIds): void {
                if ($reported) {
                    return;
                }
                $sql = strtolower($query->sql);
                if (! str_contains($sql, 'ledger_accounts') || ! str_contains($sql, 'for update')) {
                    return;
                }

                $lockedIds = [];
                foreach ($query->bindings as $binding) {
                    if (! is_int($binding) && ! (is_string($binding) && ctype_digit($binding))) {
                        continue;
                    }
                    $id = (int) $binding;
                    if (in_array($id, $targetAccountIds, true)) {
                        $lockedIds[] = $id;
                    }
                }
                $lockedIds = array_values(array_unique($lockedIds));
                sort($lockedIds, SORT_NUMERIC);
                if ($lockedIds === []) {
                    return;
                }

                $reported = true;
                echo 'ACCOUNT_LOCKED '.base64_encode(json_encode($lockedIds, JSON_THROW_ON_ERROR))."\n";
                flush();
                $barrier = fgets(STDIN);
                if ($barrier === false || trim($barrier) !== 'CONTINUE') {
                    throw new RuntimeException('Top-up account-lock barrier was not released.');
                }
            });

            echo "READY\n";
            flush();
            $go = fgets(STDIN);
            if ($go === false || trim($go) !== 'GO') {
                throw new RuntimeException('Top-up worker start barrier was not released.');
            }

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

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--financial-lock-order-canonical-worker') {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $database = $app->make(DatabaseManager::class);
        $database->connection()->statement('SET SESSION innodb_lock_wait_timeout = 5');

        try {
            $decoded = base64_decode($argv[2] ?? '', true);
            if ($decoded === false) {
                throw new RuntimeException('Invalid canonical worker payload encoding.');
            }
            /** @var array<string,mixed> $payload */
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
            $accountIds = [(int) $payload['clearing_id'], (int) $payload['wallet_id']];
            sort($accountIds, SORT_NUMERIC);

            echo "READY\n";
            flush();
            $go = fgets(STDIN);
            if ($go === false || trim($go) !== 'GO') {
                throw new RuntimeException('Canonical worker start barrier was not released.');
            }

            $database->connection()->transaction(function ($connection) use ($accountIds): void {
                echo "TRYING_FIRST\n";
                flush();
                $first = $connection->table('ledger_accounts')
                    ->where('id', $accountIds[0])
                    ->lockForUpdate()
                    ->first(['id']);
                if ($first === null) {
                    throw new RuntimeException('Canonical first ledger account is missing.');
                }
                echo "LOCKED_FIRST\n";
                flush();

                $next = fgets(STDIN);
                if ($next === false || trim($next) !== 'LOCK_SECOND') {
                    throw new RuntimeException('Canonical second-lock barrier was not released.');
                }

                echo "TRYING_SECOND\n";
                flush();
                $second = $connection->table('ledger_accounts')
                    ->where('id', $accountIds[1])
                    ->lockForUpdate()
                    ->first(['id']);
                if ($second === null) {
                    throw new RuntimeException('Canonical second ledger account is missing.');
                }
                echo "LOCKED_SECOND\n";
                flush();
            });

            echo json_encode(['ok' => true], JSON_THROW_ON_ERROR)."\n";
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
    use App\Modules\Wallet\Domain\WalletSystemAccountCode;
    use App\Shared\Domain\Money;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Str;
    use RuntimeException;
    use Tests\TestCase;

    /** @requirement PAY-002 PAY-003 WAL-001 WAL-002 DAT-002 DAT-003 DAT-004 QUA-001 */
    final class FinancialLockOrderContentionTest extends TestCase
    {
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 15;

        protected function setUp(): void
        {
            parent::setUp();
            $this->seed();
        }

        public function test_top_up_acquires_complete_ledger_account_set_before_overlapping_canonical_order(): void
        {
            $userId = $this->user();
            $walletId = $this->wallet($userId);
            $clearingId = (int) DB::table('ledger_accounts')
                ->where('code', WalletSystemAccountCode::EXTERNAL_TOP_UP_CLEARING)
                ->value('id');
            self::assertGreaterThan(0, $clearingId);
            self::assertLessThan($walletId, $clearingId);

            $intent = $this->app->make(WalletTopUpPaymentService::class)->create(
                'topup.lock-order.000001',
                $userId,
                $walletId,
                'fake_gateway',
                Money::irr(730_000),
                $this->correlation('create'),
            );
            $payload = [
                'intent_public_id' => $intent->intentPublicId,
                'provider_code' => 'fake_gateway',
                'provider_event_id' => 'evt-lock-order-topup',
                'provider_transaction_id' => 'txn-lock-order-topup',
                'amount_irr' => 730_000,
                'correlation_id' => $this->correlation('capture'),
                'clearing_id' => $clearingId,
                'wallet_id' => $walletId,
            ];

            $topUp = $this->startWorker('--financial-lock-order-top-up-worker', $payload);
            $canonical = null;
            try {
                self::assertSame("READY\n", $this->readLine($topUp, 'top-up readiness'));
                $this->sendLine($topUp, 'GO');
                $lockLine = $this->readLine($topUp, 'top-up first account lock');
                self::assertStringStartsWith('ACCOUNT_LOCKED ', $lockLine);
                $lockedIds = $this->decodeLockedIds($lockLine);

                $canonical = $this->startWorker('--financial-lock-order-canonical-worker', $payload);
                self::assertSame("READY\n", $this->readLine($canonical, 'canonical readiness'));
                $this->sendLine($canonical, 'GO');
                self::assertSame("TRYING_FIRST\n", $this->readLine($canonical, 'canonical first lock attempt'));

                $completeSet = [$clearingId, $walletId];
                sort($completeSet, SORT_NUMERIC);
                $walletOnly = [$walletId];

                if ($lockedIds === $walletOnly) {
                    self::assertSame("LOCKED_FIRST\n", $this->readLine($canonical, 'canonical first lock'));
                    $this->sendLine($canonical, 'LOCK_SECOND');
                    self::assertSame("TRYING_SECOND\n", $this->readLine($canonical, 'canonical second lock attempt'));
                    $this->sendLine($topUp, 'CONTINUE');

                    $topUpResult = $this->readJsonResult($topUp, 'top-up result');
                    $canonicalResult = $this->readJsonResult($canonical, 'canonical result');
                } elseif ($lockedIds === $completeSet) {
                    $this->sendLine($topUp, 'CONTINUE');
                    $topUpResult = $this->readJsonResult($topUp, 'top-up result');
                    self::assertSame("LOCKED_FIRST\n", $this->readLine($canonical, 'canonical first lock'));
                    $this->sendLine($canonical, 'LOCK_SECOND');
                    self::assertSame("TRYING_SECOND\n", $this->readLine($canonical, 'canonical second lock attempt'));
                    self::assertSame("LOCKED_SECOND\n", $this->readLine($canonical, 'canonical second lock'));
                    $canonicalResult = $this->readJsonResult($canonical, 'canonical result');
                } else {
                    $this->sendLine($topUp, 'CONTINUE');
                    $topUpResult = $this->readJsonResult($topUp, 'top-up unexpected-order result');
                    throw new RuntimeException('Unexpected first ledger-account lock set: '.json_encode($lockedIds, JSON_THROW_ON_ERROR).'; worker result: '.json_encode($topUpResult, JSON_THROW_ON_ERROR));
                }

                self::assertSame($completeSet, $lockedIds, 'Top-up must acquire the complete account set in canonical ascending ID order before holding any participant account.');
                self::assertTrue((bool) ($topUpResult['ok'] ?? false), json_encode($topUpResult, JSON_THROW_ON_ERROR));
                self::assertTrue((bool) ($canonicalResult['ok'] ?? false), json_encode($canonicalResult, JSON_THROW_ON_ERROR));
                self::assertFalse((bool) ($topUpResult['result']['replayed'] ?? true));
                self::assertSame(1, DB::table('wallet_top_up_settlements')->count());
                self::assertSame(1, DB::table('ledger_transactions')->where('transaction_type', 'wallet_external_top_up')->count());

                $ledgerId = (int) DB::table('wallet_top_up_settlements')->value('ledger_transaction_id');
                $debit = (int) DB::table('ledger_entries')
                    ->where('ledger_transaction_id', $ledgerId)
                    ->where('direction', 'debit')
                    ->sum('amount_irr');
                $credit = (int) DB::table('ledger_entries')
                    ->where('ledger_transaction_id', $ledgerId)
                    ->where('direction', 'credit')
                    ->sum('amount_irr');
                self::assertSame(730_000, $debit);
                self::assertSame($debit, $credit);
                self::assertSame(2, DB::table('ledger_entries')->where('ledger_transaction_id', $ledgerId)->count());
            } finally {
                $this->closeWorker($topUp);
                if ($canonical !== null) {
                    $this->closeWorker($canonical);
                }
            }
        }

        /** @param array<string,mixed> $payload
         * @return array{process:resource,pipes:array{0:resource,1:resource,2:resource}}
         */
        private function startWorker(string $mode, array $payload): array
        {
            $encoded = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
            $pipes = [];
            $process = proc_open([
                PHP_BINARY,
                '-d',
                'pcov.enabled=0',
                __FILE__,
                $mode,
                $encoded,
            ], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start financial lock-order worker.');
            }
            /** @var array{0:resource,1:resource,2:resource} $pipes */
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            return ['process' => $process, 'pipes' => $pipes];
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker */
        private function sendLine(array $worker, string $line): void
        {
            fwrite($worker['pipes'][0], $line."\n");
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

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker
         * @return array<string,mixed>
         */
        private function readJsonResult(array $worker, string $phase): array
        {
            while (true) {
                $line = $this->readLine($worker, $phase);
                if (! str_starts_with($line, '{')) {
                    continue;
                }
                /** @var array<string,mixed> $result */
                $result = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

                return $result;
            }
        }

        /** @return list<int> */
        private function decodeLockedIds(string $line): array
        {
            $encoded = trim(substr($line, strlen('ACCOUNT_LOCKED ')));
            $decoded = base64_decode($encoded, true);
            if ($decoded === false) {
                throw new RuntimeException('Invalid account-lock worker marker encoding.');
            }
            /** @var list<int> $ids */
            $ids = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);

            return $ids;
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
                'code' => 'wallet.cash.lock-order.'.$userId,
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
            return substr(hash('sha256', 'financial-lock-order:'.$suffix), 0, 64);
        }
    }
}
