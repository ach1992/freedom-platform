<?php

declare(strict_types=1);

namespace {
    use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeRedemptionContext;
    use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeRedemptionRequest;
    use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeService;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\DatabaseManager;

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--benefit-code-contention-worker') {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app->make(DatabaseManager::class)->connection()->statement('SET SESSION innodb_lock_wait_timeout = 5');

        echo "READY\n";
        flush();
        $encoded = fgets(STDIN);
        if ($encoded === false) {
            fwrite(STDERR, "Benefit code contention worker payload was not provided.\n");
            exit(2);
        }
        $decoded = base64_decode(trim($encoded), true);
        if ($decoded === false) {
            fwrite(STDERR, "Invalid benefit code contention worker payload encoding.\n");
            exit(2);
        }
        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
            $request = new BenefitCodeRedemptionRequest(
                (string) $payload['redemption_key'],
                (string) $payload['code'],
                (int) $payload['user_id'],
                isset($payload['plan_offering_id']) ? (int) $payload['plan_offering_id'] : null,
                isset($payload['promotional_wallet_account_id']) ? (int) $payload['promotional_wallet_account_id'] : null,
                (string) $payload['correlation_id'],
            );
            $receipt = $app->make(BenefitCodeService::class)->redeem($request, new BenefitCodeRedemptionContext((int) $payload['user_id']));
            echo json_encode([
                'ok' => true,
                'result' => [
                    'ledger_transaction_id' => $receipt->ledgerTransactionId,
                    'redemption_id' => $receipt->redemptionId,
                    'redemption_public_id' => $receipt->redemptionPublicId,
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
    use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeType;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\Support\CreatesBenefitCodeFixtures;
    use Tests\TestCase;

    /** @requirement PRO-002 WAL-002 DAT-003 DAT-004 SEC-001 SEC-002 SEC-003 SEC-008 QUA-001 */
    final class BenefitCodeRedemptionContentionVerificationTest extends TestCase
    {
        use CreatesBenefitCodeFixtures;
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

        public function test_competing_processes_for_final_code_slot_accept_one_redemption_and_one_ledger_effect_only(): void
        {
            $this->benefitCampaign('benefit.cont.final', BenefitCodeType::WalletCredit, $this->walletDefinition(amountIrr: 210_000), 'cont-final');
            $issued = $this->benefitIssue('benefit.cont.final', 'cont-final');
            $code = (string) $issued->items[0]->fullCode;
            $firstUser = $this->benefitUser();
            $secondUser = $this->benefitUser();
            $firstWallet = $this->benefitPromotionalWallet($firstUser);
            $secondWallet = $this->benefitPromotionalWallet($secondUser);

            $results = $this->runConcurrent([
                $this->payload('contention-final-redemption-001', $code, $firstUser, $firstWallet, 'contention-final-correlation-01'),
                $this->payload('contention-final-redemption-002', $code, $secondUser, $secondWallet, 'contention-final-correlation-02'),
            ]);

            self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['ok'] === true));
            self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['ok'] === false));
            self::assertSame(1, DB::table('benefit_code_redemptions')->count());
            self::assertSame(1, DB::table('ledger_transactions')->where('transaction_type', 'benefit_code_promotional_credit')->count());
            self::assertSame(2, DB::table('ledger_entries')->whereIn('ledger_transaction_id', DB::table('ledger_transactions')->where('transaction_type', 'benefit_code_promotional_credit')->select('id'))->count());
        }

        public function test_concurrent_exact_duplicate_redemption_returns_one_created_one_replay_and_one_ledger_effect(): void
        {
            $this->benefitCampaign('benefit.cont.duplicate', BenefitCodeType::WalletCredit, $this->walletDefinition(amountIrr: 190_000), 'cont-duplicate');
            $issued = $this->benefitIssue('benefit.cont.duplicate', 'cont-duplicate');
            $code = (string) $issued->items[0]->fullCode;
            $user = $this->benefitUser();
            $wallet = $this->benefitPromotionalWallet($user);
            $payload = $this->payload('contention-duplicate-redemption-01', $code, $user, $wallet, 'contention-duplicate-correlation');

            $results = $this->runConcurrent([$payload, $payload]);

            self::assertTrue($results[0]['ok']);
            self::assertTrue($results[1]['ok']);
            self::assertSame($results[0]['result']['redemption_id'], $results[1]['result']['redemption_id']);
            self::assertSame($results[0]['result']['ledger_transaction_id'], $results[1]['result']['ledger_transaction_id']);
            $replayed = [(bool) $results[0]['result']['replayed'], (bool) $results[1]['result']['replayed']];
            sort($replayed);
            self::assertSame([false, true], $replayed);
            self::assertSame(1, DB::table('benefit_code_redemptions')->count());
            self::assertSame(1, DB::table('ledger_transactions')->where('transaction_type', 'benefit_code_promotional_credit')->count());
        }

        /** @return array<string,mixed> */
        private function payload(string $key, string $code, int $userId, int $walletId, string $correlationId): array
        {
            return [
                'redemption_key' => $key,
                'code' => $code,
                'user_id' => $userId,
                'promotional_wallet_account_id' => $walletId,
                'correlation_id' => $correlationId,
            ];
        }

        /**
         * @param list<array<string,mixed>> $payloads
         * @return list<array<string,mixed>>
         */
        private function runConcurrent(array $payloads): array
        {
            /** @var list<array{process:resource,pipes:array{0:resource,1:resource,2:resource},payload:string}> $workers */
            $workers = [];
            try {
                foreach ($payloads as $payload) {
                    $pipes = [];
                    $process = proc_open([
                        PHP_BINARY,
                        '-d',
                        'pcov.enabled=0',
                        __FILE__,
                        '--benefit-code-contention-worker',
                    ], [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ], $pipes, dirname(__DIR__, 2));
                    if (! is_resource($process)) {
                        throw new RuntimeException('Unable to start benefit code contention worker.');
                    }
                    /** @var array{0:resource,1:resource,2:resource} $pipes */
                    stream_set_blocking($pipes[1], false);
                    stream_set_blocking($pipes[2], false);
                    $workers[] = [
                        'process' => $process,
                        'pipes' => $pipes,
                        'payload' => base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                    ];
                }

                foreach ($workers as $index => $worker) {
                    if ($this->readLine($worker, 'readiness', $index) !== "READY\n") {
                        throw new RuntimeException('Benefit code contention worker returned an invalid readiness marker.');
                    }
                }
                foreach ($workers as $worker) {
                    fwrite($worker['pipes'][0], $worker['payload']."\n");
                    fflush($worker['pipes'][0]);
                    fclose($worker['pipes'][0]);
                }

                $results = [];
                foreach ($workers as $index => $worker) {
                    $line = $this->readLine($worker, 'result', $index);
                    $stderr = stream_get_contents($worker['pipes'][2]);
                    fclose($worker['pipes'][1]);
                    fclose($worker['pipes'][2]);
                    if (proc_close($worker['process']) !== 0) {
                        throw new RuntimeException('Benefit code contention worker failed without exposing its input: '.$stderr);
                    }
                    /** @var array<string,mixed> $result */
                    $result = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    $results[] = $result;
                }

                return $results;
            } finally {
                $this->terminateWorkers($workers);
            }
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource},payload:string} $worker */
        private function readLine(array $worker, string $phase, int $index): string
        {
            $deadline = microtime(true) + self::WORKER_TIMEOUT_SECONDS;
            $stderr = '';
            while (microtime(true) < $deadline) {
                $read = [$worker['pipes'][1], $worker['pipes'][2]];
                $write = null;
                $except = null;
                $selected = stream_select($read, $write, $except, 0, 200_000);
                if ($selected === false) {
                    throw new RuntimeException('Unable to wait for benefit code contention worker output.');
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
                    throw new RuntimeException('Benefit code contention worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException(sprintf(
                'Benefit code contention worker %d timed out during %s after %d seconds: %s',
                $index,
                $phase,
                self::WORKER_TIMEOUT_SECONDS,
                $stderr,
            ));
        }

        /** @param list<array{process:resource,pipes:array{0:resource,1:resource,2:resource},payload:string}> $workers */
        private function terminateWorkers(array $workers): void
        {
            foreach ($workers as $worker) {
                if (! is_resource($worker['process'])) {
                    continue;
                }
                $status = proc_get_status($worker['process']);
                if ($status['running']) {
                    proc_terminate($worker['process']);
                }
                foreach ($worker['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($worker['process']);
            }
        }
    }
}
