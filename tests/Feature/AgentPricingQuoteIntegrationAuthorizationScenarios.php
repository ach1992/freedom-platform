<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Agents\Application\AgentPricingService;
use App\Modules\Agents\Domain\AgentPricingAction;
use App\Modules\Agents\Domain\AgentPricingRuleDefinition;
use App\Modules\Agents\Domain\AgentPricingState;
use App\Modules\Orders\Application\QuoteAgentPricingContext;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use Illuminate\Support\Facades\DB;

trait AgentPricingQuoteIntegrationAuthorizationScenarios
{
    public function test_agent_quote_rejects_caller_override_cross_user_non_agent_suspended_and_stale_profile_attempts(): void
    {
        $owner = $this->ownerAdministrator();
        $offering = $this->quoteOffering();
        $pricingService = $this->app->make(AgentPricingService::class);
        $this->activePricingProfile($pricingService, $owner, 'quote-agent-auth', true);
        $this->activePricingRule(
            $pricingService,
            $owner,
            'quote-agent-auth',
            'default',
            new AgentPricingRuleDefinition(AgentPricingState::Active, 880_000),
        );
        $agent = $this->agentSubject('quote-agent-auth');
        $other = $this->agentSubject('quote-agent-auth');
        $quoteService = $this->app->make(QuoteService::class);

        $this->assertAuthorizationDenied(fn (): mixed => $quoteService->create(
            'agent.quote.auth.missing-context',
            $agent,
            $offering['id'],
            new QuotePricingInput(
                QuoteOverrideSource::Agent,
                'quote-agent-auth',
                1,
                null,
                0,
                $this->clock->value->modify('+30 minutes'),
            ),
            $this->correlation('missing-context'),
        ));
        $this->assertAuthorizationDenied(fn (): mixed => $quoteService->create(
            'agent.quote.auth.cross-user',
            $agent,
            $offering['id'],
            $this->pricing(null, 0),
            $this->correlation('cross-user'),
            new QuoteAgentPricingContext($other, AgentPricingAction::Purchase),
        ));
        $this->assertDomainMessage(
            'Agent quote pricing cannot accept a caller-supplied override.',
            fn (): mixed => $quoteService->create(
                'agent.quote.auth.arbitrary',
                $agent,
                $offering['id'],
                new QuotePricingInput(
                    QuoteOverrideSource::Agent,
                    'quote-agent-auth',
                    1,
                    null,
                    0,
                    $this->clock->value->modify('+30 minutes'),
                ),
                $this->correlation('arbitrary'),
                new QuoteAgentPricingContext($agent, AgentPricingAction::Purchase),
            ),
        );

        $suspended = $this->agentSubject('quote-agent-auth', 'suspended');
        $this->assertDomainMessage(
            'Agent pricing resolution requires an active agent profile.',
            fn (): mixed => $quoteService->create(
                'agent.quote.auth.suspended',
                $suspended,
                $offering['id'],
                $this->pricing(null, 0),
                $this->correlation('suspended'),
                new QuoteAgentPricingContext($suspended, AgentPricingAction::Purchase),
            ),
        );

        $stale = $this->agentSubject('quote-agent-missing-config');
        $this->assertDomainMessage(
            'Agent pricing profile configuration does not exist.',
            fn (): mixed => $quoteService->create(
                'agent.quote.auth.stale-profile',
                $stale,
                $offering['id'],
                $this->pricing(null, 0),
                $this->correlation('stale-profile'),
                new QuoteAgentPricingContext($stale, AgentPricingAction::Purchase),
            ),
        );

        $customer = $this->quoteUser('customer');
        $this->assertAuthorizationDenied(fn (): mixed => $quoteService->create(
            'agent.quote.auth.customer',
            $customer,
            $offering['id'],
            $this->pricing(null, 0),
            $this->correlation('customer-context'),
            new QuoteAgentPricingContext($customer, AgentPricingAction::Purchase),
        ));

        self::assertSame(0, DB::table('quotes')->count());
        self::assertSame(0, DB::table('agent_pricing_resolutions')->count());
    }

    public function test_same_quote_key_conflicts_on_changed_action_or_discount_without_re_resolving(): void
    {
        $owner = $this->ownerAdministrator();
        $offering = $this->quoteOffering();
        $pricingService = $this->app->make(AgentPricingService::class);
        $this->activePricingProfile($pricingService, $owner, 'quote-agent-conflict', true);
        $this->activePricingRule(
            $pricingService,
            $owner,
            'quote-agent-conflict',
            'default',
            new AgentPricingRuleDefinition(AgentPricingState::Active, 900_000),
        );
        $agent = $this->agentSubject('quote-agent-conflict');
        $quoteService = $this->app->make(QuoteService::class);
        $input = $this->pricing(null, 0);
        $purchase = new QuoteAgentPricingContext($agent, AgentPricingAction::Purchase);

        $quoteService->create(
            'agent.quote.conflict.000001',
            $agent,
            $offering['id'],
            $input,
            $this->correlation('conflict-create'),
            $purchase,
        );
        self::assertSame(1, DB::table('agent_pricing_resolutions')->count());

        $this->assertRuntimeMessage(
            'Quote key conflict.',
            fn (): mixed => $quoteService->create(
                'agent.quote.conflict.000001',
                $agent,
                $offering['id'],
                $input,
                $this->correlation('conflict-action'),
                new QuoteAgentPricingContext($agent, AgentPricingAction::Renew),
            ),
        );
        $this->assertRuntimeMessage(
            'Quote key conflict.',
            fn (): mixed => $quoteService->create(
                'agent.quote.conflict.000001',
                $agent,
                $offering['id'],
                $this->pricing('discount.changed', 1),
                $this->correlation('conflict-discount'),
                $purchase,
            ),
        );
        self::assertSame(1, DB::table('agent_pricing_resolutions')->count());
        self::assertSame(1, DB::table('quotes')->count());
    }
}
