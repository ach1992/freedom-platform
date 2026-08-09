<?php

declare(strict_types=1);

namespace App\Modules\Agents\Application;

use App\Modules\Agents\Domain\AgentPricingAction;
use App\Modules\Agents\Domain\AgentPricingProfileDefinition;
use App\Modules\Agents\Domain\AgentPricingRuleDefinition;
use App\Modules\Agents\Domain\AgentPricingState;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

trait AgentPricingPersistenceSupport
{
    /** @return object{id:int|string,version:int|string,state:string,discount_combination_allowed:int|bool|string,configuration_snapshot:string,configuration_hash:string}|null */
    private function latestProfileVersion(Connection $db, int $profileId): ?object
    {
        return $db->table('agent_pricing_profile_versions')->where('agent_pricing_profile_id', $profileId)->orderByDesc('version')->first(['id', 'version', 'state', 'discount_combination_allowed', 'configuration_snapshot', 'configuration_hash']);
    }

    /** @return object{id:int|string,version:int|string,state:string}|null */
    private function latestRuleVersion(Connection $db, int $ruleId): ?object
    {
        return $db->table('agent_pricing_rule_versions')->where('agent_pricing_rule_id', $ruleId)->orderByDesc('version')->first(['id', 'version', 'state']);
    }

    /** @return object{id:int|string,agent_pricing_rule_id:int|string,version:int|string,state:string,override_price_irr:int|string,action:string|null,plan_offering_id:int|string|null,sales_server_id:int|string|null,product_id:int|string|null,configuration_snapshot:string,configuration_hash:string,rule_public_id:string,rule_code:string}|null */
    private function selectRule(Connection $db, int $profileId, AgentPricingResolutionRequest $request, int $productId, int $serverId): ?object
    {
        $rows = $db->table('agent_pricing_rule_versions as v')->join('agent_pricing_rules as r', 'r.id', '=', 'v.agent_pricing_rule_id')
            ->where('r.agent_pricing_profile_id', $profileId)->get(['v.id', 'v.agent_pricing_rule_id', 'v.version', 'v.state', 'v.override_price_irr', 'v.action', 'v.plan_offering_id', 'v.sales_server_id', 'v.product_id', 'v.configuration_snapshot', 'v.configuration_hash', 'r.public_id as rule_public_id', 'r.rule_code'])->all();
        /** @var array<int,object{id:int|string,agent_pricing_rule_id:int|string,version:int|string,state:string,override_price_irr:int|string,action:string|null,plan_offering_id:int|string|null,sales_server_id:int|string|null,product_id:int|string|null,configuration_snapshot:string,configuration_hash:string,rule_public_id:string,rule_code:string}> $latest */
        $latest = [];
        foreach ($rows as $row) {
            $id = $this->positive($row->agent_pricing_rule_id, 'Agent pricing rule ID');
            if (! isset($latest[$id]) || $this->positive($row->version, 'Agent pricing rule version') > $this->positive($latest[$id]->version, 'Agent pricing rule version')) {
                $latest[$id] = $row;
            }
        }
        $qualified = [];
        foreach ($latest as $row) {
            if ($row->state !== AgentPricingState::Active->value) {
                continue;
            }
            if (($row->action !== null && $row->action !== $request->action->value)
                || ($row->plan_offering_id !== null && $this->positive($row->plan_offering_id, 'Rule offering ID') !== $request->planOfferingId)
                || ($row->product_id !== null && $this->positive($row->product_id, 'Rule product ID') !== $productId)
                || ($row->sales_server_id !== null && $this->positive($row->sales_server_id, 'Rule server ID') !== $serverId)) {
                continue;
            }
            $this->verifyRuleVersion($row, $request->pricingProfileCode);
            $qualified[] = [$this->specificity($row), $row];
        }
        if ($qualified === []) {
            return null;
        }
        $max = max(array_column($qualified, 0));
        $winners = array_values(array_filter($qualified, static fn (array $candidate): bool => $candidate[0] === $max));
        if (count($winners) !== 1) {
            throw new RuntimeException('Agent pricing rule resolution is ambiguous.');
        }
        return $winners[0][1];
    }

    private function validateScope(Connection $db, AgentPricingRuleDefinition $definition): void
    {
        if ($definition->productId !== null && ! $db->table('products')->where('id', $definition->productId)->exists()) {
            throw new DomainException('Agent pricing rule product does not exist.');
        }
        if ($definition->salesServerId !== null && ! $db->table('sales_servers')->where('id', $definition->salesServerId)->exists()) {
            throw new DomainException('Agent pricing rule sales server does not exist.');
        }
        if ($definition->planOfferingId === null) {
            return;
        }
        /** @var object{product_id:int|string,sales_server_id:int|string}|null $offering */
        $offering = $db->table('plan_offerings')->where('id', $definition->planOfferingId)->first(['product_id', 'sales_server_id']);
        if ($offering === null) {
            throw new DomainException('Agent pricing rule offering does not exist.');
        }
        if (($definition->productId !== null && $definition->productId !== $this->positive($offering->product_id, 'Offering product ID'))
            || ($definition->salesServerId !== null && $definition->salesServerId !== $this->positive($offering->sales_server_id, 'Offering server ID'))) {
            throw new DomainException('Agent pricing compound scope is inconsistent.');
        }
    }

    /** @return object{id:int|string,agent_pricing_profile_id:int|string,mutation_payload_hash:string,version:int|string,state:string,discount_combination_allowed:int|bool|string,configuration_hash:string,profile_public_id:string,profile_code:string}|null */
    private function profileVersionByKey(Connection $db, string $key, bool $lock = false): ?object
    {
        $q = $db->table('agent_pricing_profile_versions as v')->join('agent_pricing_profiles as p', 'p.id', '=', 'v.agent_pricing_profile_id')->where('v.mutation_key', $key);
        if ($lock) { $q->lockForUpdate(); }
        return $q->first(['v.id', 'v.agent_pricing_profile_id', 'v.mutation_payload_hash', 'v.version', 'v.state', 'v.discount_combination_allowed', 'v.configuration_hash', 'p.public_id as profile_public_id', 'p.profile_code']);
    }

    /** @return object{id:int|string,agent_pricing_rule_id:int|string,mutation_payload_hash:string,version:int|string,state:string,override_price_irr:int|string,action:string|null,plan_offering_id:int|string|null,sales_server_id:int|string|null,product_id:int|string|null,configuration_hash:string,rule_public_id:string,rule_code:string,profile_code:string}|null */
    private function ruleVersionByKey(Connection $db, string $key, bool $lock = false): ?object
    {
        $q = $db->table('agent_pricing_rule_versions as v')->join('agent_pricing_rules as r', 'r.id', '=', 'v.agent_pricing_rule_id')->join('agent_pricing_profiles as p', 'p.id', '=', 'r.agent_pricing_profile_id')->where('v.mutation_key', $key);
        if ($lock) { $q->lockForUpdate(); }
        return $q->first(['v.id', 'v.agent_pricing_rule_id', 'v.mutation_payload_hash', 'v.version', 'v.state', 'v.override_price_irr', 'v.action', 'v.plan_offering_id', 'v.sales_server_id', 'v.product_id', 'v.configuration_hash', 'r.public_id as rule_public_id', 'r.rule_code', 'p.profile_code']);
    }

    /** @return object|null */
    private function resolutionRow(Connection $db, string $key, bool $lock = false): ?object
    {
        $q = $db->table('agent_pricing_resolutions')->where('resolution_key', $key);
        if ($lock) { $q->lockForUpdate(); }
        return $q->first();
    }

    /** @return object|null */
    private function resolutionById(Connection $db, int $id): ?object
    {
        return $db->table('agent_pricing_resolutions')->where('id', $id)->first();
    }

    private function profileReceipt(object $row, string $hash, bool $replayed): AgentPricingProfileVersionReceipt
    {
        if (! hash_equals((string) $row->mutation_payload_hash, $hash)) {
            throw new RuntimeException('Agent pricing profile mutation key conflict.');
        }
        $state = AgentPricingState::tryFrom((string) $row->state) ?? throw new RuntimeException('Stored agent pricing profile state is invalid.');
        return new AgentPricingProfileVersionReceipt($this->positive($row->agent_pricing_profile_id, 'Profile ID'), (string) $row->profile_public_id, (string) $row->profile_code, $this->positive($row->id, 'Profile version ID'), $this->positive($row->version, 'Profile version'), $state, (bool) $row->discount_combination_allowed, (string) $row->configuration_hash, $replayed);
    }

    private function ruleReceipt(object $row, string $hash, bool $replayed): AgentPricingRuleVersionReceipt
    {
        if (! hash_equals((string) $row->mutation_payload_hash, $hash)) {
            throw new RuntimeException('Agent pricing rule mutation key conflict.');
        }
        $state = AgentPricingState::tryFrom((string) $row->state) ?? throw new RuntimeException('Stored agent pricing rule state is invalid.');
        return new AgentPricingRuleVersionReceipt($this->positive($row->agent_pricing_rule_id, 'Rule ID'), (string) $row->rule_public_id, (string) $row->profile_code, (string) $row->rule_code, $this->positive($row->id, 'Rule version ID'), $this->positive($row->version, 'Rule version'), $state, $this->nonNegative($row->override_price_irr, 'Override'), $this->specificity($row), (string) $row->configuration_hash, $replayed);
    }

    private function resolutionReceipt(object $row, string $hash, bool $replayed): AgentPricingResolutionReceipt
    {
        if (! hash_equals((string) $row->request_payload_hash, $hash)) {
            throw new RuntimeException('Agent pricing resolution key conflict.');
        }
        $action = AgentPricingAction::tryFrom((string) $row->action) ?? throw new RuntimeException('Stored agent pricing resolution action is invalid.');
        return new AgentPricingResolutionReceipt(
            $this->positive($row->id, 'Resolution ID'), (string) $row->public_id, (string) $row->resolution_key, $this->positive($row->user_id, 'User ID'),
            $this->positive($row->agent_profile_id, 'Agent profile ID'), $this->positive($row->agent_pricing_profile_id, 'Pricing profile ID'),
            (string) $row->pricing_profile_public_id_snapshot, (string) $row->pricing_profile_code_snapshot, $this->positive($row->pricing_profile_version, 'Profile version'),
            (string) $row->pricing_profile_configuration_hash, $this->positive($row->plan_offering_id, 'Offering ID'), $this->positive($row->product_id_snapshot, 'Product ID'),
            $this->positive($row->sales_server_id_snapshot, 'Server ID'), $action, $row->agent_pricing_rule_id === null ? null : $this->positive($row->agent_pricing_rule_id, 'Rule ID'),
            $row->rule_public_id_snapshot === null ? null : (string) $row->rule_public_id_snapshot, $row->rule_code_snapshot === null ? null : (string) $row->rule_code_snapshot,
            $row->rule_version === null ? null : $this->positive($row->rule_version, 'Rule version'), $row->rule_configuration_hash === null ? null : (string) $row->rule_configuration_hash,
            $row->override_price_irr === null ? null : $this->nonNegative($row->override_price_irr, 'Override'), (bool) $row->discount_combination_allowed,
            (string) $row->configuration_snapshot_hash, $replayed,
        );
    }

    /** @return array<string,mixed> */
    private function profileSnapshot(string $code, AgentPricingProfileDefinition $definition): array
    {
        $value = $definition->snapshot(); $value['profile_code'] = $code; ksort($value, SORT_STRING); return $value;
    }

    /** @return array<string,mixed> */
    private function ruleSnapshot(string $profileCode, string $ruleCode, AgentPricingRuleDefinition $definition): array
    {
        $value = $definition->snapshot(); $value['profile_code'] = $profileCode; $value['rule_code'] = $ruleCode; ksort($value, SORT_STRING); return $value;
    }

    private function verifyProfileVersion(object $row, string $code): void
    {
        $state = AgentPricingState::tryFrom((string) $row->state) ?? throw new RuntimeException('Stored profile state is invalid.');
        $json = $this->json($this->profileSnapshot($code, new AgentPricingProfileDefinition($state, (bool) $row->discount_combination_allowed)));
        if (! hash_equals($json, (string) $row->configuration_snapshot) || ! hash_equals(hash('sha256', $json), (string) $row->configuration_hash)) {
            throw new RuntimeException('Stored agent pricing profile configuration is invalid.');
        }
    }

    private function verifyRuleVersion(object $row, string $profileCode): void
    {
        $state = AgentPricingState::tryFrom((string) $row->state) ?? throw new RuntimeException('Stored rule state is invalid.');
        $action = $row->action === null ? null : AgentPricingAction::tryFrom((string) $row->action);
        if ($row->action !== null && $action === null) { throw new RuntimeException('Stored rule action is invalid.'); }
        $definition = new AgentPricingRuleDefinition($state, $this->nonNegative($row->override_price_irr, 'Override'), $action,
            $row->plan_offering_id === null ? null : $this->positive($row->plan_offering_id, 'Offering ID'),
            $row->sales_server_id === null ? null : $this->positive($row->sales_server_id, 'Server ID'),
            $row->product_id === null ? null : $this->positive($row->product_id, 'Product ID'));
        $json = $this->json($this->ruleSnapshot($profileCode, (string) $row->rule_code, $definition));
        if (! hash_equals($json, (string) $row->configuration_snapshot) || ! hash_equals(hash('sha256', $json), (string) $row->configuration_hash)) {
            throw new RuntimeException('Stored agent pricing rule configuration is invalid.');
        }
    }

    private function specificity(object $row): int
    {
        return ($row->action === null ? 0 : 1) + ($row->plan_offering_id === null ? 0 : 1) + ($row->sales_server_id === null ? 0 : 1) + ($row->product_id === null ? 0 : 1);
    }

    /** @param array<string,mixed> $value */
    private function hash(array $value): string { ksort($value, SORT_STRING); return hash('sha256', $this->json($value)); }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 8192) { throw new RuntimeException('Agent pricing snapshot exceeds the storage boundary.'); }
        return $json;
    }

    /** @return array<string,mixed> */
    private function jsonObject(string $value): array
    {
        $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || array_is_list($decoded)) { throw new RuntimeException('Stored agent pricing snapshot is invalid.'); }
        return $decoded;
    }

    private function mutationKey(string $key): void
    {
        if (preg_match('/\A[A-Za-z0-9:_.-]{8,128}\z/', $key) !== 1) { throw new DomainException('Agent pricing mutation key is invalid.'); }
    }

    private function positive(int|string $value, string $label): int
    {
        $value = $this->integer($value, $label); if ($value < 1) { throw new RuntimeException($label.' is invalid.'); } return $value;
    }

    private function nonNegative(int|string $value, string $label): int
    {
        $value = $this->integer($value, $label); if ($value < 0) { throw new RuntimeException($label.' is invalid.'); } return $value;
    }

    private function integer(int|string $value, string $label): int
    {
        if (is_int($value)) { return $value; }
        if (preg_match('/\A-?[0-9]+\z/', $value) !== 1) { throw new RuntimeException($label.' is invalid.'); }
        $normalized = filter_var($value, FILTER_VALIDATE_INT); if (! is_int($normalized)) { throw new RuntimeException($label.' is invalid.'); } return $normalized;
    }

    private function timestamp(): string { return $this->clock->now()->format('Y-m-d H:i:s.u'); }
}
