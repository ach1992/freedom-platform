<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Agents\Application\AgentPricingService;
use App\Modules\Agents\Domain\AgentPricingAction;
use App\Modules\Agents\Domain\AgentPricingRuleDefinition;
use App\Modules\Agents\Domain\AgentPricingState;
use App\Modules\Orders\Application\QuoteAgentPricingContext;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use Illuminate\Support\Facades\DB;

trait AgentPricingQuoteIntegrationCoreScenarios
{
    public function test_agent_quote_consumes_authoritative_matched_resolution_before_discount_and_snapshots_exact_identity(): void
    {
        $owner = $this->ownerAdministrator();
        $offering = $this->quoteOffering();
        $pricingService = $this->app->make(AgentPricingService::class);
        $this->activePricingProfile($pricingService, $owner, 'quote-agent-premium', true);
        $this->activePricingRule(
            $pricingService,
            $owner,
            'quote-agent-premium',
            'purchase-specific',
            new AgentPricingRuleDefinition(
                AgentPricingState::Active,
                850_000,
                AgentPricingAction::Purchase,
                $offering['id'],
                $offering['server_id'],
                $offering['product_id'],
            ),
        );
        $agent = $this->agentSubject('quote-agent-premium');
        $quoteService = $this->app->make(QuoteService::class);
        $input = $this->pricing('discount.integration', 50_000);
        $context = new QuoteAgentPricingContext($agent, AgentPricingAction::Purchase);

        $quote = $quoteService->create(
            'agent.quote.matched.000001',
            $agent,
            $offering['id'],
            $input,
            $this->correlation('matched'),
            $context,
        );

        self::assertSame(1_000_000, $quote->basePriceIrr);
        self::assertSame(QuoteOverrideSource::Agent, $quote->overrideSource);
        self::assertSame('quote-agent-premium', $quote->overrideReferenceCode);
        self::assertSame(850_000, $quote->overridePriceIrr);
        self::assertSame(850_000, $quote->effectivePriceIrr);
        self::assertSame(50_000, $quote->discountIrr);
        self::assertSame(800_000, $quote->finalPriceIrr);
        $agentPricing = $quote->agentPricing;
        self::assertNotNull($agentPricing);
        self::assertTrue($agentPricing->matched());
        self::assertSame('purchase-specific', $agentPricing->ruleCode);
        self::assertSame(1, $agentPricing->ruleVersion);
        self::assertSame(1, $agentPricing->pricingProfileVersion);
        self::assertTrue($agentPricing->discountCombinationAllowed);
        self::assertSame(AgentPricingAction::Purchase, $agentPricing->action);
        self::assertSame(64, strlen($agentPricing->resolutionConfigurationHash));
        self::assertSame(64, strlen($agentPricing->pricingProfileConfigurationHash));
        self::assertSame(64, strlen((string) $agentPricing->ruleConfigurationHash));

        $stored = DB::table('quotes')->where('id', $quote->quoteId)->first();
        self::assertNotNull($stored);
        self::assertSame($agentPricing->resolutionId, (int) $stored->agent_pricing_resolution_id);
        self::assertSame($agentPricing->resolutionPublicId, $stored->agent_pricing_resolution_public_id);
        self::assertSame($agentPricing->pricingProfilePublicId, $stored->agent_pricing_profile_public_id_snapshot);
        self::assertSame($agentPricing->rulePublicId, $stored->agent_pricing_rule_public_id_snapshot);
        self::assertSame(1, DB::table('agent_pricing_resolutions')->count());
        self::assertSame(0, DB::table('pricing_rule_resolutions')->count());
        self::assertSame(0, DB::table('promotion_usage_reservations')->count());
        self::assertSame(0, DB::table('payment_intents')->count());
        self::assertSame(0, DB::table('ledger_transactions')->count());

        $replay = $quoteService->create(
            'agent.quote.matched.000001',
            $agent,
            $offering['id'],
            $input,
            $this->correlation('matched-replay'),
            $context,
        );
        self::assertTrue($replay->replayed);
        self::assertSame($quote->quoteId, $replay->quoteId);
        self::assertSame($agentPricing->resolutionId, $replay->agentPricing?->resolutionId);
        self::assertSame(1, DB::table('agent_pricing_resolutions')->count());
    }

    public function test_no_match_uses_base_price_and_discount_combination_policy_still_fails_closed(): void
    {
        $owner = $this->ownerAdministrator();
        $offering = $this->quoteOffering();
        $pricingService = $this->app->make(AgentPricingService::class);
        $this->activePricingProfile($pricingService, $owner, 'quote-agent-no-match', false);
        $this->activePricingRule(
            $pricingService,
            $owner,
            'quote-agent-no-match',
            'renew-only',
            new AgentPricingRuleDefinition(AgentPricingState::Active, 700_000, action: AgentPricingAction::Renew),
        );
        $agent = $this->agentSubject('quote-agent-no-match');
        $quoteService = $this->app->make(QuoteService::class);
        $context = new QuoteAgentPricingContext($agent, AgentPricingAction::Purchase);

        $quote = $quoteService->create(
            'agent.quote.nomatch.000001',
            $agent,
            $offering['id'],
            $this->pricing(null, 0),
            $this->correlation('no-match'),
            $context,
        );
        self::assertSame(QuoteOverrideSource::None, $quote->overrideSource);
        self::assertNull($quote->overrideReferenceCode);
        self::assertNull($quote->overridePriceIrr);
        self::assertSame(1_000_000, $quote->effectivePriceIrr);
        self::assertSame(1_000_000, $quote->finalPriceIrr);
        $agentPricing = $quote->agentPricing;
        self::assertNotNull($agentPricing);
        self::assertFalse($agentPricing->matched());
        self::assertFalse($agentPricing->discountCombinationAllowed);

        $this->assertDomainMessage(
            'Agent pricing profile does not allow discount combination.',
            fn (): mixed => $quoteService->create(
                'agent.quote.nomatch.000002',
                $agent,
                $offering['id'],
                $this->pricing('discount.not-allowed', 1),
                $this->correlation('no-match-discount'),
                $context,
            ),
        );
        self::assertSame(1, DB::table('quotes')->count());
        self::assertSame(1, DB::table('agent_pricing_resolutions')->count());
    }
}
