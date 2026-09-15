<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Agents\Application\AgentPricingResolutionContext;
use App\Modules\Agents\Application\AgentPricingResolutionRequest;
use App\Modules\Agents\Application\AgentPricingService;
use App\Modules\Agents\Domain\AgentPricingAction;
use App\Modules\Agents\Domain\AgentPricingProfileDefinition;
use App\Modules\Agents\Domain\AgentPricingRuleDefinition;
use App\Modules\Agents\Domain\AgentPricingState;
use App\Modules\Orders\Application\QuoteAgentPricingContext;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait AgentPricingQuoteIntegrationHistoryScenarios
{
    public function test_historical_quote_replay_is_stable_after_later_profile_and_rule_revision_and_new_quote_uses_new_resolution(): void
    {
        $owner = $this->ownerAdministrator();
        $offering = $this->quoteOffering();
        $pricingService = $this->app->make(AgentPricingService::class);
        $this->activePricingProfile($pricingService, $owner, 'quote-agent-history', true);
        $this->activePricingRule(
            $pricingService,
            $owner,
            'quote-agent-history',
            'default',
            new AgentPricingRuleDefinition(AgentPricingState::Active, 850_000),
        );
        $agent = $this->agentSubject('quote-agent-history');
        $quoteService = $this->app->make(QuoteService::class);
        $context = new QuoteAgentPricingContext($agent, AgentPricingAction::Purchase);
        $input = $this->pricing(null, 0);

        $first = $quoteService->create(
            'agent.quote.history.000001',
            $agent,
            $offering['id'],
            $input,
            $this->correlation('history-first'),
            $context,
        );
        self::assertSame(850_000, $first->finalPriceIrr);
        self::assertSame(1, $first->agentPricing?->pricingProfileVersion);
        self::assertSame(1, $first->agentPricing?->ruleVersion);

        $pricingService->reviseProfile(
            'quote.profile.revise.quote-agent-history.v2',
            'quote-agent-history',
            new AgentPricingProfileDefinition(AgentPricingState::Active, true),
            $this->pricingContext($owner, 'profile-revise-quote-agent-history-v2'),
        );
        $this->revisePricingRule(
            $pricingService,
            $owner,
            'quote-agent-history',
            'default',
            new AgentPricingRuleDefinition(AgentPricingState::Active, 700_000),
            'v2',
        );

        $replay = $quoteService->create(
            'agent.quote.history.000001',
            $agent,
            $offering['id'],
            $input,
            $this->correlation('history-replay'),
            $context,
        );
        self::assertTrue($replay->replayed);
        self::assertSame(850_000, $replay->finalPriceIrr);
        self::assertSame(1, $replay->agentPricing?->pricingProfileVersion);
        self::assertSame(1, $replay->agentPricing?->ruleVersion);
        self::assertSame($first->agentPricing?->resolutionId, $replay->agentPricing?->resolutionId);
        self::assertSame(1, DB::table('agent_pricing_resolutions')->count());

        $second = $quoteService->create(
            'agent.quote.history.000002',
            $agent,
            $offering['id'],
            $input,
            $this->correlation('history-second'),
            $context,
        );
        self::assertSame(700_000, $second->finalPriceIrr);
        self::assertSame(2, $second->agentPricing?->pricingProfileVersion);
        self::assertSame(2, $second->agentPricing?->ruleVersion);
        self::assertNotSame($first->agentPricing?->resolutionId, $second->agentPricing?->resolutionId);
        self::assertSame(2, DB::table('agent_pricing_resolutions')->count());
    }

    public function test_no_match_historical_replay_is_stable_after_version_only_profile_and_rule_revision_without_repricing(): void
    {
        $owner = $this->ownerAdministrator();
        $offering = $this->quoteOffering();
        $pricingService = $this->app->make(AgentPricingService::class);
        $this->activePricingProfile($pricingService, $owner, 'quote-agent-nomatch-history', true);
        $this->activePricingRule(
            $pricingService,
            $owner,
            'quote-agent-nomatch-history',
            'renew-only',
            new AgentPricingRuleDefinition(AgentPricingState::Active, 740_000, action: AgentPricingAction::Renew),
        );
        $agent = $this->agentSubject('quote-agent-nomatch-history');
        $quoteService = $this->app->make(QuoteService::class);
        $context = new QuoteAgentPricingContext($agent, AgentPricingAction::Purchase);
        $input = $this->pricing(null, 0);

        $first = $quoteService->create(
            'agent.quote.nomatch.history.000001',
            $agent,
            $offering['id'],
            $input,
            $this->correlation('nomatch-history-first'),
            $context,
        );
        self::assertSame(QuoteOverrideSource::None, $first->overrideSource);
        self::assertSame(1_000_000, $first->basePriceIrr);
        self::assertSame(1_000_000, $first->effectivePriceIrr);
        self::assertSame(1_000_000, $first->finalPriceIrr);
        $firstAgentPricing = $first->agentPricing;
        self::assertNotNull($firstAgentPricing);
        self::assertFalse($firstAgentPricing->matched());
        self::assertSame('quote-agent-nomatch-history', $firstAgentPricing->pricingProfileCode);
        self::assertSame(1, $firstAgentPricing->pricingProfileVersion);
        self::assertNull($firstAgentPricing->ruleId);
        self::assertNull($firstAgentPricing->ruleVersion);
        self::assertNull($firstAgentPricing->ruleConfigurationHash);
        $storedQuoteBefore = DB::table('quotes')->where('id', $first->quoteId)->first();
        $storedResolutionBefore = DB::table('agent_pricing_resolutions')
            ->where('id', $firstAgentPricing->resolutionId)
            ->first();
        self::assertNotNull($storedQuoteBefore);
        self::assertNotNull($storedResolutionBefore);
        self::assertSame(1, DB::table('agent_pricing_resolutions')->count());

        $pricingService->reviseProfile(
            'quote.profile.revise.quote-agent-nomatch-history.v2',
            'quote-agent-nomatch-history',
            new AgentPricingProfileDefinition(AgentPricingState::Active, true),
            $this->pricingContext($owner, 'profile-revise-quote-agent-nomatch-history-v2'),
        );
        $this->revisePricingRule(
            $pricingService,
            $owner,
            'quote-agent-nomatch-history',
            'renew-only',
            new AgentPricingRuleDefinition(AgentPricingState::Active, 690_000, action: AgentPricingAction::Renew),
            'v2',
        );

        $replay = $quoteService->create(
            'agent.quote.nomatch.history.000001',
            $agent,
            $offering['id'],
            $input,
            $this->correlation('nomatch-history-replay'),
            $context,
        );
        self::assertTrue($replay->replayed);
        self::assertSame($first->quoteId, $replay->quoteId);
        self::assertSame(QuoteOverrideSource::None, $replay->overrideSource);
        self::assertSame($first->basePriceIrr, $replay->basePriceIrr);
        self::assertSame($first->effectivePriceIrr, $replay->effectivePriceIrr);
        self::assertSame($first->finalPriceIrr, $replay->finalPriceIrr);
        self::assertSame($first->configurationSnapshotHash, $replay->configurationSnapshotHash);
        self::assertNotNull($replay->agentPricing);
        self::assertSame($firstAgentPricing->resolutionId, $replay->agentPricing->resolutionId);
        self::assertSame($firstAgentPricing->resolutionPublicId, $replay->agentPricing->resolutionPublicId);
        self::assertSame($firstAgentPricing->resolutionConfigurationHash, $replay->agentPricing->resolutionConfigurationHash);
        self::assertSame($firstAgentPricing->pricingProfilePublicId, $replay->agentPricing->pricingProfilePublicId);
        self::assertSame($firstAgentPricing->pricingProfileCode, $replay->agentPricing->pricingProfileCode);
        self::assertSame($firstAgentPricing->pricingProfileVersion, $replay->agentPricing->pricingProfileVersion);
        self::assertSame($firstAgentPricing->pricingProfileConfigurationHash, $replay->agentPricing->pricingProfileConfigurationHash);
        self::assertSame($firstAgentPricing->ruleId, $replay->agentPricing->ruleId);
        self::assertSame($firstAgentPricing->rulePublicId, $replay->agentPricing->rulePublicId);
        self::assertSame($firstAgentPricing->ruleCode, $replay->agentPricing->ruleCode);
        self::assertSame($firstAgentPricing->ruleVersion, $replay->agentPricing->ruleVersion);
        self::assertSame($firstAgentPricing->ruleConfigurationHash, $replay->agentPricing->ruleConfigurationHash);
        self::assertSame(1, DB::table('agent_pricing_resolutions')->count());
        self::assertSame((array) $storedQuoteBefore, (array) DB::table('quotes')->where('id', $first->quoteId)->first());
        self::assertSame(
            (array) $storedResolutionBefore,
            (array) DB::table('agent_pricing_resolutions')->where('id', $firstAgentPricing->resolutionId)->first(),
        );
    }

    public function test_mariadb_rejects_missing_or_forged_agent_binding_and_preserves_quote_immutability(): void
    {
        $owner = $this->ownerAdministrator();
        $offering = $this->quoteOffering();
        $pricingService = $this->app->make(AgentPricingService::class);
        $this->activePricingProfile($pricingService, $owner, 'quote-agent-db', true);
        $this->activePricingRule(
            $pricingService,
            $owner,
            'quote-agent-db',
            'default',
            new AgentPricingRuleDefinition(AgentPricingState::Active, 820_000),
        );
        $agent = $this->agentSubject('quote-agent-db');
        $context = new QuoteAgentPricingContext($agent, AgentPricingAction::Purchase);
        $quote = $this->app->make(QuoteService::class)->create(
            'agent.quote.db.000001',
            $agent,
            $offering['id'],
            $this->pricing(null, 0),
            $this->correlation('db-create'),
            $context,
        );

        $stored = DB::table('quotes')->where('id', $quote->quoteId)->first();
        self::assertNotNull($stored);
        /** @var array<string, mixed> $missingBinding */
        $missingBinding = (array) $stored;
        unset($missingBinding['id']);
        $missingBinding['public_id'] = (string) Str::ulid();
        $missingBinding['quote_key'] = 'agent.quote.db.missing-binding';
        foreach ([
            'agent_pricing_resolution_id', 'agent_pricing_resolution_public_id', 'agent_pricing_resolution_configuration_hash',
            'agent_profile_id_snapshot', 'agent_pricing_profile_id_snapshot', 'agent_pricing_profile_public_id_snapshot',
            'agent_pricing_profile_code_snapshot', 'agent_pricing_profile_version_snapshot', 'agent_pricing_profile_configuration_hash',
            'agent_pricing_action_snapshot', 'agent_pricing_rule_id_snapshot', 'agent_pricing_rule_public_id_snapshot',
            'agent_pricing_rule_code_snapshot', 'agent_pricing_rule_version_snapshot', 'agent_pricing_rule_configuration_hash',
            'agent_discount_combination_allowed',
        ] as $column) {
            $missingBinding[$column] = null;
        }
        $this->assertQueryRejected(static fn (): bool => DB::table('quotes')->insert($missingBinding));

        $unbound = $pricingService->resolve(
            new AgentPricingResolutionRequest(
                'agent.quote.db.unbound-resolution',
                $agent,
                'quote-agent-db',
                $offering['id'],
                AgentPricingAction::Renew,
            ),
            new AgentPricingResolutionContext($agent),
        );
        /** @var array<string, mixed> $forged */
        $forged = (array) $stored;
        unset($forged['id']);
        $forged['public_id'] = (string) Str::ulid();
        $forged['quote_key'] = 'agent.quote.db.forged-binding';
        $forged['agent_pricing_resolution_id'] = $unbound->resolutionId;
        $forged['agent_pricing_resolution_public_id'] = $unbound->resolutionPublicId;
        $forged['agent_pricing_resolution_configuration_hash'] = $unbound->configurationSnapshotHash;
        $this->assertQueryRejected(static fn (): bool => DB::table('quotes')->insert($forged));

        $this->assertQueryRejected(static fn (): int => DB::table('quotes')->where('id', $quote->quoteId)->update(['final_price_irr' => 1]));
        $this->assertQueryRejected(static fn (): int => DB::table('quotes')->where('id', $quote->quoteId)->delete());
        self::assertSame(1, DB::table('quotes')->count());
        self::assertSame(820_000, (int) DB::table('quotes')->where('id', $quote->quoteId)->value('final_price_irr'));
    }
}
