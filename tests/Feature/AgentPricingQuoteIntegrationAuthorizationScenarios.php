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

    public function test_exact_agent_quote_replay_revalidates_active_user_and_agent_profile_without_new_effect(): void
    {
        $owner = $this->ownerAdministrator();
        $offering = $this->quoteOffering();
        $pricingService = $this->app->make(AgentPricingService::class);
        $this->activePricingProfile($pricingService, $owner, 'quote-agent-replay-suspend', true);
        $this->activePricingRule(
            $pricingService,
            $owner,
            'quote-agent-replay-suspend',
            'default',
            new AgentPricingRuleDefinition(AgentPricingState::Active, 875_000),
        );
        $agent = $this->agentSubject('quote-agent-replay-suspend');
        $quoteService = $this->app->make(QuoteService::class);
        $context = new QuoteAgentPricingContext($agent, AgentPricingAction::Purchase);
        $input = $this->pricing(null, 0);
        $quote = $quoteService->create(
            'agent.quote.replay.suspend.000001',
            $agent,
            $offering['id'],
            $input,
            $this->correlation('replay-suspend-create'),
            $context,
        );
        $storedBefore = DB::table('quotes')->where('id', $quote->quoteId)->first();
        self::assertNotNull($storedBefore);
        self::assertSame(1, DB::table('quotes')->count());
        self::assertSame(1, DB::table('agent_pricing_resolutions')->count());

        DB::table('users')->where('id', $agent)->update([
            'account_status' => 'suspended',
            'updated_at' => now('UTC'),
        ]);
        $this->assertDomainMessage(
            'Agent pricing resolution requires an active agent account.',
            fn (): mixed => $quoteService->create(
                'agent.quote.replay.suspend.000001',
                $agent,
                $offering['id'],
                $input,
                $this->correlation('replay-suspend-user'),
                $context,
            ),
        );

        DB::table('users')->where('id', $agent)->update([
            'account_status' => 'active',
            'updated_at' => now('UTC'),
        ]);
        DB::table('agent_profiles')->where('user_id', $agent)->update([
            'status' => 'suspended',
            'suspended_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $this->assertDomainMessage(
            'Agent pricing resolution requires an active agent profile.',
            fn (): mixed => $quoteService->create(
                'agent.quote.replay.suspend.000001',
                $agent,
                $offering['id'],
                $input,
                $this->correlation('replay-suspend-profile'),
                $context,
            ),
        );

        self::assertSame(1, DB::table('quotes')->count());
        self::assertSame(1, DB::table('agent_pricing_resolutions')->count());
        self::assertSame((array) $storedBefore, (array) DB::table('quotes')->where('id', $quote->quoteId)->first());
    }

    public function test_exact_agent_quote_replay_denies_current_pricing_profile_code_mismatch_and_preserves_history(): void
    {
        $owner = $this->ownerAdministrator();
        $offering = $this->quoteOffering();
        $pricingService = $this->app->make(AgentPricingService::class);
        $this->activePricingProfile($pricingService, $owner, 'quote-agent-replay-original', true);
        $this->activePricingProfile($pricingService, $owner, 'quote-agent-replay-other', true);
        $this->activePricingRule(
            $pricingService,
            $owner,
            'quote-agent-replay-original',
            'default',
            new AgentPricingRuleDefinition(AgentPricingState::Active, 825_000),
        );
        $agent = $this->agentSubject('quote-agent-replay-original');
        $quoteService = $this->app->make(QuoteService::class);
        $context = new QuoteAgentPricingContext($agent, AgentPricingAction::Purchase);
        $input = $this->pricing(null, 0);
        $quote = $quoteService->create(
            'agent.quote.replay.profile.000001',
            $agent,
            $offering['id'],
            $input,
            $this->correlation('replay-profile-create'),
            $context,
        );
        $storedBefore = DB::table('quotes')->where('id', $quote->quoteId)->first();
        self::assertNotNull($storedBefore);
        self::assertSame('quote-agent-replay-original', $quote->agentPricing?->pricingProfileCode);

        DB::table('agent_profiles')->where('user_id', $agent)->update([
            'pricing_profile_code' => 'quote-agent-replay-other',
            'updated_at' => now('UTC'),
        ]);
        $this->assertDomainMessage(
            'Agent pricing profile code is stale or invalid.',
            fn (): mixed => $quoteService->create(
                'agent.quote.replay.profile.000001',
                $agent,
                $offering['id'],
                $input,
                $this->correlation('replay-profile-mismatch'),
                $context,
            ),
        );

        self::assertSame(1, DB::table('quotes')->count());
        self::assertSame(1, DB::table('agent_pricing_resolutions')->count());
        self::assertSame((array) $storedBefore, (array) DB::table('quotes')->where('id', $quote->quoteId)->first());
    }

    public function test_exact_no_match_agent_quote_replay_revalidates_active_user_and_agent_profile_without_new_effect(): void
    {
        $owner = $this->ownerAdministrator();
        $offering = $this->quoteOffering();
        $pricingService = $this->app->make(AgentPricingService::class);
        $this->activePricingProfile($pricingService, $owner, 'quote-agent-nomatch-replay-suspend', true);
        $this->activePricingRule(
            $pricingService,
            $owner,
            'quote-agent-nomatch-replay-suspend',
            'renew-only',
            new AgentPricingRuleDefinition(AgentPricingState::Active, 760_000, action: AgentPricingAction::Renew),
        );
        $agent = $this->agentSubject('quote-agent-nomatch-replay-suspend');
        $quoteService = $this->app->make(QuoteService::class);
        $context = new QuoteAgentPricingContext($agent, AgentPricingAction::Purchase);
        $input = $this->pricing(null, 0);
        $quote = $quoteService->create(
            'agent.quote.nomatch.replay.suspend.000001',
            $agent,
            $offering['id'],
            $input,
            $this->correlation('nomatch-replay-suspend-create'),
            $context,
        );
        self::assertSame(QuoteOverrideSource::None, $quote->overrideSource);
        self::assertNotNull($quote->agentPricing);
        self::assertFalse($quote->agentPricing->matched());
        $storedBefore = DB::table('quotes')->where('id', $quote->quoteId)->first();
        self::assertNotNull($storedBefore);
        self::assertSame(1, DB::table('quotes')->count());
        self::assertSame(1, DB::table('agent_pricing_resolutions')->count());

        DB::table('users')->where('id', $agent)->update([
            'account_status' => 'suspended',
            'updated_at' => now('UTC'),
        ]);
        $this->assertDomainMessage(
            'Agent pricing resolution requires an active agent account.',
            fn (): mixed => $quoteService->create(
                'agent.quote.nomatch.replay.suspend.000001',
                $agent,
                $offering['id'],
                $input,
                $this->correlation('nomatch-replay-suspend-user'),
                $context,
            ),
        );

        DB::table('users')->where('id', $agent)->update([
            'account_status' => 'active',
            'updated_at' => now('UTC'),
        ]);
        DB::table('agent_profiles')->where('user_id', $agent)->update([
            'status' => 'suspended',
            'suspended_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $this->assertDomainMessage(
            'Agent pricing resolution requires an active agent profile.',
            fn (): mixed => $quoteService->create(
                'agent.quote.nomatch.replay.suspend.000001',
                $agent,
                $offering['id'],
                $input,
                $this->correlation('nomatch-replay-suspend-profile'),
                $context,
            ),
        );

        self::assertSame(1, DB::table('quotes')->count());
        self::assertSame(1, DB::table('agent_pricing_resolutions')->count());
        self::assertSame((array) $storedBefore, (array) DB::table('quotes')->where('id', $quote->quoteId)->first());
    }

    public function test_exact_no_match_agent_quote_replay_denies_current_pricing_profile_code_mismatch_and_preserves_history(): void
    {
        $owner = $this->ownerAdministrator();
        $offering = $this->quoteOffering();
        $pricingService = $this->app->make(AgentPricingService::class);
        $this->activePricingProfile($pricingService, $owner, 'quote-agent-nomatch-replay-original', true);
        $this->activePricingProfile($pricingService, $owner, 'quote-agent-nomatch-replay-other', true);
        $this->activePricingRule(
            $pricingService,
            $owner,
            'quote-agent-nomatch-replay-original',
            'renew-only',
            new AgentPricingRuleDefinition(AgentPricingState::Active, 755_000, action: AgentPricingAction::Renew),
        );
        $agent = $this->agentSubject('quote-agent-nomatch-replay-original');
        $quoteService = $this->app->make(QuoteService::class);
        $context = new QuoteAgentPricingContext($agent, AgentPricingAction::Purchase);
        $input = $this->pricing(null, 0);
        $quote = $quoteService->create(
            'agent.quote.nomatch.replay.profile.000001',
            $agent,
            $offering['id'],
            $input,
            $this->correlation('nomatch-replay-profile-create'),
            $context,
        );
        self::assertSame(QuoteOverrideSource::None, $quote->overrideSource);
        self::assertNotNull($quote->agentPricing);
        self::assertFalse($quote->agentPricing->matched());
        self::assertSame('quote-agent-nomatch-replay-original', $quote->agentPricing->pricingProfileCode);
        $storedBefore = DB::table('quotes')->where('id', $quote->quoteId)->first();
        self::assertNotNull($storedBefore);

        DB::table('agent_profiles')->where('user_id', $agent)->update([
            'pricing_profile_code' => 'quote-agent-nomatch-replay-other',
            'updated_at' => now('UTC'),
        ]);
        $this->assertDomainMessage(
            'Agent pricing profile code is stale or invalid.',
            fn (): mixed => $quoteService->create(
                'agent.quote.nomatch.replay.profile.000001',
                $agent,
                $offering['id'],
                $input,
                $this->correlation('nomatch-replay-profile-mismatch'),
                $context,
            ),
        );

        self::assertSame(1, DB::table('quotes')->count());
        self::assertSame(1, DB::table('agent_pricing_resolutions')->count());
        self::assertSame((array) $storedBefore, (array) DB::table('quotes')->where('id', $quote->quoteId)->first());
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
