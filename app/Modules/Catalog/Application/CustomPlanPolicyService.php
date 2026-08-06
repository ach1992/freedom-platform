<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\CustomPlanPolicyDefinition;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

final readonly class CustomPlanPolicyService
{
    private const TARGET_TYPE = 'custom_plan_policy';

    public function __construct(
        private CatalogMutationExecutor $executor,
        private CatalogMutationAudit $audit,
        private Clock $clock,
    ) {}

    /** @requirement CAT-005 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function create(
        int $offeringId,
        CustomPlanPolicyDefinition $definition,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        if ($offeringId < 1) {
            throw new RuntimeException('Plan offering ID must be positive.');
        }
        $payloadHash = CatalogPayloadHash::make(['offering_id' => $offeringId, ...$definition->payload()]);

        return $this->executor->execute(
            'custom_plan_policy.create',
            self::TARGET_TYPE,
            null,
            $payloadHash,
            $context,
            function (Connection $connection) use ($offeringId, $definition, $context, $payloadHash): CatalogMutationReceipt {
                $this->assertDraftCustomPlanOffering($connection, $offeringId);
                if ($connection->table('custom_plan_policies')->where('plan_offering_id', $offeringId)->exists()) {
                    throw new RuntimeException('Custom-plan policy already exists.');
                }
                $this->assertReferences($connection, $definition);
                $configurationHash = CatalogPayloadHash::make($definition->payload());
                $policyId = (int) $connection->table('custom_plan_policies')->insertGetId([
                    'plan_offering_id' => $offeringId,
                    ...$this->scalarValues($definition),
                    'configuration_hash' => $configurationHash,
                    'version' => 1,
                    'created_at' => $this->timestamp(),
                    'updated_at' => $this->timestamp(),
                ]);
                $this->replaceChildren($connection, $policyId, $definition);
                $this->recordHistory($connection, $policyId, 1, $definition, $configurationHash, $context);
                $after = $this->safeState($offeringId, 1, $definition, $configurationHash, $payloadHash);

                return $this->audit->record(
                    $connection,
                    'custom_plan_policy.create',
                    self::TARGET_TYPE,
                    $policyId,
                    $context,
                    [],
                    $after,
                    true,
                );
            },
        );
    }

    public function update(
        int $policyId,
        int $expectedVersion,
        CustomPlanPolicyDefinition $definition,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        if ($policyId < 1 || $expectedVersion < 1) {
            throw new RuntimeException('Custom-plan policy ID and version must be positive.');
        }
        $payloadHash = CatalogPayloadHash::make([
            'policy_id' => $policyId,
            'expected_version' => $expectedVersion,
            ...$definition->payload(),
        ]);

        return $this->executor->execute(
            'custom_plan_policy.update',
            self::TARGET_TYPE,
            $policyId,
            $payloadHash,
            $context,
            function (Connection $connection) use ($policyId, $expectedVersion, $definition, $context, $payloadHash): CatalogMutationReceipt {
                /** @var object{id: int|string, plan_offering_id: int|string, configuration_hash: string, version: int|string, enabled: bool|int}|null $policy */
                $policy = $connection->table('custom_plan_policies')
                    ->where('id', $policyId)
                    ->lockForUpdate()
                    ->first(['id', 'plan_offering_id', 'configuration_hash', 'version', 'enabled']);
                if ($policy === null) {
                    throw new RuntimeException('Custom-plan policy does not exist.');
                }
                if ((int) $policy->version !== $expectedVersion) {
                    throw new RuntimeException('Catalog version conflict.');
                }
                $offeringId = (int) $policy->plan_offering_id;
                $this->assertDraftCustomPlanOffering($connection, $offeringId);
                $this->assertReferences($connection, $definition);
                $before = $this->safeExistingState($connection, $policyId, $offeringId, $policy, $payloadHash);

                $configurationHash = CatalogPayloadHash::make($definition->payload());
                $newVersion = $expectedVersion + 1;
                $connection->table('custom_plan_policies')->where('id', $policyId)->update([
                    ...$this->scalarValues($definition),
                    'configuration_hash' => $configurationHash,
                    'version' => $newVersion,
                    'updated_at' => $this->timestamp(),
                ]);
                foreach (['custom_plan_policy_tiers', 'custom_plan_policy_tags', 'custom_plan_policy_separators', 'custom_plan_policy_reserved_words'] as $table) {
                    $connection->table($table)->where('custom_plan_policy_id', $policyId)->delete();
                }
                $this->replaceChildren($connection, $policyId, $definition);
                $this->recordHistory($connection, $policyId, $newVersion, $definition, $configurationHash, $context);
                $after = $this->safeState($offeringId, $newVersion, $definition, $configurationHash, $payloadHash);

                return $this->audit->record(
                    $connection,
                    'custom_plan_policy.update',
                    self::TARGET_TYPE,
                    $policyId,
                    $context,
                    $before,
                    $after,
                    $before !== $after,
                );
            },
        );
    }

    private function assertDraftCustomPlanOffering(Connection $connection, int $offeringId): void
    {
        /** @var object{state: string, custom_plan_allowed: bool|int}|null $offering */
        $offering = $connection->table('plan_offerings')
            ->where('id', $offeringId)
            ->lockForUpdate()
            ->first(['state', 'custom_plan_allowed']);
        if ($offering === null) {
            throw new RuntimeException('Plan offering does not exist.');
        }
        if ($offering->state !== 'draft' || ! (bool) $offering->custom_plan_allowed) {
            throw new DomainException('Custom-plan policy requires a draft custom-plan Offering.');
        }
    }

    private function assertReferences(Connection $connection, CustomPlanPolicyDefinition $definition): void
    {
        if ($definition->eligibleTagIds !== []
            && $connection->table('customer_tags')
                ->whereIn('id', $definition->eligibleTagIds)
                ->where('is_active', true)
                ->count() !== count($definition->eligibleTagIds)
        ) {
            throw new DomainException('One or more custom-plan eligibility tags are unavailable.');
        }
    }

    /** @return array<string, bool|int|string> */
    private function scalarValues(CustomPlanPolicyDefinition $definition): array
    {
        return [
            'enabled' => $definition->enabled,
            'minimum_data_gb' => $definition->minimumDataGb,
            'maximum_data_gb' => $definition->maximumDataGb,
            'data_step_gb' => $definition->dataStepGb,
            'minimum_days' => $definition->minimumDays,
            'maximum_days' => $definition->maximumDays,
            'day_step' => $definition->dayStep,
            ...$definition->customerPricing->payload('customer'),
            ...$definition->agentPricing->payload('agent'),
            'discount_eligible' => $definition->discountEligible,
            'tag_match_mode' => $definition->tagMatchMode->value,
            'username_mode' => $definition->usernameMode->value,
            'username_minimum_length' => $definition->usernameMinimumLength,
            'username_maximum_length' => $definition->usernameMaximumLength,
        ];
    }

    private function replaceChildren(
        Connection $connection,
        int $policyId,
        CustomPlanPolicyDefinition $definition,
    ): void {
        $now = $this->timestamp();
        foreach ($definition->eligibleTierCodes as $tierCode) {
            $connection->table('custom_plan_policy_tiers')->insert([
                'custom_plan_policy_id' => $policyId,
                'tier_code' => $tierCode,
                'created_at' => $now,
            ]);
        }
        foreach ($definition->eligibleTagIds as $tagId) {
            $connection->table('custom_plan_policy_tags')->insert([
                'custom_plan_policy_id' => $policyId,
                'customer_tag_id' => $tagId,
                'created_at' => $now,
            ]);
        }
        foreach ($definition->allowedSeparators as $separator) {
            $connection->table('custom_plan_policy_separators')->insert([
                'custom_plan_policy_id' => $policyId,
                'separator' => $separator,
                'created_at' => $now,
            ]);
        }
        foreach ($definition->reservedWords as $word) {
            $connection->table('custom_plan_policy_reserved_words')->insert([
                'custom_plan_policy_id' => $policyId,
                'normalized_word' => $word,
                'created_at' => $now,
            ]);
        }
    }

    private function recordHistory(
        Connection $connection,
        int $policyId,
        int $version,
        CustomPlanPolicyDefinition $definition,
        string $configurationHash,
        CatalogChangeContext $context,
    ): void {
        $connection->table('custom_plan_policy_histories')->insert([
            'custom_plan_policy_id' => $policyId,
            'version' => $version,
            'configuration_hash' => $configurationHash,
            'enabled' => $definition->enabled,
            'tier_count' => count($definition->eligibleTierCodes),
            'tag_count' => count($definition->eligibleTagIds),
            'reserved_word_count' => count($definition->reservedWords),
            'actor_administrator_id' => $context->actorAdministratorId,
            'reason_code' => $context->reasonCode,
            'reason' => $context->requireReason(),
            'correlation_id' => $context->correlationId,
            'created_at' => $this->timestamp(),
        ]);
    }

    /** @return array<string, bool|int|string> */
    private function safeState(
        int $offeringId,
        int $version,
        CustomPlanPolicyDefinition $definition,
        string $configurationHash,
        string $payloadHash,
    ): array {
        return [
            'offering_id' => $offeringId,
            'version' => $version,
            'enabled' => $definition->enabled,
            'minimum_data_gb' => $definition->minimumDataGb,
            'maximum_data_gb' => $definition->maximumDataGb,
            'data_step_gb' => $definition->dataStepGb,
            'minimum_days' => $definition->minimumDays,
            'maximum_days' => $definition->maximumDays,
            'day_step' => $definition->dayStep,
            'customer_base_price_irr' => $definition->customerPricing->basePriceIrr,
            'customer_price_per_gb_irr' => $definition->customerPricing->pricePerGbIrr,
            'customer_price_per_day_irr' => $definition->customerPricing->pricePerDayIrr,
            'customer_minimum_order_amount_irr' => $definition->customerPricing->minimumOrderAmountIrr,
            'agent_base_price_irr' => $definition->agentPricing->basePriceIrr,
            'agent_price_per_gb_irr' => $definition->agentPricing->pricePerGbIrr,
            'agent_price_per_day_irr' => $definition->agentPricing->pricePerDayIrr,
            'agent_minimum_order_amount_irr' => $definition->agentPricing->minimumOrderAmountIrr,
            'discount_eligible' => $definition->discountEligible,
            'username_mode' => $definition->usernameMode->value,
            'tier_count' => count($definition->eligibleTierCodes),
            'tag_count' => count($definition->eligibleTagIds),
            'separator_count' => count($definition->allowedSeparators),
            'reserved_word_count' => count($definition->reservedWords),
            'configuration_hash' => $configurationHash,
            'request_payload_hash' => $payloadHash,
        ];
    }

    /**
     * @param object{configuration_hash: string, version: int|string, enabled: bool|int} $policy
     * @return array<string, bool|int|string>
     */
    private function safeExistingState(
        Connection $connection,
        int $policyId,
        int $offeringId,
        object $policy,
        string $payloadHash,
    ): array {
        return [
            'offering_id' => $offeringId,
            'version' => (int) $policy->version,
            'enabled' => (bool) $policy->enabled,
            'tier_count' => $connection->table('custom_plan_policy_tiers')->where('custom_plan_policy_id', $policyId)->count(),
            'tag_count' => $connection->table('custom_plan_policy_tags')->where('custom_plan_policy_id', $policyId)->count(),
            'separator_count' => $connection->table('custom_plan_policy_separators')->where('custom_plan_policy_id', $policyId)->count(),
            'reserved_word_count' => $connection->table('custom_plan_policy_reserved_words')->where('custom_plan_policy_id', $policyId)->count(),
            'configuration_hash' => $policy->configuration_hash,
            'request_payload_hash' => $payloadHash,
        ];
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
