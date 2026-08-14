<?php

declare(strict_types=1);

namespace {
    use App\Modules\Promotions\Application\ReferralRewardAccrualService;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\DatabaseManager;

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--referral-reward-contention-worker') {
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
            $receipt = $app->make(ReferralRewardAccrualService::class)->accrue(
                (string) $payload['settlement_public_id'],
                (string) $payload['correlation_id'],
            );
            echo json_encode([
                'ok' => true,
                'result' => $receipt === null ? null : [
                    'accrual_id' => $receipt->accrualId,
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
    use App\Modules\Orders\Application\QuotePricingInput;
    use App\Modules\Orders\Application\QuoteService;
    use App\Modules\Orders\Domain\QuoteOverrideSource;
    use App\Modules\Payments\Application\Contracts\PaymentEvidence;
    use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
    use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
    use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
    use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
    use App\Modules\Payments\Application\PurchasePaymentIntentService;
    use App\Modules\Payments\Application\PurchaseSettlementReceipt;
    use App\Modules\Payments\Application\PurchaseSettlementService;
    use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
    use App\Modules\Promotions\Application\PromotionRuleService;
    use App\Modules\Promotions\Application\ReferralAttributionService;
    use App\Modules\Promotions\Domain\PromotionAction;
    use App\Modules\Promotions\Domain\PromotionAudience;
    use App\Modules\Promotions\Domain\PromotionDiscountType;
    use App\Modules\Promotions\Domain\PromotionRuleDefinition;
    use App\Modules\Promotions\Domain\PromotionRuleKind;
    use App\Modules\Promotions\Domain\PromotionRuleState;
    use App\Modules\Promotions\Domain\ReferralRewardRecipient;
    use App\Shared\Application\Clock;
    use App\Shared\Domain\Money;
    use Database\Seeders\CatalogAccessFoundationSeeder;
    use Database\Seeders\IdentityAccessFoundationSeeder;
    use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
    use DateTimeImmutable;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\TestCase;

    final class ReferralRewardContentionClock implements Clock
    {
        public function __construct(public DateTimeImmutable $value) {}

        public function now(): DateTimeImmutable
        {
            return $this->value;
        }
    }

    /** @requirement REF-001 DAT-003 DAT-004 QUA-001 QUA-004 */
    final class ReferralRewardAccrualContentionVerificationTest extends TestCase
    {
        use AgentPricingQuoteIntegrationTestSupport;
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 20;

        private ReferralRewardContentionClock $clock;

        protected function setUp(): void
        {
            parent::setUp();
            $this->seed(IdentityAccessFoundationSeeder::class);
            $this->seed(CatalogAccessFoundationSeeder::class);
            $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
            $this->clock = new ReferralRewardContentionClock(new DateTimeImmutable('2026-08-14T03:00:00+00:00'));
            $this->app->instance(Clock::class, $this->clock);
        }

        public function test_concurrent_duplicate_settlement_creates_once_and_replays_once(): void
        {
            [$token, $referred] = $this->referralPair();
            $this->createRewardRule('duplicate', $token, null);
            $settlement = $this->capturePurchaseFor($referred, 'duplicate');
            $payload = [
                'settlement_public_id' => $settlement->settlementPublicId,
                'correlation_id' => $this->correlation('duplicate'),
            ];

            $results = $this->runConcurrent([$payload, $payload]);

            self::assertTrue($results[0]['ok']);
            self::assertTrue($results[1]['ok']);
            self::assertNotNull($results[0]['result']);
            self::assertNotNull($results[1]['result']);
            self::assertSame($results[0]['result']['accrual_id'], $results[1]['result']['accrual_id']);
            $replayed = [(bool) $results[0]['result']['replayed'], (bool) $results[1]['result']['replayed']];
            sort($replayed);
            self::assertSame([false, true], $replayed);
            self::assertSame(1, DB::table('referral_reward_accruals')->count());
            self::assertSame(1, DB::table('referral_rewards')->count());
            self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'referral.reward.pending')->count());
        }

        public function test_competing_settlements_serialize_at_final_total_limit_slot(): void
        {
            $inviter = $this->quoteUser('customer');
            $firstReferred = $this->quoteUser('customer');
            $secondReferred = $this->quoteUser('customer');
            $attribution = $this->app->make(ReferralAttributionService::class);
            $token = $attribution->identityForUser($inviter);
            $attribution->bind($firstReferred, $token);
            $attribution->bind($secondReferred, $token);
            $this->createRewardRule('final-slot', $token, 1);
            $firstSettlement = $this->capturePurchaseFor($firstReferred, 'final-slot-a');
            $secondSettlement = $this->capturePurchaseFor($secondReferred, 'final-slot-b');

            $results = $this->runConcurrent([
                [
                    'settlement_public_id' => $firstSettlement->settlementPublicId,
                    'correlation_id' => $this->correlation('final-slot-a'),
                ],
                [
                    'settlement_public_id' => $secondSettlement->settlementPublicId,
                    'correlation_id' => $this->correlation('final-slot-b'),
                ],
            ]);

            self::assertTrue($results[0]['ok']);
            self::assertTrue($results[1]['ok']);
            self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['result'] !== null));
            self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['result'] === null));
            self::assertSame(1, DB::table('referral_reward_accruals')->count());
            self::assertSame(1, DB::table('referral_rewards')->count());
            self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'referral.reward.pending')->count());
        }

        /** @return array{0:string,1:int} */
        private function referralPair(): array
        {
            $inviter = $this->quoteUser('customer');
            $referred = $this->quoteUser('customer');
            $attribution = $this->app->make(ReferralAttributionService::class);
            $token = $attribution->identityForUser($inviter);
            $attribution->bind($referred, $token);

            return [$token, $referred];
        }

        private function createRewardRule(string $suffix, string $referralSourceCode, ?int $totalUseLimit): void
        {
            $administratorId = $this->ownerAdministrator();
            $this->app->make(PromotionRuleService::class)->create(
                'referral.reward.contention.rule.'.$suffix,
                'referral-reward-contention-'.$suffix,
                PromotionRuleKind::Referral,
                new PromotionRuleDefinition(
                    PromotionRuleState::Active,
                    100,
                    PromotionDiscountType::Fixed,
                    100_000,
                    null,
                    0,
                    null,
                    null,
                    null,
                    $totalUseLimit,
                    null,
                    false,
                    PromotionAudience::Customers,
                    null,
                    null,
                    null,
                    null,
                    null,
                    PromotionAction::Purchase,
                    $referralSourceCode,
                    false,
                    ReferralRewardRecipient::Inviter,
                    null,
                    null,
                    false,
                    null,
                ),
                new AccessChangeContext(
                    hash('sha256', 'referral-reward-contention-rule-request:'.$suffix),
                    $this->correlation('rule-'.$suffix),
                    'referral_reward_contention_test',
                    'Referral reward contention test configuration.',
                    $administratorId,
                ),
            );
        }

        private function capturePurchaseFor(int $userId, string $suffix): PurchaseSettlementReceipt
        {
            $administratorId = $this->ownerAdministrator();
            $offering = $this->quoteOffering();
            $quote = $this->app->make(QuoteService::class)->create(
                'referral.reward.contention.quote.'.$suffix,
                $userId,
                $offering['id'],
                new QuotePricingInput(
                    QuoteOverrideSource::None,
                    null,
                    null,
                    null,
                    0,
                    $this->clock->value->modify('+30 minutes'),
                ),
                $this->correlation('quote-'.$suffix),
            );
            $methodCode = 'referral_reward_contention_gateway_'.$suffix;
            $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
            $eligibility->configureMethod(
                'referral.reward.contention.method.'.$suffix,
                $administratorId,
                $methodCode,
                true,
                false,
                1,
                'Referral reward contention test configuration.',
                $this->correlation('method-'.$suffix),
            );
            $eligibility->recordHealth(
                'referral.reward.contention.health.'.$suffix,
                $administratorId,
                $methodCode,
                true,
                $this->clock->value->modify('+10 minutes'),
                'Healthy referral reward contention observation.',
                $this->correlation('health-'.$suffix),
            );
            $decision = $eligibility->evaluate(
                'referral.reward.contention.eligibility.'.$suffix,
                $userId,
                $quote->quotePublicId,
            );
            $purchase = $this->app->make(PurchasePaymentIntentService::class)->create(
                'referral.reward.contention.intent.'.$suffix,
                $userId,
                $quote->quotePublicId,
                $decision->publicId,
                $methodCode,
                $this->correlation('intent-'.$suffix),
            );
            DB::table('payment_intents')->where('public_id', $purchase->intentPublicId)->update([
                'state' => 'submitted',
                'updated_at' => $this->timestamp(),
            ]);

            return $this->app->make(PurchaseSettlementService::class)->capture(
                $purchase->intentPublicId,
                $methodCode,
                new VerifiedPaymentEvent(
                    'referral-reward-contention-event-'.$suffix,
                    hash('sha256', 'referral-reward-contention-provider-event:'.$suffix),
                    new PaymentEvidence(
                        ProviderOperationOutcome::Success,
                        PaymentEvidenceAuthority::Authoritative,
                        PaymentTransactionStatus::Settled,
                        'referral-reward-contention-transaction-'.$suffix,
                        'referral-reward-contention-event-'.$suffix,
                        Money::irr($purchase->amount->amount()),
                        $this->clock->value,
                        $this->clock->value,
                        hash('sha256', 'referral-reward-contention-provider-evidence:'.$suffix),
                        ['provider_reference' => 'referral-reward-contention-transaction-'.$suffix],
                    ),
                ),
                $this->correlation('capture-'.$suffix),
            );
        }

        private function correlation(string $suffix): string
        {
            return hash('sha256', 'referral-reward-contention:'.$suffix);
        }

        private function timestamp(): string
        {
            return $this->clock->value->format('Y-m-d H:i:s.u');
        }

        /**
         * @param  list<array{settlement_public_id:string,correlation_id:string}>  $payloads
         * @return list<array<string, mixed>>
         */
        private function runConcurrent(array $payloads): array
        {
            /** @var list<array{process:resource,pipes:array{0:resource,1:resource,2:resource}}> $workers */
            $workers = [];
            try {
                foreach ($payloads as $payload) {
                    $pipes = [];
                    $process = proc_open([
                        PHP_BINARY,
                        '-d',
                        'pcov.enabled=0',
                        __FILE__,
                        '--referral-reward-contention-worker',
                        base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                    ], [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ], $pipes, dirname(__DIR__, 2));
                    if (! is_resource($process)) {
                        throw new RuntimeException('Unable to start referral reward contention worker.');
                    }
                    /** @var array{0:resource,1:resource,2:resource} $pipes */
                    stream_set_blocking($pipes[1], false);
                    stream_set_blocking($pipes[2], false);
                    $workers[] = ['process' => $process, 'pipes' => $pipes];
                }

                foreach ($workers as $index => $worker) {
                    if ($this->readLine($worker, 'readiness', $index) !== "READY\n") {
                        throw new RuntimeException('Referral reward contention worker returned an invalid readiness marker.');
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
                        throw new RuntimeException('Referral reward contention worker failed: '.$stderr);
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
                    throw new RuntimeException('Unable to wait for referral reward contention worker output.');
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
                    throw new RuntimeException('Referral reward contention worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException(sprintf(
                'Referral reward contention worker %d timed out during %s after %d seconds: %s',
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
