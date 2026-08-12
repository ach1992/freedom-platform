<?php

declare(strict_types=1);

namespace {
    use App\Modules\Agents\Application\AgentPricingResolutionContext;
    use App\Modules\Agents\Application\AgentPricingResolutionRequest;
    use App\Modules\Agents\Application\AgentPricingService;
    use App\Modules\Agents\Domain\AgentPricingAction;
    use App\Modules\Orders\Application\QuoteAgentPricingContext;
    use App\Modules\Orders\Application\QuotePricingInput;
    use App\Modules\Orders\Application\QuoteService;
    use App\Modules\Orders\Domain\QuoteOverrideSource;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\Connection;
    use Illuminate\Database\DatabaseManager;

    $agentPricingLockOrderMode = PHP_SAPI === 'cli' ? ($argv[1] ?? null) : null;
    if (in_array($agentPricingLockOrderMode, [
        '--agent-pricing-lock-order-quote-worker',
        '--agent-pricing-lock-order-resolve-worker',
    ], true)) {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $database = $app->make(DatabaseManager::class);
        $connection = $database->connection();
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 5');

        $decoded = base64_decode((string) ($argv[2] ?? ''), true);
        if ($decoded === false) {
            fwrite(STDERR, "Invalid agent-pricing lock-order worker payload encoding.\n");
            exit(2);
        }

        try {
            /** @var array<string,mixed> $payload */
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            fwrite(STDERR, 'Invalid agent-pricing lock-order worker payload: '.$exception->getMessage()."\n");
            exit(2);
        }

        if ($agentPricingLockOrderMode === '--agent-pricing-lock-order-quote-worker') {
            $connection->beforeExecuting(function (string $query, array $bindings, Connection $db): void {
                unset($bindings, $db);
                $sql = strtolower($query);
                if (! str_contains($sql, 'agent_pricing_profiles') || ! str_contains($sql, 'for update')) {
                    return;
                }

                echo "QUOTE_BEFORE_PROFILE\n";
                flush();
                $continue = fgets(STDIN);
                if ($continue === false || trim($continue) !== 'CONTINUE') {
                    throw new RuntimeException('Quote agent-pricing profile barrier was not released.');
                }
            });
        } else {
            $connection->beforeExecuting(function (string $query, array $bindings, Connection $db): void {
                unset($bindings, $db);
                $sql = strtolower($query);
                if (! str_contains($sql, 'plan_offerings') || ! str_contains($sql, 'for update')) {
                    return;
                }

                echo "RESOLVE_BEFORE_OFFERING\n";
                flush();
                $continue = fgets(STDIN);
                if ($continue === false || trim($continue) !== 'CONTINUE') {
                    throw new RuntimeException('Standalone agent-pricing offering barrier was not released.');
                }
            });
        }

        echo "READY\n";
        flush();
        $go = fgets(STDIN);
        if ($go === false || trim($go) !== 'GO') {
            fwrite(STDERR, "Agent-pricing lock-order worker start barrier was not released.\n");
            exit(2);
        }

        try {
            if ($agentPricingLockOrderMode === '--agent-pricing-lock-order-quote-worker') {
                $receipt = $app->make(QuoteService::class)->create(
                    (string) $payload['quote_key'],
                    (int) $payload['user_id'],
                    (int) $payload['plan_offering_id'],
                    new QuotePricingInput(
                        QuoteOverrideSource::None,
                        null,
                        null,
                        null,
                        0,
                        new DateTimeImmutable((string) $payload['expires_at']),
                    ),
                    (string) $payload['correlation_id'],
                    new QuoteAgentPricingContext((int) $payload['user_id'], AgentPricingAction::Purchase),
                );

                echo json_encode([
                    'ok' => true,
                    'quote_id' => $receipt->quoteId,
                    'quote_public_id' => $receipt->quotePublicId,
                ], JSON_THROW_ON_ERROR)."\n";
                exit(0);
            }

            $receipt = $app->make(AgentPricingService::class)->resolve(
                new AgentPricingResolutionRequest(
                    (string) $payload['resolution_key'],
                    (int) $payload['user_id'],
                    (string) $payload['pricing_profile_code'],
                    (int) $payload['plan_offering_id'],
                    AgentPricingAction::Purchase,
                ),
                new AgentPricingResolutionContext((int) $payload['user_id']),
            );

            echo json_encode([
                'ok' => true,
                'resolution_id' => $receipt->resolutionId,
                'resolution_public_id' => $receipt->resolutionPublicId,
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
    use App\Modules\Agents\Application\AgentPricingService;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Str;
    use RuntimeException;
    use Tests\TestCase;

    /** @requirement AGT-005 BUY-002 DAT-002 DAT-003 DAT-004 QUA-001 */
    final class AgentPricingQuoteLockOrderingContentionTest extends TestCase
    {
        use AgentPricingQuoteIntegrationTestSupport;
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 15;

        protected function setUp(): void
        {
            parent::setUp();
            if (DB::connection()->getDriverName() !== 'mysql') {
                $this->markTestSkipped('Agent-pricing lock-order contention requires MariaDB/MySQL.');
            }
            $this->seed();
        }

        public function test_quote_and_standalone_agent_pricing_share_one_canonical_lock_order(): void
        {
            $offering = $this->quoteOffering();
            $pricing = $this->app->make(AgentPricingService::class);
            $profileCode = 'lock-order-'.Str::lower(Str::random(10));
            $this->activePricingProfile($pricing, $offering['owner_id'], $profileCode, true);

            $quoteAgentId = $this->agentSubject($profileCode);
            $standaloneAgentId = $this->agentSubject($profileCode);
            self::assertNotSame($quoteAgentId, $standaloneAgentId);

            $quoteKey = 'quote.lock-order.'.substr(hash('sha256', (string) $quoteAgentId), 0, 24);
            $standaloneKey = 'pricing.lock-order.'.substr(hash('sha256', (string) $standaloneAgentId), 0, 24);

            $quoteWorker = $this->startWorker('--agent-pricing-lock-order-quote-worker', [
                'quote_key' => $quoteKey,
                'user_id' => $quoteAgentId,
                'plan_offering_id' => $offering['id'],
                'expires_at' => now('UTC')->addMinutes(30)->toIso8601String(),
                'correlation_id' => substr(hash('sha256', 'quote-lock-order:'.$quoteKey), 0, 64),
            ]);
            $resolveWorker = $this->startWorker('--agent-pricing-lock-order-resolve-worker', [
                'resolution_key' => $standaloneKey,
                'user_id' => $standaloneAgentId,
                'pricing_profile_code' => $profileCode,
                'plan_offering_id' => $offering['id'],
            ]);

            try {
                self::assertSame("READY\n", $this->readLine($quoteWorker, 'quote readiness'));
                self::assertSame("READY\n", $this->readLine($resolveWorker, 'standalone resolution readiness'));

                $this->sendCommand($quoteWorker, 'GO');
                self::assertSame("QUOTE_BEFORE_PROFILE\n", $this->readLine($quoteWorker, 'quote shared profile barrier'));

                $this->sendCommand($resolveWorker, 'GO');
                self::assertSame("RESOLVE_BEFORE_OFFERING\n", $this->readLine($resolveWorker, 'standalone shared offering barrier'));

                $this->sendCommand($quoteWorker, 'CONTINUE');
                $this->sendCommand($resolveWorker, 'CONTINUE');

                $quoteResult = $this->readJsonResult($quoteWorker, 'quote result');
                $resolveResult = $this->readJsonResult($resolveWorker, 'standalone resolution result');

                self::assertTrue((bool) ($quoteResult['ok'] ?? false), json_encode($quoteResult, JSON_THROW_ON_ERROR));
                self::assertTrue((bool) ($resolveResult['ok'] ?? false), json_encode($resolveResult, JSON_THROW_ON_ERROR));

                self::assertSame(1, DB::table('quotes')->where('quote_key', $quoteKey)->count());
                self::assertSame(1, DB::table('agent_pricing_resolutions')->where('resolution_key', $standaloneKey)->count());
                self::assertSame(2, DB::table('agent_pricing_resolutions')->count());

                $quote = DB::table('quotes')->where('quote_key', $quoteKey)->first([
                    'id',
                    'user_id',
                    'plan_offering_id',
                    'agent_pricing_resolution_id',
                    'agent_pricing_resolution_public_id',
                    'agent_pricing_resolution_configuration_hash',
                    'agent_pricing_profile_id_snapshot',
                    'agent_pricing_profile_code_snapshot',
                    'agent_pricing_profile_version_snapshot',
                    'agent_pricing_profile_configuration_hash',
                ]);
                self::assertNotNull($quote);
                self::assertSame($quoteAgentId, (int) $quote->user_id);
                self::assertSame($offering['id'], (int) $quote->plan_offering_id);
                self::assertNotNull($quote->agent_pricing_resolution_id);

                $quoteResolution = DB::table('agent_pricing_resolutions')
                    ->where('id', (int) $quote->agent_pricing_resolution_id)
                    ->first([
                        'public_id',
                        'user_id',
                        'plan_offering_id',
                        'agent_pricing_profile_id',
                        'pricing_profile_code_snapshot',
                        'pricing_profile_version',
                        'pricing_profile_configuration_hash',
                        'configuration_snapshot_hash',
                    ]);
                self::assertNotNull($quoteResolution);
                self::assertSame($quoteAgentId, (int) $quoteResolution->user_id);
                self::assertSame($offering['id'], (int) $quoteResolution->plan_offering_id);
                self::assertSame((string) $quoteResolution->public_id, (string) $quote->agent_pricing_resolution_public_id);
                self::assertSame((string) $quoteResolution->configuration_snapshot_hash, (string) $quote->agent_pricing_resolution_configuration_hash);
                self::assertSame((int) $quoteResolution->agent_pricing_profile_id, (int) $quote->agent_pricing_profile_id_snapshot);
                self::assertSame((string) $quoteResolution->pricing_profile_code_snapshot, (string) $quote->agent_pricing_profile_code_snapshot);
                self::assertSame((int) $quoteResolution->pricing_profile_version, (int) $quote->agent_pricing_profile_version_snapshot);
                self::assertSame((string) $quoteResolution->pricing_profile_configuration_hash, (string) $quote->agent_pricing_profile_configuration_hash);
            } finally {
                $this->closeWorker($quoteWorker);
                $this->closeWorker($resolveWorker);
            }
        }

        /** @return array{process:resource,pipes:array{0:resource,1:resource,2:resource}} */
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
            ], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start agent-pricing lock-order contention worker.');
            }
            /** @var array{0:resource,1:resource,2:resource} $pipes */
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
                    throw new RuntimeException('Unable to wait for agent-pricing lock-order worker output.');
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
                    throw new RuntimeException('Agent-pricing lock-order worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException('Agent-pricing lock-order worker timed out during '.$phase.': '.$stderr);
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource}} $worker */
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
