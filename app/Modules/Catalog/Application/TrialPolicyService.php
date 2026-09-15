<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\TrialPolicyDefinition;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

final readonly class TrialPolicyService
{
    private const TARGET_TYPE = 'trial_policy';

    public function __construct(
        private CatalogMutationExecutor $executor,
        private CatalogMutationAudit $audit,
        private Clock $clock,
    ) {}

    /** @requirement CAT-006 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function create(
        int $offeringId,
        TrialPolicyDefinition $definition,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        if ($offeringId < 1) {
            throw new RuntimeException('Plan offering ID must be positive.');
        }
        $payloadHash = CatalogPayloadHash::make(['offering_id' => $offeringId, ...$definition->payload()]);

        return $this->executor->execute(
            'trial_policy.create',
            self::TARGET_TYPE,
            null,
            $payloadHash,
            $context,
            function (Connection $connection) use ($offeringId, $definition, $context, $payloadHash): CatalogMutationReceipt {
                $this->assertDraftTrialOffering($connection, $offeringId);
                if ($connection->table('trial_policies')->where('plan_offering_id', $offeringId)->exists()) {
                    throw new RuntimeException('Trial policy already exists.');
                }
                $this->assertReferences($connection, $definition);
                $configurationHash = CatalogPayloadHash::make($definition->payload());
                $policyId = (int) $connection->table('trial_policies')->insertGetId([
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
                    'trial_policy.create',
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

    /** @requirement CAT-006 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function update(
        int $policyId,
        int $expectedVersion,
        TrialPolicyDefinition $definition,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        if ($policyId < 1 || $expectedVersion < 1) {
            throw new RuntimeException('Trial policy ID and version must be positive.');
        }
        $payloadHash = CatalogPayloadHash::make([
            'policy_id' => $policyId,
            'expected_version' => $expectedVersion,
            ...$definition->payload(),
        ]);

        return $this->executor->execute(
            'trial_policy.update',
            self::TARGET_TYPE,
            $policyId,
            $payloadHash,
            $context,
            function (Connection $connection) use ($policyId, $expectedVersion, $definition, $context, $payloadHash): CatalogMutationReceipt {
                /** @var object{plan_offering_id: int|string, configuration_hash: string, version: int|string, enabled: bool|int}|null $policy */
                $policy = $connection->table('trial_policies')
                    ->where('id', $policyId)
                    ->lockForUpdate()
                    ->first(['plan_offering_id', 'configuration_hash', 'version', 'enabled']);
                if ($policy === null) {
                    throw new RuntimeException('Trial policy does not exist.');
                }
                if ((int) $policy->version !== $expectedVersion) {
                    throw new RuntimeException('Catalog version conflict.');
                }

                $offeringId = (int) $policy->plan_offering_id;
                $this->assertDraftTrialOffering($connection, $offeringId);
                $this->assertReferences($connection, $definition);
                $before = $this->safeExistingState($connection, $policyId, $offeringId, $policy, $payloadHash);
                $configurationHash = CatalogPayloadHash::make($definition->payload());
                $newVersion = $expectedVersion + 1;
                $connection->table('trial_policies')->where('id', $policyId)->update([
                    ...$this->scalarValues($definition),
                    'configuration_hash' => $configurationHash,
                    'version' => $newVersion,
                    'updated_at' => $this->timestamp(),
                ]);
                foreach (['trial_policy_tiers', 'trial_policy_tags'] as $table) {
                    $connection->table($table)->where('trial_policy_id', $policyId)->delete();
                }
                $this->replaceChildren($connection, $policyId, $definition);
                $this->recordHistory($connection, $policyId, $newVersion, $definition, $configurationHash, $context);
                $after = $this->safeState($offeringId, $newVersion, $definition, $configurationHash, $payloadHash);

                return $this->audit->record(
                    $connection,
                    'trial_policy.update',
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

    private function assertDraftTrialOffering(Connection $connection, int $offeringId): void
    {
        /** @var object{state: string, trial_allowed: bool|int}|null $offering */
        $offering = $connection->table('plan_offerings')
            ->where('id', $offeringId)
            ->lockForUpdate()
            ->first(['state', 'trial_allowed']);
        if ($offering === null) {
            throw new RuntimeException('Plan offering does not exist.');
        }
        if ($offering->state !== 'draft' || ! (bool) $offering->trial_allowed) {
            throw new DomainException('Trial policy requires a draft trial-enabled Offering.');
        }
    }

    private function assertReferences(Connection $connection, TrialPolicyDefinition $definition): void
    {
        if ($definition->eligibleTagIds !== []
            && $connection->table('customer_tags')
                ->whereIn('id', $definition->eligibleTagIds)
                ->where('is_active', true)
                ->count() !== count($definition->eligibleTagIds)
        ) {
            throw new DomainException('One or more trial eligibility tags are unavailable.');
        }
    }

    /** @return array<string, bool|int|string> */
    private function scalarValues(TrialPolicyDefinition $definition): array
    {
        return [
            'enabled' => $definition->enabled,
            'data_bytes' => $definition->dataBytes,
            'duration_days' => $definition->durationDays,
            'daily_capacity' => $definition->dailyCapacity,
            'phone_verification_policy' => $definition->phoneVerificationPolicy->value,
            'membership_required' => $definition->membershipRequired,
            'one_per_user' => $definition->onePerUser,
            'one_per_phone' => $definition->onePerPhone,
            'administrator_regrant_allowed' => $definition->administratorRegrantAllowed,
            'fallback_allowed' => $definition->fallbackAllowed,
            'tag_match_mode' => $definition->tagMatchMode->value,
            'delivery_template_key' => $definition->deliveryTemplateKey,
        ];
    }

    private function replaceChildren(
        Connection $connection,
        int $policyId,
        TrialPolicyDefinition $definition,
    ): void {
        $now = $this->timestamp();
        foreach ($definition->eligibleTierCodes as $tierCode) {
            $connection->table('trial_policy_tiers')->insert([
                'trial_policy_id' => $policyId,
                'tier_code' => $tierCode,
                'created_at' => $now,
            ]);
        }
        foreach ($definition->eligibleTagIds as $tagId) {
            $connection->table('trial_policy_tags')->insert([
                'trial_policy_id' => $policyId,
                'customer_tag_id' => $tagId,
                'created_at' => $now,
            ]);
        }
    }

    private function recordHistory(
        Connection $connection,
        int $policyId,
        int $version,
        TrialPolicyDefinition $definition,
        string $configurationHash,
        CatalogChangeContext $context,
    ): void {
        $connection->table('trial_policy_histories')->insert([
            'trial_policy_id' => $policyId,
            'version' => $version,
            'configuration_hash' => $configurationHash,
            'enabled' => $definition->enabled,
            'tier_count' => count($definition->eligibleTierCodes),
            'tag_count' => count($definition->eligibleTagIds),
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
        TrialPolicyDefinition $definition,
        string $configurationHash,
        string $payloadHash,
    ): array {
        return [
            'offering_id' => $offeringId,
            'version' => $version,
            'enabled' => $definition->enabled,
            'data_bytes' => $definition->dataBytes,
            'duration_days' => $definition->durationDays,
            'daily_capacity' => $definition->dailyCapacity,
            'phone_verification_policy' => $definition->phoneVerificationPolicy->value,
            'membership_required' => $definition->membershipRequired,
            'one_per_user' => $definition->onePerUser,
            'one_per_phone' => $definition->onePerPhone,
            'administrator_regrant_allowed' => $definition->administratorRegrantAllowed,
            'fallback_allowed' => $definition->fallbackAllowed,
            'tag_match_mode' => $definition->tagMatchMode->value,
            'delivery_template_key' => $definition->deliveryTemplateKey,
            'tier_count' => count($definition->eligibleTierCodes),
            'tag_count' => count($definition->eligibleTagIds),
            'configuration_hash' => $configurationHash,
            'request_payload_hash' => $payloadHash,
        ];
    }

    /**
     * @param  object{configuration_hash: string, version: int|string, enabled: bool|int}  $policy
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
            'tier_count' => $connection->table('trial_policy_tiers')->where('trial_policy_id', $policyId)->count(),
            'tag_count' => $connection->table('trial_policy_tags')->where('trial_policy_id', $policyId)->count(),
            'configuration_hash' => $policy->configuration_hash,
            'request_payload_hash' => $payloadHash,
        ];
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
