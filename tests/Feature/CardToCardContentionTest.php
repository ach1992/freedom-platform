<?php

declare(strict_types=1);

namespace {
    use App\Modules\Payments\Application\PurchasePaymentMaintenanceService;
    use App\Modules\Payments\CardToCard\Application\CardToCardBankTransactionService;
    use App\Modules\Payments\CardToCard\Application\CardToCardManualSubmissionService;
    use App\Modules\Payments\CardToCard\Application\CardToCardPaymentService;
    use App\Modules\Payments\CardToCard\Application\CardToCardSettlementService;
    use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionObservation;
    use App\Modules\Payments\CardToCard\Application\Contracts\CardToCardAdjustmentGenerator;
    use App\Shared\Application\Clock;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\Connection;
    use Illuminate\Database\DatabaseManager;
    use Illuminate\Database\Events\QueryExecuted;

    require_once dirname(__DIR__, 2).'/vendor/autoload.php';

    final class C2cContentionFixedAdjustmentGenerator implements CardToCardAdjustmentGenerator
    {
        public function generate(int $minimumIrr, int $maximumIrr): int
        {
            return $minimumIrr;
        }
    }

    final readonly class C2cContentionClock implements Clock
    {
        public function __construct(private DateTimeImmutable $value) {}

        public function now(): DateTimeImmutable
        {
            return $this->value;
        }
    }

    $c2cContentionMode = PHP_SAPI === 'cli' ? ($argv[1] ?? null) : null;
    $c2cContentionWorkerModes = [
        '--c2c-create-worker',
        '--c2c-capture-worker',
        '--c2c-maintenance-worker',
        '--c2c-bank-worker',
        '--c2c-manual-worker',
    ];
    if (in_array($c2cContentionMode, $c2cContentionWorkerModes, true)) {
        $decoded = base64_decode((string) ($argv[2] ?? ''), true);
        if ($decoded === false) {
            fwrite(STDERR, "Invalid C2C contention worker payload encoding.\n");
            exit(2);
        }
        try {
            /** @var array<string,mixed> $payload */
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            fwrite(STDERR, 'Invalid C2C contention worker payload: '.$exception->getMessage()."\n");
            exit(2);
        }

        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app->instance(CardToCardAdjustmentGenerator::class, new C2cContentionFixedAdjustmentGenerator);
        config()->set('payments.card_to_card.lookup_key', str_repeat('c', 32));
        if (isset($payload['clock_now'])) {
            $app->instance(Clock::class, new C2cContentionClock(new DateTimeImmutable((string) $payload['clock_now'], new DateTimeZone('UTC'))));
        }

        $database = $app->make(DatabaseManager::class);
        $connection = $database->connection();
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 10');
        $barrier = (string) ($payload['barrier'] ?? '');
        $barrierReached = false;

        $connection->beforeExecuting(function (string $query, array $bindings, Connection $db) use ($c2cContentionMode, $barrier, &$barrierReached): void {
            unset($bindings, $db);
            if ($barrierReached) {
                return;
            }
            $sql = strtolower($query);
            $target = match (true) {
                $c2cContentionMode === '--c2c-create-worker' => str_contains($sql, 'c2c_destination_accounts') && str_contains($sql, 'for update'),
                $c2cContentionMode === '--c2c-capture-worker' => str_contains($sql, 'c2c_transaction_matches') && str_contains($sql, 'for update'),
                $c2cContentionMode === '--c2c-maintenance-worker' && $barrier === 'destination_before' => str_contains($sql, 'c2c_destination_accounts') && str_contains($sql, 'for update'),
                in_array($c2cContentionMode, ['--c2c-bank-worker', '--c2c-manual-worker'], true) && $barrier === 'destination_before' => str_contains($sql, 'c2c_destination_accounts') && str_contains($sql, 'for update'),
                $c2cContentionMode === '--c2c-maintenance-worker' && $barrier === 'reservation_expire_before' => str_contains($sql, 'c2c_amount_reservations') && str_starts_with(ltrim($sql), 'update'),
                default => false,
            };
            if (! $target) {
                return;
            }
            $barrierReached = true;
            $requestBarrier = $barrier === 'destination_before' || $barrier === 'reservation_expire_before';
            echo $requestBarrier ? "AT_REQUEST\n" : "AT_LOCK\n";
            flush();
            $continue = fgets(STDIN);
            if ($continue === false || trim($continue) !== 'CONTINUE') {
                throw new RuntimeException('C2C contention pre-query barrier was not released.');
            }
        });

        $connection->listen(function (QueryExecuted $event) use ($barrier, &$barrierReached): void {
            if ($barrierReached) {
                return;
            }
            $sql = strtolower($event->sql);
            $marker = match ($barrier) {
                'destination_after' => str_contains($sql, 'c2c_destination_accounts') && str_contains($sql, 'for update') ? 'AT_LOCK' : null,
                default => null,
            };
            if ($marker === null) {
                return;
            }
            $barrierReached = true;
            echo $marker."\n";
            flush();
            $continue = fgets(STDIN);
            if ($continue === false || trim($continue) !== 'CONTINUE') {
                throw new RuntimeException('C2C contention post-query barrier was not released.');
            }
        });

        echo "READY\n";
        flush();
        $go = fgets(STDIN);
        if ($go === false || trim($go) !== 'GO') {
            fwrite(STDERR, "C2C contention worker start barrier was not released.\n");
            exit(2);
        }

        try {
            if ($c2cContentionMode === '--c2c-create-worker') {
                $receipt = $app->make(CardToCardPaymentService::class)->create(
                    (string) $payload['creation_key'],
                    (int) $payload['user_id'],
                    (string) $payload['quote_public_id'],
                    (string) $payload['eligibility_public_id'],
                    (string) $payload['correlation_id'],
                );
                echo json_encode([
                    'ok' => true,
                    'reservation_id' => $receipt->reservationId,
                    'base_amount_irr' => $receipt->baseAmountIrr,
                    'adjustment_amount_irr' => $receipt->adjustmentAmountIrr,
                    'payable_amount_irr' => $receipt->payableAmountIrr,
                ], JSON_THROW_ON_ERROR)."\n";
                exit(0);
            }

            if ($c2cContentionMode === '--c2c-capture-worker') {
                $receipt = $app->make(CardToCardSettlementService::class)->capture(
                    (string) $payload['match_public_id'],
                    (string) $payload['correlation_id'],
                );
                echo json_encode([
                    'ok' => true,
                    'settlement_id' => $receipt->purchaseSettlementId,
                    'replayed' => $receipt->replayed,
                ], JSON_THROW_ON_ERROR)."\n";
                exit(0);
            }

            if ($c2cContentionMode === '--c2c-maintenance-worker') {
                $result = $app->make(PurchasePaymentMaintenanceService::class)->run(10);
                echo json_encode([
                    'ok' => true,
                    'c2c_examined' => $result->c2cIntentsExamined,
                    'c2c_expired' => $result->expiredC2cIntents,
                    'promotion_released' => $result->releasedPromotionReservations,
                    'failures' => $result->failures,
                ], JSON_THROW_ON_ERROR)."\n";
                exit(0);
            }

            if ($c2cContentionMode === '--c2c-bank-worker') {
                $receipt = $app->make(CardToCardBankTransactionService::class)->ingest(
                    'fake',
                    new BankTransactionObservation(
                        (string) $payload['provider_transaction_id'],
                        (string) $payload['provider_event_id'],
                        '4242424242424242',
                        (int) $payload['amount_irr'],
                        (string) $payload['status'],
                        new DateTimeImmutable((string) $payload['occurred_at'], new DateTimeZone('UTC')),
                        null,
                        null,
                        (string) $payload['reference'],
                        hash('sha256', (string) $payload['evidence_seed']),
                    ),
                    'fake',
                    (string) $payload['correlation_id'],
                );
                echo json_encode([
                    'ok' => true,
                    'transaction_id' => $receipt->transactionId,
                    'status' => $receipt->status,
                    'replayed' => $receipt->replayed,
                ], JSON_THROW_ON_ERROR)."\n";
                exit(0);
            }

            $receipt = $app->make(CardToCardManualSubmissionService::class)->submit(
                (string) $payload['submission_key'],
                (int) $payload['user_id'],
                (string) $payload['reservation_public_id'],
                (int) $payload['amount_irr'],
                new DateTimeImmutable((string) $payload['paid_at'], new DateTimeZone('UTC')),
                hash('sha256', (string) $payload['evidence_seed']),
                null,
                null,
                (string) $payload['reference'],
                null,
                (string) $payload['correlation_id'],
            );
            echo json_encode([
                'ok' => true,
                'submission_id' => $receipt->submissionId,
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
    use App\Modules\Catalog\Application\CatalogChangeContext;
    use App\Modules\Catalog\Application\PlanOfferingService;
    use App\Modules\Catalog\Domain\ProductVisibility;
    use App\Modules\Orders\Application\Contracts\QuoteDiscountAuthority;
    use App\Modules\Orders\Application\PurchaseOrderService;
    use App\Modules\Orders\Application\QuoteDiscountAuthorizationRequest;
    use App\Modules\Orders\Application\QuoteDiscountConsumptionRequest;
    use App\Modules\Orders\Application\QuotePricingInput;
    use App\Modules\Orders\Application\QuoteService;
    use App\Modules\Orders\Domain\QuoteOverrideSource;
    use App\Modules\Payments\Application\PurchasePaymentMaintenanceService;
    use App\Modules\Payments\CardToCard\Application\CardToCardBankTransactionService;
    use App\Modules\Payments\CardToCard\Application\CardToCardDestinationService;
    use App\Modules\Payments\CardToCard\Application\CardToCardMatchingService;
    use App\Modules\Payments\CardToCard\Application\CardToCardPaymentService;
    use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionObservation;
    use App\Modules\Payments\CardToCard\Application\Contracts\CardToCardAdjustmentGenerator;
    use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
    use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeType;
    use DateTimeImmutable;
    use Illuminate\Database\QueryException;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Str;
    use RuntimeException;
    use Tests\Support\CreatesBenefitCodeFixtures;
    use Tests\TestCase;

    /** @requirement C2C-002 C2C-004 C2C-005 DAT-002 DAT-003 DAT-004 QUA-004 */
    final class CardToCardContentionTest extends TestCase
    {
        use AgentPricingQuoteIntegrationTestSupport;
        use CreatesBenefitCodeFixtures;
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 20;

        protected function setUp(): void
        {
            parent::setUp();
            if (DB::connection()->getDriverName() !== 'mysql') {
                $this->markTestSkipped('C2C contention requires MariaDB/MySQL.');
            }
            $this->seed();
            $this->app->instance(CardToCardAdjustmentGenerator::class, new \C2cContentionFixedAdjustmentGenerator);
            config()->set('payments.card_to_card.lookup_key', str_repeat('c', 32));
            $this->configureMethod();
            $this->app->make(CardToCardDestinationService::class)->register(
                'contention-primary',
                '4242424242424242',
                'Contention Account',
                true,
                1000,
                9990,
                30,
                120,
                null,
                10,
                'fake',
                'C2C contention destination.',
                $this->correlation('destination'),
            );
        }

        protected function tearDown(): void
        {
            try {
                $this->truncateDatabaseTables();
            } finally {
                parent::tearDown();
            }
        }

        public function test_two_concurrent_equal_base_intents_receive_distinct_active_payable_amounts(): void
        {
            [$userA, $quoteA, $decisionA] = $this->preparedPayment('create-a');
            [$userB, $quoteB, $decisionB] = $this->preparedPayment('create-b');

            $workerA = $this->startWorker('--c2c-create-worker', [
                'creation_key' => 'c2c.contention.intent.a',
                'user_id' => $userA,
                'quote_public_id' => $quoteA,
                'eligibility_public_id' => $decisionA,
                'correlation_id' => $this->correlation('create-a'),
            ]);
            $workerB = $this->startWorker('--c2c-create-worker', [
                'creation_key' => 'c2c.contention.intent.b',
                'user_id' => $userB,
                'quote_public_id' => $quoteB,
                'eligibility_public_id' => $decisionB,
                'correlation_id' => $this->correlation('create-b'),
            ]);

            try {
                self::assertSame("READY\n", $this->readLine($workerA, 'create A readiness'));
                self::assertSame("READY\n", $this->readLine($workerB, 'create B readiness'));
                $this->sendCommand($workerA, 'GO');
                $this->sendCommand($workerB, 'GO');
                self::assertSame("AT_LOCK\n", $this->readLine($workerA, 'create A lock barrier'));
                self::assertSame("AT_LOCK\n", $this->readLine($workerB, 'create B lock barrier'));
                $this->sendCommand($workerA, 'CONTINUE');
                $this->sendCommand($workerB, 'CONTINUE');

                $resultA = $this->readJsonResult($workerA, 'create A result');
                $resultB = $this->readJsonResult($workerB, 'create B result');
                self::assertTrue((bool) ($resultA['ok'] ?? false), json_encode($resultA, JSON_THROW_ON_ERROR));
                self::assertTrue((bool) ($resultB['ok'] ?? false), json_encode($resultB, JSON_THROW_ON_ERROR));
                self::assertSame((int) $resultA['base_amount_irr'], (int) $resultB['base_amount_irr']);
                self::assertNotSame((int) $resultA['payable_amount_irr'], (int) $resultB['payable_amount_irr']);
                self::assertEqualsCanonicalizing([1000, 1001], [(int) $resultA['adjustment_amount_irr'], (int) $resultB['adjustment_amount_irr']]);
                self::assertSame(2, DB::table('c2c_amount_reservations')->where('active_lock', 1)->count());
            } finally {
                $this->closeWorker($workerA);
                $this->closeWorker($workerB);
            }
        }

        public function test_duplicate_concurrent_capture_creates_one_purchase_settlement_and_one_replay(): void
        {
            [$user, $quote, $decision] = $this->preparedPayment('capture');
            $opening = $this->app->make(PurchaseOrderService::class)->openFromQuote(
                $quote,
                $user,
                $this->correlation('capture-order'),
            );
            $payment = $this->app->make(CardToCardPaymentService::class)->create(
                'c2c.contention.capture.intent',
                $user,
                $quote,
                $decision,
                $this->correlation('capture-payment'),
            );
            $bank = $this->app->make(CardToCardBankTransactionService::class)->ingest(
                'fake',
                new BankTransactionObservation(
                    'capture-race-tx',
                    'capture-race-event',
                    '4242424242424242',
                    $payment->payableAmountIrr,
                    'settled',
                    new DateTimeImmutable('now', new \DateTimeZone('UTC')),
                    null,
                    null,
                    'capture-race-ref',
                    hash('sha256', 'capture-race-evidence'),
                ),
                'fake',
                $this->correlation('capture-bank'),
            );
            $match = $this->app->make(CardToCardMatchingService::class)->match(
                $bank->publicId,
                $this->correlation('capture-match'),
            );
            self::assertNotNull($match->matchPublicId);

            $workerA = $this->startWorker('--c2c-capture-worker', [
                'match_public_id' => $match->matchPublicId,
                'correlation_id' => $this->correlation('capture-a'),
            ]);
            $workerB = $this->startWorker('--c2c-capture-worker', [
                'match_public_id' => $match->matchPublicId,
                'correlation_id' => $this->correlation('capture-b'),
            ]);

            try {
                self::assertSame("READY\n", $this->readLine($workerA, 'capture A readiness'));
                self::assertSame("READY\n", $this->readLine($workerB, 'capture B readiness'));
                $this->sendCommand($workerA, 'GO');
                $this->sendCommand($workerB, 'GO');
                self::assertSame("AT_LOCK\n", $this->readLine($workerA, 'capture A lock barrier'));
                self::assertSame("AT_LOCK\n", $this->readLine($workerB, 'capture B lock barrier'));
                $this->sendCommand($workerA, 'CONTINUE');
                $this->sendCommand($workerB, 'CONTINUE');

                $resultA = $this->readJsonResult($workerA, 'capture A result');
                $resultB = $this->readJsonResult($workerB, 'capture B result');
                self::assertTrue((bool) ($resultA['ok'] ?? false), json_encode($resultA, JSON_THROW_ON_ERROR));
                self::assertTrue((bool) ($resultB['ok'] ?? false), json_encode($resultB, JSON_THROW_ON_ERROR));
                self::assertSame((int) $resultA['settlement_id'], (int) $resultB['settlement_id']);
                self::assertEqualsCanonicalizing([false, true], [(bool) $resultA['replayed'], (bool) $resultB['replayed']]);
                self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'card_to_card')->count());
                self::assertSame(1, DB::table('payment_provider_transactions')->where('provider_code', 'card_to_card')->count());
                self::assertSame(1, DB::table('c2c_transaction_matches')->where('state', 'captured')->count());
                self::assertSame(1, DB::table('orders')->count());
                $order = DB::table('orders')->where('public_id', $opening->orderPublicId)->first();
                self::assertNotNull($order);
                self::assertSame('paid', $order->state);
                self::assertSame((int) $resultA['settlement_id'], (int) $order->purchase_settlement_id);
                self::assertSame($payment->paymentIntent->intentPublicId, $order->payment_intent_public_id);
            } finally {
                $this->closeWorker($workerA);
                $this->closeWorker($workerB);
            }
        }

        public function test_pending_bank_evidence_first_serializes_before_late_review_expiry(): void
        {
            $this->assertBankEvidenceExpiryRace('pending', false);
        }

        public function test_settled_bank_evidence_first_serializes_before_late_review_expiry(): void
        {
            $this->assertBankEvidenceExpiryRace('settled', false);
        }

        public function test_pending_maintenance_first_keeps_later_bank_evidence_immutable_without_resurrection(): void
        {
            $this->assertBankEvidenceExpiryRace('pending', true);
        }

        public function test_settled_maintenance_first_keeps_later_bank_evidence_immutable_without_resurrection(): void
        {
            $this->assertBankEvidenceExpiryRace('settled', true);
        }

        public function test_manual_submission_first_serializes_before_late_review_expiry(): void
        {
            $this->assertManualEvidenceExpiryRace(false);
        }

        public function test_maintenance_first_rejects_later_manual_submission_without_resurrection(): void
        {
            $this->assertManualEvidenceExpiryRace(true);
        }

        public function test_discounted_bank_evidence_first_prevents_terminality_and_promotion_release(): void
        {
            $this->assertDiscountedBankEvidencePromotionRace(false);
        }

        public function test_discounted_maintenance_first_releases_promotion_and_preserves_later_bank_evidence(): void
        {
            $this->assertDiscountedBankEvidencePromotionRace(true);
        }

        private function assertDiscountedBankEvidencePromotionRace(bool $maintenanceFirst): void
        {
            $suffix = $maintenanceFirst ? 'discounted-maintenance-first' : 'discounted-bank-first';
            $authority = $this->discountedLateReviewPayment($suffix);
            self::assertSame(1, DB::table('promotion_usage_reservations')->count());
            self::assertSame(0, DB::table('promotion_usage_releases')->count());
            self::assertSame(0, DB::table('promotion_usage_redemptions')->count());

            $bank = $this->startWorker('--c2c-bank-worker', [
                'barrier' => $maintenanceFirst ? 'destination_before' : 'destination_after',
                'clock_now' => $authority['maintenance_now'],
                'provider_transaction_id' => 'c2c-race-tx-'.$suffix,
                'provider_event_id' => 'c2c-race-event-'.$suffix,
                'amount_irr' => $authority['payable_amount_irr'],
                'status' => 'settled',
                'occurred_at' => $authority['occurred_at'],
                'reference' => 'c2c-race-ref-'.$suffix,
                'evidence_seed' => 'c2c-race-evidence-'.$suffix,
                'correlation_id' => $this->correlation('bank-'.$suffix),
            ]);
            $maintenance = $this->startWorker('--c2c-maintenance-worker', [
                'barrier' => $maintenanceFirst ? 'destination_after' : 'reservation_expire_before',
                'clock_now' => $authority['maintenance_now'],
            ]);

            try {
                self::assertSame("READY\n", $this->readLine($bank, 'discounted bank readiness'));
                self::assertSame("READY\n", $this->readLine($maintenance, 'discounted maintenance readiness'));

                if ($maintenanceFirst) {
                    $this->sendCommand($maintenance, 'GO');
                    self::assertSame("AT_LOCK\n", $this->readLine($maintenance, 'discounted maintenance destination lock'));
                    $this->sendCommand($bank, 'GO');
                    self::assertSame("AT_REQUEST\n", $this->readLine($bank, 'discounted bank destination request'));
                    $this->sendCommand($bank, 'CONTINUE');
                    $this->sendCommand($maintenance, 'CONTINUE');
                } else {
                    $this->sendCommand($bank, 'GO');
                    self::assertSame("AT_LOCK\n", $this->readLine($bank, 'discounted bank destination lock'));
                    $this->sendCommand($maintenance, 'GO');
                    self::assertSame("AT_REQUEST\n", $this->readLine($maintenance, 'discounted maintenance reservation-expiry request'));
                    $this->sendCommand($maintenance, 'CONTINUE');
                    $this->sendCommand($bank, 'CONTINUE');
                }

                $bankResult = $this->readJsonResult($bank, 'discounted bank result');
                $maintenanceResult = $this->readJsonResult($maintenance, 'discounted maintenance result');
                self::assertTrue((bool) ($bankResult['ok'] ?? false), json_encode($bankResult, JSON_THROW_ON_ERROR));
                self::assertSame('settled', $bankResult['status'] ?? null);
                self::assertTrue((bool) ($maintenanceResult['ok'] ?? false), json_encode($maintenanceResult, JSON_THROW_ON_ERROR));
                self::assertSame($maintenanceFirst ? 1 : 0, (int) ($maintenanceResult['c2c_expired'] ?? -1));
                self::assertSame($maintenanceFirst ? 1 : 0, (int) ($maintenanceResult['promotion_released'] ?? -1));
                self::assertSame(0, (int) ($maintenanceResult['failures'] ?? -1));
                self::assertSame($maintenanceFirst ? 'expired' : 'awaiting_user_action', DB::table('payment_intents')
                    ->where('public_id', $authority['intent_public_id'])
                    ->value('state'));
                self::assertSame(1, DB::table('promotion_usage_reservations')->count());
                self::assertSame($maintenanceFirst ? 1 : 0, DB::table('promotion_usage_releases')->count());
                self::assertSame(0, DB::table('promotion_usage_redemptions')->count());
                self::assertSame(1, DB::table('c2c_bank_transactions')
                    ->where('provider_transaction_id', 'c2c-race-tx-'.$suffix)
                    ->where('status', 'settled')
                    ->count());

                $recheck = $this->app->make(PurchasePaymentMaintenanceService::class)->run(10);
                self::assertSame(0, $recheck->releasedPromotionReservations);
                self::assertSame($maintenanceFirst ? 1 : 0, DB::table('promotion_usage_releases')->count());
            } finally {
                $this->closeWorker($bank);
                $this->closeWorker($maintenance);
            }
        }

        public function test_database_rejects_post_terminal_backdated_bank_and_manual_resurrection(): void
        {
            $authority = $this->lateReviewPayment('strict-terminal-guard');
            $intent = DB::table('payment_intents')
                ->where('public_id', $authority['intent_public_id'])
                ->first(['id', 'user_id']);
            $reservation = DB::table('c2c_amount_reservations')
                ->where('public_id', $authority['reservation_public_id'])
                ->first(['id', 'c2c_destination_account_id', 'payable_amount_irr']);
            self::assertNotNull($intent);
            self::assertNotNull($reservation);

            $expiredAt = now('UTC')->format('Y-m-d H:i:s.u');
            self::assertSame(1, DB::table('payment_intents')
                ->where('id', $intent->id)
                ->where('state', 'awaiting_user_action')
                ->update(['state' => 'expired', 'updated_at' => $expiredAt]));
            DB::table('payment_intent_state_histories')->insert([
                'payment_intent_id' => (int) $intent->id,
                'from_state' => 'awaiting_user_action',
                'to_state' => 'expired',
                'reason_code' => 'c2c_late_review_expired',
                'correlation_id' => $this->correlation('strict-terminal-expire'),
                'created_at' => $expiredAt,
            ]);

            $occurredAt = (new DateTimeImmutable($authority['occurred_at']))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s.u');
            $bankTransactionId = (int) DB::table('c2c_bank_transactions')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'provider_code' => 'fake',
                'provider_transaction_id' => 'c2c-direct-post-terminal-bank',
                'c2c_destination_account_id' => (int) $reservation->c2c_destination_account_id,
                'amount_irr' => (int) $reservation->payable_amount_irr,
                'currency' => 'IRR',
                'status' => 'pending',
                'occurred_at' => $occurredAt,
                'sender_card_lookup_hash' => null,
                'encrypted_sender_name' => null,
                'reference' => 'c2c-direct-post-terminal-ref',
                'first_evidence_payload_hash' => hash('sha256', 'c2c-direct-post-terminal-bank'),
                'first_observed_at' => $expiredAt,
                'last_observed_at' => $expiredAt,
                'created_at' => $expiredAt,
            ]);

            try {
                DB::table('payment_intents')
                    ->where('id', $intent->id)
                    ->where('state', 'expired')
                    ->update(['state' => 'submitted', 'updated_at' => now('UTC')]);
                self::fail('Expected strict database guard to reject post-terminal resurrection with pending bank evidence.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('Payment intent state transition is invalid.', $exception->getMessage());
            }

            DB::table('c2c_bank_transaction_events')->insert([
                'c2c_bank_transaction_id' => $bankTransactionId,
                'provider_event_id' => 'c2c-direct-post-terminal-settled-event',
                'status' => 'settled',
                'evidence_payload_hash' => hash('sha256', 'c2c-direct-post-terminal-settled-event'),
                'ingestion_method' => 'manual',
                'observed_at' => $expiredAt,
                'correlation_id' => $this->correlation('direct-post-terminal-settled'),
                'created_at' => $expiredAt,
            ]);
            self::assertSame(1, DB::table('c2c_bank_transactions')
                ->where('id', $bankTransactionId)
                ->where('status', 'pending')
                ->update(['status' => 'settled', 'last_observed_at' => $expiredAt]));

            try {
                DB::table('payment_intents')
                    ->where('id', $intent->id)
                    ->where('state', 'expired')
                    ->update(['state' => 'submitted', 'updated_at' => now('UTC')]);
                self::fail('Expected strict database guard to reject post-terminal resurrection with settled bank evidence.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('Payment intent state transition is invalid.', $exception->getMessage());
            }

            try {
                DB::table('c2c_manual_submissions')->insert([
                    'public_id' => (string) Str::ulid(),
                    'submission_key' => 'c2c.manual.direct.post.terminal',
                    'payment_intent_id' => (int) $intent->id,
                    'c2c_amount_reservation_id' => (int) $reservation->id,
                    'c2c_destination_account_id' => (int) $reservation->c2c_destination_account_id,
                    'submitted_by_user_id' => (int) $intent->user_id,
                    'claimed_amount_irr' => (int) $reservation->payable_amount_irr,
                    'claimed_paid_at' => $occurredAt,
                    'sender_card_lookup_hash' => null,
                    'encrypted_sender_name' => null,
                    'reference' => 'c2c-direct-post-terminal-manual-ref',
                    'private_receipt_reference' => null,
                    'evidence_hash' => hash('sha256', 'c2c-direct-post-terminal-manual'),
                    'created_at' => $expiredAt,
                ]);
                self::fail('Expected strict database guard to reject post-terminal manual evidence.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('C2C manual submission must match one owned purchase reservation and exact payable amount.', $exception->getMessage());
            }

            self::assertSame('expired', DB::table('payment_intents')->where('id', $intent->id)->value('state'));
            self::assertSame(1, DB::table('c2c_bank_transactions')
                ->where('provider_transaction_id', 'c2c-direct-post-terminal-bank')
                ->count());
            self::assertSame(0, DB::table('c2c_manual_submissions')
                ->where('submission_key', 'c2c.manual.direct.post.terminal')
                ->count());
        }

        private function assertBankEvidenceExpiryRace(string $status, bool $maintenanceFirst): void
        {
            $suffix = $status.'-'.($maintenanceFirst ? 'maintenance-first' : 'bank-first');
            $authority = $this->lateReviewPayment($suffix);
            $bank = $this->startWorker('--c2c-bank-worker', [
                'barrier' => $maintenanceFirst ? 'destination_before' : 'destination_after',
                'clock_now' => $authority['maintenance_now'],
                'provider_transaction_id' => 'c2c-race-tx-'.$suffix,
                'provider_event_id' => 'c2c-race-event-'.$suffix,
                'amount_irr' => $authority['payable_amount_irr'],
                'status' => $status,
                'occurred_at' => $authority['occurred_at'],
                'reference' => 'c2c-race-ref-'.$suffix,
                'evidence_seed' => 'c2c-race-evidence-'.$suffix,
                'correlation_id' => $this->correlation('bank-'.$suffix),
            ]);
            $maintenance = $this->startWorker('--c2c-maintenance-worker', [
                'barrier' => $maintenanceFirst ? 'destination_after' : 'reservation_expire_before',
                'clock_now' => $authority['maintenance_now'],
            ]);

            try {
                self::assertSame("READY\n", $this->readLine($bank, 'bank readiness'));
                self::assertSame("READY\n", $this->readLine($maintenance, 'maintenance readiness'));

                if ($maintenanceFirst) {
                    $this->sendCommand($maintenance, 'GO');
                    self::assertSame("AT_LOCK\n", $this->readLine($maintenance, 'maintenance destination lock'));
                    $this->sendCommand($bank, 'GO');
                    self::assertSame("AT_REQUEST\n", $this->readLine($bank, 'bank destination request'));
                    $this->sendCommand($bank, 'CONTINUE');
                    $this->sendCommand($maintenance, 'CONTINUE');
                } else {
                    $this->sendCommand($bank, 'GO');
                    self::assertSame("AT_LOCK\n", $this->readLine($bank, 'bank destination lock'));
                    $this->sendCommand($maintenance, 'GO');
                    self::assertSame("AT_REQUEST\n", $this->readLine($maintenance, 'maintenance destination request'));
                    $this->sendCommand($maintenance, 'CONTINUE');
                    $this->sendCommand($bank, 'CONTINUE');
                }

                $bankResult = $this->readJsonResult($bank, 'bank result');
                $maintenanceResult = $this->readJsonResult($maintenance, 'maintenance result');
                self::assertTrue((bool) ($bankResult['ok'] ?? false), json_encode($bankResult, JSON_THROW_ON_ERROR));
                self::assertSame($status, $bankResult['status'] ?? null);
                self::assertTrue((bool) ($maintenanceResult['ok'] ?? false), json_encode($maintenanceResult, JSON_THROW_ON_ERROR));
                self::assertSame(0, (int) ($maintenanceResult['failures'] ?? -1));
                self::assertSame($maintenanceFirst ? 1 : 0, (int) ($maintenanceResult['c2c_expired'] ?? -1));
                self::assertSame(0, (int) ($maintenanceResult['promotion_released'] ?? -1));
                self::assertSame($maintenanceFirst ? 'expired' : 'awaiting_user_action', DB::table('payment_intents')
                    ->where('public_id', $authority['intent_public_id'])
                    ->value('state'));
                self::assertSame(1, DB::table('c2c_bank_transactions')
                    ->where('provider_transaction_id', 'c2c-race-tx-'.$suffix)
                    ->where('status', $status)
                    ->count());
                self::assertSame(0, DB::table('promotion_usage_releases')->count());
                self::assertSame(0, DB::table('payment_intent_state_histories')
                    ->where('payment_intent_id', DB::table('payment_intents')->where('public_id', $authority['intent_public_id'])->value('id'))
                    ->whereIn('reason_code', ['c2c_concurrent_bank_evidence_restored', 'c2c_concurrent_manual_evidence_restored'])
                    ->count());
            } finally {
                $this->closeWorker($bank);
                $this->closeWorker($maintenance);
            }
        }

        private function assertManualEvidenceExpiryRace(bool $maintenanceFirst): void
        {
            $suffix = $maintenanceFirst ? 'manual-maintenance-first' : 'manual-first';
            $authority = $this->lateReviewPayment($suffix);
            $manual = $this->startWorker('--c2c-manual-worker', [
                'barrier' => $maintenanceFirst ? 'destination_before' : 'destination_after',
                'clock_now' => $authority['maintenance_now'],
                'submission_key' => 'c2c.manual.race.'.$suffix,
                'user_id' => $authority['user_id'],
                'reservation_public_id' => $authority['reservation_public_id'],
                'amount_irr' => $authority['payable_amount_irr'],
                'paid_at' => $authority['occurred_at'],
                'reference' => 'c2c-manual-race-ref-'.$suffix,
                'evidence_seed' => 'c2c-manual-race-evidence-'.$suffix,
                'correlation_id' => $this->correlation('manual-'.$suffix),
            ]);
            $maintenance = $this->startWorker('--c2c-maintenance-worker', [
                'barrier' => $maintenanceFirst ? 'destination_after' : 'reservation_expire_before',
                'clock_now' => $authority['maintenance_now'],
            ]);

            try {
                self::assertSame("READY\n", $this->readLine($manual, 'manual readiness'));
                self::assertSame("READY\n", $this->readLine($maintenance, 'maintenance readiness'));

                if ($maintenanceFirst) {
                    $this->sendCommand($maintenance, 'GO');
                    self::assertSame("AT_LOCK\n", $this->readLine($maintenance, 'maintenance destination lock'));
                    $this->sendCommand($manual, 'GO');
                    self::assertSame("AT_REQUEST\n", $this->readLine($manual, 'manual destination request'));
                    $this->sendCommand($manual, 'CONTINUE');
                    $this->sendCommand($maintenance, 'CONTINUE');
                } else {
                    $this->sendCommand($manual, 'GO');
                    self::assertSame("AT_LOCK\n", $this->readLine($manual, 'manual destination lock'));
                    $this->sendCommand($maintenance, 'GO');
                    self::assertSame("AT_REQUEST\n", $this->readLine($maintenance, 'maintenance destination request'));
                    $this->sendCommand($maintenance, 'CONTINUE');
                    $this->sendCommand($manual, 'CONTINUE');
                }

                $manualResult = $this->readJsonResult($manual, 'manual result');
                $maintenanceResult = $this->readJsonResult($maintenance, 'maintenance result');
                self::assertTrue((bool) ($maintenanceResult['ok'] ?? false), json_encode($maintenanceResult, JSON_THROW_ON_ERROR));
                self::assertSame(0, (int) ($maintenanceResult['failures'] ?? -1));
                self::assertSame($maintenanceFirst ? 1 : 0, (int) ($maintenanceResult['c2c_expired'] ?? -1));
                self::assertSame(0, (int) ($maintenanceResult['promotion_released'] ?? -1));

                if ($maintenanceFirst) {
                    self::assertFalse((bool) ($manualResult['ok'] ?? true), json_encode($manualResult, JSON_THROW_ON_ERROR));
                    self::assertSame('C2C manual submission arrived after terminal payment authority closed.', $manualResult['message'] ?? null);
                    self::assertSame('expired', DB::table('payment_intents')
                        ->where('public_id', $authority['intent_public_id'])
                        ->value('state'));
                    self::assertSame(0, DB::table('c2c_manual_submissions')
                        ->where('submission_key', 'c2c.manual.race.'.$suffix)
                        ->count());
                } else {
                    self::assertTrue((bool) ($manualResult['ok'] ?? false), json_encode($manualResult, JSON_THROW_ON_ERROR));
                    self::assertSame('submitted', DB::table('payment_intents')
                        ->where('public_id', $authority['intent_public_id'])
                        ->value('state'));
                    self::assertSame(1, DB::table('c2c_manual_submissions')
                        ->where('submission_key', 'c2c.manual.race.'.$suffix)
                        ->count());
                }
                self::assertSame(0, DB::table('promotion_usage_releases')->count());
            } finally {
                $this->closeWorker($manual);
                $this->closeWorker($maintenance);
            }
        }

        /**
         * @return array{
         *   user_id:int,
         *   intent_public_id:string,
         *   reservation_public_id:string,
         *   payable_amount_irr:int,
         *   occurred_at:string,
         *   maintenance_now:string
         * }
         */
        private function discountedLateReviewPayment(string $suffix): array
        {
            $offering = $this->activeBenefitOffering('c2c-contention-'.$suffix);
            $offeringVersion = (int) DB::table('plan_offerings')->where('id', $offering['id'])->value('version');
            $this->app->make(PlanOfferingService::class)->setVisibility(
                $offering['id'],
                $offeringVersion,
                ProductVisibility::Visible,
                new CatalogChangeContext(
                    'c2c-contention-visible-'.substr(hash('sha256', $suffix), 0, 24),
                    'c2c-contention-visible-correlation-'.substr(hash('sha256', $suffix), 0, 20),
                    'c2c_contention',
                    'Expose discounted C2C contention test Offering.',
                    $this->benefitOwner(),
                ),
            );
            $rule = $this->usageRule(
                $offering['id'],
                'c2c.contention.promo.'.substr(hash('sha256', $suffix), 0, 16),
                90_000,
                1,
                null,
            );
            $campaignCode = 'c2c.discount.'.substr(hash('sha256', $suffix), 0, 12);
            $this->benefitCampaign(
                $campaignCode,
                BenefitCodeType::DiscountGrant,
                $this->discountDefinition($rule->ruleCode, $offering['id'], $offering['product_id'], $offering['server_id']),
                'c2c-contention-'.$suffix,
            );
            $issue = $this->benefitIssue($campaignCode, 'c2c-contention-'.$suffix, 1);
            $code = (string) $issue->items[0]->fullCode;
            $user = $this->benefitUser('customer');
            $quoteService = $this->app->make(QuoteService::class);
            $sourceQuote = $quoteService->create(
                'c2c.contention.source.'.substr(hash('sha256', $suffix), 0, 24),
                $user,
                $offering['id'],
                new QuotePricingInput(
                    QuoteOverrideSource::None,
                    null,
                    null,
                    null,
                    0,
                    new DateTimeImmutable('+30 minutes', new \DateTimeZone('UTC')),
                ),
                $this->correlation('discounted-source-'.$suffix),
            );
            $discounts = $this->app->make(QuoteDiscountAuthority::class);
            $authorization = $discounts->authorize(new QuoteDiscountAuthorizationRequest(
                'c2c.contention.auth.'.substr(hash('sha256', $suffix), 0, 24),
                $user,
                $sourceQuote->quotePublicId,
                $sourceQuote->configurationSnapshotHash,
                $code,
                $this->correlation('discounted-auth-'.$suffix),
            ));
            $discounted = $quoteService->create(
                'c2c.contention.discounted.'.substr(hash('sha256', $suffix), 0, 24),
                $user,
                $offering['id'],
                new QuotePricingInput(
                    QuoteOverrideSource::None,
                    null,
                    null,
                    $authorization->ruleCode,
                    $authorization->discountIrr,
                    new DateTimeImmutable('+30 minutes', new \DateTimeZone('UTC')),
                ),
                $this->correlation('discounted-quote-'.$suffix),
            );
            $discounts->consume(new QuoteDiscountConsumptionRequest(
                'c2c.contention.consume.'.substr(hash('sha256', $suffix), 0, 24),
                $user,
                $authorization,
                $discounted->quotePublicId,
                $discounted->configurationSnapshotHash,
                $this->correlation('discounted-consume-'.$suffix),
            ));
            $decision = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
                'c2c.contention.discounted.decision.'.substr(hash('sha256', $suffix), 0, 16),
                $user,
                $discounted->quotePublicId,
                $discounted->configurationSnapshotHash,
            );
            $payment = $this->app->make(CardToCardPaymentService::class)->create(
                'c2c.contention.discounted.intent.'.$suffix,
                $user,
                $discounted->quotePublicId,
                $decision->publicId,
                $this->correlation('discounted-payment-'.$suffix),
            );
            $reservation = DB::table('c2c_amount_reservations')
                ->where('id', $payment->reservationId)
                ->first(['public_id', 'reserved_at', 'late_review_until']);
            self::assertNotNull($reservation);
            $reservedAt = $this->databaseDateTimeValue((string) $reservation->reserved_at);
            $lateReviewUntil = $this->databaseDateTimeValue((string) $reservation->late_review_until);

            return [
                'user_id' => $user,
                'intent_public_id' => $payment->paymentIntent->intentPublicId,
                'reservation_public_id' => (string) $reservation->public_id,
                'payable_amount_irr' => $payment->payableAmountIrr,
                'occurred_at' => $reservedAt->modify('+1 minute')->format('Y-m-d H:i:s.uP'),
                'maintenance_now' => $lateReviewUntil->modify('+1 second')->format('Y-m-d H:i:s.uP'),
            ];
        }

        /**
         * @return array{
         *   user_id:int,
         *   intent_public_id:string,
         *   reservation_public_id:string,
         *   payable_amount_irr:int,
         *   occurred_at:string,
         *   maintenance_now:string
         * }
         */
        private function lateReviewPayment(string $suffix): array
        {
            [$user, $quote, $decision] = $this->preparedPayment('late-'.$suffix);
            $payment = $this->app->make(CardToCardPaymentService::class)->create(
                'c2c.contention.late.'.$suffix,
                $user,
                $quote,
                $decision,
                $this->correlation('late-create-'.$suffix),
            );
            $reservation = DB::table('c2c_amount_reservations')
                ->where('id', $payment->reservationId)
                ->first(['public_id', 'reserved_at', 'late_review_until']);
            self::assertNotNull($reservation);
            $reservedAt = $this->databaseDateTimeValue((string) $reservation->reserved_at);
            $lateReviewUntil = $this->databaseDateTimeValue((string) $reservation->late_review_until);

            return [
                'user_id' => $user,
                'intent_public_id' => $payment->paymentIntent->intentPublicId,
                'reservation_public_id' => (string) $reservation->public_id,
                'payable_amount_irr' => $payment->payableAmountIrr,
                'occurred_at' => $reservedAt->modify('+1 minute')->format('Y-m-d H:i:s.uP'),
                'maintenance_now' => $lateReviewUntil->modify('+1 second')->format('Y-m-d H:i:s.uP'),
            ];
        }

        private function databaseDateTimeValue(string $value): DateTimeImmutable
        {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new \DateTimeZone('UTC'));
            if ($date === false) {
                throw new RuntimeException('Stored C2C contention timestamp is invalid.');
            }

            return $date;
        }

        private function preparedPayment(string $suffix): array
        {
            $user = $this->quoteUser('customer');
            $offering = $this->quoteOffering();
            $quote = $this->app->make(QuoteService::class)->create(
                'c2c.contention.quote.'.$suffix,
                $user,
                $offering['id'],
                new QuotePricingInput(
                    QuoteOverrideSource::None,
                    null,
                    null,
                    null,
                    0,
                    new DateTimeImmutable('+30 minutes', new \DateTimeZone('UTC')),
                ),
                $this->correlation('quote-'.$suffix),
            );
            $decision = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
                'c2c.contention.eligibility.'.$suffix,
                $user,
                $quote->quotePublicId,
            );

            return [$user, $quote->quotePublicId, $decision->publicId];
        }

        private function configureMethod(): void
        {
            $administratorId = $this->ownerAdministrator();
            $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
            $eligibility->configureMethod(
                'c2c.contention.method',
                $administratorId,
                'card_to_card',
                true,
                false,
                1,
                'C2C contention method.',
                $this->correlation('method'),
            );
            $eligibility->recordHealth(
                'c2c.contention.health',
                $administratorId,
                'card_to_card',
                true,
                new DateTimeImmutable('+20 minutes', new \DateTimeZone('UTC')),
                'Healthy C2C contention provider.',
                $this->correlation('health'),
            );
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
                throw new RuntimeException('Unable to start C2C contention worker.');
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
                    throw new RuntimeException('Unable to wait for C2C contention worker output.');
                }
                foreach ($read as $stream) {
                    if ($stream === $worker['pipes'][2]) {
                        $stderr .= stream_get_contents($stream);

                        continue;
                    }
                    $line = fgets($stream);
                    if ($line !== false) {
                        if (trim($line) === '') {
                            continue;
                        }

                        return $line;
                    }
                }
                $status = proc_get_status($worker['process']);
                if (! $status['running'] && feof($worker['pipes'][1])) {
                    $stderr .= stream_get_contents($worker['pipes'][2]);
                    throw new RuntimeException('C2C contention worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException('C2C contention worker timed out during '.$phase.': '.$stderr);
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

        private function correlation(string $suffix): string
        {
            return hash('sha256', 'c2c-contention:'.$suffix);
        }
    }
}
