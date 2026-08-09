<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Agents\Application\AgentPricingResolutionContext;
use App\Modules\Agents\Application\AgentPricingService;
use App\Modules\Agents\Domain\AgentPricingAction;
use App\Modules\Agents\Domain\AgentPricingRuleDefinition;
use App\Modules\Agents\Domain\AgentPricingState;
use Illuminate\Support\Facades\DB;

trait AgentPricingResolutionSelectionScenarios
{
    public function test_supported_individual_and_compound_scopes_resolve_integer_irr_prices(): void
    {
        $owner = $this->administrator(true);
        $offering = $this->offering(1_000_000);
        $service = $this->app->make(AgentPricingService::class);

        $cases = [
            'offering' => new AgentPricingRuleDefinition(AgentPricingState::Active, 910_000, planOfferingId: $offering['id']),
            'action' => new AgentPricingRuleDefinition(AgentPricingState::Active, 920_000, action: AgentPricingAction::Purchase),
            'server' => new AgentPricingRuleDefinition(AgentPricingState::Active, 930_000, salesServerId: $offering['server_id']),
            'product' => new AgentPricingRuleDefinition(AgentPricingState::Active, 940_000, productId: $offering['product_id']),
            'compound' => new AgentPricingRuleDefinition(AgentPricingState::Active, 850_000, AgentPricingAction::Purchase, $offering['id'], $offering['server_id'], $offering['product_id']),
        ];

        foreach ($cases as $scope => $definition) {
            $profileCode = 'agt-scope-'.$scope;
            $this->createProfile($service, $owner, $profileCode, true);
            $rule = $service->createRule('agt.rule.create.'.$scope, $profileCode, 'rule-'.$scope, $definition, $this->context($owner, 'rule-'.$scope));
            $agent = $this->agent($profileCode);
            $resolution = $service->resolve($this->request('agt.resolve.scope.'.$scope, $agent, $profileCode, $offering['id']), new AgentPricingResolutionContext($agent));
            self::assertTrue($resolution->matched());
            self::assertSame($definition->overridePriceIrr, $resolution->overridePriceIrr);
            self::assertSame($definition->specificity(), $rule->specificity);
            self::assertSame('rule-'.$scope, $resolution->ruleCode);
            self::assertSame($offering['product_id'], $resolution->productId);
            self::assertSame($offering['server_id'], $resolution->salesServerId);
            self::assertSame(AgentPricingAction::Purchase, $resolution->action);
            self::assertTrue($resolution->discountCombinationAllowed);
        }
    }

    public function test_more_specific_rule_beats_less_specific_and_row_order_is_irrelevant(): void
    {
        $owner = $this->administrator(true);
        $offering = $this->offering(1_000_000);
        $service = $this->app->make(AgentPricingService::class);
        $this->createProfile($service, $owner, 'agt-order-a', false);
        $service->createRule('agt.order.a.generic', 'agt-order-a', 'generic', new AgentPricingRuleDefinition(AgentPricingState::Active, 900_000), $this->context($owner, 'order-a-generic'));
        $service->createRule('agt.order.a.specific', 'agt-order-a', 'specific', new AgentPricingRuleDefinition(AgentPricingState::Active, 700_000, AgentPricingAction::Purchase, $offering['id'], $offering['server_id'], $offering['product_id']), $this->context($owner, 'order-a-specific'));
        $this->createProfile($service, $owner, 'agt-order-b', false);
        $service->createRule('agt.order.b.specific', 'agt-order-b', 'specific', new AgentPricingRuleDefinition(AgentPricingState::Active, 700_000, AgentPricingAction::Purchase, $offering['id'], $offering['server_id'], $offering['product_id']), $this->context($owner, 'order-b-specific'));
        $service->createRule('agt.order.b.generic', 'agt-order-b', 'generic', new AgentPricingRuleDefinition(AgentPricingState::Active, 900_000), $this->context($owner, 'order-b-generic'));
        foreach (['agt-order-a', 'agt-order-b'] as $index => $profileCode) {
            $agent = $this->agent($profileCode);
            $resolution = $service->resolve($this->request('agt.resolve.order.'.($index + 1), $agent, $profileCode, $offering['id']), new AgentPricingResolutionContext($agent));
            self::assertSame(700_000, $resolution->overridePriceIrr);
            self::assertSame('specific', $resolution->ruleCode);
        }
    }

    public function test_equal_specificity_is_ambiguous_and_no_match_is_explicit(): void
    {
        $owner = $this->administrator(true);
        $offering = $this->offering(1_000_000);
        $service = $this->app->make(AgentPricingService::class);
        $this->createProfile($service, $owner, 'agt-ambiguous', true);
        $service->createRule('agt.ambiguous.action', 'agt-ambiguous', 'action-only', new AgentPricingRuleDefinition(AgentPricingState::Active, 800_000, action: AgentPricingAction::Purchase), $this->context($owner, 'ambiguous-action'));
        $service->createRule('agt.ambiguous.offer', 'agt-ambiguous', 'offering-only', new AgentPricingRuleDefinition(AgentPricingState::Active, 790_000, planOfferingId: $offering['id']), $this->context($owner, 'ambiguous-offer'));
        $agent = $this->agent('agt-ambiguous');
        $this->assertRuntimeMessage('Agent pricing rule resolution is ambiguous.', fn (): mixed => $service->resolve($this->request('agt.resolve.ambiguous', $agent, 'agt-ambiguous', $offering['id']), new AgentPricingResolutionContext($agent)));
        self::assertFalse(DB::table('agent_pricing_resolutions')->where('resolution_key', 'agt.resolve.ambiguous')->exists());

        $this->createProfile($service, $owner, 'agt-no-match', false);
        $service->createRule('agt.nomatch.rule', 'agt-no-match', 'renew-only', new AgentPricingRuleDefinition(AgentPricingState::Active, 750_000, action: AgentPricingAction::Renew), $this->context($owner, 'no-match-rule'));
        $agent = $this->agent('agt-no-match');
        $result = $service->resolve($this->request('agt.resolve.no-match', $agent, 'agt-no-match', $offering['id']), new AgentPricingResolutionContext($agent));
        self::assertFalse($result->matched());
        self::assertNull($result->overridePriceIrr);
        self::assertNull($result->ruleCode);
        self::assertFalse($result->discountCombinationAllowed);
        self::assertSame(64, strlen($result->configurationSnapshotHash));
    }

    public function test_resolution_requires_current_active_agent_and_denies_cross_user_stale_and_invalid_subjects(): void
    {
        $owner = $this->administrator(true);
        $offering = $this->offering(1_000_000);
        $service = $this->app->make(AgentPricingService::class);
        $this->createProfile($service, $owner, 'agt-auth', true);
        $service->createRule('agt.auth.rule', 'agt-auth', 'default', new AgentPricingRuleDefinition(AgentPricingState::Active, 800_000), $this->context($owner, 'auth-rule'));
        $active = $this->agent('agt-auth');
        self::assertSame(800_000, $service->resolve($this->request('agt.resolve.auth.ok', $active, 'agt-auth', $offering['id']), new AgentPricingResolutionContext($active))->overridePriceIrr);
        $other = $this->agent('agt-auth');
        $this->assertAuthorizationDenied(fn (): mixed => $service->resolve($this->request('agt.resolve.auth.cross', $active, 'agt-auth', $offering['id']), new AgentPricingResolutionContext($other)));
        $customer = $this->user('customer');
        $this->assertDomainMessage('Agent pricing resolution requires an active agent account.', fn (): mixed => $service->resolve($this->request('agt.resolve.auth.customer', $customer, 'agt-auth', $offering['id']), new AgentPricingResolutionContext($customer)));
        foreach (['suspended', 'invalid', 'limited'] as $status) {
            $agent = $this->agent('agt-auth', $status);
            $this->assertDomainMessage('Agent pricing resolution requires an active agent profile.', fn (): mixed => $service->resolve($this->request('agt.resolve.auth.'.$status, $agent, 'agt-auth', $offering['id']), new AgentPricingResolutionContext($agent)));
        }
        $stale = $this->agent('agt-auth');
        $this->createProfile($service, $owner, 'agt-other-profile', true);
        $this->assertDomainMessage('Agent pricing profile code is stale or invalid.', fn (): mixed => $service->resolve($this->request('agt.resolve.auth.stale', $stale, 'agt-other-profile', $offering['id']), new AgentPricingResolutionContext($stale)));
    }
}
