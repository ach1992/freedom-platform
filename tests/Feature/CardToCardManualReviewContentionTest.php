<?php

declare(strict_types=1);

namespace {
    use App\Modules\Payments\CardToCard\Application\CardToCardReviewDecisionService;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\Connection;
    use Illuminate\Database\DatabaseManager;

    $c2cReviewMode = PHP_SAPI === 'cli' ? ($argv[1] ?? null) : null;
    if ($c2cReviewMode === '--c2c-review-worker') {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $connection = $app->make(DatabaseManager::class)->connection();
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 5');

        $decoded = base64_decode((string) ($argv[2] ?? ''), true);
        if ($decoded === false) {
            fwrite(STDERR, "Invalid C2C review worker payload.\n");
            exit(2);
        }
        /** @var array<string,mixed> $payload */
        $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);

        $barrierReached = false;
        $connection->beforeExecuting(function (string $query, array $bindings, Connection $db) use (&$barrierReached): void {
            unset($bindings, $db);
            if ($barrierReached) {
                return;
            }
            $sql = strtolower($query);
            if (! str_contains($sql, 'c2c_match_reviews') || ! str_contains($sql, 'for update')) {
                return;
            }
            $barrierReached = true;
            echo "AT_REVIEW_LOCK\n";
            flush();
            $continue = fgets(STDIN);
            if ($continue === false || trim($continue) !== 'CONTINUE') {
                throw new RuntimeException('C2C review contention barrier was not released.');
            }
        });

        echo "READY\n";
        flush();
        $go = fgets(STDIN);
        if ($go === false || trim($go) !== 'GO') {
            fwrite(STDERR, "C2C review worker start barrier was not released.\n");
            exit(2);
        }

        try {
            $receipt = $app->make(CardToCardReviewDecisionService::class)->approve(
                (string) $payload['review_public_id'],
                (string) $payload['reservation_public_id'],
                (int) $payload['administrator_id'],
                (string) $payload['reason'],
                (string) $payload['correlation_id'],
            );
            echo json_encode([
                'ok' => true,
                'settlement_id' => $receipt->purchaseSettlementId,
                'replayed' => $receipt->replayed,
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
    use App\Modules\Orders\Application\QuotePricingInput;
    use App\Modules\Orders\Application\QuoteService;
    use App\Modules\Orders\Domain\QuoteOverrideSource;
    use App\Modules\Payments\CardToCard\Application\CardToCardBankTransactionService;
    use App\Modules\Payments\CardToCard\Application\CardToCardDestinationService;
    use App\Modules\Payments\CardToCard\Application\CardToCardMatchingService;
    use App\Modules\Payments\CardToCard\Application\CardToCardPaymentService;
    use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionObservation;
    use App\Modules\Payments\CardToCard\Application\Contracts\CardToCardAdjustmentGenerator;
    use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
    use App\Shared\Application\Clock;
    use Database\Seeders\CatalogAccessFoundationSeeder;
    use Database\Seeders\IdentityAccessFoundationSeeder;
    use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
    use DateTimeImmutable;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\TestCase;

    final class ReviewContentionAdjustmentGenerator implements CardToCardAdjustmentGenerator
    {
        public function generate(int $minimumIrr, int $maximumIrr): int
        {
            return $minimumIrr;
        }
    }

    final class ReviewContentionClock implements Clock
    {
        public function __construct(public DateTimeImmutable $value) {}

        public function now(): DateTimeImmutable
        {
            return $this->value;
        }
    }

    /** @requirement C2C-004 C2C-005 ACL-002 DAT-002 DAT-003 DAT-004 QUA-004 */
    final class CardToCardManualReviewContentionTest extends TestCase
    {
        use AgentPricingQuoteIntegrationTestSupport;
        use DatabaseTruncation;

        private ReviewContentionClock $clock;

        private const TIMEOUT = 20;

        protected function setUp(): void
        {
            parent::setUp();
            if (DB::connection()->getDriverName() !== 'mysql') {
                $this->markTestSkipped('C2C manual review contention requires MariaDB/MySQL.');
            }
            $this->seed(IdentityAccessFoundationSeeder::class);
            $this->seed(CatalogAccessFoundationSeeder::class);
            $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
            $this->clock = new ReviewContentionClock(new DateTimeImmutable('2026-08-14T15:00:00+00:00'));
            $this->app->instance(Clock::class, $this->clock);
            $this->app->instance(CardToCardAdjustmentGenerator::class, new ReviewContentionAdjustmentGenerator);
            config()->set('payments.card_to_card.lookup_key', str_repeat('q', 32));

            $administratorId = $this->ownerAdministrator();
            $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
            $eligibility->configureMethod('c2c.review-race.method', $administratorId, 'card_to_card', true, false, 1, 'Review contention method.', $this->correlation('method'));
            $eligibility->recordHealth('c2c.review-race.health', $administratorId, 'card_to_card', true, $this->clock->value->modify('+30 minutes'), 'Healthy review race provider.', $this->correlation('health'));
            $this->app->make(CardToCardDestinationService::class)->register(
                'review-race-primary', '4242424242424242', 'Review Race Account', true, 1000, 9990, 5, 60, null, 10, 'fake', 'Review race destination.', $this->correlation('destination')
            );
        }

        public function test_two_concurrent_manual_approvals_produce_one_financial_result(): void
        {
            $user = $this->quoteUser('customer');
            $offering = $this->quoteOffering();
            $quote = $this->app->make(QuoteService::class)->create(
                'c2c.review-race.quote', $user, $offering['id'],
                new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->value->modify('+30 minutes')),
                $this->correlation('quote'),
            );
            $decision = $this->app->make(PaymentMethodEligibilityService::class)->evaluate('c2c.review-race.eligibility', $user, $quote->quotePublicId);
            $payment = $this->app->make(CardToCardPaymentService::class)->create(
                'c2c.review-race.intent', $user, $quote->quotePublicId, $decision->publicId, $this->correlation('payment')
            );

            $lateAt = $this->clock->value->modify('+10 minutes');
            $bank = $this->app->make(CardToCardBankTransactionService::class)->ingest(
                'fake',
                new BankTransactionObservation(
                    'review-race-tx', 'review-race-event', '4242424242424242', $payment->payableAmountIrr, 'settled', $lateAt,
                    null, null, 'review-race-reference', hash('sha256', 'review-race-evidence')
                ),
                'fake',
                $this->correlation('bank'),
            );
            $review = $this->app->make(CardToCardMatchingService::class)->match($bank->publicId, $this->correlation('match'));
            self::assertNotNull($review->reviewPublicId);
            self::assertStringStartsWith('review_pending:late', $review->outcome);

            $administratorId = $this->ownerAdministrator();
            $payloadA = [
                'review_public_id' => $review->reviewPublicId,
                'reservation_public_id' => $payment->reservationPublicId,
                'administrator_id' => $administratorId,
                'reason' => 'Concurrent authoritative approval A.',
                'correlation_id' => $this->correlation('approve-a'),
            ];
            $payloadB = $payloadA;
            $payloadB['reason'] = 'Concurrent authoritative approval B.';
            $payloadB['correlation_id'] = $this->correlation('approve-b');

            $a = $this->worker($payloadA);
            $b = $this->worker($payloadB);
            try {
                self::assertSame("READY\n", $this->line($a, 'A ready'));
                self::assertSame("READY\n", $this->line($b, 'B ready'));
                $this->send($a, 'GO');
                $this->send($b, 'GO');
                self::assertSame("AT_REVIEW_LOCK\n", $this->line($a, 'A review lock'));
                self::assertSame("AT_REVIEW_LOCK\n", $this->line($b, 'B review lock'));
                $this->send($a, 'CONTINUE');
                $this->send($b, 'CONTINUE');

                $ra = $this->workerJson($a, 'A result');
                $rb = $this->workerJson($b, 'B result');
                self::assertTrue((bool) ($ra['ok'] ?? false), json_encode($ra, JSON_THROW_ON_ERROR));
                self::assertTrue((bool) ($rb['ok'] ?? false), json_encode($rb, JSON_THROW_ON_ERROR));
                self::assertSame((int) $ra['settlement_id'], (int) $rb['settlement_id']);
                self::assertEqualsCanonicalizing([false, true], [(bool) $ra['replayed'], (bool) $rb['replayed']]);
                self::assertSame(1, DB::table('purchase_settlements')->count());
                self::assertSame(1, DB::table('c2c_transaction_matches')->where('state', 'captured')->count());
                self::assertSame('accepted', DB::table('c2c_match_reviews')->where('public_id', $review->reviewPublicId)->value('state'));
            } finally {
                $this->close($a);
                $this->close($b);
            }
        }

        /** @return array{process:resource,pipes:array{0:resource,1:resource,2:resource}} */
        private function worker(array $payload): array
        {
            $pipes = [];
            $process = proc_open([
                PHP_BINARY, '-d', 'pcov.enabled=0', __FILE__, '--c2c-review-worker',
                base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start C2C review contention worker.');
            }
            /** @var array{0:resource,1:resource,2:resource} $pipes */
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            return ['process' => $process, 'pipes' => $pipes];
        }

        private function send(array $worker, string $command): void
        {
            fwrite($worker['pipes'][0], $command."\n");
            fflush($worker['pipes'][0]);
        }

        private function line(array $worker, string $phase): string
        {
            $deadline = microtime(true) + self::TIMEOUT;
            $stderr = '';
            while (microtime(true) < $deadline) {
                $read = [$worker['pipes'][1], $worker['pipes'][2]];
                $write = null;
                $except = null;
                $selected = stream_select($read, $write, $except, 0, 200_000);
                if ($selected === false) {
                    throw new RuntimeException('Unable to wait for C2C review worker output.');
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
                    throw new RuntimeException('C2C review worker exited before '.$phase.': '.$stderr);
                }
            }
            throw new RuntimeException('C2C review worker timed out during '.$phase.': '.$stderr);
        }

        private function workerJson(array $worker, string $phase): array
        {
            /** @var array<string,mixed> $result */
            $result = json_decode($this->line($worker, $phase), true, flags: JSON_THROW_ON_ERROR);

            return $result;
        }

        private function close(array $worker): void
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

        private function correlation(string $suffix): string
        {
            return hash('sha256', 'c2c-review-race:'.$suffix);
        }
    }
}
