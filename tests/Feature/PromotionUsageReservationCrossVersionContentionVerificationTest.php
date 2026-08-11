<?php

declare(strict_types=1);

namespace {
    use App\Modules\Promotions\Application\PromotionUsageContext;
    use App\Modules\Promotions\Application\PromotionUsageReservationService;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\DatabaseManager;

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--promotion-usage-cross-version-contention-worker') {
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
            $receipt = $app->make(PromotionUsageReservationService::class)->reserve(
                (string) $payload['reservation_key'],
                (string) $payload['resolution_public_id'],
                (string) $payload['quote_public_id'],
                new PromotionUsageContext((int) $payload['user_id']),
            );
            echo json_encode([
                'ok' => true,
                'result' => [
                    'reservation_id' => $receipt->reservationId,
                    'rule_version' => $receipt->ruleVersion,
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
    use App\Modules\Orders\Application\QuoteReceipt;
    use App\Modules\Promotions\Application\PromotionResolutionReceipt;
    use App\Modules\Promotions\Application\PromotionRuleService;
    use App\Modules\Promotions\Application\PromotionRuleVersionReceipt;
    use App\Modules\Promotions\Domain\PromotionAction;
    use App\Modules\Promotions\Domain\PromotionAudience;
    use App\Modules\Promotions\Domain\PromotionDiscountType;
    use App\Modules\Promotions\Domain\PromotionRuleDefinition;
    use App\Modules\Promotions\Domain\PromotionRuleState;
    use DateTimeImmutable;
    use DateTimeZone;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\Support\CreatesPromotionUsageFixtures;
    use Tests\TestCase;

    /** @requirement PRO-001 BUY-002 DAT-003 DAT-004 SEC-001 QUA-001 */
    final class PromotionUsageReservationCrossVersionContentionVerificationTest extends TestCase
    {
        use CreatesPromotionUsageFixtures;
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

        public function test_different_versions_of_same_stable_rule_competing_for_final_slot_cannot_both_succeed(): void
        {
            $firstUser = $this->usageUser();
            $secondUser = $this->usageUser();
            $offering = $this->usageOffering(suffix: 'cross-version-contention');
            $ruleCode = 'promo.usage.crosscont';
            $this->usageRule($offering['id'], $ruleCode, 70_000, 1, null);
            [$firstResolution, $firstQuote] = $this->pair($firstUser, $offering['id'], $ruleCode, 70_000, 'cross-cont-v1');
            self::assertSame(1, $firstResolution->ruleVersion);

            $revision = $this->reviseRule($offering['id'], $ruleCode, 'cross-cont-v2', 70_000, 1, null);
            self::assertSame(2, $revision->version);
            [$secondResolution, $secondQuote] = $this->pair($secondUser, $offering['id'], $ruleCode, 70_000, 'cross-cont-v2');
            self::assertSame(2, $secondResolution->ruleVersion);

            $results = $this->runConcurrent([
                $this->reservePayload($firstUser, 'usage.cont.crossversion.v1.000001', $firstResolution, $firstQuote),
                $this->reservePayload($secondUser, 'usage.cont.crossversion.v2.000001', $secondResolution, $secondQuote),
            ]);

            self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['ok'] === true));
            self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['ok'] === false));
            self::assertSame(1, $this->activeReservationsForRule($revision->ruleId));
        }

        /** @return array{0:PromotionResolutionReceipt,1:QuoteReceipt} */
        private function pair(int $userId, int $offeringId, string $ruleCode, int $discountIrr, string $suffix): array
        {
            return [
                $this->usageResolution($userId, $offeringId, 1_000_000, $suffix),
                $this->usageQuote(
                    $userId,
                    $offeringId,
                    $ruleCode,
                    $discountIrr,
                    new DateTimeImmutable('+30 minutes', new DateTimeZone('UTC')),
                    $suffix,
                ),
            ];
        }

        private function reviseRule(
            int $offeringId,
            string $ruleCode,
            string $suffix,
            int $discountIrr,
            ?int $totalUseLimit,
            ?int $perUserUseLimit,
        ): PromotionRuleVersionReceipt {
            return $this->app->make(PromotionRuleService::class)->revise(
                'usage.rule.revise.'.substr(hash('sha256', $ruleCode.':'.$suffix), 0, 24),
                $ruleCode,
                new PromotionRuleDefinition(
                    PromotionRuleState::Active,
                    10,
                    PromotionDiscountType::Fixed,
                    $discountIrr,
                    null,
                    0,
                    null,
                    null,
                    null,
                    $totalUseLimit,
                    $perUserUseLimit,
                    false,
                    PromotionAudience::Both,
                    null,
                    null,
                    $offeringId,
                    null,
                    null,
                    PromotionAction::Purchase,
                ),
                new AccessChangeContext(
                    hash('sha256', 'usage-rule-revise-request:'.$ruleCode.':'.$suffix),
                    substr(hash('sha256', 'usage-rule-revise-correlation:'.$ruleCode.':'.$suffix), 0, 64),
                    'promotion_usage_test',
                    'Revise stable promotion rule for cross-version contention verification.',
                    $this->usageAdministrator(),
                ),
            );
        }

        /** @return array<string, mixed> */
        private function reservePayload(int $userId, string $key, PromotionResolutionReceipt $resolution, QuoteReceipt $quote): array
        {
            return [
                'user_id' => $userId,
                'reservation_key' => $key,
                'resolution_public_id' => $resolution->resolutionPublicId,
                'quote_public_id' => $quote->quotePublicId,
            ];
        }

        private function activeReservationsForRule(int $ruleId): int
        {
            return (int) DB::table('promotion_usage_reservations as reservation')
                ->leftJoin('promotion_usage_releases as release', 'release.promotion_usage_reservation_id', '=', 'reservation.id')
                ->where('reservation.pricing_rule_id', $ruleId)
                ->whereNull('release.id')
                ->count();
        }

        /**
         * @param  list<array<string, mixed>>  $payloads
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
                        '--promotion-usage-cross-version-contention-worker',
                        base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                    ], [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ], $pipes, dirname(__DIR__, 2));
                    if (! is_resource($process)) {
                        throw new RuntimeException('Unable to start cross-version promotion usage contention worker.');
                    }
                    /** @var array{0:resource,1:resource,2:resource} $pipes */
                    stream_set_blocking($pipes[1], false);
                    stream_set_blocking($pipes[2], false);
                    $workers[] = ['process' => $process, 'pipes' => $pipes];
                }

                foreach ($workers as $index => $worker) {
                    if ($this->readLine($worker, 'readiness', $index) !== "READY\n") {
                        throw new RuntimeException('Cross-version promotion usage contention worker returned an invalid readiness marker.');
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
                        throw new RuntimeException('Cross-version promotion usage contention worker failed: '.$stderr);
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
                    throw new RuntimeException('Unable to wait for cross-version promotion usage contention worker output.');
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
                    throw new RuntimeException('Cross-version promotion usage contention worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException(sprintf(
                'Cross-version promotion usage contention worker %d timed out during %s after %d seconds: %s',
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
