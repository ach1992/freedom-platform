<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Agents\Application\AgentPricingResolutionContext;
use App\Modules\Agents\Application\AgentPricingResolutionReceipt;
use App\Modules\Agents\Application\AgentPricingResolutionRequest;
use App\Modules\Agents\Domain\AgentPricingAction;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use DomainException;
use Illuminate\Database\Connection;

trait QuoteServiceAgentPricing
{
    private function resolveAgentPricing(
        Connection $connection,
        string $quoteKey,
        int $userId,
        int $planOfferingId,
        QuoteAgentPricingContext $context,
    ): AgentPricingResolutionReceipt {
        /** @var object{pricing_profile_code:string|null}|null $agent */
        $agent = $connection->table('agent_profiles')
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first(['pricing_profile_code']);
        if ($agent === null || $agent->pricing_profile_code === null || $agent->pricing_profile_code === '') {
            throw new DomainException('Agent quote pricing requires a current pricing profile.');
        }

        return $this->agentPricingService->resolve(
            new AgentPricingResolutionRequest(
                $this->agentPricingResolutionKey($quoteKey, $userId, $planOfferingId, $context->action),
                $userId,
                $agent->pricing_profile_code,
                $planOfferingId,
                $context->action,
            ),
            new AgentPricingResolutionContext($context->actorUserId),
        );
    }

    private function agentPricingResolutionKey(
        string $quoteKey,
        int $userId,
        int $planOfferingId,
        AgentPricingAction $action,
    ): string {
        return 'quote-agent:'.hash('sha256', json_encode([
            'quote_key' => $quoteKey,
            'user_id' => $userId,
            'plan_offering_id' => $planOfferingId,
            'action' => $action->value,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function agentPricingSnapshotArray(AgentPricingResolutionReceipt $resolution): array
    {
        return [
            'action' => $resolution->action->value,
            'agent_profile_id' => $resolution->agentProfileId,
            'discount_combination_allowed' => $resolution->discountCombinationAllowed,
            'pricing_profile' => [
                'configuration_hash' => $resolution->pricingProfileConfigurationHash,
                'id' => $resolution->pricingProfileId,
                'profile_code' => $resolution->pricingProfileCode,
                'public_id' => $resolution->pricingProfilePublicId,
                'version' => $resolution->pricingProfileVersion,
            ],
            'resolution' => [
                'configuration_hash' => $resolution->configurationSnapshotHash,
                'id' => $resolution->resolutionId,
                'public_id' => $resolution->resolutionPublicId,
            ],
            'rule' => $resolution->matched() ? [
                'configuration_hash' => $resolution->ruleConfigurationHash,
                'id' => $resolution->ruleId,
                'public_id' => $resolution->rulePublicId,
                'rule_code' => $resolution->ruleCode,
                'version' => $resolution->ruleVersion,
            ] : null,
        ];
    }

    /** @return array<string, int|string|bool|null> */
    private function agentPricingColumns(AgentPricingResolutionReceipt $resolution): array
    {
        return [
            'agent_pricing_resolution_id' => $resolution->resolutionId,
            'agent_pricing_resolution_public_id' => $resolution->resolutionPublicId,
            'agent_pricing_resolution_configuration_hash' => $resolution->configurationSnapshotHash,
            'agent_profile_id_snapshot' => $resolution->agentProfileId,
            'agent_pricing_profile_id_snapshot' => $resolution->pricingProfileId,
            'agent_pricing_profile_public_id_snapshot' => $resolution->pricingProfilePublicId,
            'agent_pricing_profile_code_snapshot' => $resolution->pricingProfileCode,
            'agent_pricing_profile_version_snapshot' => $resolution->pricingProfileVersion,
            'agent_pricing_profile_configuration_hash' => $resolution->pricingProfileConfigurationHash,
            'agent_pricing_action_snapshot' => $resolution->action->value,
            'agent_pricing_rule_id_snapshot' => $resolution->ruleId,
            'agent_pricing_rule_public_id_snapshot' => $resolution->rulePublicId,
            'agent_pricing_rule_code_snapshot' => $resolution->ruleCode,
            'agent_pricing_rule_version_snapshot' => $resolution->ruleVersion,
            'agent_pricing_rule_configuration_hash' => $resolution->ruleConfigurationHash,
            'agent_discount_combination_allowed' => $resolution->discountCombinationAllowed,
        ];
    }

    private function validateOverrideReference(
        Connection $connection,
        int $userId,
        string $accountType,
        QuotePricingInput $pricing,
    ): void {
        if ($pricing->overrideSource === QuoteOverrideSource::Agent) {
            if ($accountType !== 'agent') {
                throw new DomainException('Agent quote override requires an agent account.');
            }
            $matches = $connection->table('agent_profiles')
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->where('pricing_profile_code', $pricing->overrideReferenceCode)
                ->lockForUpdate()
                ->count();
            if ($matches !== 1) {
                throw new DomainException('Agent quote override reference is not current.');
            }
        }

        if ($pricing->overrideSource === QuoteOverrideSource::Tier) {
            $matches = $connection->table('customer_profiles as p')
                ->join('customer_tiers as t', 't.id', '=', 'p.current_tier_id')
                ->where('p.user_id', $userId)
                ->where('t.is_active', true)
                ->where('t.code', $pricing->overrideReferenceCode)
                ->lockForUpdate()
                ->count();
            if ($matches !== 1) {
                throw new DomainException('Tier quote override reference is not current.');
            }
        }
    }
}
