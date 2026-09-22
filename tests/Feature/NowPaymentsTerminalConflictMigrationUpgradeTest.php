<?php

declare(strict_types=1);

namespace Tests\Feature;

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
use App\Modules\Payments\Application\Contracts\PurchasePromotionUsageAuthority;
use App\Modules\Payments\Application\PurchasePaymentIntentService;
use App\Modules\Payments\Application\PurchasePaymentMaintenanceService;
use App\Modules\Payments\Application\PurchaseProviderMutationAttempt;
use App\Modules\Payments\Application\PurchaseProviderMutationBarrier;
use App\Modules\Payments\Application\PurchaseSettlementService;
use App\Modules\Payments\Application\PurchaseWalletPaymentService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Payments\NowPayments\Application\Contracts\NowPaymentsCreateRequest;
use App\Modules\Payments\NowPayments\Application\Contracts\NowPaymentsPaymentResult;
use App\Modules\Payments\NowPayments\Application\Contracts\NowPaymentsTransport;
use App\Modules\Payments\NowPayments\Application\NowPaymentsAuthorityState;
use App\Modules\Payments\NowPayments\Application\NowPaymentsPaymentService;
use App\Modules\Payments\Usdt\Application\UsdtCircuitBreaker;
use App\Modules\Payments\Usdt\Application\UsdtRateResolver;
use App\Modules\Payments\Usdt\Domain\UsdtRate;
use App\Modules\Payments\Usdt\Domain\UsdtRatePolicy;
use App\Modules\Payments\Usdt\Domain\UsdtRateProvider;
use App\Modules\Payments\Usdt\Domain\UsdtRateSide;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeType;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Database\Seeders\PromotionAccessFoundationSeeder;
use DateTimeImmutable;
use DomainException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\Support\RestoresDatabaseTrigger;
use Tests\TestCase;
use Throwable;

final class LegacyUpgradeNowPaymentsTransport implements NowPaymentsTransport
{
    public int $createCalls = 0;

    public int $statusCalls = 0;

    public ?NowPaymentsCreateRequest $lastCreateRequest = null;

    public string $createStatus = 'waiting';

    public string $statusValue = 'waiting';

    public string $payAmount = '12.500000000000000000';

    public ?string $actuallyPaid = null;

    public string $providerPaymentId = 'legacy-upgrade-900001';

    public function create(NowPaymentsCreateRequest $request): NowPaymentsPaymentResult
    {
        $this->createCalls++;
        $this->lastCreateRequest = $request;

        return $this->result($this->createStatus, null, 'create');
    }

    public function status(string $providerPaymentId): NowPaymentsPaymentResult
    {
        $this->statusCalls++;
        if ($providerPaymentId !== $this->providerPaymentId || $this->lastCreateRequest === null) {
            throw new RuntimeException('unexpected legacy-upgrade NOWPayments status lookup');
        }

        return $this->result($this->statusValue, $this->actuallyPaid, 'status-'.$this->statusCalls);
    }

    private function result(string $status, ?string $actuallyPaid, string $suffix): NowPaymentsPaymentResult
    {
        $request = $this->lastCreateRequest ?? throw new RuntimeException('missing legacy-upgrade NOWPayments create request');
        $time = new DateTimeImmutable('2026-09-21T18:00:00+00:00');
        $hash = hash('sha256', implode('|', [
            $this->providerPaymentId,
            $status,
            $request->priceAmountUsd,
            $request->payCurrency,
            $request->orderId,
            $this->payAmount,
            $actuallyPaid ?? '',
            $suffix,
        ]));

        return new NowPaymentsPaymentResult(
            $this->providerPaymentId,
            $status,
            $request->priceAmountUsd,
            'USD',
            $this->payAmount,
            $actuallyPaid,
            $request->payCurrency,
            '0x1111111111111111111111111111111111111111',
            $request->orderId,
            $time,
            $time,
            $hash,
        );
    }
}

final readonly class LegacyUpgradeNowPaymentsRateProvider implements UsdtRateProvider
{
    public function __construct(private Clock $clock) {}

    public function code(): string
    {
        return 'manual';
    }

    public function fetch(UsdtRateSide $side): UsdtRate
    {
        return new UsdtRate(
            'manual',
            '900000.00000000',
            $this->clock->now(),
            hash('sha256', 'legacy-upgrade-manual|900000|'.$side->value),
        );
    }
}

final class LegacyUpgradeNowPaymentsClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement IPG-002 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
final class NowPaymentsTerminalConflictMigrationUpgradeTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use CreatesBenefitCodeFixtures;
    use DatabaseTruncation;
    use RestoresDatabaseTrigger;

    private LegacyUpgradeNowPaymentsTransport $transport;

    private LegacyUpgradeNowPaymentsClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('NOWPayments terminal-conflict migration upgrade coverage requires MariaDB/MySQL.');
        }

        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->seed(PromotionAccessFoundationSeeder::class);
        $this->clock = new LegacyUpgradeNowPaymentsClock(new DateTimeImmutable('2026-09-21T18:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
        DB::statement('SET timestamp = '.$this->clock->value->getTimestamp());
        $this->transport = new LegacyUpgradeNowPaymentsTransport;

        config()->set('app.url', 'https://payments.example.test');
        config()->set('services.nowpayments.enabled', true);
        config()->set('services.nowpayments.ipn_secret', 'nowpayments-test-secret');
        config()->set('services.nowpayments.ipn_callback_url', 'https://payments.example.test/api/payments/nowpayments/ipn');
        config()->set('services.nowpayments.pay_currency', 'usdtbsc');
        config()->set('services.nowpayments.connect_timeout_seconds', 5);
        config()->set('services.nowpayments.timeout_seconds', 15);
        config()->set('services.nowpayments.max_ipn_body_bytes', 262144);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->app) && DB::connection()->getDriverName() === 'mysql') {
                $this->truncateDatabaseTables();
                $this->migration()->up();
                DB::statement('SET timestamp = DEFAULT');
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_upgrade_can_roll_back_and_reapply_when_no_terminal_conflict_evidence_exists(): void
    {
        $this->migration()->down();
        self::assertStringNotContainsString(
            'finished_status_observation_count',
            $this->triggerAction('nowpayments_authority_update_guard'),
        );
        self::assertStringNotContainsString(
            'unresolved_purchase_manual_review_count',
            $this->triggerAction('payment_intents_insert_guard'),
        );

        $this->migration()->up();
        self::assertStringContainsString(
            'finished_status_observation_count',
            $this->triggerAction('nowpayments_authority_update_guard'),
        );
        self::assertStringContainsString(
            'unresolved_purchase_manual_review_count',
            $this->triggerAction('payment_intents_insert_guard'),
        );
        $this->assertUpgradeFencesAbsent();
    }

    public function test_upgrade_normalizes_legacy_failed_and_expired_conflicts_and_blocks_fresh_competing_intent(): void
    {
        $this->migration()->down();

        foreach (['failed', 'expired'] as $terminalStatus) {
            $fixture = $this->legacyTerminalConflictFixture('normalize-'.$terminalStatus, $terminalStatus);
            $this->seedLegacyFinishedConflict($fixture['authority_id'], $fixture['provider_payment_id'], 'normalize-'.$terminalStatus);
        }

        $this->migration()->up();

        foreach (['failed', 'expired'] as $terminalStatus) {
            $fixture = $this->fixtureBySuffix['normalize-'.$terminalStatus];
            self::assertSame(
                'manual_review',
                DB::table('nowpayments_payment_authorities')->where('id', $fixture['authority_id'])->value('state'),
            );
            self::assertSame(
                'finished',
                DB::table('nowpayments_payment_authorities')->where('id', $fixture['authority_id'])->value('provider_status'),
            );
            self::assertSame(
                'pending_manual_review',
                DB::table('payment_intents')->where('id', $fixture['payment_intent_id'])->value('state'),
            );
            self::assertSame(
                1,
                DB::table('payment_intent_state_histories')
                    ->where('payment_intent_id', $fixture['payment_intent_id'])
                    ->where('from_state', $terminalStatus)
                    ->where('to_state', 'pending_manual_review')
                    ->where('reason_code', 'nowpayments_legacy_terminal_finished_conflict')
                    ->count(),
            );

            $zarinpalDecision = $this->zarinpalDecision(
                $fixture['user_id'],
                $fixture['quote_public_id'],
                $fixture['quote_configuration_hash'],
                'normalize-'.$terminalStatus,
            );
            try {
                $this->app->make(PurchasePaymentIntentService::class)->create(
                    'legacy-upgrade-zarinpal-fresh-'.$terminalStatus,
                    $fixture['user_id'],
                    $fixture['quote_public_id'],
                    $zarinpalDecision,
                    'zarinpal',
                    $this->correlation('legacy-upgrade-zarinpal-fresh-'.$terminalStatus),
                );
                self::fail('Migrated legacy conflict must immediately block a fresh competing purchase PaymentIntent.');
            } catch (DomainException $exception) {
                self::assertSame(
                    'Purchase payment is locked by unresolved payment reconciliation.',
                    $exception->getMessage(),
                );
            }
        }

        try {
            $this->migration()->down();
            self::fail('Normalized terminal-conflict evidence must prevent semantic rollback.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Cannot roll back terminal NOWPayments conflict hardening while terminal-conflict reconciliation evidence exists.',
                $exception->getMessage(),
            );
        }
    }

    public function test_upgrade_keeps_preexisting_competing_intent_manual_and_blocks_exact_replay(): void
    {
        $this->migration()->down();
        $fixture = $this->legacyTerminalConflictFixture('preexisting-competitor', 'failed');
        $zarinpalDecision = $this->zarinpalDecision(
            $fixture['user_id'],
            $fixture['quote_public_id'],
            $fixture['quote_configuration_hash'],
            'preexisting-competitor',
        );
        $creationKey = 'legacy-upgrade-zarinpal-preexisting';
        $competing = $this->app->make(PurchasePaymentIntentService::class)->create(
            $creationKey,
            $fixture['user_id'],
            $fixture['quote_public_id'],
            $zarinpalDecision,
            'zarinpal',
            $this->correlation('legacy-upgrade-zarinpal-preexisting'),
        );
        self::assertSame('awaiting_user_action', $competing->state->value);

        $this->seedLegacyFinishedConflict(
            $fixture['authority_id'],
            $fixture['provider_payment_id'],
            'preexisting-competitor',
        );
        $this->migration()->up();

        $competingIntentId = DB::table('payment_intents')
            ->where('public_id', $competing->intentPublicId)
            ->value('id');
        self::assertNotNull($competingIntentId);
        $providerMutationCalled = false;
        try {
            $this->app->make(PurchaseProviderMutationBarrier::class)->runForPaymentIntent(
                (int) $competingIntentId,
                'test:stale-competing-provider-claim',
                function (PurchaseProviderMutationAttempt $attempt) use (&$providerMutationCalled): void {
                    $providerMutationCalled = true;
                },
            );
            self::fail('A stale competing provider claim must not cross normalized reconciliation after migration.');
        } catch (DomainException $exception) {
            self::assertSame(
                'Purchase payment is locked by unresolved payment reconciliation.',
                $exception->getMessage(),
            );
        }
        self::assertFalse($providerMutationCalled);

        try {
            $this->app->make(PurchasePaymentIntentService::class)->create(
                $creationKey,
                $fixture['user_id'],
                $fixture['quote_public_id'],
                $zarinpalDecision,
                'zarinpal',
                $this->correlation('legacy-upgrade-zarinpal-preexisting-replay'),
            );
            self::fail('Exact competing PaymentIntent replay must be blocked after legacy conflict normalization.');
        } catch (DomainException $exception) {
            self::assertSame(
                'Purchase payment is locked by unresolved payment reconciliation.',
                $exception->getMessage(),
            );
        }

        $this->transport->statusValue = 'finished';
        $this->transport->actuallyPaid = $this->transport->payAmount;
        $stillManual = $this->service()->refresh(
            $fixture['payment_intent_public_id'],
            $this->correlation('legacy-upgrade-preexisting-refresh'),
        );
        self::assertSame(NowPaymentsAuthorityState::ManualReview, $stillManual->state);
        self::assertNull($stillManual->settlementPublicId);
        self::assertSame(1, $this->transport->createCalls);
        self::assertSame(0, DB::table('purchase_settlements')->where('payment_intent_id', $fixture['payment_intent_id'])->count());
        self::assertSame('awaiting_payment', DB::table('orders')->where('public_id', $fixture['order_public_id'])->value('state'));
    }

    public function test_upgrade_materializes_legacy_contradictory_history_and_keeps_later_finished_manual(): void
    {
        $this->migration()->down();
        $fixture = $this->legacyTerminalConflictFixture('sticky-history', 'failed');
        $finished = $this->seedLegacyFinishedConflict(
            $fixture['authority_id'],
            $fixture['provider_payment_id'],
            'sticky-history',
        );
        $this->clock->value = $this->clock->value->modify('+1 second');
        DB::statement('SET timestamp = '.$this->clock->value->getTimestamp());
        $contradictoryHash = hash('sha256', 'legacy-terminal-conflict-contradictory-status');
        DB::table('nowpayments_payment_observations')->insert([
            'nowpayments_payment_authority_id' => $fixture['authority_id'],
            'event_key' => 'nowpayments:'.$fixture['authority_id'].':status_lookup:'.substr($contradictoryHash, 0, 32),
            'event_type' => 'status_lookup',
            'provider_payment_id' => $fixture['provider_payment_id'],
            'provider_status' => 'expired',
            'response_hash' => $contradictoryHash,
            'occurred_at' => $this->timestamp(),
            'correlation_id' => $this->correlation('legacy-terminal-conflict-contradictory-status'),
            'created_at' => $this->timestamp(),
        ]);
        DB::table('nowpayments_payment_authorities')
            ->where('id', $fixture['authority_id'])
            ->update([
                'provider_status' => 'expired',
                'last_status_at' => $this->timestamp(),
                'updated_at' => $this->timestamp(),
            ]);
        self::assertGreaterThan(
            $finished['observation_id'],
            (int) DB::table('nowpayments_payment_observations')->where('response_hash', $contradictoryHash)->value('id'),
        );

        $this->migration()->up();

        self::assertSame(
            'manual_review',
            DB::table('nowpayments_payment_authorities')->where('id', $fixture['authority_id'])->value('state'),
        );
        self::assertSame(
            'finished',
            DB::table('nowpayments_payment_authorities')->where('id', $fixture['authority_id'])->value('provider_status'),
        );
        self::assertSame(
            1,
            DB::table('nowpayments_reconciliation_findings')
                ->where('nowpayments_payment_authority_id', $fixture['authority_id'])
                ->where('code', 'terminal_finished_conflict_status_changed')
                ->where('provider_status', 'expired')
                ->where('evidence_hash', $contradictoryHash)
                ->count(),
        );

        $this->transport->statusValue = 'finished';
        $this->transport->actuallyPaid = $this->transport->payAmount;
        $stillManual = $this->service()->refresh(
            $fixture['payment_intent_public_id'],
            $this->correlation('legacy-upgrade-sticky-finished-again'),
        );
        self::assertSame(NowPaymentsAuthorityState::ManualReview, $stillManual->state);
        self::assertNull($stillManual->settlementPublicId);
        self::assertSame(0, DB::table('purchase_settlements')->where('payment_intent_id', $fixture['payment_intent_id'])->count());

        try {
            DB::table('nowpayments_payment_authorities')
                ->where('id', $fixture['authority_id'])
                ->update(['state' => 'finished']);
            self::fail('Migrated contradictory history must independently block direct automatic completion.');
        } catch (QueryException) {
            // Expected: database guard keeps the historical ambiguity manual.
        }
    }

    public function test_upgrade_clean_legacy_conflict_can_reconcile_same_authority_exactly_once(): void
    {
        $this->migration()->down();
        $fixture = $this->legacyTerminalConflictFixture('clean-reconcile', 'expired');
        $this->seedLegacyFinishedConflict(
            $fixture['authority_id'],
            $fixture['provider_payment_id'],
            'clean-reconcile',
        );

        $this->migration()->up();

        $this->transport->statusValue = 'finished';
        $this->transport->actuallyPaid = $this->transport->payAmount;
        $settled = $this->service()->refresh(
            $fixture['payment_intent_public_id'],
            $this->correlation('legacy-upgrade-clean-reconcile'),
        );
        self::assertSame(NowPaymentsAuthorityState::Finished, $settled->state);
        self::assertNotNull($settled->settlementPublicId);
        self::assertSame(1, $this->transport->createCalls);
        self::assertSame(1, DB::table('purchase_settlements')->where('payment_intent_id', $fixture['payment_intent_id'])->count());
        self::assertSame('paid', DB::table('orders')->where('public_id', $fixture['order_public_id'])->value('state'));

        $replay = $this->service()->refresh(
            $fixture['payment_intent_public_id'],
            $this->correlation('legacy-upgrade-clean-reconcile-replay'),
        );
        self::assertSame($settled->settlementPublicId, $replay->settlementPublicId);
        self::assertSame(1, $this->transport->createCalls);
        self::assertSame(1, DB::table('purchase_settlements')->where('payment_intent_id', $fixture['payment_intent_id'])->count());
        self::assertSame(1, DB::table('orders')->where('public_id', $fixture['order_public_id'])->count());
    }

    public function test_upgrade_rejects_ambiguous_legacy_conflict_before_replacing_guards(): void
    {
        $this->migration()->down();
        $fixture = $this->legacyTerminalConflictFixture('ambiguous-evidence', 'failed');
        $evidenceHash = hash('sha256', 'legacy-terminal-conflict-missing-observation');
        DB::table('nowpayments_reconciliation_findings')->insert([
            'nowpayments_payment_authority_id' => $fixture['authority_id'],
            'finding_key' => 'nowpayments:'.$fixture['authority_id']
                .':terminal_local_state_conflicts_with_finished_provider:'.substr($evidenceHash, 0, 32),
            'code' => 'terminal_local_state_conflicts_with_finished_provider',
            'severity' => 'critical',
            'provider_status' => 'finished',
            'evidence_hash' => $evidenceHash,
            'detected_at' => $this->timestamp(),
            'correlation_id' => $this->correlation('legacy-terminal-conflict-missing-observation'),
            'created_at' => $this->timestamp(),
        ]);

        try {
            $this->migration()->up();
            self::fail('Ambiguous legacy provider-finished conflict must abort before guard replacement.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Legacy NOWPayments terminal conflict lacks its exact finished status observation.',
                $exception->getMessage(),
            );
        }

        $authorityGuard = $this->triggerAction('nowpayments_authority_update_guard');
        $intentInsertGuard = $this->triggerAction('payment_intents_insert_guard');
        self::assertStringNotContainsString('finished_status_observation_count', $authorityGuard);
        self::assertStringNotContainsString('unresolved_purchase_manual_review_count', $intentInsertGuard);
        self::assertSame('failed', DB::table('nowpayments_payment_authorities')->where('id', $fixture['authority_id'])->value('state'));
        self::assertSame('failed', DB::table('payment_intents')->where('id', $fixture['payment_intent_id'])->value('state'));
        $this->assertUpgradeFenceActive();
    }

    public function test_provider_mutation_attempt_authority_reenters_after_partial_ddl(): void
    {
        $conflictMigration = $this->migration();
        $conflictMigration->down();
        $attemptMigration = $this->providerAttemptMigration();
        $attemptMigration->down();

        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            $sql = strtolower(preg_replace('/\\s+/', ' ', $query->sql) ?? $query->sql);
            if ($injected
                || ! str_contains($sql, 'alter table purchase_provider_mutation_attempts')
                || ! str_contains($sql, 'add constraint')
                || ! str_contains($sql, 'purchase_provider_mutation_slot_chk')) {
                return;
            }

            $injected = true;
            throw new RuntimeException('Injected after first provider-mutation attempt constraint.');
        });

        try {
            $attemptMigration->up();
            self::fail('Partial provider-mutation attempt DDL must interrupt the first apply.');
        } catch (RuntimeException $exception) {
            self::assertTrue($injected);
            self::assertSame(
                'Injected after first provider-mutation attempt constraint.',
                $exception->getMessage(),
            );
        }

        self::assertTrue(DB::connection()->getSchemaBuilder()->hasTable('purchase_provider_mutation_attempts'));
        self::assertSame(
            1,
            DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_NAME', 'purchase_provider_mutation_attempts')
                ->where('CONSTRAINT_NAME', 'purchase_provider_mutation_slot_chk')
                ->count(),
        );
        self::assertFalse($this->triggerExists('purchase_provider_mutation_attempts_update_guard'));

        // Re-enter the same migration after MariaDB committed the first DDL.
        // Existing constraints are reused and triggers are composed idempotently.
        $attemptMigration->up();

        foreach ([
            'purchase_provider_mutation_slot_chk',
            'purchase_provider_mutation_state_chk',
            'purchase_provider_mutation_timestamps_chk',
        ] as $constraint) {
            self::assertSame(
                1,
                DB::table('information_schema.TABLE_CONSTRAINTS')
                    ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
                    ->where('TABLE_NAME', 'purchase_provider_mutation_attempts')
                    ->where('CONSTRAINT_NAME', $constraint)
                    ->count(),
                'Provider mutation attempt constraint did not converge exactly once: '.$constraint,
            );
        }
        self::assertTrue($this->triggerExists('purchase_provider_mutation_attempts_update_guard'));
        self::assertTrue($this->triggerExists('purchase_provider_mutation_attempts_delete_guard'));

        $conflictMigration->up();
        $this->assertUpgradeFencesAbsent();
    }

    public function test_interrupted_upgrade_fence_installation_never_crosses_legacy_preflight_and_reenters(): void
    {
        $migration = $this->migration();
        $migration->down();
        $fixture = $this->legacyTerminalConflictFixture('partial-fence-install', 'failed');
        $this->seedLegacyFinishedConflict(
            $fixture['authority_id'],
            $fixture['provider_payment_id'],
            'partial-fence-install',
        );

        $injected = false;
        $needle = 'create or replace trigger nowpayments_observation_np_conflict_upgrade_insert_fence';
        DB::listen(function (QueryExecuted $query) use (&$injected, $needle): void {
            if ($injected || ! str_contains(strtolower($query->sql), $needle)) {
                return;
            }

            $injected = true;
            throw new RuntimeException('Injected partial NOWPayments upgrade-fence installation.');
        });

        try {
            $migration->up();
            self::fail('Fence-installation failure injection must interrupt migration before preflight.');
        } catch (RuntimeException $exception) {
            self::assertTrue($injected);
            self::assertSame('Injected partial NOWPayments upgrade-fence installation.', $exception->getMessage());
        }

        self::assertTrue(
            DB::connection()->getSchemaBuilder()->hasTable('nowpayments_terminal_conflict_upgrade_fence'),
        );
        self::assertSame(
            0,
            DB::table('nowpayments_terminal_conflict_upgrade_fence')
                ->where('id', 1)
                ->where('active', 1)
                ->count(),
            'Partial staged-fence composition must not activate the financial cut before every trigger exists.',
        );
        self::assertTrue($this->triggerExists('payment_intents_np_conflict_upgrade_insert_fence'));
        self::assertTrue($this->triggerExists('nowpayments_observation_np_conflict_upgrade_insert_fence'));
        self::assertFalse($this->triggerExists('zarinpal_request_np_conflict_upgrade_insert_fence'));
        self::assertStringNotContainsString(
            'finished_status_observation_count',
            $this->triggerAction('nowpayments_authority_update_guard'),
        );
        self::assertSame(
            'failed',
            DB::table('nowpayments_payment_authorities')->where('id', $fixture['authority_id'])->value('state'),
        );
        self::assertSame(
            'failed',
            DB::table('payment_intents')->where('id', $fixture['payment_intent_id'])->value('state'),
        );

        $migration->up();

        $this->assertUpgradeFencesAbsent();
        self::assertSame(
            'manual_review',
            DB::table('nowpayments_payment_authorities')->where('id', $fixture['authority_id'])->value('state'),
        );
        self::assertSame(
            'pending_manual_review',
            DB::table('payment_intents')->where('id', $fixture['payment_intent_id'])->value('state'),
        );
    }

    public function test_interrupted_upgrade_fence_blocks_runtime_provider_mutations_before_external_effects(): void
    {
        $migration = $this->migration();
        $migration->down();
        $fixture = $this->legacyTerminalConflictFixture('provider-barrier-active-fence', 'failed');

        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            $sql = strtolower($query->sql);
            if ($injected
                || ! str_contains($sql, 'insert into')
                || ! str_contains($sql, 'nowpayments_terminal_conflict_upgrade_fence')) {
                return;
            }

            $injected = true;
            throw new RuntimeException('Injected active provider-mutation fence.');
        });

        try {
            $migration->up();
            self::fail('Provider-mutation fence interruption must leave the durable migration boundary active.');
        } catch (RuntimeException $exception) {
            self::assertTrue($injected);
            self::assertSame('Injected active provider-mutation fence.', $exception->getMessage());
        }

        self::assertTrue(
            DB::connection()->getSchemaBuilder()->hasTable('nowpayments_terminal_conflict_upgrade_fence'),
        );
        self::assertSame(
            1,
            DB::table('nowpayments_terminal_conflict_upgrade_fence')
                ->where('id', 1)
                ->where('active', 1)
                ->count(),
        );
        $called = false;
        try {
            $this->app->make(PurchaseProviderMutationBarrier::class)->runForPaymentIntent(
                $fixture['payment_intent_id'],
                'test:active-migration-fence',
                function (PurchaseProviderMutationAttempt $attempt) use (&$called): void {
                    $called = true;
                },
            );
            self::fail('Persistent migration fence must reject provider mutation before its external effect callback.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Purchase provider mutation is blocked by an active financial migration.',
                $exception->getMessage(),
            );
        }
        self::assertFalse($called);
        $this->assertFailClosedCutWithSecondConnection($fixture, 'interrupted-provider-drain');
    }

    public function test_active_cut_rejects_post_cut_provider_attempt_entry_and_start_at_database_boundary(): void
    {
        $migration = $this->migration();
        $migration->down();
        $fixture = $this->legacyTerminalConflictFixture('provider-attempt-cut-race', 'failed');

        $pdo = $this->independentPdo();
        $database = config('database.connections.mysql.database');
        self::assertIsString($database);
        $slot = 17;
        $lockName = sprintf(
            'purchase-provider:%s:%03d',
            substr(hash('sha256', $database), 0, 16),
            $slot,
        );
        $lockStatement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lockStatement->execute([$lockName]);
        self::assertSame(1, (int) $lockStatement->fetchColumn());
        $providerSessionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        self::assertGreaterThan(0, $providerSessionId);

        $insert = $pdo->prepare(<<<'SQL'
INSERT INTO purchase_provider_mutation_attempts (
    public_id,
    payment_intent_id,
    payment_intent_public_id,
    provider_code,
    mutation_key,
    provider_session_id,
    slot,
    state,
    prepared_at,
    external_started_at,
    resolved_at,
    updated_at
) VALUES (?, ?, ?, ?, ?, ?, ?, 'prepared', UTC_TIMESTAMP(6), NULL, NULL, UTC_TIMESTAMP(6))
SQL);
        $insert->execute([
            (string) Str::ulid(),
            $fixture['payment_intent_id'],
            $fixture['payment_intent_public_id'],
            'nowpayments',
            'test:prepared-before-financial-cut',
            $providerSessionId,
            $slot,
        ]);
        $preparedAttemptId = (int) $pdo->lastInsertId();
        self::assertGreaterThan(0, $preparedAttemptId);

        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            $sql = strtolower($query->sql);
            if ($injected
                || ! str_contains($sql, 'insert into')
                || ! str_contains($sql, 'nowpayments_terminal_conflict_upgrade_fence')) {
                return;
            }

            $injected = true;
            throw new RuntimeException('Injected active provider-attempt cut.');
        });

        try {
            $migration->up();
            self::fail('Provider-attempt cut injection must interrupt after marker activation.');
        } catch (RuntimeException $exception) {
            self::assertTrue($injected);
            self::assertSame('Injected active provider-attempt cut.', $exception->getMessage());
        }
        $this->assertUpgradeFenceActive();

        try {
            $pdo->exec(sprintf(
                "UPDATE purchase_provider_mutation_attempts
                 SET state = 'external_started',
                     external_started_at = UTC_TIMESTAMP(6),
                     updated_at = UTC_TIMESTAMP(6)
                 WHERE id = %d",
                $preparedAttemptId,
            ));
            self::fail('A prepared pre-cut attempt must not cross the external-effect boundary after marker activation.');
        } catch (PDOException $exception) {
            self::assertStringContainsString(
                'Prepared provider mutation cannot cross the external-effect boundary after migration cutover.',
                $exception->getMessage(),
            );
        }
        self::assertSame(
            'prepared',
            DB::table('purchase_provider_mutation_attempts')->where('id', $preparedAttemptId)->value('state'),
        );

        try {
            $insert->execute([
                (string) Str::ulid(),
                $fixture['payment_intent_id'],
                $fixture['payment_intent_public_id'],
                'nowpayments',
                'test:new-after-financial-cut',
                $providerSessionId,
                $slot,
            ]);
            self::fail('A fresh provider mutation attempt must not be admitted after marker activation.');
        } catch (PDOException $exception) {
            self::assertStringContainsString(
                'New provider mutation attempts are fenced during NOWPayments conflict migration.',
                $exception->getMessage(),
            );
        }

        // A prepared attempt never crossed the provider-effect boundary, so aborting
        // it remains safe and lets the interrupted migration re-enter normally.
        $aborted = $pdo->exec(sprintf(
            "UPDATE purchase_provider_mutation_attempts
             SET state = 'aborted',
                 resolved_at = UTC_TIMESTAMP(6),
                 updated_at = UTC_TIMESTAMP(6)
             WHERE id = %d AND state = 'prepared'",
            $preparedAttemptId,
        ));
        self::assertSame(1, $aborted);
        self::assertSame(
            1,
            $pdo->exec(sprintf(
                "DELETE FROM purchase_provider_mutation_attempts WHERE id = %d AND state = 'aborted'",
                $preparedAttemptId,
            )),
        );

        $releaseStatement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $releaseStatement->execute([$lockName]);
        self::assertSame(1, (int) $releaseStatement->fetchColumn());

        $migration->up();
        $this->assertUpgradeFencesAbsent();
    }

    public function test_inflight_provider_mutation_is_drained_before_migration_preflight(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the NOWPayments provider-mutation migration regression.');
        }

        $migration = $this->migration();
        $migration->down();
        $fixture = $this->legacyTerminalConflictFixture('provider-barrier-inflight', 'failed');
        $this->seedLegacyFinishedConflict(
            $fixture['authority_id'],
            $fixture['provider_payment_id'],
            'provider-barrier-inflight',
        );
        $walletFixture = $this->walletCutFixture('provider-barrier-local-cut');
        $ledgerCountBefore = DB::table('ledger_transactions')->count();

        $ready = sys_get_temp_dir().'/nowpayments-provider-barrier-ready-'.bin2hex(random_bytes(8));
        $fenceActive = $ready.'.fence';
        $localWriterReady = $ready.'.local-writer-ready';
        $localWriterDone = $ready.'.local-writer';
        $pid = null;
        $writerPid = null;

        try {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    $writerConfiguration = config('database.connections.mysql');
                    if (! is_array($writerConfiguration)) {
                        throw new RuntimeException('Provider-mutation writer database configuration is unavailable.');
                    }
                    config()->set('database.connections.np_provider_mutation_writer', $writerConfiguration);
                    DB::setDefaultConnection('np_provider_mutation_writer');

                    $this->app->make(PurchaseProviderMutationBarrier::class)->runForPaymentIntent(
                        $fixture['payment_intent_id'],
                        'test:inflight-provider-drain',
                        function (PurchaseProviderMutationAttempt $attempt) use ($ready, $fenceActive, $localWriterDone, $fixture): void {
                            $attempt->markExternalEffectStarted();
                            file_put_contents($ready, 'ready');
                            $deadline = microtime(true) + 10.0;
                            while (! file_exists($fenceActive)) {
                                if (microtime(true) >= $deadline) {
                                    throw new RuntimeException('Timed out waiting for the durable migration fence.');
                                }
                                usleep(1000);
                            }

                            // Keep the provider mutation in flight after the migration
                            // has activated its durable fence until an unrelated writer
                            // proves that fresh local financial effects are rejected in
                            // this exact provider-drain window.
                            $deadline = microtime(true) + 10.0;
                            while (! file_exists($localWriterDone)) {
                                if (microtime(true) >= $deadline) {
                                    throw new RuntimeException('Timed out waiting for the local financial cut regression.');
                                }
                                usleep(1000);
                            }
                            $writerState = trim((string) file_get_contents($localWriterDone));
                            if ($writerState !== 'blocked:3') {
                                throw new RuntimeException('Local financial writer escaped the active migration cut: '.$writerState);
                            }

                            $hash = hash('sha256', 'np-provider-barrier-inflight-expired');
                            DB::table('nowpayments_payment_observations')->insert([
                                'nowpayments_payment_authority_id' => $fixture['authority_id'],
                                'event_key' => 'nowpayments:'.$fixture['authority_id'].':status_lookup:'.substr($hash, 0, 32),
                                'event_type' => 'status_lookup',
                                'provider_payment_id' => $fixture['provider_payment_id'],
                                'provider_status' => 'expired',
                                'response_hash' => $hash,
                                'occurred_at' => $this->timestamp(),
                                'correlation_id' => $this->correlation('provider-barrier-inflight-expired'),
                                'created_at' => $this->timestamp(),
                            ]);
                        },
                    );

                    pcntl_exec('/bin/true');
                    file_put_contents($ready, 'error|pcntl_exec');
                    exit(1);
                } catch (Throwable $exception) {
                    file_put_contents($ready, 'error|'.$exception::class.'|'.$exception->getMessage());
                    pcntl_exec('/bin/false');
                    exit(1);
                }
            }

            $deadline = microtime(true) + 10.0;
            while (! file_exists($ready)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Timed out waiting for the in-flight provider mutation barrier.');
                }
                usleep(1000);
            }
            $state = trim((string) file_get_contents($ready));
            if ($state !== 'ready') {
                throw new RuntimeException('In-flight provider mutation failed before migration: '.$state);
            }

            $writerPid = pcntl_fork();
            self::assertNotSame(-1, $writerPid);
            if ($writerPid === 0) {
                try {
                    $writerConfiguration = config('database.connections.mysql');
                    if (! is_array($writerConfiguration)) {
                        throw new RuntimeException('Local financial writer database configuration is unavailable.');
                    }
                    config()->set('database.connections.np_local_financial_writer', $writerConfiguration);
                    DB::setDefaultConnection('np_local_financial_writer');

                    $blocked = 0;
                    $pdo = $this->independentPdo();
                    $pdo->beginTransaction();
                    // Establish a REPEATABLE READ snapshot before marker activation.
                    // The staged trigger must still observe the later cut as a current
                    // read rather than trusting this transaction's older snapshot.
                    $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
                    file_put_contents($localWriterReady, 'ready');

                    $deadline = microtime(true) + 10.0;
                    while (! file_exists($fenceActive)) {
                        if (microtime(true) >= $deadline) {
                            throw new RuntimeException('Timed out waiting for the local financial migration cut.');
                        }
                        usleep(1000);
                    }

                    try {
                        $this->clonePaymentIntentViaPdo($pdo, $fixture['payment_intent_id'], 'provider-drain-local-writer');
                    } catch (PDOException $exception) {
                        if (str_contains($exception->getMessage(), 'Purchase payment creation is fenced')) {
                            $blocked++;
                        }
                    }
                    try {
                        $this->insertNowPaymentsObservationViaPdo(
                            $pdo,
                            $fixture['authority_id'],
                            $fixture['provider_payment_id'],
                            'expired',
                            'provider-drain-local-writer',
                        );
                    } catch (PDOException $exception) {
                        if (str_contains($exception->getMessage(), 'NOWPayments status evidence is fenced')) {
                            $blocked++;
                        }
                    }
                    try {
                        $this->app->make(PurchaseWalletPaymentService::class)->capture(
                            $walletFixture['intent_public_id'],
                            $this->correlation('provider-drain-wallet-capture'),
                        );
                    } catch (Throwable) {
                        $blocked++;
                    }

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    file_put_contents($localWriterDone, 'blocked:'.$blocked);
                    pcntl_exec($blocked === 3 ? '/bin/true' : '/bin/false');
                    exit($blocked === 3 ? 0 : 1);
                } catch (Throwable $exception) {
                    file_put_contents($localWriterDone, 'error|'.$exception::class.'|'.$exception->getMessage());
                    pcntl_exec('/bin/false');
                    exit(1);
                }
            }

            $deadline = microtime(true) + 10.0;
            while (! file_exists($localWriterReady)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Timed out waiting for the pre-cut local-writer snapshot.');
                }
                usleep(1000);
            }
            self::assertSame('ready', trim((string) file_get_contents($localWriterReady)));

            $fenceSignaled = false;
            DB::listen(function (QueryExecuted $query) use (&$fenceSignaled, $fenceActive): void {
                $sql = strtolower($query->sql);
                if ($fenceSignaled
                    || ! str_contains($sql, 'insert into')
                    || ! str_contains($sql, 'nowpayments_terminal_conflict_upgrade_fence')) {
                    return;
                }

                $fenceSignaled = true;
                file_put_contents($fenceActive, 'active');
            });

            $migration->up();

            self::assertTrue($fenceSignaled);
            self::assertIsInt($pid);
            pcntl_waitpid($pid, $status);
            $pid = null;
            self::assertSame(0, pcntl_wexitstatus($status));
            self::assertIsInt($writerPid);
            pcntl_waitpid($writerPid, $writerStatus);
            $writerPid = null;
            self::assertSame(0, pcntl_wexitstatus($writerStatus));
            self::assertSame('blocked:3', trim((string) file_get_contents($localWriterDone)));
            self::assertSame(
                $ledgerCountBefore,
                DB::table('ledger_transactions')->count(),
                'Wallet capture must not post a ledger effect while migration drains an entered provider mutation.',
            );
            self::assertSame(
                'active',
                DB::table('wallet_holds')->where('source_id', $walletFixture['intent_public_id'])->value('status'),
            );
            self::assertSame(
                0,
                DB::table('purchase_settlements')->where('payment_intent_id', $walletFixture['intent_id'])->count(),
            );
            self::assertNotSame(
                'paid',
                DB::table('orders')->where('id', $walletFixture['order_id'])->value('state'),
            );

            $lateHash = hash('sha256', 'np-provider-barrier-inflight-expired');
            self::assertSame(
                1,
                DB::table('nowpayments_reconciliation_findings')
                    ->where('nowpayments_payment_authority_id', $fixture['authority_id'])
                    ->where('code', 'terminal_finished_conflict_status_changed')
                    ->where('severity', 'critical')
                    ->where('provider_status', 'expired')
                    ->where('evidence_hash', $lateHash)
                    ->count(),
            );
            self::assertSame(
                'manual_review',
                DB::table('nowpayments_payment_authorities')->where('id', $fixture['authority_id'])->value('state'),
            );
            self::assertSame(
                'pending_manual_review',
                DB::table('payment_intents')->where('id', $fixture['payment_intent_id'])->value('state'),
            );
        } finally {
            if (is_int($pid) && $pid > 0) {
                pcntl_waitpid($pid, $status);
            }
            if (is_int($writerPid) && $writerPid > 0) {
                pcntl_waitpid($writerPid, $writerStatus);
            }
            @unlink($ready);
            @unlink($fenceActive);
            @unlink($localWriterReady);
            @unlink($localWriterDone);
        }
    }

    public function test_database_session_loss_keeps_external_provider_mutation_as_durable_migration_blocker(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the provider-session-loss migration regression.');
        }

        $migration = $this->migration();
        $migration->down();
        $fixture = $this->legacyTerminalConflictFixture('provider-session-loss', 'failed');
        $this->seedLegacyFinishedConflict(
            $fixture['authority_id'],
            $fixture['provider_payment_id'],
            'provider-session-loss',
        );

        $ready = sys_get_temp_dir().'/np-provider-session-loss-ready-'.bin2hex(random_bytes(8));
        $release = $ready.'.release';
        $externalEffect = $ready.'.external-effect';
        $childState = $ready.'.child-state';
        $pid = null;

        try {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    $writerConfiguration = config('database.connections.mysql');
                    if (! is_array($writerConfiguration)) {
                        throw new RuntimeException('Session-loss provider database configuration is unavailable.');
                    }
                    config()->set('database.connections.np_provider_session_loss_writer', $writerConfiguration);
                    DB::setDefaultConnection('np_provider_session_loss_writer');

                    try {
                        $this->app->make(PurchaseProviderMutationBarrier::class)->runForPaymentIntent(
                            $fixture['payment_intent_id'],
                            'test:provider-session-loss',
                            function (PurchaseProviderMutationAttempt $attempt) use (
                                $ready,
                                $release,
                                $externalEffect,
                                $fixture,
                            ): void {
                                $attempt->markExternalEffectStarted();
                                $session = DB::selectOne('SELECT CONNECTION_ID() AS connection_id');
                                $connectionId = (int) ($session->connection_id ?? 0);
                                if ($connectionId < 1) {
                                    throw new RuntimeException('Session-loss provider connection ID is unavailable.');
                                }
                                file_put_contents($ready, 'ready:'.$connectionId);

                                $deadline = microtime(true) + 10.0;
                                while (! file_exists($release)) {
                                    if (microtime(true) >= $deadline) {
                                        throw new RuntimeException('Timed out waiting to release the simulated provider effect.');
                                    }
                                    usleep(1000);
                                }

                                // Model a provider-side success that returns only after
                                // MariaDB has already terminated this application's
                                // named-lock session.
                                file_put_contents($externalEffect, 'provider-success');
                                $hash = hash('sha256', 'provider-session-loss-late-success');
                                DB::table('nowpayments_payment_observations')->insert([
                                    'nowpayments_payment_authority_id' => $fixture['authority_id'],
                                    'event_key' => 'nowpayments:'.$fixture['authority_id'].':status_lookup:'.substr($hash, 0, 32),
                                    'event_type' => 'status_lookup',
                                    'provider_payment_id' => $fixture['provider_payment_id'],
                                    'provider_status' => 'finished',
                                    'response_hash' => $hash,
                                    'occurred_at' => $this->timestamp(),
                                    'correlation_id' => $this->correlation('provider-session-loss-late-success'),
                                    'created_at' => $this->timestamp(),
                                ]);
                            },
                        );
                        throw new RuntimeException('Killed provider session unexpectedly converged without reconciliation.');
                    } catch (Throwable) {
                        $pdo = $this->independentPdo();
                        $statement = $pdo->prepare(
                            'SELECT state FROM purchase_provider_mutation_attempts
                             WHERE payment_intent_id = ? AND payment_intent_public_id = ?
                             ORDER BY id DESC LIMIT 1',
                        );
                        $statement->execute([
                            $fixture['payment_intent_id'],
                            $fixture['payment_intent_public_id'],
                        ]);
                        $state = $statement->fetchColumn();
                        file_put_contents($childState, is_string($state) ? $state : 'missing');
                        pcntl_exec($state === 'reconciliation_required' ? '/bin/true' : '/bin/false');
                        exit($state === 'reconciliation_required' ? 0 : 1);
                    }
                } catch (Throwable $exception) {
                    file_put_contents($childState, 'error|'.$exception::class.'|'.$exception->getMessage());
                    pcntl_exec('/bin/false');
                    exit(1);
                }
            }

            $deadline = microtime(true) + 10.0;
            while (! file_exists($ready)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Timed out waiting for the provider session-loss seam.');
                }
                usleep(1000);
            }
            $readyState = trim((string) file_get_contents($ready));
            self::assertStringStartsWith('ready:', $readyState);
            $connectionId = (int) substr($readyState, strlen('ready:'));
            self::assertGreaterThan(0, $connectionId);

            $attempt = DB::table('purchase_provider_mutation_attempts')
                ->where('payment_intent_id', $fixture['payment_intent_id'])
                ->where('payment_intent_public_id', $fixture['payment_intent_public_id'])
                ->where('state', 'external_started')
                ->first(['provider_session_id', 'slot']);
            self::assertNotNull($attempt);
            self::assertSame($connectionId, (int) $attempt->provider_session_id);

            $pdo = $this->independentPdo();
            $pdo->exec('KILL '.$connectionId);
            $database = config('database.connections.mysql.database');
            self::assertIsString($database);
            $lockName = sprintf(
                'purchase-provider:%s:%03d',
                substr(hash('sha256', $database), 0, 16),
                (int) $attempt->slot,
            );
            $lockReleased = false;
            $deadline = microtime(true) + 5.0;
            $lockStatement = $pdo->prepare('SELECT IS_USED_LOCK(?)');
            while (microtime(true) < $deadline) {
                $lockStatement->execute([$lockName]);
                if ($lockStatement->fetchColumn() === null) {
                    $lockReleased = true;
                    break;
                }
                usleep(1000);
            }
            self::assertTrue($lockReleased, 'MariaDB must release the named slot after the provider session is killed.');

            $retryCalled = false;
            try {
                $this->app->make(PurchaseProviderMutationBarrier::class)->runForPaymentIntent(
                    $fixture['payment_intent_id'],
                    'test:provider-session-loss-retry',
                    function (PurchaseProviderMutationAttempt $attempt) use (&$retryCalled): void {
                        $retryCalled = true;
                    },
                );
                self::fail('A lost-session external mutation must block a fresh provider retry.');
            } catch (DomainException $exception) {
                self::assertSame(
                    'Purchase provider mutation is locked by unresolved provider reconciliation.',
                    $exception->getMessage(),
                );
            }
            self::assertFalse($retryCalled);

            try {
                $migration->up();
                self::fail('Migration must not cross preflight after a provider session releases its named lock mid-effect.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString(
                    'Financial migration is blocked by unresolved provider mutation attempt',
                    $exception->getMessage(),
                );
            }
            $this->assertUpgradeFenceActive();
            self::assertSame(
                'failed',
                DB::table('nowpayments_payment_authorities')->where('id', $fixture['authority_id'])->value('state'),
            );
            self::assertSame(
                'failed',
                DB::table('payment_intents')->where('id', $fixture['payment_intent_id'])->value('state'),
            );
            self::assertSame(
                0,
                DB::table('purchase_settlements')->where('payment_intent_id', $fixture['payment_intent_id'])->count(),
            );

            file_put_contents($release, 'release');
            self::assertIsInt($pid);
            pcntl_waitpid($pid, $status);
            $pid = null;
            self::assertSame(0, pcntl_wexitstatus($status));
            self::assertSame('provider-success', trim((string) file_get_contents($externalEffect)));
            self::assertSame('reconciliation_required', trim((string) file_get_contents($childState)));
            self::assertSame(
                'reconciliation_required',
                DB::table('purchase_provider_mutation_attempts')
                    ->where('payment_intent_id', $fixture['payment_intent_id'])
                    ->where('payment_intent_public_id', $fixture['payment_intent_public_id'])
                    ->value('state'),
            );
            self::assertSame(
                0,
                DB::table('purchase_settlements')->where('payment_intent_id', $fixture['payment_intent_id'])->count(),
            );

            // No synthetic cleanup is performed here. A session-loss mutation must
            // remain a durable reconciliation blocker until a real provider-specific
            // recovery path has established authoritative outcome/local convergence.
            // Test teardown resets the isolated disposable database only.
        } finally {
            if (is_int($pid) && $pid > 0) {
                @file_put_contents($release, 'release');
                pcntl_waitpid($pid, $status);
            }
            @unlink($ready);
            @unlink($release);
            @unlink($externalEffect);
            @unlink($childState);
        }
    }

    public function test_inflight_competing_payment_intent_transaction_is_drained_before_preflight(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the NOWPayments migration in-flight PaymentIntent regression.');
        }

        $migration = $this->migration();
        $migration->down();
        $fixture = $this->legacyTerminalConflictFixture('inflight-intent', 'failed');
        $zarinpalDecision = $this->zarinpalDecision(
            $fixture['user_id'],
            $fixture['quote_public_id'],
            $fixture['quote_configuration_hash'],
            'inflight-intent',
        );
        $this->seedLegacyFinishedConflict(
            $fixture['authority_id'],
            $fixture['provider_payment_id'],
            'inflight-intent',
        );

        $barrier = sys_get_temp_dir().'/nowpayments-upgrade-inflight-intent-'.bin2hex(random_bytes(8));
        $creationKey = 'legacy-upgrade-zarinpal-inflight-intent';
        $pid = null;

        try {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    $writerConfiguration = config('database.connections.mysql');
                    if (! is_array($writerConfiguration)) {
                        throw new RuntimeException('Migration writer database configuration is unavailable.');
                    }
                    config()->set('database.connections.np_migration_writer', $writerConfiguration);
                    DB::setDefaultConnection('np_migration_writer');

                    $paused = false;
                    DB::listen(function (QueryExecuted $query) use (&$paused, $barrier): void {
                        if ($paused
                            || ! str_contains(strtolower($query->sql), 'insert into')
                            || ! str_contains(strtolower($query->sql), 'payment_intents')) {
                            return;
                        }

                        $paused = true;
                        file_put_contents($barrier, 'ready');
                        usleep(250000);
                    });

                    $this->app->make(PurchasePaymentIntentService::class)->create(
                        $creationKey,
                        $fixture['user_id'],
                        $fixture['quote_public_id'],
                        $zarinpalDecision,
                        'zarinpal',
                        $this->correlation('inflight-intent-create'),
                    );
                    if (! $paused) {
                        throw new RuntimeException('In-flight PaymentIntent writer did not cross the expected insert seam.');
                    }

                    pcntl_exec('/bin/true');
                    file_put_contents($barrier, 'error|pcntl_exec');
                    exit(1);
                } catch (Throwable $exception) {
                    file_put_contents($barrier, 'error|'.$exception::class.'|'.$exception->getMessage());
                    pcntl_exec('/bin/false');
                    exit(1);
                }
            }

            $deadline = microtime(true) + 10.0;
            while (! file_exists($barrier)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Timed out waiting for the in-flight competing PaymentIntent transaction.');
                }
                usleep(1000);
            }
            $state = trim((string) file_get_contents($barrier));
            if ($state !== 'ready') {
                throw new RuntimeException('In-flight competing PaymentIntent writer failed before migration: '.$state);
            }

            // The first PaymentIntent fence DDL must wait for this transaction to
            // commit before preflight is allowed to observe the legacy conflict.
            $migration->up();

            self::assertIsInt($pid);
            pcntl_waitpid($pid, $status);
            $pid = null;
            self::assertSame(0, pcntl_wexitstatus($status));

            $competitor = DB::table('payment_intents')
                ->where('creation_key', $creationKey)
                ->first(['id', 'source_quote_id', 'user_id', 'payment_method_code', 'state']);
            self::assertNotNull($competitor);
            self::assertSame('zarinpal', $competitor->payment_method_code);
            self::assertSame(
                'manual_review',
                DB::table('nowpayments_payment_authorities')->where('id', $fixture['authority_id'])->value('state'),
            );
            self::assertSame(
                'pending_manual_review',
                DB::table('payment_intents')->where('id', $fixture['payment_intent_id'])->value('state'),
            );

            $this->transport->statusValue = 'finished';
            $this->transport->actuallyPaid = $this->transport->payAmount;
            $stillManual = $this->service()->refresh(
                $fixture['payment_intent_public_id'],
                $this->correlation('inflight-intent-finished'),
            );
            self::assertSame(NowPaymentsAuthorityState::ManualReview, $stillManual->state);
            self::assertNull($stillManual->settlementPublicId);
            self::assertSame(
                0,
                DB::table('purchase_settlements')
                    ->where('payment_intent_id', $fixture['payment_intent_id'])
                    ->count(),
            );
        } finally {
            if (is_int($pid) && $pid > 0) {
                pcntl_waitpid($pid, $status);
            }
            @unlink($barrier);
        }
    }

    public function test_inflight_legacy_status_transaction_is_drained_before_preflight_and_becomes_sticky(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the NOWPayments migration in-flight writer regression.');
        }

        $migration = $this->migration();
        $migration->down();
        $fixture = $this->legacyTerminalConflictFixture('inflight-status', 'failed');
        $this->seedLegacyFinishedConflict(
            $fixture['authority_id'],
            $fixture['provider_payment_id'],
            'inflight-status',
        );

        $barrier = sys_get_temp_dir().'/nowpayments-upgrade-inflight-'.bin2hex(random_bytes(8));
        $pid = null;

        try {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    $pdo = $this->independentPdo();
                    $pdo->beginTransaction();
                    $this->insertNowPaymentsObservationViaPdo(
                        $pdo,
                        $fixture['authority_id'],
                        $fixture['provider_payment_id'],
                        'expired',
                        'inflight-contradictory',
                    );
                    file_put_contents($barrier, 'ready');
                    usleep(250000);
                    $pdo->commit();

                    // Replace the forked process so inherited Laravel/PDO resources
                    // cannot emit COM_QUIT against the parent's database sessions.
                    pcntl_exec('/bin/true');
                    file_put_contents($barrier, 'error|pcntl_exec');
                    exit(1);
                } catch (Throwable $exception) {
                    file_put_contents($barrier, 'error|'.$exception::class.'|'.$exception->getMessage());
                    pcntl_exec('/bin/false');
                    exit(1);
                }
            }

            $deadline = microtime(true) + 10.0;
            while (! file_exists($barrier)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Timed out waiting for the in-flight NOWPayments status transaction.');
                }
                usleep(1000);
            }
            $state = trim((string) file_get_contents($barrier));
            if ($state !== 'ready') {
                throw new RuntimeException('In-flight NOWPayments status writer failed before migration: '.$state);
            }

            // CREATE OR REPLACE TRIGGER on the observation table must wait for
            // this already-entered transaction to commit before preflight can run.
            $migration->up();

            self::assertIsInt($pid);
            pcntl_waitpid($pid, $status);
            $pid = null;
            self::assertSame(0, pcntl_wexitstatus($status));

            $lateHash = hash('sha256', 'np-upgrade-fence-observation-inflight-contradictory');
            self::assertSame(
                1,
                DB::table('nowpayments_reconciliation_findings')
                    ->where('nowpayments_payment_authority_id', $fixture['authority_id'])
                    ->where('code', 'terminal_finished_conflict_status_changed')
                    ->where('severity', 'critical')
                    ->where('provider_status', 'expired')
                    ->where('evidence_hash', $lateHash)
                    ->count(),
            );
            self::assertSame(
                'manual_review',
                DB::table('nowpayments_payment_authorities')->where('id', $fixture['authority_id'])->value('state'),
            );
            self::assertSame(
                'pending_manual_review',
                DB::table('payment_intents')->where('id', $fixture['payment_intent_id'])->value('state'),
            );

            $this->transport->statusValue = 'finished';
            $this->transport->actuallyPaid = $this->transport->payAmount;
            $stillManual = $this->service()->refresh(
                $fixture['payment_intent_public_id'],
                $this->correlation('inflight-status-finished-again'),
            );
            self::assertSame(NowPaymentsAuthorityState::ManualReview, $stillManual->state);
            self::assertNull($stillManual->settlementPublicId);
            self::assertSame(
                0,
                DB::table('purchase_settlements')
                    ->where('payment_intent_id', $fixture['payment_intent_id'])
                    ->count(),
            );
        } finally {
            if (is_int($pid) && $pid > 0) {
                pcntl_waitpid($pid, $status);
            }
            @unlink($barrier);
        }
    }

    public function test_upgrade_fence_precedes_preflight_and_blocks_second_connection_payment_writers(): void
    {
        $migration = $this->migration();
        $migration->down();

        $fixture = $this->legacyTerminalConflictFixture('preflight-fence', 'failed');
        $zarinpalDecision = $this->zarinpalDecision(
            $fixture['user_id'],
            $fixture['quote_public_id'],
            $fixture['quote_configuration_hash'],
            'preflight-fence',
        );
        $creationKey = 'legacy-upgrade-zarinpal-preflight-fence';
        $competing = $this->app->make(PurchasePaymentIntentService::class)->create(
            $creationKey,
            $fixture['user_id'],
            $fixture['quote_public_id'],
            $zarinpalDecision,
            'zarinpal',
            $this->correlation('legacy-upgrade-zarinpal-preflight-fence'),
        );
        $competingIntentId = DB::table('payment_intents')
            ->where('public_id', $competing->intentPublicId)
            ->value('id');
        self::assertNotNull($competingIntentId);

        $this->seedLegacyFinishedConflict(
            $fixture['authority_id'],
            $fixture['provider_payment_id'],
            'preflight-fence',
        );
        $observationCount = DB::table('nowpayments_payment_observations')
            ->where('nowpayments_payment_authority_id', $fixture['authority_id'])
            ->count();

        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            $sql = strtolower($query->sql);
            if ($injected
                || ! str_contains($sql, 'select distinct')
                || ! str_contains($sql, 'nowpayments_reconciliation_findings')) {
                return;
            }

            $injected = true;
            throw new RuntimeException('Injected after fenced NOWPayments legacy preflight read.');
        });

        try {
            $migration->up();
            self::fail('Preflight cut injector must interrupt the fenced migration.');
        } catch (RuntimeException $exception) {
            self::assertTrue($injected);
            self::assertSame('Injected after fenced NOWPayments legacy preflight read.', $exception->getMessage());
        }

        $this->assertUpgradeFenceActive();
        self::assertStringNotContainsString(
            'finished_status_observation_count',
            $this->triggerAction('nowpayments_authority_update_guard'),
        );

        $replay = $this->app->make(PurchasePaymentIntentService::class)->create(
            $creationKey,
            $fixture['user_id'],
            $fixture['quote_public_id'],
            $zarinpalDecision,
            'zarinpal',
            $this->correlation('legacy-upgrade-zarinpal-preflight-fence-replay'),
        );
        self::assertSame($competing->intentPublicId, $replay->intentPublicId);

        $pdo = $this->independentPdo();
        $this->assertPdoRejected(
            fn () => $this->clonePaymentIntentViaPdo($pdo, (int) $competingIntentId, 'preflight-fresh'),
            'Purchase payment creation is fenced',
        );
        $this->assertPdoRejected(
            fn () => $this->insertZarinpalRequestViaPdo($pdo, (int) $competingIntentId, 'preflight-replay'),
            'Zarinpal purchase authority creation is fenced',
        );
        $this->assertPdoRejected(
            fn () => $this->insertNowPaymentsObservationViaPdo(
                $pdo,
                $fixture['authority_id'],
                $fixture['provider_payment_id'],
                'expired',
                'preflight-late-status',
            ),
            'NOWPayments status evidence is fenced',
        );

        self::assertSame(
            $observationCount,
            DB::table('nowpayments_payment_observations')
                ->where('nowpayments_payment_authority_id', $fixture['authority_id'])
                ->count(),
        );
        self::assertSame(0, DB::table('zarinpal_payment_requests')->where('payment_intent_id', $competingIntentId)->count());

        $migration->up();
        $this->assertUpgradeFencesAbsent();
        self::assertSame(
            'manual_review',
            DB::table('nowpayments_payment_authorities')->where('id', $fixture['authority_id'])->value('state'),
        );
        self::assertSame(
            'pending_manual_review',
            DB::table('payment_intents')->where('id', $fixture['payment_intent_id'])->value('state'),
        );

        $this->transport->statusValue = 'finished';
        $this->transport->actuallyPaid = $this->transport->payAmount;
        $stillManual = $this->service()->refresh(
            $fixture['payment_intent_public_id'],
            $this->correlation('preflight-fence-finished'),
        );
        self::assertSame(NowPaymentsAuthorityState::ManualReview, $stillManual->state);
        self::assertNull($stillManual->settlementPublicId);
        self::assertSame(0, DB::table('purchase_settlements')->where('payment_intent_id', $fixture['payment_intent_id'])->count());
        self::assertSame('awaiting_payment', DB::table('orders')->where('public_id', $fixture['order_public_id'])->value('state'));
    }

    public function test_upgrade_fence_blocks_terminal_promotion_release_until_reentry_completes(): void
    {
        [$userId, $quote, $decision] = $this->discountedNowPaymentsContext('promotion-release-fence');
        $this->transport->providerPaymentId = 'legacy-promo-release-fence';
        $created = $this->service()->initiatePurchase(
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            $this->correlation('promo-release-create'),
        );
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());

        $this->transport->statusValue = 'failed';
        $failed = $this->service()->refresh(
            $created->paymentIntentPublicId,
            $this->correlation('promo-release-failed'),
        );
        self::assertSame(NowPaymentsAuthorityState::Failed, $failed->state);
        $this->clock->value = $this->clock->value->modify('+31 minutes');
        DB::statement('SET timestamp = '.$this->clock->value->getTimestamp());

        $migration = $this->migration();
        $migration->down();
        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            $sql = strtolower($query->sql);
            if ($injected
                || ! str_contains($sql, 'select distinct')
                || ! str_contains($sql, 'nowpayments_reconciliation_findings')) {
                return;
            }

            $injected = true;
            throw new RuntimeException('Injected after complete NOWPayments migration fence composition.');
        });

        try {
            $migration->up();
            self::fail('Promotion release fence regression must interrupt after complete fence composition.');
        } catch (RuntimeException $exception) {
            self::assertTrue($injected);
            self::assertSame(
                'Injected after complete NOWPayments migration fence composition.',
                $exception->getMessage(),
            );
        }

        $this->assertUpgradeFenceActive();
        $fenced = $this->app->make(PurchasePaymentMaintenanceService::class)->run();
        self::assertSame(1, $fenced->promotionReservationsExamined);
        self::assertSame(0, $fenced->releasedPromotionReservations);
        self::assertSame(1, $fenced->failures);
        self::assertSame(0, DB::table('promotion_usage_releases')->count());

        $migration->up();
        $this->assertUpgradeFencesAbsent();

        $released = $this->app->make(PurchasePaymentMaintenanceService::class)->run();
        self::assertSame(1, $released->promotionReservationsExamined);
        self::assertSame(1, $released->releasedPromotionReservations);
        self::assertSame(0, $released->failures);
        self::assertSame(1, DB::table('promotion_usage_releases')->count());
        self::assertSame(0, DB::table('promotion_usage_redemptions')->count());
    }

    #[DataProvider('canonicalTriggerReplacementCuts')]
    public function test_every_canonical_trigger_replacement_cut_remains_fenced_and_reentrant_up_and_down(
        string $direction,
        string $trigger,
        int $index,
    ): void {
        $migration = $this->migration();
        if ($direction === 'up') {
            $migration->down();
        }

        $fixture = $this->legacyTerminalConflictFixture($direction.'-cut-'.$index, 'failed');
        $injected = false;
        $needle = 'create or replace trigger '.$trigger;
        DB::listen(function (QueryExecuted $query) use (&$injected, $needle, $direction): void {
            if ($injected || ! str_contains(strtolower($query->sql), $needle)) {
                return;
            }
            $injected = true;
            $phase = $direction === 'up' ? 'trigger replacement' : 'rollback trigger replacement';
            throw new RuntimeException('Injected committed '.$phase.' cut: '.$needle);
        });

        try {
            $migration->{$direction}();
            self::fail(ucfirst($direction).' migration trigger replacement cut must be injectable.');
        } catch (RuntimeException $exception) {
            self::assertTrue($injected);
            $phase = $direction === 'up' ? 'trigger replacement' : 'rollback trigger replacement';
            self::assertSame('Injected committed '.$phase.' cut: '.$needle, $exception->getMessage());
        }

        $this->assertUpgradeFenceActive();
        $this->assertCanonicalFinancialTriggersPresent();
        $this->assertFailClosedCutWithSecondConnection($fixture, $direction.'-'.$index);

        $migration->{$direction}();
        $this->assertUpgradeFencesAbsent();

        if ($direction === 'down') {
            $migration->up();
            $this->assertUpgradeFencesAbsent();
        }
    }

    /** @return array<string,array{string,string,int}> */
    public static function canonicalTriggerReplacementCuts(): array
    {
        $triggers = [
            'payment_intents_insert_guard',
            'nowpayments_authority_update_guard',
            'payment_intents_update_guard',
        ];
        $cases = [];
        foreach (['up', 'down'] as $direction) {
            foreach ($triggers as $index => $trigger) {
                $cases[$direction.'-'.$trigger] = [$direction, $trigger, $index];
            }
        }

        return $cases;
    }

    public function test_exceptional_reopens_require_one_exact_finished_observation_finding_pair(): void
    {
        $authorityFixture = $this->legacyTerminalConflictFixture('exact-pair-authority', 'failed');
        $authorityObservationHash = hash('sha256', 'exact-pair-authority-observation');
        $authorityFindingHash = hash('sha256', 'exact-pair-authority-finding');
        $this->insertFinishedObservation(
            $authorityFixture['authority_id'],
            $authorityFixture['provider_payment_id'],
            $authorityObservationHash,
            'exact-pair-authority-observation',
        );
        $this->insertTerminalConflictFinding(
            $authorityFixture['authority_id'],
            $authorityFindingHash,
            'exact-pair-authority-finding',
        );

        try {
            DB::table('nowpayments_payment_authorities')
                ->where('id', $authorityFixture['authority_id'])
                ->update([
                    'state' => 'manual_review',
                    'provider_status' => 'finished',
                    'last_status_at' => $this->timestamp(),
                    'updated_at' => $this->timestamp(),
                ]);
            self::fail('Mismatched observation/finding hashes must not authorize authority reopen.');
        } catch (QueryException) {
            self::assertSame(
                'failed',
                DB::table('nowpayments_payment_authorities')->where('id', $authorityFixture['authority_id'])->value('state'),
            );
        }

        $this->insertFinishedObservation(
            $authorityFixture['authority_id'],
            $authorityFixture['provider_payment_id'],
            $authorityFindingHash,
            'exact-pair-authority-matched',
        );
        self::assertSame(
            1,
            DB::table('nowpayments_payment_authorities')
                ->where('id', $authorityFixture['authority_id'])
                ->where('state', 'failed')
                ->update([
                    'state' => 'manual_review',
                    'provider_status' => 'finished',
                    'last_status_at' => $this->timestamp(),
                    'updated_at' => $this->timestamp(),
                ]),
        );

        $intentFixture = $this->legacyTerminalConflictFixture('exact-pair-intent', 'expired');
        $intentObservationHash = hash('sha256', 'exact-pair-intent-observation');
        $intentFindingHash = hash('sha256', 'exact-pair-intent-finding');
        $this->insertFinishedObservation(
            $intentFixture['authority_id'],
            $intentFixture['provider_payment_id'],
            $intentObservationHash,
            'exact-pair-intent-observation',
        );
        $this->insertTerminalConflictFinding(
            $intentFixture['authority_id'],
            $intentFindingHash,
            'exact-pair-intent-finding',
        );

        $this->withDatabaseTriggerDisabled('nowpayments_authority_update_guard', function () use ($intentFixture): void {
            DB::table('nowpayments_payment_authorities')
                ->where('id', $intentFixture['authority_id'])
                ->update([
                    'state' => 'manual_review',
                    'provider_status' => 'finished',
                    'last_status_at' => $this->timestamp(),
                    'updated_at' => $this->timestamp(),
                ]);
        });

        try {
            DB::table('payment_intents')
                ->where('id', $intentFixture['payment_intent_id'])
                ->update([
                    'state' => 'pending_manual_review',
                    'updated_at' => $this->timestamp(),
                ]);
            self::fail('Mismatched observation/finding hashes must not authorize PaymentIntent reopen.');
        } catch (QueryException) {
            self::assertSame(
                'expired',
                DB::table('payment_intents')->where('id', $intentFixture['payment_intent_id'])->value('state'),
            );
        }

        $this->insertFinishedObservation(
            $intentFixture['authority_id'],
            $intentFixture['provider_payment_id'],
            $intentFindingHash,
            'exact-pair-intent-matched',
        );
        self::assertSame(
            1,
            DB::table('payment_intents')
                ->where('id', $intentFixture['payment_intent_id'])
                ->where('state', 'expired')
                ->update([
                    'state' => 'pending_manual_review',
                    'updated_at' => $this->timestamp(),
                ]),
        );
    }

    /** @var array<string,array{
     *     user_id:int,
     *     quote_public_id:string,
     *     quote_configuration_hash:string,
     *     order_public_id:string,
     *     authority_id:int,
     *     provider_payment_id:string,
     *     payment_intent_id:int,
     *     payment_intent_public_id:string
     * }> */
    private array $fixtureBySuffix = [];

    /**
     * @return array{
     *     user_id:int,
     *     quote_public_id:string,
     *     quote_configuration_hash:string,
     *     order_public_id:string,
     *     authority_id:int,
     *     provider_payment_id:string,
     *     payment_intent_id:int,
     *     payment_intent_public_id:string
     * }
     */
    private function legacyTerminalConflictFixture(string $suffix, string $terminalStatus): array
    {
        $this->transport->providerPaymentId = 'legacy-'.substr(hash('sha256', $suffix), 0, 24);
        [$userId, $quote, $decision, $opening] = $this->purchaseContext($suffix);
        $service = $this->service();
        $created = $service->initiatePurchase(
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            $this->correlation('legacy-create-'.$suffix),
        );
        $this->transport->statusValue = $terminalStatus;
        $terminal = $service->refresh(
            $created->paymentIntentPublicId,
            $this->correlation('legacy-terminal-'.$suffix),
        );
        self::assertSame($terminalStatus, $terminal->state->value);

        $intentId = DB::table('payment_intents')
            ->where('public_id', $created->paymentIntentPublicId)
            ->value('id');
        self::assertNotNull($intentId);

        $fixture = [
            'user_id' => $userId,
            'quote_public_id' => $quote->quotePublicId,
            'quote_configuration_hash' => $quote->configurationSnapshotHash,
            'order_public_id' => $opening->orderPublicId,
            'authority_id' => $created->authorityId,
            'provider_payment_id' => (string) $created->providerPaymentId,
            'payment_intent_id' => (int) $intentId,
            'payment_intent_public_id' => $created->paymentIntentPublicId,
        ];
        $this->fixtureBySuffix[$suffix] = $fixture;

        return $fixture;
    }

    /** @return array{observation_id:int,evidence_hash:string} */
    private function seedLegacyFinishedConflict(int $authorityId, string $providerPaymentId, string $suffix): array
    {
        $evidenceHash = hash('sha256', 'legacy-terminal-finished-conflict:'.$suffix);
        $correlationId = $this->correlation('legacy-terminal-finished-conflict-'.$suffix);
        $timestamp = $this->timestamp();
        $observationId = (int) DB::table('nowpayments_payment_observations')->insertGetId([
            'nowpayments_payment_authority_id' => $authorityId,
            'event_key' => 'nowpayments:'.$authorityId.':status_lookup:'.substr($evidenceHash, 0, 32),
            'event_type' => 'status_lookup',
            'provider_payment_id' => $providerPaymentId,
            'provider_status' => 'finished',
            'response_hash' => $evidenceHash,
            'occurred_at' => $timestamp,
            'correlation_id' => $correlationId,
            'created_at' => $timestamp,
        ]);
        DB::table('nowpayments_reconciliation_findings')->insert([
            'nowpayments_payment_authority_id' => $authorityId,
            'finding_key' => 'nowpayments:'.$authorityId
                .':terminal_local_state_conflicts_with_finished_provider:'.substr($evidenceHash, 0, 32),
            'code' => 'terminal_local_state_conflicts_with_finished_provider',
            'severity' => 'critical',
            'provider_status' => 'finished',
            'evidence_hash' => $evidenceHash,
            'detected_at' => $timestamp,
            'correlation_id' => $correlationId,
            'created_at' => $timestamp,
        ]);

        return ['observation_id' => $observationId, 'evidence_hash' => $evidenceHash];
    }

    private function zarinpalDecision(
        int $userId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $suffix,
    ): string {
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $administratorId = $this->ownerAdministrator();
        $eligibility->configureMethod(
            'legacy-upgrade-zarinpal-method-'.$suffix,
            $administratorId,
            'zarinpal',
            true,
            false,
            2,
            'Legacy NOWPayments upgrade competing method.',
            $this->correlation('legacy-upgrade-zarinpal-method-'.$suffix),
        );
        $eligibility->recordHealth(
            'legacy-upgrade-zarinpal-health-'.$suffix,
            $administratorId,
            'zarinpal',
            true,
            $this->clock->value->modify('+10 minutes'),
            'Healthy competing Zarinpal observation for legacy NOWPayments upgrade.',
            $this->correlation('legacy-upgrade-zarinpal-health-'.$suffix),
        );

        return $eligibility->evaluate(
            'legacy-upgrade-zarinpal-decision-'.$suffix,
            $userId,
            $quotePublicId,
            $quoteConfigurationHash,
        )->publicId;
    }

    /** @return array{0:int,1:object,2:object} */
    private function discountedNowPaymentsContext(string $suffix): array
    {
        $offering = $this->activeBenefitOffering('np-upgrade-'.$suffix);
        $version = (int) DB::table('plan_offerings')->where('id', $offering['id'])->value('version');
        $this->app->make(PlanOfferingService::class)->setVisibility(
            $offering['id'],
            $version,
            ProductVisibility::Visible,
            new CatalogChangeContext(
                'np-upgrade-visible-'.substr(hash('sha256', $suffix), 0, 24),
                'np-upgrade-visible-correlation-'.substr(hash('sha256', $suffix), 0, 20),
                'nowpayments_migration_test',
                'Expose NOWPayments migration promotion fixture.',
                $this->benefitOwner(),
            ),
        );
        $rule = $this->usageRule(
            $offering['id'],
            'np.upgrade.promo.'.substr(hash('sha256', $suffix), 0, 16),
            90_000,
            1,
            null,
        );
        $campaignCode = 'np.upgrade.discount.'.substr(hash('sha256', $suffix), 0, 12);
        $this->benefitCampaign(
            $campaignCode,
            BenefitCodeType::DiscountGrant,
            $this->discountDefinition($rule->ruleCode, $offering['id'], $offering['product_id'], $offering['server_id']),
            'np-upgrade-'.$suffix,
        );
        $issue = $this->benefitIssue($campaignCode, 'np-upgrade-'.$suffix, 1);
        $userId = $this->benefitUser('customer');
        $quotes = $this->app->make(QuoteService::class);
        $source = $quotes->create(
            'np-upgrade-source-'.substr(hash('sha256', $suffix), 0, 24),
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
            $this->correlation('promo-source-'.$suffix),
        );
        $discounts = $this->app->make(QuoteDiscountAuthority::class);
        $authorization = $discounts->authorize(new QuoteDiscountAuthorizationRequest(
            'np-upgrade-auth-'.substr(hash('sha256', $suffix), 0, 24),
            $userId,
            $source->quotePublicId,
            $source->configurationSnapshotHash,
            (string) $issue->items[0]->fullCode,
            $this->correlation('promo-auth-'.$suffix),
        ));
        $quote = $quotes->create(
            'np-upgrade-discounted-'.substr(hash('sha256', $suffix), 0, 24),
            $userId,
            $offering['id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                $authorization->ruleCode,
                $authorization->discountIrr,
                $this->clock->value->modify('+30 minutes'),
            ),
            $this->correlation('promo-discounted-'.$suffix),
        );
        $discounts->consume(new QuoteDiscountConsumptionRequest(
            'np-upgrade-consume-'.substr(hash('sha256', $suffix), 0, 24),
            $userId,
            $authorization,
            $quote->quotePublicId,
            $quote->configurationSnapshotHash,
            $this->correlation('promo-consume-'.$suffix),
        ));

        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $administratorId = $this->benefitOwner();
        $eligibility->configureMethod(
            'np-upgrade-promo-method-'.substr(hash('sha256', $suffix), 0, 24),
            $administratorId,
            'nowpayments',
            true,
            false,
            1,
            'NOWPayments promotion migration fixture.',
            $this->correlation('promo-method-'.$suffix),
        );
        $eligibility->recordHealth(
            'np-upgrade-promo-health-'.substr(hash('sha256', $suffix), 0, 24),
            $administratorId,
            'nowpayments',
            true,
            $this->clock->value->modify('+10 minutes'),
            'Healthy NOWPayments promotion migration observation.',
            $this->correlation('promo-health-'.$suffix),
        );
        $decision = $eligibility->evaluate(
            'np-upgrade-promo-decision-'.substr(hash('sha256', $suffix), 0, 24),
            $userId,
            $quote->quotePublicId,
            $quote->configurationSnapshotHash,
        );
        $this->app->make(PurchaseOrderService::class)->openFromQuote(
            $quote->quotePublicId,
            $userId,
            $this->correlation('promo-order-'.$suffix),
        );

        return [$userId, $quote, $decision];
    }

    /** @return array{0:int,1:object,2:object,3:object} */
    private function purchaseContext(string $suffix): array
    {
        $userId = $this->quoteUser('customer');
        $administratorId = $this->ownerAdministrator();
        $offering = $this->quoteOffering(10_000_000);
        $quote = $this->app->make(QuoteService::class)->create(
            'legacy-upgrade-quote-'.$suffix,
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
            $this->correlation('legacy-upgrade-quote-'.$suffix),
        );
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'legacy-upgrade-nowpayments-method-'.$suffix,
            $administratorId,
            'nowpayments',
            true,
            false,
            1,
            'Legacy NOWPayments upgrade method.',
            $this->correlation('legacy-upgrade-nowpayments-method-'.$suffix),
        );
        $eligibility->recordHealth(
            'legacy-upgrade-nowpayments-health-'.$suffix,
            $administratorId,
            'nowpayments',
            true,
            $this->clock->value->modify('+10 minutes'),
            'Healthy NOWPayments observation for legacy upgrade.',
            $this->correlation('legacy-upgrade-nowpayments-health-'.$suffix),
        );
        $decision = $eligibility->evaluate(
            'legacy-upgrade-nowpayments-decision-'.$suffix,
            $userId,
            $quote->quotePublicId,
            $quote->configurationSnapshotHash,
        );
        $opening = $this->app->make(PurchaseOrderService::class)->openFromQuote(
            $quote->quotePublicId,
            $userId,
            $this->correlation('legacy-upgrade-order-'.$suffix),
        );

        return [$userId, $quote, $decision, $opening];
    }

    /** @return array{intent_public_id:string,intent_id:int,quote_public_id:string,order_id:int} */
    private function walletCutFixture(string $suffix): array
    {
        $userId = $this->quoteUser('customer');
        $administratorId = $this->ownerAdministrator();
        $offering = $this->quoteOffering(1_000_000);
        $quote = $this->app->make(QuoteService::class)->create(
            'np-upgrade-wallet-cut-quote-'.$suffix,
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
            $this->correlation('wallet-cut-quote-'.$suffix),
        );
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'np-upgrade-wallet-cut-method-'.$suffix,
            $administratorId,
            'wallet',
            true,
            false,
            1,
            'Wallet cutover regression method.',
            $this->correlation('wallet-cut-method-'.$suffix),
        );
        $eligibility->recordHealth(
            'np-upgrade-wallet-cut-health-'.$suffix,
            $administratorId,
            'wallet',
            true,
            $this->clock->value->modify('+10 minutes'),
            'Healthy Wallet observation for financial cutover regression.',
            $this->correlation('wallet-cut-health-'.$suffix),
        );
        $decision = $eligibility->evaluate(
            'np-upgrade-wallet-cut-decision-'.$suffix,
            $userId,
            $quote->quotePublicId,
            $quote->configurationSnapshotHash,
        );
        $opening = $this->app->make(PurchaseOrderService::class)->openFromQuote(
            $quote->quotePublicId,
            $userId,
            $this->correlation('wallet-cut-order-'.$suffix),
        );
        $walletId = $this->fundedWalletForCut($userId, 1_500_000, $suffix);
        $intent = $this->app->make(PurchaseWalletPaymentService::class)->reserve(
            'np-upgrade-wallet-cut-reserve-'.$suffix,
            $userId,
            $walletId,
            $quote->quotePublicId,
            $decision->publicId,
            $this->correlation('wallet-cut-reserve-'.$suffix),
        );
        $intentId = DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->value('id');
        if (! is_int($intentId) && ! ctype_digit((string) $intentId)) {
            throw new RuntimeException('Wallet cutover fixture payment intent ID is unavailable.');
        }
        $orderId = DB::table('orders')->where('public_id', $opening->orderPublicId)->value('id');
        if (! is_int($orderId) && ! ctype_digit((string) $orderId)) {
            throw new RuntimeException('Wallet cutover fixture Order ID is unavailable.');
        }

        return [
            'intent_public_id' => $intent->intentPublicId,
            'intent_id' => (int) $intentId,
            'quote_public_id' => $quote->quotePublicId,
            'order_id' => (int) $orderId,
        ];
    }

    private function fundedWalletForCut(int $userId, int $amountIrr, string $suffix): int
    {
        $now = now('UTC');
        $assetId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'system.np.upgrade.wallet.cut.asset.'.$suffix,
            'account_class' => 'asset',
            'owner_user_id' => null,
            'wallet_bucket' => null,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $walletId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'wallet.cash.np.upgrade.cut.'.$suffix.'.'.$userId,
            'account_class' => 'liability',
            'owner_user_id' => $userId,
            'wallet_bucket' => 'cash',
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->app->make(LedgerPostingService::class)->post(
            'ledger.np.upgrade.wallet.cut.'.$suffix,
            'nowpayments_upgrade_wallet_cut_funding',
            $this->correlation('wallet-cut-fund-'.$suffix),
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amountIrr)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amountIrr)),
            ],
            'test_fixture',
            'np-upgrade-wallet-cut-'.$suffix,
        );

        return $walletId;
    }

    private function service(): NowPaymentsPaymentService
    {
        return new NowPaymentsPaymentService(
            $this->app['db'],
            $this->transport,
            $this->rateResolver(),
            $this->app->make(PurchasePaymentIntentService::class),
            $this->app->make(PurchasePromotionUsageAuthority::class),
            $this->app->make(PurchaseOrderService::class),
            $this->app->make(PurchaseSettlementService::class),
            $this->app->make(PurchaseProviderMutationBarrier::class),
            $this->clock,
        );
    }

    private function rateResolver(): UsdtRateResolver
    {
        $policy = new UsdtRatePolicy(
            ['manual'],
            UsdtRateSide::Buy,
            120,
            '100000',
            '10000000',
            500,
            true,
            3,
            60,
        );

        return new UsdtRateResolver(
            [new LegacyUpgradeNowPaymentsRateProvider($this->clock)],
            $policy,
            new UsdtCircuitBreaker(new Repository(new ArrayStore), $this->clock, 3, 60),
            $this->clock,
        );
    }

    private function assertUpgradeFenceActive(): void
    {
        self::assertTrue(
            DB::connection()->getSchemaBuilder()->hasTable('nowpayments_terminal_conflict_upgrade_fence'),
        );
        self::assertSame(
            1,
            DB::table('nowpayments_terminal_conflict_upgrade_fence')
                ->where('id', 1)
                ->where('active', 1)
                ->count(),
        );

        foreach ($this->upgradeFenceTriggers() as $trigger) {
            self::assertTrue($this->triggerExists($trigger), 'Missing active migration fence: '.$trigger);
        }
    }

    private function assertUpgradeFencesAbsent(): void
    {
        foreach ($this->upgradeFenceTriggers() as $trigger) {
            self::assertFalse($this->triggerExists($trigger), 'Stale migration fence remained: '.$trigger);
        }
        self::assertFalse(
            DB::connection()->getSchemaBuilder()->hasTable('nowpayments_terminal_conflict_upgrade_fence'),
        );
    }

    /** @return list<string> */
    private function upgradeFenceTriggers(): array
    {
        return [
            'provider_mutation_attempt_np_upgrade_insert_fence',
            'provider_mutation_attempt_np_upgrade_start_fence',
            'payment_intents_np_conflict_upgrade_insert_fence',
            'payment_intents_np_conflict_upgrade_update_fence',
            'nowpayments_authority_np_conflict_upgrade_insert_fence',
            'nowpayments_authority_np_conflict_upgrade_update_fence',
            'nowpayments_observation_np_conflict_upgrade_insert_fence',
            'zarinpal_request_np_conflict_upgrade_insert_fence',
            'usdt_authority_np_conflict_upgrade_insert_fence',
            'c2c_reservation_np_conflict_upgrade_insert_fence',
            'gift_card_submission_np_conflict_upgrade_insert_fence',
            'wallet_hold_np_conflict_upgrade_insert_fence',
            'wallet_hold_np_conflict_upgrade_update_fence',
            'purchase_wallet_np_conflict_upgrade_insert_fence',
            'promotion_reservation_np_conflict_upgrade_insert_fence',
            'promotion_release_np_conflict_upgrade_insert_fence',
            'promotion_redemption_np_conflict_upgrade_insert_fence',
            'orders_np_conflict_upgrade_insert_fence',
            'orders_np_conflict_upgrade_update_fence',
            'purchase_settlement_np_conflict_upgrade_insert_fence',
        ];
    }

    private function assertCanonicalFinancialTriggersPresent(): void
    {
        foreach ([
            'payment_intents_insert_guard',
            'payment_intents_update_guard',
            'nowpayments_authority_update_guard',
        ] as $trigger) {
            self::assertTrue($this->triggerExists($trigger), 'Canonical financial trigger disappeared: '.$trigger);
        }
    }

    /**
     * @param array{
     *     user_id:int,
     *     quote_public_id:string,
     *     quote_configuration_hash:string,
     *     order_public_id:string,
     *     authority_id:int,
     *     provider_payment_id:string,
     *     payment_intent_id:int,
     *     payment_intent_public_id:string
     * } $fixture
     */
    private function assertFailClosedCutWithSecondConnection(array $fixture, string $suffix): void
    {
        $pdo = $this->independentPdo();
        $this->assertPdoRejected(
            fn () => $this->clonePaymentIntentViaPdo($pdo, $fixture['payment_intent_id'], 'cut-'.$suffix),
            'Purchase payment creation is fenced',
        );
        $this->assertPdoRejected(
            fn () => $this->insertNowPaymentsObservationViaPdo(
                $pdo,
                $fixture['authority_id'],
                $fixture['provider_payment_id'],
                'expired',
                'cut-'.$suffix,
            ),
            'NOWPayments status evidence is fenced',
        );

        $this->assertPdoRejected(
            fn () => $pdo->exec(
                'UPDATE nowpayments_payment_authorities SET amount_irr = amount_irr + 1 WHERE id = '
                .$fixture['authority_id'],
            ),
            '',
        );
        $this->assertPdoRejected(
            fn () => $pdo->exec(
                "UPDATE payment_intents SET state = 'captured', captured_at = UTC_TIMESTAMP(6) WHERE id = "
                .$fixture['payment_intent_id'],
            ),
            '',
        );

        self::assertSame(
            10_000_000,
            (int) DB::table('nowpayments_payment_authorities')->where('id', $fixture['authority_id'])->value('amount_irr'),
        );
        self::assertContains(
            DB::table('payment_intents')->where('id', $fixture['payment_intent_id'])->value('state'),
            ['failed', 'expired'],
        );
    }

    private function assertPdoRejected(callable $action, string $messageFragment): void
    {
        try {
            $action();
            self::fail('Expected independent MariaDB writer to remain fenced.');
        } catch (PDOException $exception) {
            if ($messageFragment !== '') {
                self::assertStringContainsString($messageFragment, $exception->getMessage());
            }
        }
    }

    private function independentPdo(): PDO
    {
        $database = config('database.connections.mysql');
        self::assertIsArray($database);

        return new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) $database['host'],
                (int) $database['port'],
                (string) $database['database'],
            ),
            (string) $database['username'],
            (string) $database['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function clonePaymentIntentViaPdo(PDO $pdo, int $sourceIntentId, string $suffix): void
    {
        $source = DB::table('payment_intents')->where('id', $sourceIntentId)->first();
        self::assertNotNull($source);
        $row = (array) $source;
        unset($row['id']);
        $row['public_id'] = (string) Str::ulid();
        $row['creation_key'] = 'np-upgrade-fence-'.substr(hash('sha256', $suffix), 0, 32);
        $row['creation_correlation_id'] = $this->correlation('clone-'.$suffix);

        $columns = array_keys($row);
        $quotedColumns = implode(',', array_map(
            static fn (string $column): string => chr(96).$column.chr(96),
            $columns,
        ));
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $statement = $pdo->prepare(
            'INSERT INTO payment_intents ('.$quotedColumns.') VALUES ('.$placeholders.')',
        );
        $statement->execute(array_values($row));
    }

    private function insertZarinpalRequestViaPdo(PDO $pdo, int $paymentIntentId, string $suffix): void
    {
        $intent = DB::table('payment_intents')->where('id', $paymentIntentId)->first(['amount_irr', 'currency']);
        self::assertNotNull($intent);
        $timestamp = $this->timestamp();

        $statement = $pdo->prepare(<<<'SQL'
INSERT INTO zarinpal_payment_requests
(public_id,request_key,payload_hash,payment_intent_id,promotion_usage_reservation_id,merchant_configuration_hash,authority,state,amount_irr,currency,callback_url,request_provider_code,request_attempted_at,authority_received_at,created_at,updated_at)
VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
SQL);
        $statement->execute([
            (string) Str::ulid(),
            'np-upgrade-fence-zarinpal-'.substr(hash('sha256', $suffix), 0, 24),
            hash('sha256', 'zarinpal-payload-'.$suffix),
            $paymentIntentId,
            null,
            hash('sha256', 'zarinpal-merchant-'.$suffix),
            null,
            'initiating',
            (int) $intent->amount_irr,
            (string) $intent->currency,
            'https://payments.example.test/payments/zarinpal/callback',
            null,
            $timestamp,
            null,
            $timestamp,
            $timestamp,
        ]);
    }

    private function insertNowPaymentsObservationViaPdo(
        PDO $pdo,
        int $authorityId,
        string $providerPaymentId,
        string $providerStatus,
        string $suffix,
    ): void {
        $hash = hash('sha256', 'np-upgrade-fence-observation-'.$suffix);
        $timestamp = $this->timestamp();
        $statement = $pdo->prepare(<<<'SQL'
INSERT INTO nowpayments_payment_observations
(nowpayments_payment_authority_id,event_key,event_type,provider_payment_id,provider_status,response_hash,occurred_at,correlation_id,created_at)
VALUES (?,?,?,?,?,?,?,?,?)
SQL);
        $statement->execute([
            $authorityId,
            'nowpayments:'.$authorityId.':status_lookup:'.substr($hash, 0, 32),
            'status_lookup',
            $providerPaymentId,
            $providerStatus,
            $hash,
            $timestamp,
            $this->correlation('pdo-observation-'.$suffix),
            $timestamp,
        ]);
    }

    private function insertFinishedObservation(
        int $authorityId,
        string $providerPaymentId,
        string $responseHash,
        string $suffix,
    ): void {
        DB::table('nowpayments_payment_observations')->insert([
            'nowpayments_payment_authority_id' => $authorityId,
            'event_key' => 'nowpayments:'.$authorityId.':status_lookup:'.substr($responseHash, 0, 32),
            'event_type' => 'status_lookup',
            'provider_payment_id' => $providerPaymentId,
            'provider_status' => 'finished',
            'response_hash' => $responseHash,
            'occurred_at' => $this->timestamp(),
            'correlation_id' => $this->correlation($suffix),
            'created_at' => $this->timestamp(),
        ]);
    }

    private function insertTerminalConflictFinding(int $authorityId, string $evidenceHash, string $suffix): void
    {
        DB::table('nowpayments_reconciliation_findings')->insert([
            'nowpayments_payment_authority_id' => $authorityId,
            'finding_key' => 'nowpayments:'.$authorityId
                .':terminal_local_state_conflicts_with_finished_provider:'.substr($evidenceHash, 0, 32),
            'code' => 'terminal_local_state_conflicts_with_finished_provider',
            'severity' => 'critical',
            'provider_status' => 'finished',
            'evidence_hash' => $evidenceHash,
            'detected_at' => $this->timestamp(),
            'correlation_id' => $this->correlation($suffix),
            'created_at' => $this->timestamp(),
        ]);
    }

    private function triggerExists(string $trigger): bool
    {
        return DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TRIGGER_NAME', $trigger)
            ->exists();
    }

    private function providerAttemptMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path(
            'migrations/2026_09_21_000050_create_purchase_provider_mutation_attempt_authority.php',
        );

        return $migration;
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path(
            'migrations/2026_09_21_000100_harden_nowpayments_terminal_provider_conflict.php',
        );

        return $migration;
    }

    private function triggerAction(string $trigger): string
    {
        $action = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TRIGGER_NAME', $trigger)
            ->value('ACTION_STATEMENT');
        self::assertIsString($action);

        return $action;
    }

    private function timestamp(): string
    {
        return $this->clock->value->format('Y-m-d H:i:s.u');
    }

    private function correlation(string $suffix): string
    {
        return substr(hash('sha256', 'nowpayments-terminal-conflict-migration:'.$suffix), 0, 64);
    }
}
