<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\Orders\Application\QuoteReceipt;
use App\Modules\Payments\Application\Contracts\ProviderHealth;
use App\Modules\Payments\Application\PaymentEligibilityResolutionContext;
use App\Modules\Payments\Application\PaymentEligibilityResolutionRequest;
use App\Modules\Payments\Application\PaymentMethodEligibilityService;
use App\Modules\Payments\Domain\PaymentConfigurationState;
use App\Modules\Payments\Domain\PaymentEligibilityAction;
use App\Modules\Payments\Domain\PaymentEligibilityEffect;
use App\Modules\Payments\Domain\PaymentEligibilityOutcome;
use App\Modules\Payments\Domain\PaymentEligibilityRuleDefinition;
use App\Modules\Payments\Domain\PaymentMethodDefinition;
use App\Modules\Payments\Domain\PaymentMethodKind;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\CreatesPromotionUsageFixtures;
use Tests\TestCase;

final class MutablePaymentEligibilityClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement PAY-001 BUY-002 PAY-002 PAY-003 ACL-002 DAT-002 DAT-003 SEC-001 SEC-002 QUA-001 */
final class PaymentMethodEligibilityFoundationTest extends TestCase
{
    use CreatesPromotionUsageFixtures;
    use RefreshDatabase;

    private MutablePaymentEligibilityClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->clock = new MutablePaymentEligibilityClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_management_is_authorized_at_execution_time_and_mutation_replay_is_exact(): void
    {
        $finance = $this->administrator('finance');
        $support = $this->administrator('support');
        $service = $this->service();
        $definition = new PaymentMethodDefinition(PaymentConfigurationState::Active, 100, 100_000, 2_000_000, false);

        $created = $service->createMethod('method-auth-a', 'bank_gateway', PaymentMethodKind::Gateway, 'provider_a', $definition, $this->context($finance, 'method-create'));
        self::assertSame(1, $created->version);
        self::assertSame(PaymentConfigurationState::Active, $created->state);
        self::assertFalse($created->replayed);
        self::assertSame('bank_gateway', $created->methodCode);
        self::assertSame('provider_a', $created->providerCode);
        self::assertSame(64, strlen($created->configurationHash));

        $replay = $service->createMethod('method-auth-a', 'bank_gateway', PaymentMethodKind::Gateway, 'provider_a', $definition, $this->context($finance, 'method-replay'));
        self::assertTrue($replay->replayed);
        self::assertSame($created->versionId, $replay->versionId);

        $this->assertRuntimeMessage('Payment method mutation key conflict.', fn (): mixed => $service->createMethod(
            'method-auth-a',
            'bank_gateway',
            PaymentMethodKind::Gateway,
            'provider_a',
            new PaymentMethodDefinition(PaymentConfigurationState::Active, 101),
            $this->context($finance, 'method-conflict'),
        ));
        $this->assertAuthorizationDenied(fn (): mixed => $service->createMethod(
            'method-auth-b',
            'support_gateway',
            PaymentMethodKind::Gateway,
            null,
            $definition,
            $this->context($support, 'method-denied'),
        ));

        DB::table('administrator_role_assignments')->where('administrator_id', $finance)->update(['revoked_at' => now('UTC'), 'updated_at' => now('UTC')]);
        $this->assertAuthorizationDenied(fn (): mixed => $service->createMethod(
            'method-auth-a',
            'bank_gateway',
            PaymentMethodKind::Gateway,
            'provider_a',
            $definition,
            $this->context($finance, 'method-replay-after-revoke'),
        ));
    }

    public function test_method_state_health_limits_and_historical_replay_fail_closed_without_financial_effect(): void
    {
        $owner = $this->usageAdministrator();
        $user = $this->usageUser();
        $offering = $this->usageOffering(1_000_000, true, 'pay-state');
        $quote = $this->quote($user, $offering['id'], 'pay-state');
        $service = $this->service();
        $method = $service->createMethod(
            'method-state-a',
            'gateway_state',
            PaymentMethodKind::Gateway,
            'provider_state',
            new PaymentMethodDefinition(PaymentConfigurationState::Active, 50, 800_000, 1_000_000, false),
            $this->context($owner, 'state-method'),
        );
        $this->allowRule($service, $owner, 'gateway_state', 'state.allow', 'rule-state-a');

        $healthyRequest = $this->request('case-state-a', $user, $quote, ProviderHealth::Healthy, 'gateway_state');
        $accepted = $service->resolve($healthyRequest, new PaymentEligibilityResolutionContext($user));
        self::assertTrue($accepted->hasEligibleMethods());
        self::assertSame(['gateway_state'], $accepted->eligibleMethodCodes());
        self::assertSame(900_000, $accepted->amountIrr);
        self::assertSame('IRR', $accepted->currency);
        self::assertFalse($accepted->replayed);

        $unavailable = $service->resolve(
            $this->request('case-state-b', $user, $quote, ProviderHealth::Unavailable, 'gateway_state'),
            new PaymentEligibilityResolutionContext($user),
        );
        self::assertFalse($unavailable->hasEligibleMethods());
        self::assertSame(PaymentEligibilityOutcome::HealthUnavailable->value, $this->outcome($unavailable->decisionId, 'gateway_state'));

        $degraded = $service->resolve(
            $this->request('case-state-c', $user, $quote, ProviderHealth::Degraded, 'gateway_state'),
            new PaymentEligibilityResolutionContext($user),
        );
        self::assertFalse($degraded->hasEligibleMethods());

        $disabled = $service->reviseMethod(
            'method-state-b',
            'gateway_state',
            new PaymentMethodDefinition(PaymentConfigurationState::Disabled, 50, 800_000, 1_000_000, false),
            $this->context($owner, 'state-disable'),
        );
        self::assertSame(2, $disabled->version);
        $newDecision = $service->resolve(
            $this->request('case-state-d', $user, $quote, ProviderHealth::Healthy, 'gateway_state'),
            new PaymentEligibilityResolutionContext($user),
        );
        self::assertFalse($newDecision->hasEligibleMethods());
        self::assertSame(PaymentEligibilityOutcome::MethodDisabled->value, $this->outcome($newDecision->decisionId, 'gateway_state'));

        $replay = $service->resolve($healthyRequest, new PaymentEligibilityResolutionContext($user));
        self::assertTrue($replay->replayed);
        self::assertSame($accepted->decisionId, $replay->decisionId);
        self::assertSame($method->configurationHash, $replay->eligibleMethods[0]->methodConfigurationHash);
        self::assertSame(0, DB::table('payment_intents')->count());
        self::assertSame(0, DB::table('ledger_transactions')->count());
    }

    public function test_subject_account_tier_tag_and_identity_scopes_use_current_authority(): void
    {
        $owner = $this->usageAdministrator();
        $customer = $this->usageUser('customer');
        $agent = $this->usageUser('agent');
        $offering = $this->usageOffering(1_000_000, true, 'pay-subject');
        $customerQuote = $this->quote($customer, $offering['id'], 'pay-subject-customer');
        $agentQuote = $this->quote($agent, $offering['id'], 'pay-subject-agent');
        $tagId = $this->profileAndTag($customer, 'normal', 'unverified');
        $service = $this->service();
        $service->createMethod('method-subject-a', 'subject_gateway', PaymentMethodKind::Gateway, null, new PaymentMethodDefinition(PaymentConfigurationState::Active, 40), $this->context($owner, 'subject-method'));
        $service->createRule(
            'rule-subject-a',
            'subject_gateway',
            'subject.customer',
            $this->ruleDefinition(
                accountType: 'customer',
                tierCode: 'normal',
                identityStatus: 'unverified',
                tagId: $tagId,
            ),
            $this->context($owner, 'subject-customer-rule'),
        );
        $service->createRule(
            'rule-subject-b',
            'subject_gateway',
            'subject.agent',
            $this->ruleDefinition(accountType: 'agent'),
            $this->context($owner, 'subject-agent-rule'),
        );

        $customerDecision = $service->resolve(
            $this->request('case-subject-a', $customer, $customerQuote, ProviderHealth::Healthy, 'subject_gateway'),
            new PaymentEligibilityResolutionContext($customer),
        );
        self::assertSame(['subject_gateway'], $customerDecision->eligibleMethodCodes());

        $agentDecision = $service->resolve(
            $this->request('case-subject-b', $agent, $agentQuote, ProviderHealth::Healthy, 'subject_gateway'),
            new PaymentEligibilityResolutionContext($agent),
        );
        self::assertSame(['subject_gateway'], $agentDecision->eligibleMethodCodes());

        DB::table('customer_profiles')->where('user_id', $customer)->update(['identity_verification_status' => 'verified', 'updated_at' => now('UTC')]);
        $changedIdentity = $service->resolve(
            $this->request('case-subject-c', $customer, $customerQuote, ProviderHealth::Healthy, 'subject_gateway'),
            new PaymentEligibilityResolutionContext($customer),
        );
        self::assertFalse($changedIdentity->hasEligibleMethods());
        self::assertSame(PaymentEligibilityOutcome::NoMatchingRule->value, $this->outcome($changedIdentity->decisionId, 'subject_gateway'));
    }

    public function test_amount_action_offering_product_server_time_and_limit_scopes_are_authoritative(): void
    {
        $owner = $this->usageAdministrator();
        $user = $this->usageUser();
        $offering = $this->usageOffering(1_000_000, true, 'pay-scope-a');
        $otherOffering = $this->usageOffering(1_000_000, true, 'pay-scope-b');
        $quote = $this->quote($user, $offering['id'], 'pay-scope-a');
        $otherQuote = $this->quote($user, $otherOffering['id'], 'pay-scope-b');
        $service = $this->service();
        $service->createMethod('method-scope-a', 'scope_gateway', PaymentMethodKind::Gateway, null, new PaymentMethodDefinition(PaymentConfigurationState::Active, 30), $this->context($owner, 'scope-method'));
        $service->createRule(
            'rule-scope-a',
            'scope_gateway',
            'scope.allow',
            $this->ruleDefinition(
                priority: 20,
                minimumAmountIrr: 800_000,
                maximumAmountIrr: 950_000,
                action: PaymentEligibilityAction::Purchase,
                offeringId: $offering['id'],
                productId: $offering['product_id'],
                serverId: $offering['server_id'],
                effectiveFrom: $this->clock->value->modify('-1 minute'),
                effectiveUntil: $this->clock->value->modify('+30 minutes'),
            ),
            $this->context($owner, 'scope-rule'),
        );

        $accepted = $service->resolve(
            $this->request('case-scope-a', $user, $quote, ProviderHealth::Healthy, 'scope_gateway'),
            new PaymentEligibilityResolutionContext($user),
        );
        self::assertSame(['scope_gateway'], $accepted->eligibleMethodCodes());

        $wrongAction = $service->resolve(
            new PaymentEligibilityResolutionRequest('case-scope-b', $user, $quote->quotePublicId, PaymentEligibilityAction::Renew, ['scope_gateway' => ProviderHealth::Healthy]),
            new PaymentEligibilityResolutionContext($user),
        );
        self::assertFalse($wrongAction->hasEligibleMethods());

        $wrongOffering = $service->resolve(
            $this->request('case-scope-c', $user, $otherQuote, ProviderHealth::Healthy, 'scope_gateway'),
            new PaymentEligibilityResolutionContext($user),
        );
        self::assertFalse($wrongOffering->hasEligibleMethods());

        $service->reviseRule(
            'rule-scope-b',
            'scope_gateway',
            'scope.allow',
            $this->ruleDefinition(
                priority: 20,
                minimumAmountIrr: 800_000,
                maximumAmountIrr: 850_000,
                action: PaymentEligibilityAction::Purchase,
                offeringId: $offering['id'],
                productId: $offering['product_id'],
                serverId: $offering['server_id'],
                effectiveFrom: $this->clock->value->modify('-1 minute'),
                effectiveUntil: $this->clock->value->modify('+30 minutes'),
            ),
            $this->context($owner, 'scope-limit-revision'),
        );
        $outsideLimit = $service->resolve(
            $this->request('case-scope-d', $user, $quote, ProviderHealth::Healthy, 'scope_gateway'),
            new PaymentEligibilityResolutionContext($user),
        );
        self::assertFalse($outsideLimit->hasEligibleMethods());
    }

    public function test_deny_override_and_same_effect_ties_are_deterministic_independent_of_row_order(): void
    {
        $owner = $this->usageAdministrator();
        $user = $this->usageUser();
        $offering = $this->usageOffering(1_000_000, true, 'pay-precedence');
        $quote = $this->quote($user, $offering['id'], 'pay-precedence');
        $service = $this->service();

        $service->createMethod('method-route-a', 'deny_route', PaymentMethodKind::Gateway, null, new PaymentMethodDefinition(PaymentConfigurationState::Active, 30), $this->context($owner, 'deny-method'));
        $service->createMethod('method-route-b', 'stable_route', PaymentMethodKind::Gateway, null, new PaymentMethodDefinition(PaymentConfigurationState::Active, 20), $this->context($owner, 'stable-method'));
        $service->createMethod('method-route-c', 'override_route', PaymentMethodKind::Gateway, null, new PaymentMethodDefinition(PaymentConfigurationState::Active, 10), $this->context($owner, 'override-method'));

        $service->createRule('rule-deny-a', 'deny_route', 'deny.allow', $this->ruleDefinition(priority: 10), $this->context($owner, 'deny-allow'));
        $service->createRule('rule-deny-b', 'deny_route', 'deny.block', $this->ruleDefinition(effect: PaymentEligibilityEffect::Deny, priority: 20), $this->context($owner, 'deny-block'));

        $service->createRule('rule-stable-z', 'stable_route', 'z.allow', $this->ruleDefinition(priority: 10), $this->context($owner, 'stable-z'));
        $service->createRule('rule-stable-a', 'stable_route', 'a.allow', $this->ruleDefinition(priority: 10), $this->context($owner, 'stable-a'));

        $service->createRule('rule-override-a', 'override_route', 'override.deny', $this->ruleDefinition(effect: PaymentEligibilityEffect::Deny, priority: 100), $this->context($owner, 'override-deny'));
        $service->createRule('rule-override-b', 'override_route', 'override.allow', $this->ruleDefinition(priority: 1, isOverride: true), $this->context($owner, 'override-allow'));

        $decision = $service->resolve(
            new PaymentEligibilityResolutionRequest(
                'case-route-a',
                $user,
                $quote->quotePublicId,
                PaymentEligibilityAction::Purchase,
                [
                    'override_route' => ProviderHealth::Healthy,
                    'stable_route' => ProviderHealth::Healthy,
                    'deny_route' => ProviderHealth::Healthy,
                ],
            ),
            new PaymentEligibilityResolutionContext($user),
        );

        self::assertSame(['stable_route', 'override_route'], $decision->eligibleMethodCodes());
        self::assertSame(PaymentEligibilityOutcome::RuleDenied->value, $this->outcome($decision->decisionId, 'deny_route'));
        self::assertSame('a.allow', $this->selectedRule($decision->decisionId, 'stable_route'));
        self::assertSame('override.allow', $this->selectedRule($decision->decisionId, 'override_route'));
    }

    public function test_equal_precedence_material_conflict_fails_closed_without_persisting_decision(): void
    {
        $owner = $this->usageAdministrator();
        $user = $this->usageUser();
        $offering = $this->usageOffering(1_000_000, true, 'pay-ambiguous');
        $quote = $this->quote($user, $offering['id'], 'pay-ambiguous');
        $service = $this->service();
        $service->createMethod('method-ambiguous-a', 'ambiguous_gateway', PaymentMethodKind::Gateway, null, new PaymentMethodDefinition(PaymentConfigurationState::Active, 10), $this->context($owner, 'ambiguous-method'));
        $service->createRule('rule-ambiguous-a', 'ambiguous_gateway', 'ambiguous.allow', $this->ruleDefinition(priority: 10), $this->context($owner, 'ambiguous-allow'));
        $service->createRule('rule-ambiguous-b', 'ambiguous_gateway', 'ambiguous.deny', $this->ruleDefinition(effect: PaymentEligibilityEffect::Deny, priority: 10), $this->context($owner, 'ambiguous-deny'));
        $decisionReference = 'case-ambiguity-a';

        $this->assertRuntimeMessage(
            'Payment eligibility rule resolution is ambiguous.',
            fn (): mixed => $service->resolve(
                $this->request($decisionReference, $user, $quote, ProviderHealth::Healthy, 'ambiguous_gateway'),
                new PaymentEligibilityResolutionContext($user),
            ),
        );
        self::assertFalse(DB::table('payment_eligibility_decisions')->where('decision_key', $decisionReference)->exists());
    }

    public function test_health_and_time_qualification_return_explicit_fail_closed_outcomes_without_ambiguity(): void
    {
        $owner = $this->usageAdministrator();
        $user = $this->usageUser();
        $offering = $this->usageOffering(1_000_000, true, 'pay-health-time');
        $quote = $this->quote($user, $offering['id'], 'pay-health-time');
        $service = $this->service();
        $service->createMethod('method-health-a', 'health_gateway', PaymentMethodKind::Gateway, null, new PaymentMethodDefinition(PaymentConfigurationState::Active, 10), $this->context($owner, 'health-method'));
        $service->createRule(
            'rule-health-a',
            'health_gateway',
            'health.future',
            $this->ruleDefinition(
                effectiveFrom: $this->clock->value->modify('+5 minutes'),
                effectiveUntil: $this->clock->value->modify('+20 minutes'),
            ),
            $this->context($owner, 'health-future-rule'),
        );

        $outsideWindow = $service->resolve(
            $this->request('case-health-a', $user, $quote, ProviderHealth::Healthy, 'health_gateway'),
            new PaymentEligibilityResolutionContext($user),
        );
        self::assertFalse($outsideWindow->hasEligibleMethods());
        self::assertSame(PaymentEligibilityOutcome::NoMatchingRule->value, $this->outcome($outsideWindow->decisionId, 'health_gateway'));

        $unhealthy = $service->resolve(
            $this->request('case-health-b', $user, $quote, ProviderHealth::Unavailable, 'health_gateway'),
            new PaymentEligibilityResolutionContext($user),
        );
        self::assertFalse($unhealthy->hasEligibleMethods());
        self::assertSame(PaymentEligibilityOutcome::HealthUnavailable->value, $this->outcome($unhealthy->decisionId, 'health_gateway'));

        $this->clock->value = $this->clock->value->modify('+6 minutes');
        $qualified = $service->resolve(
            $this->request('case-health-c', $user, $quote, ProviderHealth::Healthy, 'health_gateway'),
            new PaymentEligibilityResolutionContext($user),
        );
        self::assertSame(['health_gateway'], $qualified->eligibleMethodCodes());
    }

    public function test_explicit_no_match_replays_exactly_and_cross_user_denial_precedes_replay_or_effect(): void
    {
        $owner = $this->usageAdministrator();
        $user = $this->usageUser();
        $other = $this->usageUser();
        $offering = $this->usageOffering(1_000_000, true, 'pay-replay');
        $quote = $this->quote($user, $offering['id'], 'pay-replay');
        $service = $this->service();
        $service->createMethod('method-replay-a', 'no_match_gateway', PaymentMethodKind::Gateway, null, new PaymentMethodDefinition(PaymentConfigurationState::Active, 10), $this->context($owner, 'replay-method'));
        $request = $this->request('case-replay-a', $user, $quote, ProviderHealth::Healthy, 'no_match_gateway');

        $decision = $service->resolve($request, new PaymentEligibilityResolutionContext($user));
        self::assertFalse($decision->hasEligibleMethods());
        self::assertSame(0, (int) DB::table('payment_eligibility_decisions')->where('id', $decision->decisionId)->value('eligible_count'));
        self::assertSame(PaymentEligibilityOutcome::NoMatchingRule->value, $this->outcome($decision->decisionId, 'no_match_gateway'));

        $replay = $service->resolve($request, new PaymentEligibilityResolutionContext($user));
        self::assertTrue($replay->replayed);
        self::assertSame($decision->decisionId, $replay->decisionId);

        $this->assertAuthorizationDenied(fn (): mixed => $service->resolve($request, new PaymentEligibilityResolutionContext($other)));
        $this->assertRuntimeMessage(
            'Payment eligibility decision key conflict.',
            fn (): mixed => $service->resolve(
                new PaymentEligibilityResolutionRequest('case-replay-a', $user, $quote->quotePublicId, PaymentEligibilityAction::Renew, ['no_match_gateway' => ProviderHealth::Healthy]),
                new PaymentEligibilityResolutionContext($user),
            ),
        );
    }

    public function test_new_decision_revalidates_current_subject_and_quote_but_accepted_replay_is_historical(): void
    {
        $owner = $this->usageAdministrator();
        $user = $this->usageUser();
        $offering = $this->usageOffering(1_000_000, true, 'pay-current');
        $quote = $this->quote($user, $offering['id'], 'pay-current', '+5 minutes');
        $service = $this->service();
        $service->createMethod('method-current-a', 'current_gateway', PaymentMethodKind::Gateway, null, new PaymentMethodDefinition(PaymentConfigurationState::Active, 10), $this->context($owner, 'current-method'));
        $this->allowRule($service, $owner, 'current_gateway', 'current.allow', 'rule-current-a');
        $request = $this->request('case-current-a', $user, $quote, ProviderHealth::Healthy, 'current_gateway');
        $accepted = $service->resolve($request, new PaymentEligibilityResolutionContext($user));
        self::assertTrue($accepted->hasEligibleMethods());

        $this->clock->value = $this->clock->value->modify('+10 minutes');
        $replay = $service->resolve($request, new PaymentEligibilityResolutionContext($user));
        self::assertTrue($replay->replayed);
        self::assertSame($accepted->decisionId, $replay->decisionId);
        $this->assertDomainMessage(
            'Payment eligibility requires a currently valid Quote.',
            fn (): mixed => $service->resolve(
                $this->request('case-current-b', $user, $quote, ProviderHealth::Healthy, 'current_gateway'),
                new PaymentEligibilityResolutionContext($user),
            ),
        );

        $this->clock->value = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $freshQuote = $this->quote($user, $offering['id'], 'pay-current-fresh');
        DB::table('users')->where('id', $user)->update(['account_status' => 'suspended', 'updated_at' => now('UTC')]);
        $this->assertDomainMessage(
            'Payment eligibility requires an active customer or agent.',
            fn (): mixed => $service->resolve(
                $this->request('case-current-c', $user, $freshQuote, ProviderHealth::Healthy, 'current_gateway'),
                new PaymentEligibilityResolutionContext($user),
            ),
        );
    }

    public function test_database_authority_rejects_mutation_invalid_hash_and_invalid_method_identity(): void
    {
        $owner = $this->usageAdministrator();
        $user = $this->usageUser();
        $offering = $this->usageOffering(1_000_000, true, 'pay-db');
        $quote = $this->quote($user, $offering['id'], 'pay-db');
        $service = $this->service();
        $method = $service->createMethod('method-db-a', 'db_gateway', PaymentMethodKind::Gateway, null, new PaymentMethodDefinition(PaymentConfigurationState::Active, 10), $this->context($owner, 'db-method'));
        $this->allowRule($service, $owner, 'db_gateway', 'db.allow', 'rule-db-a');
        $decision = $service->resolve($this->request('case-db-a', $user, $quote, ProviderHealth::Healthy, 'db_gateway'), new PaymentEligibilityResolutionContext($user));

        $this->assertQueryRejected(fn (): int => DB::table('payment_method_versions')->where('id', $method->versionId)->update(['state' => 'disabled']));
        $this->assertQueryRejected(fn (): int => DB::table('payment_eligibility_decisions')->where('id', $decision->decisionId)->delete());
        $this->assertQueryRejected(fn (): bool => DB::table('payment_methods')->insert([
            'public_id' => (string) Str::ulid(),
            'method_code' => 'invalid_kind',
            'kind' => 'provider_specific_magic',
            'provider_code' => null,
            'created_at' => now('UTC'),
        ]));
        $this->assertQueryRejected(fn (): bool => DB::table('payment_method_versions')->insert([
            'payment_method_id' => $method->methodId,
            'mutation_key' => 'method-db-forged',
            'mutation_payload_hash' => str_repeat('a', 64),
            'version' => 99,
            'state' => 'active',
            'display_priority' => 10,
            'minimum_amount_irr' => null,
            'maximum_amount_irr' => null,
            'allow_degraded_health' => false,
            'configuration_snapshot' => json_encode(['forged' => true], JSON_THROW_ON_ERROR),
            'configuration_hash' => str_repeat('b', 64),
            'actor_administrator_id' => $owner,
            'reason_code' => 'forged',
            'reason' => 'forged',
            'correlation_id' => 'forged',
            'created_at' => now('UTC'),
        ]));
        self::assertSame(1, DB::table('payment_eligibility_decisions')->count());
        self::assertSame(0, DB::table('payment_intents')->count());
        self::assertSame(0, DB::table('ledger_transactions')->count());
    }

    private function service(): PaymentMethodEligibilityService
    {
        return $this->app->make(PaymentMethodEligibilityService::class);
    }

    private function administrator(string $roleCode): int
    {
        $now = now('UTC');
        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => $this->usageUser(),
            'status' => 'active',
            'is_owner' => false,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $roleId = (int) DB::table('roles')->where('code', $roleCode)->value('id');
        DB::table('administrator_role_assignments')->insert([
            'administrator_id' => $administratorId,
            'role_id' => $roleId,
            'granted_by_administrator_id' => null,
            'granted_at' => $now,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $administratorId;
    }

    private function context(int $administratorId, string $suffix): AccessChangeContext
    {
        return new AccessChangeContext(
            hash('sha256', 'pay-eligibility-request:'.$suffix),
            substr(hash('sha256', 'pay-eligibility-correlation:'.$suffix), 0, 64),
            'payment_eligibility_test',
            'PAY-001 payment eligibility management test.',
            $administratorId,
        );
    }

    private function profileAndTag(int $userId, string $tierCode, string $identityStatus): int
    {
        $now = now('UTC');
        $tierId = (int) DB::table('customer_tiers')->where('code', $tierCode)->value('id');
        DB::table('customer_profiles')->insert([
            'user_id' => $userId,
            'current_tier_id' => $tierId,
            'tier_locked' => false,
            'tier_lock_reason_code' => null,
            'phone_verification_status' => 'unverified',
            'identity_verification_status' => $identityStatus,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $tagId = (int) DB::table('customer_tags')->insertGetId([
            'code' => 'pay-tag-'.Str::lower(Str::random(8)),
            'name_translation_key' => 'pay.test.tag',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('customer_tag_assignments')->insert([
            'user_id' => $userId,
            'tag_id' => $tagId,
            'assigned_by_administrator_id' => null,
            'assigned_at' => $now,
            'removed_at' => null,
        ]);

        return $tagId;
    }

    private function quote(int $userId, int $offeringId, string $suffix, string $validity = '+30 minutes'): QuoteReceipt
    {
        return $this->usageQuote(
            $userId,
            $offeringId,
            'pay-discount-'.$suffix,
            100_000,
            $this->clock->value->modify($validity),
            $suffix,
        );
    }

    private function request(string $reference, int $userId, QuoteReceipt $quote, ProviderHealth $health, string $methodCode): PaymentEligibilityResolutionRequest
    {
        return new PaymentEligibilityResolutionRequest($reference, $userId, $quote->quotePublicId, PaymentEligibilityAction::Purchase, [$methodCode => $health]);
    }

    private function allowRule(PaymentMethodEligibilityService $service, int $administratorId, string $methodCode, string $ruleCode, string $mutationReference): void
    {
        $service->createRule($mutationReference, $methodCode, $ruleCode, $this->ruleDefinition(), $this->context($administratorId, $ruleCode));
    }

    private function ruleDefinition(
        PaymentEligibilityEffect $effect = PaymentEligibilityEffect::Allow,
        int $priority = 10,
        bool $isOverride = false,
        ?string $accountType = null,
        ?string $tierCode = null,
        ?string $identityStatus = null,
        ?int $tagId = null,
        ?int $minimumAmountIrr = null,
        ?int $maximumAmountIrr = null,
        ?PaymentEligibilityAction $action = null,
        ?int $offeringId = null,
        ?int $productId = null,
        ?int $serverId = null,
        ?DateTimeImmutable $effectiveFrom = null,
        ?DateTimeImmutable $effectiveUntil = null,
    ): PaymentEligibilityRuleDefinition {
        return new PaymentEligibilityRuleDefinition(
            PaymentConfigurationState::Active,
            $effect,
            $priority,
            $isOverride,
            $accountType,
            $tierCode,
            $identityStatus,
            $tagId,
            $minimumAmountIrr,
            $maximumAmountIrr,
            $action,
            $offeringId,
            $productId,
            $serverId,
            $effectiveFrom,
            $effectiveUntil,
        );
    }

    private function outcome(int $decisionId, string $methodCode): string
    {
        return (string) DB::table('payment_eligibility_decision_items')
            ->where('decision_id', $decisionId)
            ->where('method_code_snapshot', $methodCode)
            ->value('outcome');
    }

    private function selectedRule(int $decisionId, string $methodCode): ?string
    {
        $value = DB::table('payment_eligibility_decision_items')
            ->where('decision_id', $decisionId)
            ->where('method_code_snapshot', $methodCode)
            ->value('rule_code_snapshot');

        return $value === null ? null : (string) $value;
    }

    private function assertRuntimeMessage(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function assertDomainMessage(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected DomainException was not thrown.');
        } catch (DomainException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function assertAuthorizationDenied(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected authorization denial was not thrown.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected MariaDB query rejection was not thrown.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
