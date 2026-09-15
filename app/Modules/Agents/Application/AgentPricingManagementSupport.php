<?php

declare(strict_types=1);

namespace App\Modules\Agents\Application;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\Agents\Domain\AgentPricingProfileDefinition;
use App\Modules\Agents\Domain\AgentPricingRuleDefinition;
use App\Modules\Agents\Domain\AgentPricingState;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

trait AgentPricingManagementSupport
{
    private function writeProfile(string $operation, string $key, string $code, AgentPricingProfileDefinition $definition, AccessChangeContext $context): AgentPricingProfileVersionReceipt
    {
        $this->mutationKey($key);
        $reason = $context->requireReason();
        $this->authorizer->authorize($context->actorAdministratorId, self::MANAGE_PERMISSION);
        $payloadHash = $this->hash(['actor' => $context->actorAdministratorId, 'configuration' => $this->profileSnapshot($code, $definition), 'operation' => $operation]);

        try {
            return $this->database->connection()->transaction(function (Connection $db) use ($operation, $key, $code, $definition, $context, $reason, $payloadHash): AgentPricingProfileVersionReceipt {
                $existing = $this->profileVersionByKey($db, $key, true);
                if ($existing !== null) {
                    return $this->profileReceipt($existing, $payloadHash, true);
                }
                /** @var object{id:int|string}|null $profile */
                $profile = $db->table('agent_pricing_profiles')->where('profile_code', $code)->lockForUpdate()->first(['id']);
                if ($operation === 'create') {
                    if ($profile !== null) {
                        throw new DomainException('Agent pricing profile code already exists.');
                    }
                    $profileId = (int) $db->table('agent_pricing_profiles')->insertGetId(['public_id' => (string) Str::ulid(), 'profile_code' => $code, 'created_at' => $this->timestamp()]);
                    $version = 1;
                } else {
                    if ($profile === null) {
                        throw new DomainException('Agent pricing profile does not exist.');
                    }
                    $profileId = $this->positive($profile->id, 'Agent pricing profile ID');
                    $latest = $this->latestProfileVersion($db, $profileId);
                    if ($latest === null) {
                        throw new RuntimeException('Agent pricing profile has no stored configuration.');
                    }
                    if ($latest->state === AgentPricingState::Archived->value) {
                        throw new DomainException('Archived agent pricing profile cannot be revised.');
                    }
                    $version = $this->positive($latest->version, 'Agent pricing profile version') + 1;
                }
                $snapshot = $this->json($this->profileSnapshot($code, $definition));
                $db->table('agent_pricing_profile_versions')->insert([
                    'agent_pricing_profile_id' => $profileId, 'mutation_key' => $key, 'mutation_payload_hash' => $payloadHash,
                    'version' => $version, 'state' => $definition->state->value, 'discount_combination_allowed' => $definition->discountCombinationAllowed,
                    'configuration_snapshot' => $snapshot, 'configuration_hash' => hash('sha256', $snapshot),
                    'actor_administrator_id' => $context->actorAdministratorId, 'reason_code' => $context->reasonCode, 'reason' => $reason,
                    'correlation_id' => $context->correlationId, 'created_at' => $this->timestamp(),
                ]);
                $created = $this->profileVersionByKey($db, $key);
                if ($created === null) {
                    throw new RuntimeException('Agent pricing profile version persistence failed.');
                }

                return $this->profileReceipt($created, $payloadHash, false);
            });
        } catch (QueryException $exception) {
            $existing = $this->profileVersionByKey($this->database->connection(), $key);
            if ($existing !== null) {
                return $this->profileReceipt($existing, $payloadHash, true);
            }
            throw $exception;
        }
    }

    private function writeRule(string $operation, string $key, string $profileCode, string $ruleCode, AgentPricingRuleDefinition $definition, AccessChangeContext $context): AgentPricingRuleVersionReceipt
    {
        $this->mutationKey($key);
        $reason = $context->requireReason();
        $this->authorizer->authorize($context->actorAdministratorId, self::MANAGE_PERMISSION);
        $payloadHash = $this->hash(['actor' => $context->actorAdministratorId, 'configuration' => $this->ruleSnapshot($profileCode, $ruleCode, $definition), 'operation' => $operation]);

        try {
            return $this->database->connection()->transaction(function (Connection $db) use ($operation, $key, $profileCode, $ruleCode, $definition, $context, $reason, $payloadHash): AgentPricingRuleVersionReceipt {
                $existing = $this->ruleVersionByKey($db, $key, true);
                if ($existing !== null) {
                    return $this->ruleReceipt($existing, $payloadHash, true);
                }
                /** @var object{id:int|string}|null $profile */
                $profile = $db->table('agent_pricing_profiles')->where('profile_code', $profileCode)->lockForUpdate()->first(['id']);
                if ($profile === null) {
                    throw new DomainException('Agent pricing profile does not exist.');
                }
                $profileId = $this->positive($profile->id, 'Agent pricing profile ID');
                $profileVersion = $this->latestProfileVersion($db, $profileId);
                if ($profileVersion === null || $profileVersion->state === AgentPricingState::Archived->value) {
                    throw new DomainException('Archived or unconfigured agent pricing profile rules cannot be revised.');
                }
                $this->validateScope($db, $definition);
                /** @var object{id:int|string}|null $rule */
                $rule = $db->table('agent_pricing_rules')->where('agent_pricing_profile_id', $profileId)->where('rule_code', $ruleCode)->lockForUpdate()->first(['id']);
                if ($operation === 'create') {
                    if ($rule !== null) {
                        throw new DomainException('Agent pricing rule code already exists in this profile.');
                    }
                    $ruleId = (int) $db->table('agent_pricing_rules')->insertGetId(['public_id' => (string) Str::ulid(), 'agent_pricing_profile_id' => $profileId, 'rule_code' => $ruleCode, 'created_at' => $this->timestamp()]);
                    $version = 1;
                } else {
                    if ($rule === null) {
                        throw new DomainException('Agent pricing rule does not exist.');
                    }
                    $ruleId = $this->positive($rule->id, 'Agent pricing rule ID');
                    $latest = $this->latestRuleVersion($db, $ruleId);
                    if ($latest === null) {
                        throw new RuntimeException('Agent pricing rule has no stored configuration.');
                    }
                    if ($latest->state === AgentPricingState::Archived->value) {
                        throw new DomainException('Archived agent pricing rule cannot be revised.');
                    }
                    $version = $this->positive($latest->version, 'Agent pricing rule version') + 1;
                }
                $snapshot = $this->json($this->ruleSnapshot($profileCode, $ruleCode, $definition));
                $db->table('agent_pricing_rule_versions')->insert([
                    'agent_pricing_rule_id' => $ruleId, 'mutation_key' => $key, 'mutation_payload_hash' => $payloadHash, 'version' => $version,
                    'state' => $definition->state->value, 'override_price_irr' => $definition->overridePriceIrr, 'action' => $definition->action?->value,
                    'plan_offering_id' => $definition->planOfferingId, 'sales_server_id' => $definition->salesServerId, 'product_id' => $definition->productId,
                    'configuration_snapshot' => $snapshot, 'configuration_hash' => hash('sha256', $snapshot),
                    'actor_administrator_id' => $context->actorAdministratorId, 'reason_code' => $context->reasonCode, 'reason' => $reason,
                    'correlation_id' => $context->correlationId, 'created_at' => $this->timestamp(),
                ]);
                $created = $this->ruleVersionByKey($db, $key);
                if ($created === null) {
                    throw new RuntimeException('Agent pricing rule version persistence failed.');
                }

                return $this->ruleReceipt($created, $payloadHash, false);
            });
        } catch (QueryException $exception) {
            $existing = $this->ruleVersionByKey($this->database->connection(), $key);
            if ($existing !== null) {
                return $this->ruleReceipt($existing, $payloadHash, true);
            }
            throw $exception;
        }
    }
}
