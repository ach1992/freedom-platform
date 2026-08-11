<?php

declare(strict_types=1);

namespace {
    use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\DatabaseManager;

    $mode = $argv[1] ?? null;
    if (PHP_SAPI === 'cli' && in_array($mode, [
        '--payment-eligibility-subject-writer',
        '--payment-eligibility-subject-evaluator',
    ], true)) {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        try {
            $decoded = base64_decode((string) ($argv[2] ?? ''), true);
            if ($decoded === false) {
                throw new RuntimeException('Payment eligibility subject-race payload is invalid.');
            }
            /** @var array<string,mixed> $payload */
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
            /** @var DatabaseManager $database */
            $database = $app->make(DatabaseManager::class);
            $connection = $database->connection();
            $connection->statement('SET SESSION innodb_lock_wait_timeout = 10');
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Payment eligibility subject-race bootstrap failed: '.$exception->getMessage()."\n");
            exit(2);
        }

        try {
            if ($mode === '--payment-eligibility-subject-writer') {
                $fact = (string) ($payload['fact'] ?? '');
                $userId = (int) ($payload['user_id'] ?? 0);
                $administratorId = (int) ($payload['administrator_id'] ?? 0);
                $connection->beginTransaction();

                $locked = match ($fact) {
                    'account', 'tag', 'identity' => $connection->table('users')
                        ->where('id', $userId)
                        ->lockForUpdate()
                        ->exists(),
                    'tier' => $connection->table('customer_profiles')
                        ->where('user_id', $userId)
                        ->lockForUpdate()
                        ->exists(),
                    'agent' => $connection->table('agent_profiles')
                        ->where('user_id', $userId)
                        ->lockForUpdate()
                        ->exists(),
                    default => false,
                };
                if (! $locked) {
                    throw new RuntimeException('Payment eligibility subject-race target is missing.');
                }

                echo "LOCKED\n";
                flush();
                if (trim((string) fgets(STDIN)) !== 'STAGE') {
                    throw new RuntimeException('Payment eligibility subject-race stage command is invalid.');
                }

                $now = now('UTC');
                match ($fact) {
                    'account' => $connection->table('users')->where('id', $userId)->update([
                        'account_status' => 'suspended',
                        'updated_at' => $now,
                    ]),
                    'tier' => $connection->table('customer_profiles')->where('user_id', $userId)->update([
                        'current_tier_id' => (int) $connection->table('customer_tiers')->where('code', 'vip')->value('id'),
                        'updated_at' => $now,
                    ]),
                    'tag' => $connection->table('customer_tag_assignments')->insert([
                        'user_id' => $userId,
                        'tag_id' => (int) $connection->table('customer_tags')->where('code', 'risk_review')->value('id'),
                        'assigned_by_administrator_id' => $administratorId,
                        'assigned_at' => $now,
                        'removed_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]),
                    'identity' => $connection->table('customer_profiles')->where('user_id', $userId)->update([
                        'identity_verification_status' => 'pending',
                        'updated_at' => $now,
                    ]),
                    'agent' => $connection->table('agent_profiles')->where('user_id', $userId)->update([
                        'status' => 'suspended',
                        'suspended_at' => $now,
                        'updated_at' => $now,
                    ]),
                    default => throw new RuntimeException('Payment eligibility subject-race fact is invalid.'),
                };

                echo "STAGED\n";
                flush();
                if (trim((string) fgets(STDIN)) !== 'COMMIT') {
                    throw new RuntimeException('Payment eligibility subject-race commit command is invalid.');
                }

                $connection->commit();
                echo json_encode(['ok' => true], JSON_THROW_ON_ERROR)."\n";
                exit(0);
            }

            echo "READY\n";
            flush();
            if (trim((string) fgets(STDIN)) !== 'GO') {
                throw new RuntimeException('Payment eligibility subject-race evaluator barrier was not released.');
            }

            $decision = $app->make(PaymentMethodEligibilityService::class)->evaluate(
                (string) $payload['decision_key'],
                (int) $payload['user_id'],
                (string) $payload['quote_public_id'],
            );
            echo json_encode([
                'decision_id' => $decision->decisionId,
                'methods' => $decision->methods,
                'ok' => true,
            ], JSON_THROW_ON_ERROR)."\n";
        } catch (Throwable $exception) {
            while (isset($connection) && $connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            echo json_encode([
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'ok' => false,
            ], JSON_THROW_ON_ERROR)."\n";
        }

        exit(0);
    }
}

namespace Tests\Feature {
    use App\Modules\Orders\Application\QuotePricingInput;
    use App\Modules\Orders\Application\QuoteService;
    use App\Modules\Orders\Domain\QuoteOverrideSource;
    use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
    use App\Modules\Payments\Eligibility\Domain\PaymentEligibilityRuleDefinition;
    use App\Modules\Payments\Eligibility\Domain\PaymentEligibilityRuleEffect;
    use App\Shared\Application\Clock;
    use Database\Seeders\CatalogAccessFoundationSeeder;
    use Database\Seeders\IdentityAccessFoundationSeeder;
    use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
    use DateTimeImmutable;
    use Illuminate\Database\QueryException;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\TestCase;

    final class PaymentEligibilitySubjectRaceClock implements Clock
    {
        public function __construct(private readonly DateTimeImmutable $time) {}

        public function now(): DateTimeImmutable
        {
            return $this->time;
        }
    }

    /** @requirement PAY-001 DAT-003 DAT-004 QUA-001 QUA-004 */
    final class PaymentMethodEligibilitySubjectFactConcurrencyTest extends TestCase
    {
        use AgentPricingQuoteIntegrationTestSupport;
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 15;

        private PaymentEligibilitySubjectRaceClock $clock;

        protected function setUp(): void
        {
            parent::setUp();
            $this->seed(IdentityAccessFoundationSeeder::class);
            $this->seed(CatalogAccessFoundationSeeder::class);
            $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
            $this->clock = new PaymentEligibilitySubjectRaceClock(new DateTimeImmutable('now'));
            $this->app->instance(Clock::class, $this->clock);
        }

        protected function tearDown(): void
        {
            try {
                $this->truncateDatabaseTables();
            } finally {
                parent::tearDown();
            }
        }

        public function test_account_status_race_is_coherent_or_fails_closed(): void
        {
            [$administratorId, $userId, $quote, $service] = $this->scenario('subject_account');
            $decisionKey = 'eligibility.subject.account.000001';
            $result = $this->runSubjectRace('account', $administratorId, $userId, $quote->quotePublicId, $decisionKey);

            self::assertSame('suspended', DB::table('users')->where('id', $userId)->value('account_status'));
            $this->assertSafeOrCoherent($result, $decisionKey, 'subject_account', 'account_status', 'suspended', 'subject_blocked');
        }

        public function test_tier_race_is_coherent_or_fails_closed(): void
        {
            [$administratorId, $userId, $quote, $service] = $this->scenario('subject_tier');
            $service->configureRule(
                'eligibility.subject.rule.tier.000001',
                $administratorId,
                new PaymentEligibilityRuleDefinition('subject_tier', 'vip_deny', true, PaymentEligibilityRuleEffect::Deny, 10, tierCodes: ['vip']),
                'Subject tier race rule.',
                $this->correlation('tier-rule'),
            );
            $decisionKey = 'eligibility.subject.tier.000001';
            $result = $this->runSubjectRace('tier', $administratorId, $userId, $quote->quotePublicId, $decisionKey);

            self::assertSame('vip', DB::table('customer_profiles as profiles')
                ->join('customer_tiers as tiers', 'tiers.id', '=', 'profiles.current_tier_id')
                ->where('profiles.user_id', $userId)
                ->value('tiers.code'));
            $this->assertSafeOrCoherent($result, $decisionKey, 'subject_tier', 'tier_code', 'vip', 'rule_denied');
        }

        public function test_tag_race_is_coherent_or_fails_closed(): void
        {
            [$administratorId, $userId, $quote, $service] = $this->scenario('subject_tag');
            $this->createTag();
            $service->configureRule(
                'eligibility.subject.rule.tag.000001',
                $administratorId,
                new PaymentEligibilityRuleDefinition('subject_tag', 'tag_deny', true, PaymentEligibilityRuleEffect::Deny, 10, tagCodes: ['risk_review']),
                'Subject tag race rule.',
                $this->correlation('tag-rule'),
            );
            $decisionKey = 'eligibility.subject.tag.000001';
            $result = $this->runSubjectRace('tag', $administratorId, $userId, $quote->quotePublicId, $decisionKey);

            self::assertSame(1, DB::table('customer_tag_assignments')->where('user_id', $userId)->whereNull('removed_at')->count());
            $this->assertSafeOrCoherent($result, $decisionKey, 'subject_tag', 'tag_codes', ['risk_review'], 'rule_denied');
        }

        public function test_identity_status_race_is_coherent_or_fails_closed(): void
        {
            [$administratorId, $userId, $quote, $service] = $this->scenario('subject_identity');
            $service->configureRule(
                'eligibility.subject.rule.identity.000001',
                $administratorId,
                new PaymentEligibilityRuleDefinition('subject_identity', 'identity_deny', true, PaymentEligibilityRuleEffect::Deny, 10, requiredIdentityStatus: 'pending'),
                'Subject identity race rule.',
                $this->correlation('identity-rule'),
            );
            $decisionKey = 'eligibility.subject.identity.000001';
            $result = $this->runSubjectRace('identity', $administratorId, $userId, $quote->quotePublicId, $decisionKey);

            self::assertSame('pending', DB::table('customer_profiles')->where('user_id', $userId)->value('identity_verification_status'));
            $this->assertSafeOrCoherent($result, $decisionKey, 'subject_identity', 'identity_status', 'pending', 'rule_denied');
        }

        public function test_agent_status_race_is_coherent_or_fails_closed(): void
        {
            [$administratorId, $userId, $quote, $service] = $this->scenario('subject_agent');
            $this->createAgentProfile($userId);
            $service->configureRule(
                'eligibility.subject.rule.agent.000001',
                $administratorId,
                new PaymentEligibilityRuleDefinition('subject_agent', 'agent_deny', true, PaymentEligibilityRuleEffect::Deny, 10, requiredAgentStatus: 'suspended'),
                'Subject agent race rule.',
                $this->correlation('agent-rule'),
            );
            $decisionKey = 'eligibility.subject.agent.000001';
            $result = $this->runSubjectRace('agent', $administratorId, $userId, $quote->quotePublicId, $decisionKey);

            self::assertSame('suspended', DB::table('agent_profiles')->where('user_id', $userId)->value('status'));
            $this->assertSafeOrCoherent($result, $decisionKey, 'subject_agent', 'agent_status', 'suspended', 'rule_denied');
        }

        /** @return array{0:int,1:int,2:object,3:PaymentMethodEligibilityService} */
        private function scenario(string $methodCode): array
        {
            $administratorId = $this->ownerAdministrator();
            $userId = $this->customerWithProfile();
            $quote = $this->quoteFor($userId);
            $service = $this->app->make(PaymentMethodEligibilityService::class);
            $this->configureHealthyMethod($service, $administratorId, $methodCode);

            return [$administratorId, $userId, $quote, $service];
        }

        /** @return array<string,mixed> */
        private function runSubjectRace(
            string $fact,
            int $administratorId,
            int $userId,
            string $quotePublicId,
            string $decisionKey,
        ): array {
            $writer = $this->startWorker('--payment-eligibility-subject-writer', [
                'administrator_id' => $administratorId,
                'fact' => $fact,
                'user_id' => $userId,
            ]);
            $evaluator = null;

            try {
                self::assertSame("LOCKED\n", $this->readLine($writer, $fact.' writer lock'));
                $this->sendCommand($writer, 'STAGE');
                self::assertSame("STAGED\n", $this->readLine($writer, $fact.' writer staged mutation'));

                $evaluator = $this->startWorker('--payment-eligibility-subject-evaluator', [
                    'decision_key' => $decisionKey,
                    'quote_public_id' => $quotePublicId,
                    'user_id' => $userId,
                ]);
                self::assertSame("READY\n", $this->readLine($evaluator, $fact.' evaluator readiness'));
                $this->sendCommand($evaluator, 'GO');
                $this->assertWorkerRemainsBlocked($evaluator, 'Evaluator completed before the staged '.$fact.' fact committed.');

                $this->sendCommand($writer, 'COMMIT');
                $writerResult = $this->readJsonResult($writer, $fact.' writer commit');
                self::assertTrue((bool) ($writerResult['ok'] ?? false), $this->diagnostic($writerResult));

                return $this->readJsonResult($evaluator, $fact.' evaluator result');
            } finally {
                $this->closeWorker($writer);
                if ($evaluator !== null) {
                    $this->closeWorker($evaluator);
                }
            }
        }

        private function assertSafeOrCoherent(
            array $result,
            string $decisionKey,
            string $methodCode,
            string $subjectField,
            mixed $expectedValue,
            string $expectedReason,
        ): void {
            if (! (bool) ($result['ok'] ?? false)) {
                self::assertSame(QueryException::class, $result['exception'] ?? null, $this->diagnostic($result));
                self::assertSame(0, DB::table('payment_method_eligibility_decisions')->where('decision_key', $decisionKey)->count());

                return;
            }

            self::assertSame([], $result['methods'] ?? null, $this->diagnostic($result));
            $snapshotRaw = DB::table('payment_method_eligibility_decisions')
                ->where('id', (int) ($result['decision_id'] ?? 0))
                ->value('configuration_snapshot');
            self::assertIsString($snapshotRaw);
            /** @var array<string,mixed> $snapshot */
            $snapshot = json_decode($snapshotRaw, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($expectedValue, data_get($snapshot, 'subject.'.$subjectField));
            self::assertSame($expectedReason, $this->candidateReason((int) $result['decision_id'], $methodCode));
        }

        private function customerWithProfile(): int
        {
            $userId = $this->quoteUser('customer');
            $now = now('UTC');
            $tierId = (int) DB::table('customer_tiers')->where('code', 'new')->value('id');
            DB::table('customer_profiles')->insert([
                'user_id' => $userId,
                'current_tier_id' => $tierId,
                'tier_locked' => false,
                'tier_lock_reason_code' => null,
                'phone_verification_status' => 'unverified',
                'identity_verification_status' => 'unverified',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $userId;
        }

        private function createTag(): void
        {
            $now = now('UTC');
            DB::table('customer_tags')->insert([
                'code' => 'risk_review',
                'name_translation_key' => 'customer_tags.risk_review',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        private function createAgentProfile(int $userId): void
        {
            $now = now('UTC');
            $applicationId = (int) DB::table('agent_applications')->insertGetId([
                'customer_id' => $userId,
                'active_customer_id' => null,
                'state' => 'approved',
                'claimed_by_administrator_id' => null,
                'decided_by_administrator_id' => null,
                'decision_reason_code' => null,
                'decision_reason' => null,
                'application_version' => 1,
                'submitted_at' => $now,
                'claimed_at' => null,
                'decided_at' => $now,
                'reapply_allowed_at' => null,
                'reapplication_released_at' => null,
                'reapplication_released_by_administrator_id' => null,
                'reapplication_release_reason_code' => null,
                'reapplication_release_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('agent_profiles')->insert([
                'user_id' => $userId,
                'status' => 'active',
                'pricing_profile_code' => 'default',
                'approved_application_id' => $applicationId,
                'approved_by_administrator_id' => null,
                'approved_at' => $now,
                'suspended_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        private function configureHealthyMethod(PaymentMethodEligibilityService $service, int $administratorId, string $methodCode): void
        {
            $service->configureMethod(
                'eligibility.subject.method.'.$methodCode.'.000001',
                $administratorId,
                $methodCode,
                true,
                false,
                1,
                'Subject-race method.',
                $this->correlation('method-'.$methodCode),
            );
            $service->recordHealth(
                'eligibility.subject.health.'.$methodCode.'.000001',
                $administratorId,
                $methodCode,
                true,
                $this->clock->now()->modify('+10 minutes'),
                'Subject-race health.',
                $this->correlation('health-'.$methodCode),
            );
        }

        private function quoteFor(int $userId): object
        {
            $offering = $this->quoteOffering();

            return $this->app->make(QuoteService::class)->create(
                'eligibility.subject.quote.'.substr(hash('sha256', (string) $userId), 0, 20),
                $userId,
                $offering['id'],
                new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->now()->modify('+30 minutes')),
                $this->correlation('quote-'.$userId),
            );
        }

        private function candidateReason(int $decisionId, string $methodCode): ?string
        {
            $reason = DB::table('payment_method_eligibility_decision_methods')
                ->where('payment_method_eligibility_decision_id', $decisionId)
                ->where('method_code', $methodCode)
                ->value('reason_code');

            return is_string($reason) ? $reason : null;
        }

        private function correlation(string $suffix): string
        {
            return hash('sha256', 'payment-eligibility-subject-race:'.$suffix);
        }

        /** @param array<string,mixed> $payload
         * @return array{process:resource,pipes:array{0:resource,1:resource,2:resource}}
         */
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
            ], $pipes, base_path());
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start payment eligibility subject-race worker.');
            }
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
        private function assertWorkerRemainsBlocked(array $worker, string $message): void
        {
            usleep(500_000);
            self::assertSame('', trim((string) stream_get_contents($worker['pipes'][1])), $message);
            self::assertSame('', trim((string) stream_get_contents($worker['pipes'][2])), $message);
            self::assertTrue((bool) proc_get_status($worker['process'])['running'], $message);
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker
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
                    throw new RuntimeException('Unable to wait for payment eligibility subject-race worker output.');
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
                if (! proc_get_status($worker['process'])['running'] && feof($worker['pipes'][1])) {
                    throw new RuntimeException('Payment eligibility subject-race worker exited before '.$phase.': '.trim($stderr));
                }
            }
            throw new RuntimeException('Payment eligibility subject-race worker timed out during '.$phase.': '.trim($stderr));
        }

        /** @param array<string,mixed> $result */
        private function diagnostic(array $result): string
        {
            return json_encode($result, JSON_THROW_ON_ERROR);
        }
    }
}
