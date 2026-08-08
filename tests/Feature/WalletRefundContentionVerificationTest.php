<?php

declare(strict_types=1);

namespace {
    use App\Modules\AccessControl\Application\AccessChangeContext;
    use App\Modules\Wallet\Application\RefundEntryAllocation;
    use App\Modules\Wallet\Application\WalletRefundService;
    use App\Modules\Wallet\Domain\IrrMoney;
    use App\Modules\Wallet\Domain\RefundDestination;
    use Illuminate\Contracts\Console\Kernel;

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--wallet-refund-contention-worker') {
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
            /** @var list<array{source_ledger_entry_id:int, amount_irr:int}> $rawAllocations */
            $rawAllocations = $payload['allocations'];
            $allocations = array_map(
                static fn (array $allocation): RefundEntryAllocation => new RefundEntryAllocation(
                    $allocation['source_ledger_entry_id'],
                    IrrMoney::positive($allocation['amount_irr']),
                ),
                $rawAllocations,
            );
            $context = new AccessChangeContext(
                (string) $payload['request_fingerprint'],
                (string) $payload['correlation_id'],
                'refund_contention_test',
                'Refund contention verification.',
                (int) $payload['actor_administrator_id'],
            );
            $receipt = $app->make(WalletRefundService::class)->refund(
                (string) $payload['refund_key'],
                (int) $payload['source_ledger_transaction_id'],
                RefundDestination::from((string) $payload['destination']),
                $allocations,
                $context,
                isset($payload['manual_external_reference']) ? (string) $payload['manual_external_reference'] : null,
                isset($payload['manual_external_evidence_reference']) ? (string) $payload['manual_external_evidence_reference'] : null,
            );

            echo json_encode([
                'ok' => true,
                'result' => [
                    'refund_id' => $receipt->refundId,
                    'ledger_id' => $receipt->ledgerTransactionId,
                    'amount_irr' => $receipt->amount->amount,
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
    use App\Modules\Wallet\Application\LedgerEntryDraft;
    use App\Modules\Wallet\Application\LedgerPostingService;
    use App\Modules\Wallet\Application\LedgerRefundabilitySnapshot;
    use App\Modules\Wallet\Domain\IrrMoney;
    use App\Modules\Wallet\Domain\LedgerDirection;
    use App\Modules\Wallet\Domain\RefundDestination;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Str;
    use RuntimeException;
    use Tests\TestCase;

    /** @requirement WAL-004 DAT-002 DAT-003 DAT-004 ACL-001 ACL-002 QUA-001 */
    final class WalletRefundContentionVerificationTest extends TestCase
    {
        use DatabaseTruncation;

        protected function setUp(): void
        {
            parent::setUp();
            $this->seed();
        }

        public function test_concurrent_partial_refunds_cannot_exceed_refundable_source_value(): void
        {
            [$ownerId, $sourceId, $walletEntryId, $revenueEntryId] = $this->walletRefundFixture('partial-cap', 1_000_000);
            $base = [
                'source_ledger_transaction_id' => $sourceId,
                'destination' => RefundDestination::Wallet->value,
                'actor_administrator_id' => $ownerId,
                'allocations' => [
                    ['source_ledger_entry_id' => $walletEntryId, 'amount_irr' => 700_000],
                    ['source_ledger_entry_id' => $revenueEntryId, 'amount_irr' => 700_000],
                ],
            ];

            $results = $this->runConcurrent([
                $base + [
                    'refund_key' => 'refund.contention.cap.000001',
                    'request_fingerprint' => 'refund-contention-cap-request-000001',
                    'correlation_id' => 'refund-contention-cap-corr-000001',
                ],
                $base + [
                    'refund_key' => 'refund.contention.cap.000002',
                    'request_fingerprint' => 'refund-contention-cap-request-000002',
                    'correlation_id' => 'refund-contention-cap-corr-000002',
                ],
            ]);

            $successes = array_values(array_filter($results, static fn (array $result): bool => $result['ok'] === true));
            $failures = array_values(array_filter($results, static fn (array $result): bool => $result['ok'] === false));
            self::assertCount(1, $successes);
            self::assertCount(1, $failures);
            self::assertSame(700_000, $successes[0]['result']['amount_irr']);
            self::assertSame('Refund source entry would be over-refunded.', $failures[0]['message']);
            self::assertSame(1, DB::table('refunds')->count());
            self::assertSame(700_000, (int) DB::table('refunds')->sum('amount_irr'));
            self::assertSame(2, DB::table('ledger_transactions')->count());
            self::assertSame(1, DB::table('audit_logs')->where('action', 'wallet.refund.complete')->count());
        }

        public function test_concurrent_duplicate_refund_key_creates_one_primary_effect_and_one_exact_replay(): void
        {
            [$ownerId, $sourceId, $walletEntryId, $revenueEntryId] = $this->walletRefundFixture('duplicate-key', 1_000_000);
            $payload = [
                'refund_key' => 'refund.contention.duplicate.000001',
                'source_ledger_transaction_id' => $sourceId,
                'destination' => RefundDestination::Wallet->value,
                'actor_administrator_id' => $ownerId,
                'allocations' => [
                    ['source_ledger_entry_id' => $walletEntryId, 'amount_irr' => 400_000],
                    ['source_ledger_entry_id' => $revenueEntryId, 'amount_irr' => 400_000],
                ],
                'request_fingerprint' => 'refund-contention-duplicate-request',
                'correlation_id' => 'refund-contention-duplicate-corr',
            ];

            $results = $this->runConcurrent([$payload, $payload]);

            self::assertTrue($results[0]['ok']);
            self::assertTrue($results[1]['ok']);
            $refundIds = [(int) $results[0]['result']['refund_id'], (int) $results[1]['result']['refund_id']];
            $ledgerIds = [(int) $results[0]['result']['ledger_id'], (int) $results[1]['result']['ledger_id']];
            self::assertSame($refundIds[0], $refundIds[1]);
            self::assertSame($ledgerIds[0], $ledgerIds[1]);
            $replayed = [(bool) $results[0]['result']['replayed'], (bool) $results[1]['result']['replayed']];
            sort($replayed);
            self::assertSame([false, true], $replayed);
            self::assertSame(1, DB::table('refunds')->count());
            self::assertSame(2, DB::table('refund_allocations')->count());
            self::assertSame(2, DB::table('ledger_transactions')->count());
            self::assertSame(1, DB::table('audit_logs')->where('action', 'wallet.refund.complete')->count());
        }

        /** @return array{0:int,1:int,2:int,3:int} */
        private function walletRefundFixture(string $suffix, int $refundableTotal): array
        {
            $ownerId = $this->administrator(true);
            $userId = $this->user();
            $walletId = $this->account('wallet.cash.refund.contention.'.$suffix.'.'.$userId, 'liability', $userId, 'cash');
            $revenueId = $this->account('system.refund.contention.revenue.'.$suffix, 'revenue');
            $source = $this->app->make(LedgerPostingService::class)->post(
                'ledger.refund.contention.source.'.$suffix,
                'order_capture',
                'corr-refund-contention-source-'.$suffix,
                [
                    new LedgerEntryDraft($walletId, LedgerDirection::Debit, IrrMoney::positive(1_000_000)),
                    new LedgerEntryDraft($revenueId, LedgerDirection::Credit, IrrMoney::positive(1_000_000)),
                ],
                'order',
                'refund-contention-source-'.$suffix,
                new LedgerRefundabilitySnapshot(IrrMoney::positive($refundableTotal), RefundDestination::Wallet),
            );

            return [
                $ownerId,
                $source->transactionId,
                $this->entryId($source->transactionId, $walletId, LedgerDirection::Debit),
                $this->entryId($source->transactionId, $revenueId, LedgerDirection::Credit),
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
                $command = [PHP_BINARY, __FILE__, '--wallet-refund-contention-worker', $encoded];
                $pipes = [];
                $process = proc_open($command, [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes, dirname(__DIR__, 2));
                if (! is_resource($process)) {
                    throw new RuntimeException('Unable to start refund contention worker.');
                }
                /** @var array{0:resource,1:resource,2:resource} $pipes */
                $workers[] = ['process' => $process, 'pipes' => $pipes];
            }

            foreach ($workers as $worker) {
                $ready = fgets($worker['pipes'][1]);
                if ($ready !== "READY\n") {
                    $stderr = stream_get_contents($worker['pipes'][2]);
                    throw new RuntimeException('Refund contention worker failed readiness barrier: '.$stderr);
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
                    throw new RuntimeException('Refund contention worker failed: '.$stderr);
                }
                /** @var array<string, mixed> $result */
                $result = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $results[] = $result;
            }

            return $results;
        }

        private function entryId(int $transactionId, int $accountId, LedgerDirection $direction): int
        {
            $entryId = DB::table('ledger_entries')
                ->where('ledger_transaction_id', $transactionId)
                ->where('ledger_account_id', $accountId)
                ->where('direction', $direction->value)
                ->value('id');
            if (! is_int($entryId) && ! is_string($entryId)) {
                throw new RuntimeException('Expected source ledger entry was not found.');
            }

            return (int) $entryId;
        }

        private function administrator(bool $owner = false): int
        {
            $now = now('UTC');

            return (int) DB::table('administrators')->insertGetId([
                'user_id' => $this->user(),
                'status' => 'active',
                'is_owner' => $owner,
                'permission_version' => 1,
                'last_authenticated_at' => $now,
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

        private function account(string $code, string $class, ?int $userId = null, ?string $bucket = null): int
        {
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
