<?php

declare(strict_types=1);

namespace {
    use App\Modules\AccessControl\Application\AccessChangeContext;
    use App\Modules\Wallet\Application\WalletCorrectionService;
    use Illuminate\Contracts\Console\Kernel;

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--wallet-correction-contention-worker') {
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
            $context = new AccessChangeContext(
                (string) $payload['request_fingerprint'],
                (string) $payload['correlation_id'],
                'wallet_correction_contention_test',
                'Wallet correction contention verification.',
                (int) $payload['actor_administrator_id'],
            );
            $receipt = $app->make(WalletCorrectionService::class)->execute(
                (int) $payload['preview_id'],
                (string) $payload['confirmation_token'],
                isset($payload['approval_id']) ? (string) $payload['approval_id'] : null,
                $context,
            );

            echo json_encode([
                'ok' => true,
                'result' => [
                    'correction_id' => $receipt->correctionId,
                    'ledger_id' => $receipt->ledgerTransactionId,
                    'ledger_after_irr' => $receipt->ledgerBalanceAfter->amount,
                    'available_after_irr' => $receipt->availableBalanceAfter->amount,
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
    use App\Modules\AccessControl\Application\AccessChangeContext;
    use App\Modules\AccessControl\Application\SensitiveActionApprovalService;
    use App\Modules\Wallet\Application\LedgerEntryDraft;
    use App\Modules\Wallet\Application\LedgerPostingService;
    use App\Modules\Wallet\Application\WalletCorrectionService;
    use App\Modules\Wallet\Application\WalletHoldService;
    use App\Modules\Wallet\Domain\IrrMoney;
    use App\Modules\Wallet\Domain\LedgerDirection;
    use App\Modules\Wallet\Domain\WalletCorrectionDirection;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Str;
    use RuntimeException;
    use Tests\TestCase;

    /** @requirement WAL-005 DAT-002 DAT-003 DAT-004 ACL-001 ACL-002 QUA-001 */
    final class WalletCorrectionContentionVerificationTest extends TestCase
    {
        use DatabaseTruncation;

        protected function setUp(): void
        {
            parent::setUp();
            $this->seed();
        }

        public function test_concurrent_debit_corrections_cannot_drive_wallet_available_balance_negative(): void
        {
            $ownerId = $this->administrator(true);
            $userId = $this->user();
            $walletId = $this->walletAccount($userId, 'cash', 'debit-race');
            $this->fundWallet($walletId, 1_000_000, 'debit-race');
            $service = $this->app->make(WalletCorrectionService::class);

            $first = $service->preview(
                'correction.contention.debit.000001',
                $userId,
                $walletId,
                WalletCorrectionDirection::Debit,
                IrrMoney::positive(700_000),
                'First competing debit correction.',
                $this->context($ownerId, 'preview-debit-race-1'),
            );
            $second = $service->preview(
                'correction.contention.debit.000002',
                $userId,
                $walletId,
                WalletCorrectionDirection::Debit,
                IrrMoney::positive(700_000),
                'Second competing debit correction.',
                $this->context($ownerId, 'preview-debit-race-2'),
            );

            $results = $this->runConcurrent([
                $this->workerPayload($ownerId, $first->previewId, $first->confirmationToken, 'debit-race-1'),
                $this->workerPayload($ownerId, $second->previewId, $second->confirmationToken, 'debit-race-2'),
            ]);
            $successes = array_values(array_filter($results, static fn (array $result): bool => $result['ok'] === true));
            $failures = array_values(array_filter($results, static fn (array $result): bool => $result['ok'] === false));

            self::assertCount(1, $successes);
            self::assertCount(1, $failures);
            self::assertSame(300_000, $successes[0]['result']['ledger_after_irr']);
            self::assertSame(300_000, $successes[0]['result']['available_after_irr']);
            self::assertSame('Wallet correction preview is stale; create a new correction preview.', $failures[0]['message']);
            self::assertSame(1, DB::table('wallet_corrections')->count());
            self::assertSame(1, DB::table('audit_logs')->where('action', 'wallet.correction.execute')->count());
            $balance = $this->app->make(WalletHoldService::class)->balance($userId, $walletId);
            self::assertSame(300_000, $balance->ledgerBalance->amount);
            self::assertSame(300_000, $balance->availableBalance->amount);
        }

        public function test_concurrent_duplicate_execution_creates_one_correction_effect_and_one_replay(): void
        {
            $ownerId = $this->administrator(true);
            $userId = $this->user();
            $walletId = $this->walletAccount($userId, 'promotional', 'duplicate-race');
            $this->fundWallet($walletId, 600_000, 'duplicate-race');
            $preview = $this->app->make(WalletCorrectionService::class)->preview(
                'correction.contention.duplicate.000001',
                $userId,
                $walletId,
                WalletCorrectionDirection::Credit,
                IrrMoney::positive(400_000),
                'Concurrent duplicate correction execution.',
                $this->context($ownerId, 'preview-duplicate-race'),
            );
            $payload = $this->workerPayload($ownerId, $preview->previewId, $preview->confirmationToken, 'duplicate-race');

            $results = $this->runConcurrent([$payload, $payload]);

            self::assertTrue($results[0]['ok']);
            self::assertTrue($results[1]['ok']);
            self::assertSame($results[0]['result']['correction_id'], $results[1]['result']['correction_id']);
            self::assertSame($results[0]['result']['ledger_id'], $results[1]['result']['ledger_id']);
            $replayed = [(bool) $results[0]['result']['replayed'], (bool) $results[1]['result']['replayed']];
            sort($replayed);
            self::assertSame([false, true], $replayed);
            self::assertSame(1, DB::table('wallet_corrections')->count());
            self::assertSame(1, DB::table('audit_logs')->where('action', 'wallet.correction.execute')->count());
            $balance = $this->app->make(WalletHoldService::class)->balance($userId, $walletId);
            self::assertSame(1_000_000, $balance->ledgerBalance->amount);
        }

        public function test_concurrent_duplicate_execution_with_one_approved_sensitive_action_consumes_once_and_replays(): void
        {
            $financeId = $this->administrator(false, 'finance');
            $approverId = $this->administrator(true);
            $userId = $this->user();
            $walletId = $this->walletAccount($userId, 'cash', 'approval-race');
            $this->fundWallet($walletId, 800_000, 'approval-race');
            $service = $this->app->make(WalletCorrectionService::class);
            $preview = $service->preview(
                'correction.contention.approval.000001',
                $userId,
                $walletId,
                WalletCorrectionDirection::Credit,
                IrrMoney::positive(200_000),
                'Concurrent execution using one approved correction.',
                $this->context($financeId, 'preview-approval-race'),
            );
            self::assertTrue($preview->approvalRequired);
            $approval = $service->requestApproval(
                $preview->previewId,
                $this->context($financeId, 'request-approval-race'),
            );
            $this->app->make(SensitiveActionApprovalService::class)->approve(
                $approval->approvalId,
                $this->context($approverId, 'approve-approval-race'),
            );
            $payload = $this->workerPayload(
                $financeId,
                $preview->previewId,
                $preview->confirmationToken,
                'approval-race',
                $approval->approvalId,
            );

            $results = $this->runConcurrent([$payload, $payload]);

            self::assertTrue($results[0]['ok']);
            self::assertTrue($results[1]['ok']);
            self::assertSame($results[0]['result']['correction_id'], $results[1]['result']['correction_id']);
            self::assertSame($results[0]['result']['ledger_id'], $results[1]['result']['ledger_id']);
            $replayed = [(bool) $results[0]['result']['replayed'], (bool) $results[1]['result']['replayed']];
            sort($replayed);
            self::assertSame([false, true], $replayed);
            self::assertSame('consumed', DB::table('sensitive_action_approvals')->where('id', $approval->approvalId)->value('state'));
            self::assertSame(1, DB::table('wallet_corrections')->count());
            self::assertSame(1, DB::table('audit_logs')->where('action', 'wallet.correction.execute')->count());
        }

        /** @return array<string, mixed> */
        private function workerPayload(
            int $actorAdministratorId,
            int $previewId,
            string $confirmationToken,
            string $suffix,
            ?string $approvalId = null,
        ): array {
            $payload = [
                'actor_administrator_id' => $actorAdministratorId,
                'preview_id' => $previewId,
                'confirmation_token' => $confirmationToken,
                'request_fingerprint' => 'wallet-correction-contention-request-'.$suffix,
                'correlation_id' => 'wallet-correction-contention-correlation-'.$suffix,
            ];
            if ($approvalId !== null) {
                $payload['approval_id'] = $approvalId;
            }

            return $payload;
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
                $command = [PHP_BINARY, __FILE__, '--wallet-correction-contention-worker', $encoded];
                $pipes = [];
                $process = proc_open($command, [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes, dirname(__DIR__, 2));
                if (! is_resource($process)) {
                    throw new RuntimeException('Unable to start wallet correction contention worker.');
                }
                /** @var array{0:resource,1:resource,2:resource} $pipes */
                $workers[] = ['process' => $process, 'pipes' => $pipes];
            }

            foreach ($workers as $worker) {
                $ready = fgets($worker['pipes'][1]);
                if ($ready !== "READY\n") {
                    $stderr = stream_get_contents($worker['pipes'][2]);
                    throw new RuntimeException('Wallet correction contention worker failed readiness barrier: '.$stderr);
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
                    throw new RuntimeException('Wallet correction contention worker failed: '.$stderr);
                }
                /** @var array<string, mixed> $result */
                $result = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $results[] = $result;
            }

            return $results;
        }

        private function fundWallet(int $walletId, int $amount, string $suffix): void
        {
            $offsetId = $this->systemAccount('system.wallet.correction.contention.funding.'.$suffix, 'equity');
            $this->app->make(LedgerPostingService::class)->post(
                'ledger.wallet.correction.contention.funding.'.$suffix,
                'wallet_test_funding',
                'corr-wallet-correction-contention-funding-'.$suffix,
                [
                    new LedgerEntryDraft($offsetId, LedgerDirection::Debit, IrrMoney::positive($amount)),
                    new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amount)),
                ],
                'test_fixture',
                $suffix,
            );
        }

        private function walletAccount(int $userId, string $bucket, string $suffix): int
        {
            $now = now('UTC');

            return (int) DB::table('ledger_accounts')->insertGetId([
                'code' => 'wallet.'.$bucket.'.correction.contention.'.$suffix.'.'.$userId,
                'account_class' => 'liability',
                'owner_user_id' => $userId,
                'wallet_bucket' => $bucket,
                'currency' => 'IRR',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        private function systemAccount(string $code, string $class): int
        {
            $now = now('UTC');

            return (int) DB::table('ledger_accounts')->insertGetId([
                'code' => $code,
                'account_class' => $class,
                'owner_user_id' => null,
                'wallet_bucket' => null,
                'currency' => 'IRR',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        private function administrator(bool $owner = false, ?string $roleCode = null): int
        {
            $now = now('UTC');
            $administratorId = (int) DB::table('administrators')->insertGetId([
                'user_id' => $this->user(),
                'status' => 'active',
                'is_owner' => $owner,
                'permission_version' => 1,
                'last_authenticated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($roleCode !== null) {
                $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
                if (! is_int($roleId) && ! is_string($roleId)) {
                    throw new RuntimeException('Expected administrator role was not seeded.');
                }
                DB::table('administrator_role_assignments')->insert([
                    'administrator_id' => $administratorId,
                    'role_id' => (int) $roleId,
                    'granted_by_administrator_id' => null,
                    'granted_at' => $now,
                    'revoked_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return $administratorId;
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

        private function context(int $actorAdministratorId, string $suffix): AccessChangeContext
        {
            return new AccessChangeContext(
                'wallet-correction-contention-parent-request-'.$suffix,
                'wallet-correction-contention-parent-correlation-'.$suffix,
                'wallet_correction_contention_test',
                'Wallet correction contention test reason.',
                $actorAdministratorId,
            );
        }
    }
}
