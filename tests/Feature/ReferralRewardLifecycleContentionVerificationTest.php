<?php

declare(strict_types=1);

namespace {
    use App\Modules\Payments\Application\Contracts\PaymentEvidence;
    use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
    use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
    use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
    use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
    use App\Modules\Payments\Application\PurchaseRefundService;
    use App\Modules\Promotions\Application\ReferralRewardLifecycleService;
    use App\Shared\Domain\Money;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\DatabaseManager;

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--referral-reward-lifecycle-contention-worker') {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app->make(DatabaseManager::class)->connection()->statement('SET SESSION innodb_lock_wait_timeout = 5');

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
            if (($payload['mode'] ?? null) === 'process') {
                $receipt = $app->make(ReferralRewardLifecycleService::class)->process(
                    (string) $payload['reward_public_id'],
                    (string) $payload['correlation_id'],
                );
                $result = [
                    'state' => $receipt->state->value,
                    'changed' => $receipt->changed,
                    'replayed' => $receipt->replayed,
                    'release_ledger_transaction_id' => $receipt->releaseLedgerTransactionId,
                    'reversal_ledger_transaction_id' => $receipt->reversalLedgerTransactionId,
                ];
            } elseif (($payload['mode'] ?? null) === 'refund') {
                $eventId = (string) $payload['provider_event_id'];
                $refund = $app->make(PurchaseRefundService::class)->record(
                    (string) $payload['refund_key'],
                    (string) $payload['settlement_public_id'],
                    (string) $payload['provider_code'],
                    new VerifiedPaymentEvent(
                        $eventId,
                        (string) $payload['event_payload_hash'],
                        new PaymentEvidence(
                            ProviderOperationOutcome::Success,
                            PaymentEvidenceAuthority::Authoritative,
                            PaymentTransactionStatus::Refunded,
                            (string) $payload['provider_refund_id'],
                            $eventId,
                            Money::irr((int) $payload['amount_irr']),
                            new DateTimeImmutable((string) $payload['occurred_at']),
                            null,
                            (string) $payload['evidence_payload_hash'],
                            ['provider_reference' => (string) $payload['provider_refund_id']],
                        ),
                    ),
                    (string) $payload['refund_correlation_id'],
                );
                $receipts = $app->make(ReferralRewardLifecycleService::class)->applyPurchaseRefund(
                    $refund->publicId,
                    (string) $payload['correlation_id'],
                );
                $result = [
                    'refund_id' => $refund->refundId,
                    'states' => array_map(static fn ($receipt): string => $receipt->state->value, $receipts),
                ];
            } else {
                throw new RuntimeException('Unknown referral reward lifecycle contention worker mode.');
            }

            echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR)."\n";
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
    use App\Shared\Application\Clock;
    use Database\Seeders\CatalogAccessFoundationSeeder;
    use Database\Seeders\IdentityAccessFoundationSeeder;
    use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
    use Database\Seeders\WalletFinancialFoundationSeeder;
    use DateTimeImmutable;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\TestCase;

    final class ReferralRewardLifecycleContentionClock implements Clock
    {
        public function __construct(public DateTimeImmutable $value) {}

        public function now(): DateTimeImmutable
        {
            return $this->value;
        }
    }

    /** @requirement REF-001 WAL-002 DAT-002 DAT-003 DAT-004 QUA-001 QUA-004 */
    final class ReferralRewardLifecycleContentionVerificationTest extends TestCase
    {
        use AgentPricingQuoteIntegrationTestSupport;
        use DatabaseTruncation;
        use ReferralRewardLifecycleTestSupport;

        private const WORKER_TIMEOUT_SECONDS = 25;

        private ReferralRewardLifecycleContentionClock $clock;

        protected function setUp(): void
        {
            parent::setUp();
            $this->seed(IdentityAccessFoundationSeeder::class);
            $this->seed(CatalogAccessFoundationSeeder::class);
            $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
            $this->seed(WalletFinancialFoundationSeeder::class);
            $this->clock = new ReferralRewardLifecycleContentionClock(new DateTimeImmutable('2026-08-13T22:00:00+00:00'));
            $this->app->instance(Clock::class, $this->clock);
        }

        public function test_duplicate_mature_workers_create_exactly_one_release_effect(): void
        {
            $fixture = $this->pendingReferralRewardFixture('contention-duplicate', 1);
            $this->createLifecyclePromotionalWallet($fixture['recipient_user_id'], 'contention-duplicate');
            $payload = [
                'mode' => 'process',
                'reward_public_id' => $fixture['reward_public_id'],
                'correlation_id' => $this->lifecycleCorrelation('contention-duplicate'),
            ];

            $results = $this->runConcurrent([$payload, $payload]);

            self::assertTrue($results[0]['ok']);
            self::assertTrue($results[1]['ok']);
            self::assertSame(['released'], array_values(array_unique([
                $results[0]['result']['state'],
                $results[1]['result']['state'],
            ])));
            self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['result']['changed'] === true)));
            self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['result']['replayed'] === true)));
            self::assertSame(1, DB::table('ledger_transactions')->where('transaction_type', 'referral_reward_release')->count());
            self::assertSame(0, DB::table('ledger_transactions')->where('transaction_type', 'referral_reward_reversal')->count());
            self::assertSame(1, DB::table('referral_reward_lifecycle_events')->where('event_type', 'released')->count());
            self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'referral.reward.released')->count());
        }

        public function test_release_vs_refund_race_finishes_canceled_or_reversed_without_duplicate_financial_effect(): void
        {
            $fixture = $this->pendingReferralRewardFixture('contention-refund', 1);
            $this->createLifecyclePromotionalWallet($fixture['recipient_user_id'], 'contention-refund');
            $refundSuffix = 'contention-refund';
            $refundEventId = 'referral-lifecycle-contention-refund-event-'.$refundSuffix;

            $results = $this->runConcurrent([
                [
                    'mode' => 'process',
                    'reward_public_id' => $fixture['reward_public_id'],
                    'correlation_id' => $this->lifecycleCorrelation('contention-process'),
                ],
                [
                    'mode' => 'refund',
                    'refund_key' => 'referral.lifecycle.contention.refund.000001',
                    'settlement_public_id' => $fixture['settlement']->settlementPublicId,
                    'provider_code' => $fixture['provider_code'],
                    'provider_event_id' => $refundEventId,
                    'event_payload_hash' => hash('sha256', 'referral-lifecycle-contention-refund-event-payload:'.$refundSuffix),
                    'provider_refund_id' => 'referral-lifecycle-contention-refund-transaction-'.$refundSuffix,
                    'amount_irr' => $fixture['settlement']->amount->amount(),
                    'occurred_at' => '2026-08-14T01:00:00+00:00',
                    'evidence_payload_hash' => hash('sha256', 'referral-lifecycle-contention-refund-evidence:'.$refundSuffix),
                    'refund_correlation_id' => $this->lifecycleCorrelation('contention-refund-record'),
                    'correlation_id' => $this->lifecycleCorrelation('contention-refund-apply'),
                ],
            ]);

            self::assertTrue($results[0]['ok'], json_encode($results[0], JSON_THROW_ON_ERROR));
            self::assertTrue($results[1]['ok'], json_encode($results[1], JSON_THROW_ON_ERROR));

            $reward = DB::table('referral_rewards')->where('public_id', $fixture['reward_public_id'])->first();
            self::assertNotNull($reward);
            self::assertContains($reward->state, ['canceled', 'reversed']);
            self::assertNotSame('released', $reward->state);
            self::assertSame(1, DB::table('purchase_refunds')->where('purchase_settlement_id', $fixture['settlement']->settlementId)->count());

            $releaseCount = DB::table('ledger_transactions')->where('transaction_type', 'referral_reward_release')->count();
            $reversalCount = DB::table('ledger_transactions')->where('transaction_type', 'referral_reward_reversal')->count();
            if ($reward->state === 'canceled') {
                self::assertSame(0, $releaseCount);
                self::assertSame(0, $reversalCount);
                self::assertSame(1, DB::table('referral_reward_lifecycle_events')->where('event_type', 'canceled')->count());
            } else {
                self::assertSame(1, $releaseCount);
                self::assertSame(1, $reversalCount);
                self::assertSame(1, DB::table('referral_reward_lifecycle_events')->where('event_type', 'released')->count());
                self::assertSame(1, DB::table('referral_reward_lifecycle_events')->where('event_type', 'reversed')->count());
                self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'referral.reward.released')->count());
                self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'referral.reward.reversed')->count());
            }
        }

        /**
         * @param  list<array<string, mixed>>  $payloads
         * @return list<array<string, mixed>>
         */
        private function runConcurrent(array $payloads): array
        {
            $workers = [];
            try {
                foreach ($payloads as $payload) {
                    $pipes = [];
                    $process = proc_open([
                        PHP_BINARY,
                        '-d',
                        'pcov.enabled=0',
                        __FILE__,
                        '--referral-reward-lifecycle-contention-worker',
                        base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                    ], [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ], $pipes, dirname(__DIR__, 2));
                    if (! is_resource($process)) {
                        throw new RuntimeException('Unable to start referral reward lifecycle contention worker.');
                    }
                    /** @var array{0:resource,1:resource,2:resource} $pipes */
                    stream_set_blocking($pipes[1], false);
                    stream_set_blocking($pipes[2], false);
                    $workers[] = ['process' => $process, 'pipes' => $pipes];
                }

                foreach ($workers as $index => $worker) {
                    if ($this->readLine($worker, 'readiness', $index) !== "READY\n") {
                        throw new RuntimeException('Referral reward lifecycle contention worker returned an invalid readiness marker.');
                    }
                }
                foreach ($workers as $worker) {
                    fwrite($worker['pipes'][0], "GO\n");
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
                        throw new RuntimeException('Referral reward lifecycle contention worker failed: '.$stderr);
                    }
                    /** @var array<string, mixed> $result */
                    $result = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    $results[] = $result;
                }

                return $results;
            } finally {
                $this->terminateWorkers($workers);
            }
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker */
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
                    throw new RuntimeException('Unable to wait for referral reward lifecycle contention worker output.');
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
                    throw new RuntimeException('Referral reward lifecycle contention worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException(sprintf(
                'Referral reward lifecycle contention worker %d timed out during %s after %d seconds: %s',
                $index,
                $phase,
                self::WORKER_TIMEOUT_SECONDS,
                $stderr,
            ));
        }

        /** @param list<array{process:resource,pipes:array{0:resource,1:resource,2:resource}}> $workers */
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
