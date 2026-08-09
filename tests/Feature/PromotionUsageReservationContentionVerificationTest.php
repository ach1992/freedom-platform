<?php

declare(strict_types=1);

namespace {
    use App\Modules\Promotions\Application\PromotionUsageContext;
    use App\Modules\Promotions\Application\PromotionUsageReservationService;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\DatabaseManager;

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--promotion-usage-contention-worker') {
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
            $service = $app->make(PromotionUsageReservationService::class);
            $context = new PromotionUsageContext((int) $payload['user_id']);
            if (($payload['operation'] ?? null) === 'reserve') {
                $receipt = $service->reserve(
                    (string) $payload['reservation_key'],
                    (string) $payload['resolution_public_id'],
                    (string) $payload['quote_public_id'],
                    $context,
                );
                $result = [
                    'reservation_id' => $receipt->reservationId,
                    'reservation_public_id' => $receipt->reservationPublicId,
                    'replayed' => $receipt->replayed,
                ];
            } elseif (($payload['operation'] ?? null) === 'release') {
                $receipt = $service->release(
                    (string) $payload['release_key'],
                    (string) $payload['reservation_public_id'],
                    $context,
                );
                $result = [
                    'release_id' => $receipt->releaseId,
                    'release_public_id' => $receipt->releasePublicId,
                    'replayed' => $receipt->replayed,
                ];
            } else {
                throw new RuntimeException('Unsupported promotion usage contention operation.');
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
    use App\Modules\Orders\Application\QuoteReceipt;
    use App\Modules\Promotions\Application\PromotionResolutionReceipt;
    use App\Modules\Promotions\Application\PromotionUsageContext;
    use App\Modules\Promotions\Application\PromotionUsageReservationService;
    use DateTimeImmutable;
    use DateTimeZone;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\Support\CreatesPromotionUsageFixtures;
    use Tests\TestCase;

    /** @requirement PRO-001 BUY-002 DAT-003 DAT-004 SEC-001 QUA-001 */
    final class PromotionUsageReservationContentionVerificationTest extends TestCase
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

        public function test_competing_reservations_for_final_global_slot_cannot_both_succeed(): void
        {
            $firstUser = $this->usageUser();
            $secondUser = $this->usageUser();
            $offering = $this->usageOffering(suffix: 'contention-global');
            $this->usageRule($offering['id'], 'promo.cont.global', 100_000, 1, null);
            [$firstResolution, $firstQuote] = $this->pair($firstUser, $offering['id'], 'promo.cont.global', 100_000, 'global-first');
            [$secondResolution, $secondQuote] = $this->pair($secondUser, $offering['id'], 'promo.cont.global', 100_000, 'global-second');

            $results = $this->runConcurrent([
                $this->reservePayload($firstUser, 'usage.cont.global.reserve.000001', $firstResolution, $firstQuote),
                $this->reservePayload($secondUser, 'usage.cont.global.reserve.000002', $secondResolution, $secondQuote),
            ]);

            self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['ok'] === true));
            self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['ok'] === false));
            self::assertSame(1, $this->activeReservations());
        }

        public function test_competing_reservations_for_final_per_user_slot_cannot_both_succeed(): void
        {
            $userId = $this->usageUser();
            $offering = $this->usageOffering(suffix: 'contention-user');
            $this->usageRule($offering['id'], 'promo.cont.user', 90_000, 10, 1);
            [$firstResolution, $firstQuote] = $this->pair($userId, $offering['id'], 'promo.cont.user', 90_000, 'user-first');
            [$secondResolution, $secondQuote] = $this->pair($userId, $offering['id'], 'promo.cont.user', 90_000, 'user-second');

            $results = $this->runConcurrent([
                $this->reservePayload($userId, 'usage.cont.user.reserve.000001', $firstResolution, $firstQuote),
                $this->reservePayload($userId, 'usage.cont.user.reserve.000002', $secondResolution, $secondQuote),
            ]);

            self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['ok'] === true));
            self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['ok'] === false));
            self::assertSame(1, $this->activeReservations());
        }

        public function test_concurrent_exact_duplicate_reserve_creates_one_reservation_and_one_replay(): void
        {
            $userId = $this->usageUser();
            $offering = $this->usageOffering(suffix: 'contention-duplicate');
            $this->usageRule($offering['id'], 'promo.cont.duplicate', 80_000, 2, 2);
            [$resolution, $quote] = $this->pair($userId, $offering['id'], 'promo.cont.duplicate', 80_000, 'duplicate');
            $payload = $this->reservePayload($userId, 'usage.cont.duplicate.reserve.000001', $resolution, $quote);

            $results = $this->runConcurrent([$payload, $payload]);
            self::assertTrue($results[0]['ok']);
            self::assertTrue($results[1]['ok']);
            self::assertSame($results[0]['result']['reservation_id'], $results[1]['result']['reservation_id']);
            $replayed = [(bool) $results[0]['result']['replayed'], (bool) $results[1]['result']['replayed']];
            sort($replayed);
            self::assertSame([false, true], $replayed);
            self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        }

        public function test_reserve_vs_release_serializes_and_released_capacity_is_reusable(): void
        {
            $firstUser = $this->usageUser();
            $secondUser = $this->usageUser();
            $offering = $this->usageOffering(suffix: 'contention-release');
            $this->usageRule($offering['id'], 'promo.cont.release', 70_000, 1, null);
            [$firstResolution, $firstQuote] = $this->pair($firstUser, $offering['id'], 'promo.cont.release', 70_000, 'release-first');
            $service = $this->app->make(PromotionUsageReservationService::class);
            $existing = $service->reserve(
                'usage.cont.release.reserve.000001',
                $firstResolution->resolutionPublicId,
                $firstQuote->quotePublicId,
                new PromotionUsageContext($firstUser),
            );
            [$secondResolution, $secondQuote] = $this->pair($secondUser, $offering['id'], 'promo.cont.release', 70_000, 'release-second');

            $results = $this->runConcurrent([
                [
                    'operation' => 'release',
                    'user_id' => $firstUser,
                    'release_key' => 'usage.cont.release.release.000001',
                    'reservation_public_id' => $existing->reservationPublicId,
                ],
                $this->reservePayload($secondUser, 'usage.cont.release.reserve.000002', $secondResolution, $secondQuote),
            ]);

            self::assertTrue($results[0]['ok']);
            self::assertLessThanOrEqual(1, $this->activeReservations());
            if ($results[1]['ok'] === false) {
                $service->reserve(
                    'usage.cont.release.reserve.000002',
                    $secondResolution->resolutionPublicId,
                    $secondQuote->quotePublicId,
                    new PromotionUsageContext($secondUser),
                );
            }
            self::assertSame(1, $this->activeReservations());
            self::assertSame(1, DB::table('promotion_usage_releases')->count());
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

        /** @return array<string, mixed> */
        private function reservePayload(int $userId, string $key, PromotionResolutionReceipt $resolution, QuoteReceipt $quote): array
        {
            return [
                'operation' => 'reserve',
                'user_id' => $userId,
                'reservation_key' => $key,
                'resolution_public_id' => $resolution->resolutionPublicId,
                'quote_public_id' => $quote->quotePublicId,
            ];
        }

        private function activeReservations(): int
        {
            return (int) DB::table('promotion_usage_reservations as reservation')
                ->leftJoin('promotion_usage_releases as release', 'release.promotion_usage_reservation_id', '=', 'reservation.id')
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
                        '--promotion-usage-contention-worker',
                        base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                    ], [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ], $pipes, dirname(__DIR__, 2));
                    if (! is_resource($process)) {
                        throw new RuntimeException('Unable to start promotion usage contention worker.');
                    }
                    /** @var array{0:resource,1:resource,2:resource} $pipes */
                    stream_set_blocking($pipes[1], false);
                    stream_set_blocking($pipes[2], false);
                    $workers[] = ['process' => $process, 'pipes' => $pipes];
                }

                foreach ($workers as $index => $worker) {
                    if ($this->readLine($worker, 'readiness', $index) !== "READY\n") {
                        throw new RuntimeException('Promotion usage contention worker returned an invalid readiness marker.');
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
                        throw new RuntimeException('Promotion usage contention worker failed: '.$stderr);
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
                    throw new RuntimeException('Unable to wait for promotion usage contention worker output.');
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
                    throw new RuntimeException('Promotion usage contention worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException(sprintf(
                'Promotion usage contention worker %d timed out during %s after %d seconds: %s',
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
