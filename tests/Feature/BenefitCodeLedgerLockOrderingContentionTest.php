<?php

declare(strict_types=1);

namespace {
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\Connection;
    use Illuminate\Database\DatabaseManager;

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--benefit-code-canonical-ledger-lock-worker') {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $database = $app->make(DatabaseManager::class);
        $database->connection()->statement('SET SESSION innodb_lock_wait_timeout = 5');

        echo "READY\n";
        flush();
        $encoded = fgets(STDIN);
        if ($encoded === false) {
            fwrite(STDERR, "Canonical ledger lock worker payload was not provided.\n");
            exit(2);
        }
        try {
            /** @var array<string,mixed> $payload */
            $payload = json_decode((string) base64_decode(trim($encoded), true), true, flags: JSON_THROW_ON_ERROR);
            $accountIds = [(int) $payload['funding_id'], (int) $payload['wallet_id']];
            sort($accountIds, SORT_NUMERIC);
            $database->connection()->transaction(function (Connection $db) use ($accountIds): void {
                $first = $db->table('ledger_accounts')->where('id', $accountIds[0])->lockForUpdate()->first(['id']);
                if ($first === null) {
                    throw new RuntimeException('Canonical ledger lock worker first account is missing.');
                }
                echo "LOCKED_FIRST\n";
                flush();
                usleep(750_000);
                $second = $db->table('ledger_accounts')->where('id', $accountIds[1])->lockForUpdate()->first(['id']);
                if ($second === null) {
                    throw new RuntimeException('Canonical ledger lock worker second account is missing.');
                }
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
    use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeType;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\Support\CreatesBenefitCodeFixtures;
    use Tests\TestCase;

    /** @requirement PRO-002 WAL-002 DAT-003 DAT-004 QUA-001 */
    final class BenefitCodeLedgerLockOrderingContentionTest extends TestCase
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

        public function test_wallet_credit_follows_canonical_funding_then_wallet_lock_order_without_deadlock(): void
        {
            $this->benefitCampaign('benefit.lock.order', BenefitCodeType::WalletCredit, $this->walletDefinition(amountIrr: 205_000), 'lock-order');
            $issued = $this->benefitIssue('benefit.lock.order', 'lock-order-issue');
            $code = (string) $issued->items[0]->fullCode;
            $userId = $this->benefitUser();
            $walletId = $this->benefitPromotionalWallet($userId);
            $fundingId = (int) DB::table('ledger_accounts')->where('code', 'system.benefit-code.promotional-funding')->value('id');
            self::assertGreaterThan(0, $fundingId);
            self::assertLessThan($walletId, $fundingId);

            $canonical = $this->startWorker(__FILE__, '--benefit-code-canonical-ledger-lock-worker');
            $redeemer = null;
            try {
                self::assertSame("READY\n", $this->readLine($canonical, 'canonical readiness'));
                $this->sendPayload($canonical, [
                    'funding_id' => $fundingId,
                    'wallet_id' => $walletId,
                ]);
                self::assertSame("LOCKED_FIRST\n", $this->readLine($canonical, 'canonical first lock'));

                $redeemer = $this->startWorker(dirname(__DIR__).'/Feature/BenefitCodeRedemptionContentionVerificationTest.php', '--benefit-code-contention-worker');
                self::assertSame("READY\n", $this->readLine($redeemer, 'redemption readiness'));
                $this->sendPayload($redeemer, [
                    'redemption_key' => 'lock-order-redemption-0001',
                    'code' => $code,
                    'user_id' => $userId,
                    'promotional_wallet_account_id' => $walletId,
                    'correlation_id' => 'lock-order-correlation-0001',
                ]);

                $canonicalResult = $this->readJsonResult($canonical, 'canonical result');
                $redemptionResult = $this->readJsonResult($redeemer, 'redemption result');
                self::assertTrue((bool) ($canonicalResult['ok'] ?? false), json_encode($canonicalResult, JSON_THROW_ON_ERROR));
                self::assertTrue((bool) ($redemptionResult['ok'] ?? false), json_encode($redemptionResult, JSON_THROW_ON_ERROR));
                self::assertSame(1, DB::table('benefit_code_redemptions')->count());
                self::assertSame(1, DB::table('ledger_transactions')->where('transaction_type', 'benefit_code_promotional_credit')->count());
            } finally {
                $this->closeWorker($canonical);
                if ($redeemer !== null) {
                    $this->closeWorker($redeemer);
                }
            }
        }

        /** @return array{process:resource,pipes:array{0:resource,1:resource,2:resource}} */
        private function startWorker(string $file, string $mode): array
        {
            $pipes = [];
            $process = proc_open([
                PHP_BINARY,
                '-d',
                'pcov.enabled=0',
                $file,
                $mode,
            ], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start benefit code lock-order worker.');
            }
            /** @var array{0:resource,1:resource,2:resource} $pipes */
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            return ['process' => $process, 'pipes' => $pipes];
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker
         * @param array<string,mixed> $payload
         */
        private function sendPayload(array $worker, array $payload): void
        {
            fwrite($worker['pipes'][0], base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))."\n");
            fflush($worker['pipes'][0]);
            fclose($worker['pipes'][0]);
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
                    throw new RuntimeException('Unable to wait for benefit code lock-order worker output.');
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
                    throw new RuntimeException('Benefit code lock-order worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException('Benefit code lock-order worker timed out during '.$phase.': '.$stderr);
        }

        /**
         * @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker
         * @return array<string,mixed>
         */
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
