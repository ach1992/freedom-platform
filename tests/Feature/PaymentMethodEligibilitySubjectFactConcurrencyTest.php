<?php

declare(strict_types=1);

namespace {
    use App\Modules\Agents\Application\AgentChangeContext;
    use App\Modules\Agents\Application\AgentProfileService;
    use App\Modules\Agents\Domain\AgentStatus;
    use App\Modules\Customers\Application\CustomerAccountStateService;
    use App\Modules\Customers\Application\CustomerChangeContext;
    use App\Modules\Customers\Application\CustomerTagService;
    use App\Modules\Customers\Application\CustomerTierService;
    use App\Modules\Customers\Domain\CustomerTierCode;
    use App\Modules\Identity\Application\IdentityChangeContext;
    use App\Modules\Identity\Application\IdentityItemService;
    use App\Modules\Identity\Domain\AccountStatus;
    use App\Modules\Identity\Domain\IdentityItemType;
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

                if ($fact !== 'identity') {
                    $administrator = $connection->table('administrators')
                        ->where('id', $administratorId)
                        ->where('status', 'active')
                        ->lockForUpdate()
                        ->first(['id']);
                    if ($administrator === null) {
                        throw new RuntimeException('Payment eligibility subject-race administrator is missing.');
                    }
                }

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

                match ($fact) {
                    'account' => $app->make(CustomerAccountStateService::class)->transition(
                        $userId,
                        AccountStatus::Suspended,
                        new CustomerChangeContext(
                            'subject-race-account-0001',
                            hash('sha256', 'subject-race-account'),
                            'race_test',
                            'Subject-fact serialization test.',
                            $administratorId,
                        ),
                    ),
                    'tier' => $app->make(CustomerTierService::class)->assignManual(
                        $userId,
                        CustomerTierCode::Vip,
                        true,
                        new CustomerChangeContext(
                            'subject-race-tier-000001',
                            hash('sha256', 'subject-race-tier'),
                            'race_test',
                            'Subject-fact serialization test.',
                            $administratorId,
                        ),
                    ),
                    'tag' => $app->make(CustomerTagService::class)->assign(
                        $userId,
                        'risk_review',
                        new CustomerChangeContext(
                            'subject-race-tag-0000001',
                            hash('sha256', 'subject-race-tag'),
                            'race_test',
                            'Subject-fact serialization test.',
                            $administratorId,
                        ),
                    ),
                    'identity' => $app->make(IdentityItemService::class)->submit(
                        $userId,
                        IdentityItemType::FullName,
                        'Ali Rezaei',
                        false,
                        new IdentityChangeContext(
                            'subject-race-identity-01',
                            hash('sha256', 'subject-race-identity'),
                            'race_test',
                            actorUserId: $userId,
                        ),
                    ),
                    'agent' => $app->make(AgentProfileService::class)->transitionStatus(
                        $userId,
                        AgentStatus::Suspended,
                        new AgentChangeContext(
                            'subject-race-agent-00001',
                            hash('sha256', 'subject-race-agent'),
                            'race_test',
                            'Subject-fact serialization test.',
                            actorAdministratorId: $administratorId,
                        ),
                    ),
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

        public function test_subject_fact_mutations_serialize_with_evaluation_and_snapshot_committed_state(): void
        {
            $administratorId = $this->ownerAdministrator();
            $service = $this->app->make(PaymentMethodEligibilityService::class);

            $accountUser = $this->customerWithProfile();
            $accountQuote = $this->quoteFor($accountUser);
            $this->configureHealthyMethod($service, $administratorId, 'subject_account');
            [$accountResult, $accountSnapshot] = $this->runSubjectRace('account', $administratorId, $accountUser, $accountQuote->quotePublicId, 'eligibility.subject.account.000001');
            self::assertSame([], $accountResult['methods']);
            self::assertSame('suspended', $accountSnapshot['subject']['account_status']);
            self::assertSame('subject_blocked', $this->candidateReason((int) $accountResult['decision_id'], 'subject_account'));

            $tierUser = $this->customerWithProfile();
            $tierQuote = $this->quoteFor($tierUser);
            $this->configureHealthyMethod($service, $administratorId, 'subject_tier');
            $service->configureRule(
                'eligibility.subject.rule.tier.000001',
                $administratorId,
                new PaymentEligibilityRuleDefinition('subject_tier', 'vip_deny', true, PaymentEligibilityRuleEffect::Deny, 10, tierCodes: ['vip']),
                'Subject tier race rule.',
                $this->correlation('tier-rule'),
            );
            [$tierResult, $tierSnapshot] = $this->runSubjectRace('tier', $administratorId, $tierUser, $tierQuote->quotePublicId, 'eligibility.subject.tier.000001');
            self::assertSame([], $tierResult['methods']);
            self::assertSame('vip', $tierSnapshot['subject']['tier_code']);
            self::assertSame('rule_denied', $this->candidateReason((int) $tierResult['decision_id'], 'subject_tier'));

            $tagUser = $this->customerWithProfile();
            $tagQuote = $this->quoteFor($tagUser);
            $this->createTag('risk_review');
            $this->configureHealthyMethod($service, $administratorId, 'subject_tag');
            $service->configureRule(
                'eligibility.subject.rule.tag.000001',
                $administratorId,
                new PaymentEligibilityRuleDefinition('subject_tag', 'tag_deny', true, PaymentEligibilityRuleEffect::Deny, 10, tagCodes: ['risk_review']),
                'Subject tag race rule.',
                $this->correlation('tag-rule'),
            );
            [$tagResult, $tagSnapshot] = $this->runSubjectRace('tag', $administratorId, $tagUser, $tagQuote->quotePublicId, 'eligibility.subject.tag.000001');
            self::assertSame([], $tagResult['methods']);
            self::assertSame(['risk_review'], $tagSnapshot['subject']['tag_codes']);
            self::assertSame('rule_denied', $this->candidateReason((int) $tagResult['decision_id'], 'subject_tag'));

            $identityUser = $this->customerWithProfile();
            $identityQuote = $this->quoteFor($identityUser);
            $this->configureHealthyMethod($service, $administratorId, 'subject_identity');
            $service->configureRule(
                'eligibility.subject.rule.identity.000001',
                $administratorId,
                new PaymentEligibilityRuleDefinition('subject_identity', 'identity_deny', true, PaymentEligibilityRuleEffect::Deny, 10, requiredIdentityStatus: 'pending'),
                'Subject identity race rule.',
                $this->correlation('identity-rule'),
            );
            [$identityResult, $identitySnapshot] = $this->runSubjectRace('identity', $administratorId, $identityUser, $identityQuote->quotePublicId, 'eligibility.subject.identity.000001');
            self::assertSame([], $identityResult['methods']);
            self::assertSame('pending', $identitySnapshot['subject']['identity_status']);
            self::assertSame('rule_denied', $this->candidateReason((int) $identityResult['decision_id'], 'subject_identity'));

            $agentUser = $this->customerWithProfile();
            $agentQuote = $this->quoteFor($agentUser);
            $this->promoteToAgent($agentUser);
            $this->configureHealthyMethod($service, $administratorId, 'subject_agent');
            [$agentResult, $agentSnapshot] = $this->runSubjectRace('agent', $administratorId, $agentUser, $agentQuote->quotePublicId, 'eligibility.subject.agent.000001');
            self::assertSame([], $agentResult['methods']);
            self::assertSame('agent', $agentSnapshot['source_quote']['account_type']);
            self::assertSame('suspended', $agentSnapshot['subject']['agent_status']);
            self::assertSame('subject_blocked', $this->candidateReason((int) $agentResult['decision_id'], 'subject_agent'));
        }

        /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
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
                $this->assertWorkerRemainsBlocked($evaluator, 'Evaluator observed an uncommitted '.$fact.' subject fact.');

                $this->sendCommand($writer, 'COMMIT');
                $writerResult = $this->readJsonResult($writer, $fact.' writer commit');
                self::assertTrue((bool) ($writerResult['ok'] ?? false), $this->diagnostic($writerResult));

                $result = $this->readJsonResult($evaluator, $fact.' evaluator result');
                self::assertTrue((bool) ($result['ok'] ?? false), $this->diagnostic($result));
                $snapshotRaw = DB::table('payment_method_eligibility_decisions')
                    ->where('id', (int) $result['decision_id'])
                    ->value('configuration_snapshot');
                if (! is_string($snapshotRaw)) {
                    throw new RuntimeException('Payment eligibility subject-race decision snapshot is missing.');
                }
                /** @var array<string,mixed> $snapshot */
                $snapshot = json_decode($snapshotRaw, true, flags: JSON_THROW_ON_ERROR);

                return [$result, $snapshot];
            } finally {
                $this->closeWorker($writer);
                if ($evaluator !== null) {
                    $this->closeWorker($evaluator);
                }
            }
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

        private function createTag(string $code): void
        {
            $now = now('UTC');
            DB::table('customer_tags')->insert([
                'code' => $code,
                'name_translation_key' => 'customer_tags.'.$code,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        private function promoteToAgent(int $userId): void
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
            DB::table('users')->where('id', $userId)->update([
                'account_type' => 'agent',
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
