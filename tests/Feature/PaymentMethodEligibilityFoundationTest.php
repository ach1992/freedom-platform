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

    public function test_unavailable_facts_reject_only_the_dependent_rule_and_decision_key_conflict_does_not_overwrite_history(): void
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
        self::assertSame(['history_gateway'], array_column($decision->methods, 'method_code'));
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
        $this->assertRuntimeMessage('Payment eligibility requires a current purchase Quote.', fn (): mixed => $service->evaluate(
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
