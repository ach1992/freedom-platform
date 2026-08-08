<?php

declare(strict_types=1);

namespace {
    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--wallet-contention-worker') {
        require dirname(__DIR__, 2).'/vendor/autoload.php';

        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $encoded = $argv[2] ?? '';
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            fwrite(STDERR, "Invalid worker payload encoding.\n");
            exit(2);
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
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
            $action = $payload['action'] ?? null;
            if (! is_string($action)) {
                throw new \RuntimeException('Worker action is missing.');
            }

            $result = match ($action) {
                'hold_place' => (static function () use ($app, $payload): array {
                    $receipt = $app->make(\App\Modules\Wallet\Application\WalletHoldService::class)->place(
                        (string) $payload['hold_key'],
                        (int) $payload['owner_user_id'],
                        (int) $payload['ledger_account_id'],
                        \App\Modules\Wallet\Domain\IrrMoney::positive((int) $payload['amount_irr']),
                        (string) $payload['source_type'],
                        (string) $payload['source_id'],
                        new \DateTimeImmutable((string) $payload['expires_at']),
                    );

                    return [
                        'hold_id' => $receipt->holdId,
                        'status' => $receipt->status->value,
                        'replayed' => $receipt->replayed,
                    ];
                })(),
                'hold_capture' => (static function () use ($app, $payload): array {
                    $receipt = $app->make(\App\Modules\Wallet\Application\WalletHoldService::class)->capture(
                        (string) $payload['hold_key'],
                        (int) $payload['offset_account_id'],
                        (string) $payload['correlation_id'],
                    );

                    return [
                        'hold_id' => $receipt->holdId,
                        'status' => $receipt->status->value,
                        'ledger_transaction_id' => $receipt->capturedLedgerTransactionId,
                        'replayed' => $receipt->replayed,
                    ];
                })(),
                'hold_release' => (static function () use ($app, $payload): array {
                    $receipt = $app->make(\App\Modules\Wallet\Application\WalletHoldService::class)->release(
                        (string) $payload['hold_key'],
                        (string) $payload['reason'],
                    );

                    return [
                        'hold_id' => $receipt->holdId,
                        'status' => $receipt->status->value,
                        'replayed' => $receipt->replayed,
                    ];
                })(),
                'ledger_post' => (static function () use ($app, $payload): array {
                    /** @var list<array{account_id:int, direction:string, amount_irr:int}> $entryPayloads */
                    $entryPayloads = $payload['entries'];
                    $entries = array_map(
                        static fn (array $entry): \App\Modules\Wallet\Application\LedgerEntryDraft => new \App\Modules\Wallet\Application\LedgerEntryDraft(
                            $entry['account_id'],
                            \App\Modules\Wallet\Domain\LedgerDirection::from($entry['direction']),
                            \App\Modules\Wallet\Domain\IrrMoney::positive($entry['amount_irr']),
                        ),
                        $entryPayloads,
                    );
                    $receipt = $app->make(\App\Modules\Wallet\Application\LedgerPostingService::class)->post(
                        (string) $payload['command_key'],
                        (string) $payload['transaction_type'],
                        (string) $payload['correlation_id'],
                        $entries,
                        (string) $payload['source_type'],
                        (string) $payload['source_id'],
                    );

                    return [
                        'transaction_id' => $receipt->transactionId,
                        'replayed' => $receipt->replayed,
                    ];
                })(),
                'transfer_prepare' => (static function () use ($app, $payload): array {
                    config()->set('wallet.transfers', [
                        'enabled' => true,
                        'allowed_buckets' => ['cash'],
                        'minimum_irr' => 1,
                        'maximum_irr' => 5_000_000,
                        'daily_limit_irr' => 5_000_000,
                        'fixed_fee_irr' => 0,
                        'fee_basis_points' => 0,
                        'confirmation_ttl_seconds' => 900,
                        'fee_account_code' => null,
                    ]);
                    $receipt = $app->make(\App\Modules\Wallet\Application\WalletTransferService::class)->prepare(
                        (string) $payload['transfer_key'],
                        (int) $payload['sender_user_id'],
                        (string) $payload['recipient_public_id'],
                        'cash',
                        \App\Modules\Wallet\Domain\IrrMoney::positive((int) $payload['amount_irr']),
                    );

                    return [
                        'transfer_id' => $receipt->transferId,
                        'hold_id' => $receipt->holdId,
                        'status' => $receipt->status->value,
                        'replayed' => $receipt->replayed,
                    ];
                })(),
                'transfer_confirm' => (static function () use ($app, $payload): array {
                    config()->set('wallet.transfers', [
                        'enabled' => true,
                        'allowed_buckets' => ['cash'],
                        'minimum_irr' => 1,
                        'maximum_irr' => 5_000_000,
                        'daily_limit_irr' => 5_000_000,
                        'fixed_fee_irr' => 0,
                        'fee_basis_points' => 0,
                        'confirmation_ttl_seconds' => 900,
                        'fee_account_code' => null,
                    ]);
                    $receipt = $app->make(\App\Modules\Wallet\Application\WalletTransferService::class)->confirm(
                        (string) $payload['transfer_key'],
                        (string) $payload['confirmation_key'],
                        (string) $payload['correlation_id'],
                    );

                    return [
                        'transfer_id' => $receipt->transferId,
                        'ledger_transaction_id' => $receipt->ledgerTransactionId,
                        'status' => $receipt->status->value,
                        'replayed' => $receipt->replayed,
                    ];
                })(),
                'reconcile' => (static function () use ($app, $payload): array {
                    $result = $app->make(\App\Modules\Wallet\Application\WalletReconciliationService::class)->reconcile(
                        (int) $payload['owner_user_id'],
                        (int) $payload['ledger_account_id'],
                    );

                    return [
                        'snapshot_id' => $result->snapshotId,
                        'ledger_balance_irr' => $result->balance->ledgerBalance->amount,
                        'active_holds_irr' => $result->balance->activeHolds->amount,
                        'available_balance_irr' => $result->balance->availableBalance->amount,
                    ];
                })(),
                default => throw new \RuntimeException('Unknown worker action.'),
            };

            echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR)."\n";
            exit(0);
        } catch (\Throwable $exception) {
            echo json_encode([
                'ok' => false,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR)."\n";
            exit(0);
        }
    }
}

namespace Tests\Feature {
    use App\Modules\Wallet\Application\LedgerEntryDraft;
    use App\Modules\Wallet\Application\LedgerPostingService;
    use App\Modules\Wallet\Application\WalletHoldService;
    use App\Modules\Wallet\Domain\IrrMoney;
    use App\Modules\Wallet\Domain\LedgerDirection;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Str;
    use RuntimeException;
    use Tests\TestCase;

    /** @requirement WAL-002 WAL-003 DAT-002 DAT-003 DAT-004 QUA-001 */
    final class WalletContentionVerificationTest extends TestCase
    {
        use DatabaseTruncation;

        public function test_same_wallet_concurrent_holds_never_over_reserve_available_balance(): void
        {
            [$userId, $walletId] = $this->fundedWallet('contention-holds', 1_000_000);
            $expiry = now('UTC')->addHour()->toIso8601String();

            $results = $this->runConcurrent([
                [
                    'action' => 'hold_place',
                    'hold_key' => 'wallet.contention.hold.a',
                    'owner_user_id' => $userId,
                    'ledger_account_id' => $walletId,
                    'amount_irr' => 700_000,
                    'source_type' => 'order',
                    'source_id' => 'contention-order-a',
                    'expires_at' => $expiry,
                ],
                [
                    'action' => 'hold_place',
                    'hold_key' => 'wallet.contention.hold.b',
                    'owner_user_id' => $userId,
                    'ledger_account_id' => $walletId,
                    'amount_irr' => 700_000,
                    'source_type' => 'order',
                    'source_id' => 'contention-order-b',
                    'expires_at' => $expiry,
                ],
            ]);

            self::assertSame(1, $this->successCount($results), $this->resultsDiagnostic($results));
            self::assertSame(1, $this->failureCount($results), $this->resultsDiagnostic($results));
            self::assertSame(1, DB::table('wallet_holds')->where('status', 'active')->count());
            self::assertSame(700_000, (int) DB::table('wallet_holds')->where('status', 'active')->sum('amount_irr'));

            $balance = $this->app->make(WalletHoldService::class)->balance($userId, $walletId);
            self::assertSame(1_000_000, $balance->ledgerBalance->amount);
            self::assertSame(700_000, $balance->activeHolds->amount);
            self::assertSame(300_000, $balance->availableBalance->amount);
        }

        public function test_concurrent_capture_and_release_produce_one_terminal_hold_outcome_only(): void
        {
            [$userId, $walletId, $assetId] = $this->fundedWallet('contention-terminal', 1_000_000);
            unset($assetId);
            $revenueId = $this->account('system.wallet.contention.terminal.revenue', 'revenue');
            $service = $this->app->make(WalletHoldService::class);
            $service->place(
                'wallet.contention.terminal.hold',
                $userId,
                $walletId,
                IrrMoney::positive(250_000),
                'order',
                'contention-terminal-order',
                now('UTC')->addHour()->toDateTimeImmutable(),
            );
            $ledgerBefore = DB::table('ledger_transactions')->count();

            $results = $this->runConcurrent([
                [
                    'action' => 'hold_capture',
                    'hold_key' => 'wallet.contention.terminal.hold',
                    'offset_account_id' => $revenueId,
                    'correlation_id' => 'corr-contention-terminal-capture',
                ],
                [
                    'action' => 'hold_release',
                    'hold_key' => 'wallet.contention.terminal.hold',
                    'reason' => 'contention terminal release',
                ],
            ]);

            self::assertSame(1, $this->successCount($results), $this->resultsDiagnostic($results));
            self::assertSame(1, $this->failureCount($results), $this->resultsDiagnostic($results));

            $hold = DB::table('wallet_holds')->where('hold_key', 'wallet.contention.terminal.hold')->first();
            self::assertNotNull($hold);
            self::assertContains($hold->status, ['captured', 'released']);
            $ledgerAfter = DB::table('ledger_transactions')->count();
            self::assertSame($hold->status === 'captured' ? $ledgerBefore + 1 : $ledgerBefore, $ledgerAfter);
            self::assertLessThanOrEqual(1, DB::table('ledger_transactions')->where('transaction_type', 'wallet_hold_capture')->count());
        }

        public function test_duplicate_concurrent_ledger_command_key_finalizes_one_effect_and_exact_replays(): void
        {
            $assetId = $this->account('system.wallet.contention.ledger.asset', 'asset');
            $revenueId = $this->account('system.wallet.contention.ledger.revenue', 'revenue');
            $payload = [
                'action' => 'ledger_post',
                'command_key' => 'ledger.contention.duplicate.000001',
                'transaction_type' => 'contention_verification',
                'correlation_id' => 'corr-contention-ledger-000001',
                'source_type' => 'verification',
                'source_id' => 'wallet-contention-ledger',
                'entries' => [
                    ['account_id' => $assetId, 'direction' => 'debit', 'amount_irr' => 100_000],
                    ['account_id' => $revenueId, 'direction' => 'credit', 'amount_irr' => 100_000],
                ],
            ];

            $results = $this->runConcurrent([$payload, $payload]);

            self::assertSame(2, $this->successCount($results), $this->resultsDiagnostic($results));
            self::assertSame([false, true], $this->sortedReplayFlags($results));
            self::assertSame(1, DB::table('ledger_transactions')->where('command_key', 'ledger.contention.duplicate.000001')->count());
            self::assertSame(2, DB::table('ledger_entries')->count());
            self::assertSame(100_000, (int) DB::table('ledger_transactions')->where('command_key', 'ledger.contention.duplicate.000001')->value('posted_debit_irr'));
            self::assertSame(100_000, (int) DB::table('ledger_transactions')->where('command_key', 'ledger.contention.duplicate.000001')->value('posted_credit_irr'));
        }

        public function test_duplicate_concurrent_transfer_prepare_and_confirm_create_one_hold_and_one_ledger_effect(): void
        {
            [$senderId, $senderWalletId] = $this->fundedWallet('contention-transfer-sender', 1_000_000);
            $recipientPublicId = (string) Str::ulid();
            $recipientId = $this->user($recipientPublicId);
            $recipientWalletId = $this->account('wallet.cash.contention-transfer-recipient.'.$recipientId, 'liability', $recipientId, 'cash');
            unset($senderWalletId, $recipientWalletId);

            $preparePayload = [
                'action' => 'transfer_prepare',
                'transfer_key' => 'wallet.contention.transfer.000001',
                'sender_user_id' => $senderId,
                'recipient_public_id' => $recipientPublicId,
                'amount_irr' => 300_000,
            ];
            $prepareResults = $this->runConcurrent([$preparePayload, $preparePayload]);

            self::assertSame(2, $this->successCount($prepareResults), $this->resultsDiagnostic($prepareResults));
            self::assertSame([false, true], $this->sortedReplayFlags($prepareResults));
            self::assertSame(1, DB::table('wallet_transfers')->where('transfer_key', 'wallet.contention.transfer.000001')->count());
            self::assertSame(1, DB::table('wallet_holds')->where('source_type', 'wallet_transfer')->where('source_id', 'wallet.contention.transfer.000001')->count());

            $confirmPayload = [
                'action' => 'transfer_confirm',
                'transfer_key' => 'wallet.contention.transfer.000001',
                'confirmation_key' => 'confirm-contention-transfer-000001',
                'correlation_id' => 'corr-contention-transfer-000001',
            ];
            $confirmResults = $this->runConcurrent([$confirmPayload, $confirmPayload]);

            self::assertSame(2, $this->successCount($confirmResults), $this->resultsDiagnostic($confirmResults));
            self::assertSame([false, true], $this->sortedReplayFlags($confirmResults));
            self::assertSame('completed', DB::table('wallet_transfers')->where('transfer_key', 'wallet.contention.transfer.000001')->value('status'));
            self::assertSame(1, DB::table('ledger_transactions')->where('transaction_type', 'wallet_transfer')->count());
            self::assertSame(1, DB::table('wallet_holds')->where('source_type', 'wallet_transfer')->where('source_id', 'wallet.contention.transfer.000001')->where('status', 'released')->count());
        }

        public function test_reconciliation_concurrent_with_hold_mutation_records_only_an_internally_consistent_before_or_after_state(): void
        {
            [$userId, $walletId] = $this->fundedWallet('contention-reconcile', 1_000_000);
            $results = $this->runConcurrent([
                [
                    'action' => 'reconcile',
                    'owner_user_id' => $userId,
                    'ledger_account_id' => $walletId,
                ],
                [
                    'action' => 'hold_place',
                    'hold_key' => 'wallet.contention.reconcile.hold',
                    'owner_user_id' => $userId,
                    'ledger_account_id' => $walletId,
                    'amount_irr' => 300_000,
                    'source_type' => 'order',
                    'source_id' => 'contention-reconcile-order',
                    'expires_at' => now('UTC')->addHour()->toIso8601String(),
                ],
            ]);

            self::assertSame(2, $this->successCount($results), $this->resultsDiagnostic($results));
            $reconcile = $results[0]['result'];
            self::assertIsArray($reconcile);
            self::assertSame(1_000_000, $reconcile['ledger_balance_irr']);
            self::assertContains($reconcile['active_holds_irr'], [0, 300_000]);
            self::assertSame(
                $reconcile['ledger_balance_irr'] - $reconcile['active_holds_irr'],
                $reconcile['available_balance_irr'],
            );
            self::assertContains($reconcile['available_balance_irr'], [1_000_000, 700_000]);

            $snapshot = DB::table('wallet_balance_snapshots')->where('id', $reconcile['snapshot_id'])->first();
            self::assertNotNull($snapshot);
            self::assertSame((int) $snapshot->ledger_balance_irr - (int) $snapshot->active_holds_irr, (int) $snapshot->available_balance_irr);
            self::assertSame(1, DB::table('wallet_holds')->where('hold_key', 'wallet.contention.reconcile.hold')->count());
            self::assertSame(700_000, $this->app->make(WalletHoldService::class)->balance($userId, $walletId)->availableBalance->amount);
        }

        public function test_worker_failures_are_explicit_and_never_counted_as_successful_primary_effects(): void
        {
            [$userId, $walletId] = $this->fundedWallet('contention-errors', 500_000);
            $expiry = now('UTC')->addHour()->toIso8601String();
            $results = $this->runConcurrent([
                [
                    'action' => 'hold_place',
                    'hold_key' => 'wallet.contention.errors.a',
                    'owner_user_id' => $userId,
                    'ledger_account_id' => $walletId,
                    'amount_irr' => 400_000,
                    'source_type' => 'order',
                    'source_id' => 'contention-errors-a',
                    'expires_at' => $expiry,
                ],
                [
                    'action' => 'hold_place',
                    'hold_key' => 'wallet.contention.errors.b',
                    'owner_user_id' => $userId,
                    'ledger_account_id' => $walletId,
                    'amount_irr' => 400_000,
                    'source_type' => 'order',
                    'source_id' => 'contention-errors-b',
                    'expires_at' => $expiry,
                ],
            ]);

            self::assertSame(1, $this->successCount($results), $this->resultsDiagnostic($results));
            $failure = $results[0]['ok'] ? $results[1] : $results[0];
            self::assertFalse($failure['ok']);
            self::assertNotSame('', $failure['exception']);
            self::assertNotSame('', $failure['message']);
            self::assertSame(1, DB::table('wallet_holds')->where('status', 'active')->count());
            self::assertSame(100_000, $this->app->make(WalletHoldService::class)->balance($userId, $walletId)->availableBalance->amount);
        }

        /**
         * @param list<array<string, mixed>> $payloads
         * @return list<array{ok:bool, result?:array<string,mixed>, exception?:string, message?:string}>
         */
        private function runConcurrent(array $payloads): array
        {
            $workers = [];
            foreach ($payloads as $payload) {
                $encoded = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, __FILE__, '--wallet-contention-worker', $encoded],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    base_path(),
                );
                if (! is_resource($process)) {
                    throw new RuntimeException('Unable to start wallet contention worker.');
                }
                $ready = fgets($pipes[1]);
                if ($ready !== "READY\n") {
                    $stderr = stream_get_contents($pipes[2]);
                    proc_terminate($process);
                    proc_close($process);
                    throw new RuntimeException('Wallet contention worker failed before barrier: '.trim((string) $stderr));
                }
                $workers[] = ['process' => $process, 'pipes' => $pipes];
            }

            foreach ($workers as $worker) {
                fwrite($worker['pipes'][0], "GO\n");
                fclose($worker['pipes'][0]);
            }

            $results = [];
            foreach ($workers as $worker) {
                $stdout = stream_get_contents($worker['pipes'][1]);
                $stderr = stream_get_contents($worker['pipes'][2]);
                fclose($worker['pipes'][1]);
                fclose($worker['pipes'][2]);
                $exitCode = proc_close($worker['process']);
                if ($exitCode !== 0) {
                    throw new RuntimeException(sprintf('Wallet contention worker exited %d: %s', $exitCode, trim((string) $stderr)));
                }
                if (trim((string) $stderr) !== '') {
                    throw new RuntimeException('Wallet contention worker emitted stderr: '.trim((string) $stderr));
                }

                $decoded = json_decode(trim((string) $stdout), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($decoded) || ! array_key_exists('ok', $decoded) || ! is_bool($decoded['ok'])) {
                    throw new RuntimeException('Wallet contention worker returned malformed output.');
                }
                $results[] = $decoded;
            }

            return $results;
        }

        /** @param list<array{ok:bool, result?:array<string,mixed>, exception?:string, message?:string}> $results */
        private function successCount(array $results): int
        {
            return count(array_filter($results, static fn (array $result): bool => $result['ok']));
        }

        /** @param list<array{ok:bool, result?:array<string,mixed>, exception?:string, message?:string}> $results */
        private function failureCount(array $results): int
        {
            return count($results) - $this->successCount($results);
        }

        /**
         * @param list<array{ok:bool, result?:array<string,mixed>, exception?:string, message?:string}> $results
         * @return list<bool>
         */
        private function sortedReplayFlags(array $results): array
        {
            $flags = array_map(static function (array $result): bool {
                if (! $result['ok'] || ! isset($result['result']['replayed']) || ! is_bool($result['result']['replayed'])) {
                    throw new RuntimeException('Expected successful replay-aware contention result.');
                }

                return $result['result']['replayed'];
            }, $results);
            sort($flags);

            return $flags;
        }

        /** @param list<array{ok:bool, result?:array<string,mixed>, exception?:string, message?:string}> $results */
        private function resultsDiagnostic(array $results): string
        {
            return json_encode($results, JSON_THROW_ON_ERROR);
        }

        /** @return array{0:int,1:int,2:int} */
        private function fundedWallet(string $suffix, int $amount): array
        {
            $userId = $this->user((string) Str::ulid());
            $assetId = $this->account('system.wallet.'.$suffix.'.asset', 'asset');
            $walletId = $this->account('wallet.cash.'.$suffix.'.'.$userId, 'liability', $userId, 'cash');
            $this->app->make(LedgerPostingService::class)->post(
                'ledger.wallet.'.$suffix.'.fund',
                'wallet_topup_capture',
                'corr-wallet-'.$suffix.'-fund',
                [
                    new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amount)),
                    new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amount)),
                ],
                'verification',
                'wallet-'.$suffix.'-fund',
            );

            return [$userId, $walletId, $assetId];
        }

        private function user(string $publicId): int
        {
            $now = now('UTC');

            return (int) DB::table('users')->insertGetId([
                'public_id' => $publicId,
                'account_type' => 'customer',
                'account_status' => 'active',
                'locale' => 'fa',
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        private function account(
            string $code,
            string $class,
            ?int $userId = null,
            ?string $bucket = null,
        ): int {
            $now = now('UTC');

            return (int) DB::table('ledger_accounts')->insertGetId([
                'code' => $code,
                'account_class' => $class,
                'owner_user_id' => $userId,
                'wallet_bucket' => $bucket,
                'currency' => 'IRR',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
