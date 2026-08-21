<?php

declare(strict_types=1);

namespace Tests\Feature;

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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class PaymentEligibilityClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement PAY-001 BUY-002 ACL-002 DAT-002 DAT-003 DAT-004 SEC-002 SEC-003 QUA-001 QUA-003 QUA-004 */
final class PaymentMethodEligibilityFoundationTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private PaymentEligibilityClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->clock = new PaymentEligibilityClock(new DateTimeImmutable('2026-08-10T12:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_healthy_methods_route_deterministically_and_snapshot_without_financial_effect(): void
    {
        $administratorId = $this->ownerAdministrator();
        $quote = $this->quoteFor($this->quoteUser('customer'));
        $service = $this->app->make(PaymentMethodEligibilityService::class);

        $this->configureHealthyMethod($service, $administratorId, 'alpha_gateway', 20);
        $this->configureHealthyMethod($service, $administratorId, 'beta_gateway', 10);

        $decision = $service->evaluate('eligibility.route.000001', $quote->userId, $quote->quotePublicId);

        self::assertFalse($decision->replayed);
        self::assertSame(['beta_gateway', 'alpha_gateway'], array_column($decision->methods, 'method_code'));
        self::assertSame([1, 2], array_column($decision->methods, 'route_order'));
        self::assertSame(1, DB::table('payment_method_eligibility_decisions')->count());
        self::assertSame(2, DB::table('payment_method_eligibility_decision_methods')->count());

        /** @var string $candidateSnapshot */
        $candidateSnapshot = DB::table('payment_method_eligibility_decision_methods')
            ->where('payment_method_eligibility_decision_id', $decision->decisionId)
            ->where('method_code', 'beta_gateway')
            ->value('configuration_snapshot');
        /** @var array{health:array{observation_id:int,configuration_snapshot_hash:string,observed_at:string,expires_at:string}} $candidate */
        $candidate = json_decode($candidateSnapshot, true, 512, JSON_THROW_ON_ERROR);
        /** @var object{id:int|string,configuration_snapshot_hash:string,observed_at:string,expires_at:string}|null $health */
        $health = DB::table('payment_method_health_observations')
            ->where('method_code', 'beta_gateway')
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first(['id', 'configuration_snapshot_hash', 'observed_at', 'expires_at']);
        self::assertNotNull($health);
        self::assertSame((int) $health->id, $candidate['health']['observation_id']);
        self::assertSame((string) $health->configuration_snapshot_hash, $candidate['health']['configuration_snapshot_hash']);
        self::assertSame((string) $health->observed_at, $candidate['health']['observed_at']);
        self::assertSame((string) $health->expires_at, $candidate['health']['expires_at']);

        self::assertSame(0, DB::table('payment_intents')->count());
        self::assertSame(0, DB::table('ledger_transactions')->count());

        $replay = $service->evaluate('eligibility.route.000001', $quote->userId, $quote->quotePublicId);
        self::assertTrue($replay->replayed);
        self::assertSame($decision->decisionId, $replay->decisionId);
        self::assertSame($decision->configurationSnapshotHash, $replay->configurationSnapshotHash);
    }

    public function test_deny_and_equal_precedence_conflict_fail_closed_but_user_allow_never_bypasses_health(): void
    {
        $administratorId = $this->ownerAdministrator();
        $userId = $this->quoteUser('customer');
        $quote = $this->quoteFor($userId);
        $service = $this->app->make(PaymentMethodEligibilityService::class);
        $this->configureHealthyMethod($service, $administratorId, 'bank_gateway', 1);

        $service->configureRule(
            'eligibility.rule.deny.000001',
            $administratorId,
            new PaymentEligibilityRuleDefinition('bank_gateway', 'specific_deny', true, PaymentEligibilityRuleEffect::Deny, 1, $userId),
            'Specific denial.',
            $this->correlation('specific-deny'),
        );
        self::assertSame([], $service->evaluate('eligibility.deny.000001', $userId, $quote->quotePublicId)->methods);

        $otherQuote = $this->quoteFor($this->quoteUser('customer'));
        $service->configureRule(
            'eligibility.rule.allow.000001',
            $administratorId,
            new PaymentEligibilityRuleDefinition('bank_gateway', 'normal_allow', true, PaymentEligibilityRuleEffect::Allow, 10),
            'Normal allow.',
            $this->correlation('normal-allow'),
        );
        $service->configureRule(
            'eligibility.rule.conflict.000001',
            $administratorId,
            new PaymentEligibilityRuleDefinition('bank_gateway', 'normal_deny', true, PaymentEligibilityRuleEffect::Deny, 10),
            'Normal denial.',
            $this->correlation('normal-deny'),
        );
        self::assertSame([], $service->evaluate('eligibility.ambiguous.000001', $otherQuote->userId, $otherQuote->quotePublicId)->methods);

        $service->configureMethod(
            'eligibility.method.maintenance.000001',
            $administratorId,
            'bank_gateway',
            true,
            true,
            1,
            'Maintenance.',
            $this->correlation('maintenance'),
        );
        $service->configureRule(
            'eligibility.rule.user-allow.000001',
            $administratorId,
            new PaymentEligibilityRuleDefinition('bank_gateway', 'specific_allow', true, PaymentEligibilityRuleEffect::Allow, 99, $userId),
            'Specific allow.',
            $this->correlation('specific-allow'),
        );
        self::assertSame([], $service->evaluate('eligibility.maintenance.000001', $userId, $quote->quotePublicId)->methods);
    }

    public function test_unavailable_required_facts_fail_closed_and_decision_key_conflict_does_not_overwrite_history(): void
    {
        $administratorId = $this->ownerAdministrator();
        $quote = $this->quoteFor($this->quoteUser('customer'));
        $service = $this->app->make(PaymentMethodEligibilityService::class);
        $this->configureHealthyMethod($service, $administratorId, 'history_gateway', 1);
        $service->configureRule(
            'eligibility.rule.history.000001',
            $administratorId,
            new PaymentEligibilityRuleDefinition(
                'history_gateway',
                'requires_history',
                true,
                PaymentEligibilityRuleEffect::Deny,
                99,
                requiresPurchaseHistory: true,
            ),
            'History is not yet available.',
            $this->correlation('requires-history'),
        );

        $decision = $service->evaluate('eligibility.unavailable.000001', $quote->userId, $quote->quotePublicId);
        self::assertSame([], $decision->methods);
        self::assertSame('required_fact_unavailable', DB::table('payment_method_eligibility_decision_methods')->where('payment_method_eligibility_decision_id', $decision->decisionId)->value('reason_code'));
        self::assertNull(DB::table('payment_method_eligibility_decision_methods')->where('payment_method_eligibility_decision_id', $decision->decisionId)->value('route_order'));
        /** @var string $snapshot */
        $snapshot = DB::table('payment_method_eligibility_decisions')->where('id', $decision->decisionId)->value('configuration_snapshot');
        self::assertStringContainsString('purchase_history_unavailable', $snapshot);

        $otherQuote = $this->quoteFor($this->quoteUser('customer'));
        $this->assertRuntimeMessage('Payment eligibility decision key conflict.', fn (): mixed => $service->evaluate(
            'eligibility.unavailable.000001',
            $otherQuote->userId,
            $otherQuote->quotePublicId,
        ));
        self::assertSame(1, DB::table('payment_method_eligibility_decisions')->count());
    }

    public function test_authorization_foreign_expired_quote_and_immutable_snapshot_guards(): void
    {
        $administratorId = $this->ownerAdministrator();
        $quote = $this->quoteFor($this->quoteUser('customer'), '+5 minutes');
        $service = $this->app->make(PaymentMethodEligibilityService::class);
        $this->configureHealthyMethod($service, $administratorId, 'secure_gateway', 1);
        $decision = $service->evaluate('eligibility.guard.000001', $quote->userId, $quote->quotePublicId);

        $this->assertAuthorizationDenied(fn (): mixed => $service->evaluate(
            'eligibility.foreign.000001',
            $this->quoteUser('customer'),
            $quote->quotePublicId,
        ));
        $this->assertQueryRejected(static fn (): int => DB::table('payment_method_eligibility_decisions')
            ->where('id', $decision->decisionId)
            ->update(['amount_irr_snapshot' => 1]));

        $this->clock->value = $this->clock->value->modify('+5 minutes');
        $this->assertRuntimeMessage('Payment eligibility requires a current commercial Quote.', fn (): mixed => $service->evaluate(
            'eligibility.guard.000001',
            $quote->userId,
            $quote->quotePublicId,
        ));
        $this->assertRuntimeMessage('Payment eligibility requires a current commercial Quote.', fn (): mixed => $service->evaluate(
            'eligibility.expired.000001',
            $quote->userId,
            $quote->quotePublicId,
        ));
        $this->assertAuthorizationDenied(fn (): mixed => $service->configureMethod(
            'eligibility.method.denied.000001',
            $this->administratorWithoutPaymentPermission(),
            'denied_gateway',
            true,
            false,
            1,
            'Denied.',
            $this->correlation('denied'),
        ));
    }

    public function test_policy_fact_classes_are_evaluated_from_server_owned_quote_and_identity_state(): void
    {
        $administratorId = $this->ownerAdministrator();
        $quote = $this->quoteFor($this->quoteUser('customer'));
        $service = $this->app->make(PaymentMethodEligibilityService::class);
        $definitions = [
            'account_gateway' => new PaymentEligibilityRuleDefinition('account_gateway', 'account_scope', true, PaymentEligibilityRuleEffect::Deny, 10, accountTypes: ['agent']),
            'tier_gateway' => new PaymentEligibilityRuleDefinition('tier_gateway', 'tier_scope', true, PaymentEligibilityRuleEffect::Deny, 10, tierCodes: ['vip']),
            'tag_gateway' => new PaymentEligibilityRuleDefinition('tag_gateway', 'tag_scope', true, PaymentEligibilityRuleEffect::Deny, 10, tagCodes: ['trusted']),
            'identity_gateway' => new PaymentEligibilityRuleDefinition('identity_gateway', 'identity_scope', true, PaymentEligibilityRuleEffect::Deny, 10, requiredIdentityStatus: 'verified'),
            'agent_gateway' => new PaymentEligibilityRuleDefinition('agent_gateway', 'agent_scope', true, PaymentEligibilityRuleEffect::Deny, 10, requiredAgentStatus: 'active'),
            'time_gateway' => new PaymentEligibilityRuleDefinition('time_gateway', 'time_scope', true, PaymentEligibilityRuleEffect::Deny, 10, startsAtUtc: '13:00', endsAtUtc: '14:00'),
            'amount_gateway' => new PaymentEligibilityRuleDefinition('amount_gateway', 'amount_scope', true, PaymentEligibilityRuleEffect::Deny, 10, minimumAmountIrr: 9_999_999_999),
            'limit_gateway' => new PaymentEligibilityRuleDefinition('limit_gateway', 'limit_scope', true, PaymentEligibilityRuleEffect::Deny, 10, requiresDailyPaymentLimit: true),
        ];
        foreach ($definitions as $methodCode => $definition) {
            $this->configureHealthyMethod($service, $administratorId, $methodCode, 10);
            $service->configureRule(
                'eligibility.rule.'.$methodCode.'.000001',
                $administratorId,
                $definition,
                'Server-fact policy coverage.',
                $this->correlation('policy-'.$methodCode),
            );
        }

        $decision = $service->evaluate('eligibility.policy-facts.000001', $quote->userId, $quote->quotePublicId);
        self::assertSame(['account_gateway', 'agent_gateway', 'amount_gateway', 'identity_gateway', 'tag_gateway', 'tier_gateway', 'time_gateway'], array_column($decision->methods, 'method_code'));
        $snapshot = (string) DB::table('payment_method_eligibility_decisions')->where('id', $decision->decisionId)->value('configuration_snapshot');
        foreach (['account_type_mismatch', 'tier_mismatch', 'tag_mismatch', 'identity_mismatch', 'agent_status_mismatch', 'time_window_mismatch', 'amount_mismatch', 'daily_payment_limit_unavailable'] as $reason) {
            self::assertStringContainsString($reason, $snapshot);
        }
        self::assertSame('required_fact_unavailable', DB::table('payment_method_eligibility_decision_methods')
            ->where('payment_method_eligibility_decision_id', $decision->decisionId)
            ->where('method_code', 'limit_gateway')
            ->value('reason_code'));
    }

    public function test_database_guards_reject_forged_scalar_snapshots_and_invalid_clock_times(): void
    {
        $administratorId = $this->ownerAdministrator();
        $service = $this->app->make(PaymentMethodEligibilityService::class);
        $this->configureHealthyMethod($service, $administratorId, 'forgery_gateway', 7);
        $service->configureRule(
            'eligibility.rule.forgery.000001',
            $administratorId,
            new PaymentEligibilityRuleDefinition('forgery_gateway', 'valid_window', true, PaymentEligibilityRuleEffect::Allow, 7, startsAtUtc: '12:00', endsAtUtc: '13:00'),
            'Valid window.',
            $this->correlation('forgery-rule'),
        );

        $method = (array) DB::table('payment_method_versions')->where('method_code', 'forgery_gateway')->first();
        $methodSnapshot = json_decode((string) $method['configuration_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $methodSnapshot['method_code'] = 'forged_gateway';
        $methodSnapshot['version'] = 1;
        ksort($methodSnapshot, SORT_STRING);
        unset($method['id']);
        $method['method_code'] = 'forged_gateway';
        $method['version'] = 1;
        $method['display_priority'] = 8;
        $method['mutation_key'] = 'eligibility.method.forged.000001';
        $method['request_payload_hash'] = hash('sha256', 'forged-method');
        $method['configuration_snapshot'] = json_encode($methodSnapshot, JSON_THROW_ON_ERROR);
        $method['configuration_snapshot_hash'] = hash('sha256', (string) $method['configuration_snapshot']);
        self::assertQueryRejected(static fn (): bool => DB::table('payment_method_versions')->insert($method));

        $health = (array) DB::table('payment_method_health_observations')->where('method_code', 'forgery_gateway')->first();
        unset($health['id']);
        $health['observation_key'] = 'eligibility.health.forged.000001';
        $health['request_payload_hash'] = hash('sha256', 'forged-health');
        $health['healthy'] = false;
        self::assertQueryRejected(static fn (): bool => DB::table('payment_method_health_observations')->insert($health));

        $rule = (array) DB::table('payment_method_rule_versions')->where('rule_code', 'valid_window')->first();
        $ruleSnapshot = json_decode((string) $rule['configuration_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $ruleSnapshot['rule_code'] = 'forged_window';
        $ruleSnapshot['version'] = 1;
        $ruleSnapshot['starts_at_utc'] = '12:00';
        $ruleSnapshot['ends_at_utc'] = '24:00';
        ksort($ruleSnapshot, SORT_STRING);
        unset($rule['id']);
        $rule['rule_code'] = 'forged_window';
        $rule['version'] = 1;
        $rule['starts_at_utc'] = '12:00';
        $rule['ends_at_utc'] = '24:00';
        $rule['mutation_key'] = 'eligibility.rule.forged.000001';
        $rule['request_payload_hash'] = hash('sha256', 'forged-rule');
        $rule['configuration_snapshot'] = json_encode($ruleSnapshot, JSON_THROW_ON_ERROR);
        $rule['configuration_snapshot_hash'] = hash('sha256', (string) $rule['configuration_snapshot']);
        self::assertQueryRejected(static fn (): bool => DB::table('payment_method_rule_versions')->insert($rule));
    }

    private function configureHealthyMethod(PaymentMethodEligibilityService $service, int $administratorId, string $methodCode, int $priority): void
    {
        $service->configureMethod(
            'eligibility.method.'.$methodCode.'.000001',
            $administratorId,
            $methodCode,
            true,
            false,
            $priority,
            'Initial configuration.',
            $this->correlation('method-'.$methodCode),
        );
        $service->recordHealth(
            'eligibility.health.'.$methodCode.'.000001',
            $administratorId,
            $methodCode,
            true,
            $this->clock->value->modify('+10 minutes'),
            'Healthy local observation.',
            $this->correlation('health-'.$methodCode),
        );
    }

    private function quoteFor(int $userId, string $expiry = '+30 minutes'): object
    {
        $offering = $this->quoteOffering();

        return $this->app->make(QuoteService::class)->create(
            'eligibility.quote.'.substr(hash('sha256', (string) $userId.$expiry), 0, 20),
            $userId,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->value->modify($expiry)),
            $this->correlation('quote-'.$userId.$expiry),
        );
    }

    private function administratorWithoutPaymentPermission(): int
    {
        $now = now('UTC');

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => $this->quoteUser('customer'),
            'status' => 'active',
            'is_owner' => false,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'payment-eligibility:'.$suffix);
    }

    private function assertRuntimeMessage(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected runtime exception.');
        } catch (RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function assertAuthorizationDenied(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected authorization denial.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected database guard rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
