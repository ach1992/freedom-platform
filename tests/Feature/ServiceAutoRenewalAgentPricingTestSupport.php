<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Agents\Application\AgentApplicationService;
use App\Modules\Agents\Application\AgentChangeContext;
use App\Modules\Agents\Application\AgentPricingService;
use App\Modules\Agents\Domain\AgentPricingAction;
use App\Modules\Agents\Domain\AgentPricingRuleDefinition;
use App\Modules\Agents\Domain\AgentPricingState;
use Illuminate\Support\Facades\DB;

trait ServiceAutoRenewalAgentPricingTestSupport
{
    /**
     * @param  array{user_id:int,offering_id:int}  $scenario
     * @return array{profile_code:string,rule_code:string}
     */
    private function enableScenarioAgentRenewPricing(array $scenario, int $priceIrr, string $suffix): array
    {
        $administratorId = $this->ownerAdministrator();
        $profileCode = 'ar-'.substr(hash('sha256', 'profile:'.$suffix), 0, 16);
        $ruleCode = 'renew-'.substr(hash('sha256', 'rule:'.$suffix), 0, 16);
        $pricing = $this->app->make(AgentPricingService::class);

        $this->activePricingProfile($pricing, $administratorId, $profileCode, false);
        $this->activePricingRule(
            $pricing,
            $administratorId,
            $profileCode,
            $ruleCode,
            new AgentPricingRuleDefinition(
                AgentPricingState::Active,
                $priceIrr,
                AgentPricingAction::Renew,
                $scenario['offering_id'],
            ),
        );

        $agents = $this->app->make(AgentApplicationService::class);
        $submitted = $agents->submit(
            $scenario['user_id'],
            $this->agentAutoRenewContext($suffix.'-submit', actorUserId: $scenario['user_id']),
        );
        $applicationId = (int) ($submitted->after['application_id'] ?? 0);
        if ($applicationId < 1) {
            $applicationId = (int) DB::table('agent_applications')
                ->where('customer_id', $scenario['user_id'])
                ->orderByDesc('application_version')
                ->value('id');
        }

        $agents->claim(
            $applicationId,
            $this->agentAutoRenewContext($suffix.'-claim', actorAdministratorId: $administratorId),
        );
        $agents->approve(
            $applicationId,
            $profileCode,
            $this->agentAutoRenewContext(
                $suffix.'-approve',
                actorAdministratorId: $administratorId,
                reason: 'Service auto-renew price-change integration scenario.',
            ),
        );

        self::assertSame('agent', DB::table('users')->where('id', $scenario['user_id'])->value('account_type'));

        return ['profile_code' => $profileCode, 'rule_code' => $ruleCode];
    }

    /**
     * @param  array{offering_id:int}  $scenario
     * @param  array{profile_code:string,rule_code:string}  $pricingAuthority
     */
    private function reviseScenarioAgentRenewPricing(
        array $scenario,
        array $pricingAuthority,
        int $priceIrr,
        string $suffix,
    ): void {
        $administratorId = $this->ownerAdministrator();
        $this->revisePricingRule(
            $this->app->make(AgentPricingService::class),
            $administratorId,
            $pricingAuthority['profile_code'],
            $pricingAuthority['rule_code'],
            new AgentPricingRuleDefinition(
                AgentPricingState::Active,
                $priceIrr,
                AgentPricingAction::Renew,
                $scenario['offering_id'],
            ),
            $suffix,
        );
    }

    private function agentAutoRenewContext(
        string $suffix,
        ?int $actorAdministratorId = null,
        ?int $actorUserId = null,
        ?string $reason = null,
    ): AgentChangeContext {
        return new AgentChangeContext(
            hash('sha256', 'service-auto-renew-agent-request:'.$suffix),
            substr(hash('sha256', 'service-auto-renew-agent-correlation:'.$suffix), 0, 64),
            'service-auto-renew-test',
            $reason,
            $actorAdministratorId,
            $actorUserId,
        );
    }
}
