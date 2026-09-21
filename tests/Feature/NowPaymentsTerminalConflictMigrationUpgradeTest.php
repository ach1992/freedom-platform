<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Application\Contracts\PurchasePromotionUsageAuthority;
use App\Modules\Payments\Application\PurchasePaymentIntentService;
use App\Modules\Payments\Application\PurchaseSettlementService;
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
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DateTimeImmutable;
use DomainException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

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
    use DatabaseTruncation;

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
