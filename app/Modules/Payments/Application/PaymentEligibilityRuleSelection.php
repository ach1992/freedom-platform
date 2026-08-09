<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Application\Contracts\ProviderHealth;
use App\Modules\Payments\Domain\PaymentConfigurationState;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * @phpstan-type MethodVersionRow object{id:int|string,payment_method_id:int|string,mutation_payload_hash:string,version:int|string,state:string,display_priority:int|string,minimum_amount_irr:int|string|null,maximum_amount_irr:int|string|null,allow_degraded_health:int|bool|string,configuration_snapshot:string,configuration_hash:string,method_public_id:string,method_code:string,kind:string,provider_code:string|null}
 * @phpstan-type RuleVersionRow object{id:int|string,payment_eligibility_rule_id:int|string,payment_method_id:int|string,mutation_payload_hash:string,version:int|string,state:string,effect:string,priority:int|string,is_override:int|bool|string,account_type:string|null,tier_code:string|null,identity_status:string|null,customer_tag_id:int|string|null,minimum_amount_irr:int|string|null,maximum_amount_irr:int|string|null,action:string|null,plan_offering_id:int|string|null,product_id:int|string|null,sales_server_id:int|string|null,effective_from:string|null,effective_until:string|null,configuration_snapshot:string,configuration_hash:string,rule_public_id:string,rule_code:string,method_code:string}
 * @phpstan-type DecisionRow object{id:int|string,public_id:string,decision_key:string,request_payload_hash:string,user_id:int|string,quote_id:int|string,quote_public_id_snapshot:string,action:string,amount_irr:int|string,currency:string,eligible_count:int|string,configuration_snapshot:string,configuration_snapshot_hash:string}
 * @phpstan-type DecisionItemRow object{payment_method_id:int|string,method_public_id_snapshot:string,method_code_snapshot:string,kind_snapshot:string,provider_code_snapshot:string|null,method_version:int|string,method_configuration_hash:string,display_priority:int|string,eligible:int|bool|string,payment_eligibility_rule_id:int|string|null,rule_public_id_snapshot:string|null,rule_code_snapshot:string|null,rule_version:int|string|null,rule_configuration_hash:string|null}
 */
trait PaymentEligibilityRuleSelection
{
    private function latestMethodVersions(Connection $db): array
    {
        /** @var list<MethodVersionRow> $rows */
        $rows = $db->table('payment_method_versions as v')
            ->join('payment_methods as m', 'm.id', '=', 'v.payment_method_id')
            ->get([
                'v.id', 'v.payment_method_id', 'v.mutation_payload_hash', 'v.version', 'v.state', 'v.display_priority',
                'v.minimum_amount_irr', 'v.maximum_amount_irr', 'v.allow_degraded_health', 'v.configuration_snapshot',
                'v.configuration_hash', 'm.public_id as method_public_id', 'm.method_code', 'm.kind', 'm.provider_code',
            ])->all();
        /** @var array<int,MethodVersionRow> $latest */
        $latest = [];
        foreach ($rows as $row) {
            $methodId = $this->positive($row->payment_method_id, 'Payment method ID');
            if (! isset($latest[$methodId]) || $this->positive($row->version, 'Payment method version') > $this->positive($latest[$methodId]->version, 'Payment method version')) {
                $latest[$methodId] = $row;
            }
        }
        $byCode = [];
        foreach ($latest as $row) {
            $byCode[$row->method_code] = $row;
        }
        ksort($byCode, SORT_STRING);

        return array_values($byCode);
    }

    /**
     * @param list<int> $tagIds
     * @return RuleVersionRow|null
     */
    private function selectRule(
        Connection $db,
        int $methodId,
        PaymentEligibilityResolutionRequest $request,
        int $amountIrr,
        int $offeringId,
        int $productId,
        int $serverId,
        string $accountType,
        ?string $tierCode,
        ?string $identityStatus,
        array $tagIds,
        DateTimeImmutable $now,
    ): ?object {
        /** @var list<RuleVersionRow> $rows */
        $rows = $db->table('payment_eligibility_rule_versions as v')
            ->join('payment_eligibility_rules as r', 'r.id', '=', 'v.payment_eligibility_rule_id')
            ->join('payment_methods as m', 'm.id', '=', 'r.payment_method_id')
            ->where('r.payment_method_id', $methodId)
            ->get([
                'v.id', 'v.payment_eligibility_rule_id', 'v.payment_method_id', 'v.mutation_payload_hash', 'v.version',
                'v.state', 'v.effect', 'v.priority', 'v.is_override', 'v.account_type', 'v.tier_code', 'v.identity_status', 'v.customer_tag_id',
                'v.minimum_amount_irr', 'v.maximum_amount_irr', 'v.action', 'v.plan_offering_id', 'v.product_id',
                'v.sales_server_id', 'v.effective_from', 'v.effective_until', 'v.configuration_snapshot',
                'v.configuration_hash', 'r.public_id as rule_public_id', 'r.rule_code', 'm.method_code',
            ])->all();
        /** @var array<int,RuleVersionRow> $latest */
        $latest = [];
        foreach ($rows as $row) {
            $ruleId = $this->positive($row->payment_eligibility_rule_id, 'Payment eligibility rule ID');
            if (! isset($latest[$ruleId]) || $this->positive($row->version, 'Payment eligibility rule version') > $this->positive($latest[$ruleId]->version, 'Payment eligibility rule version')) {
                $latest[$ruleId] = $row;
            }
        }
        /** @var list<array{score:array{int,int,int},row:RuleVersionRow}> $qualified */
        $qualified = [];
        foreach ($latest as $row) {
            if ($row->state !== PaymentConfigurationState::Active->value) {
                continue;
            }
            if ($row->account_type !== null && ! hash_equals($row->account_type, $accountType)) {
                continue;
            }
            if ($row->tier_code !== null && ! hash_equals($row->tier_code, $tierCode ?? '')) {
                continue;
            }
            if ($row->identity_status !== null && ! hash_equals($row->identity_status, $identityStatus ?? '')) {
                continue;
            }
            if ($row->customer_tag_id !== null && ! in_array((int) $row->customer_tag_id, $tagIds, true)) {
                continue;
            }
            if (! $this->amountWithinLimits($amountIrr, $row->minimum_amount_irr, $row->maximum_amount_irr)) {
                continue;
            }
            if ($row->action !== null && ! hash_equals($row->action, $request->action->value)) {
                continue;
            }
            if ($row->plan_offering_id !== null && (int) $row->plan_offering_id !== $offeringId) {
                continue;
            }
            if ($row->product_id !== null && (int) $row->product_id !== $productId) {
                continue;
            }
            if ($row->sales_server_id !== null && (int) $row->sales_server_id !== $serverId) {
                continue;
            }
            if ($row->effective_from !== null && $now < $this->dateFromDatabase($row->effective_from, 'Rule effective-from')) {
                continue;
            }
            if ($row->effective_until !== null && $now >= $this->dateFromDatabase($row->effective_until, 'Rule effective-until')) {
                continue;
            }
            $this->verifyRuleVersion($row);
            $qualified[] = [
                'score' => [(bool) $row->is_override ? 1 : 0, $this->nonNegative($row->priority, 'Rule priority'), $this->ruleSpecificity($row)],
                'row' => $row,
            ];
        }
        if ($qualified === []) {
            return null;
        }
        $bestScore = null;
        $winners = [];
        foreach ($qualified as $candidate) {
            if ($bestScore === null) {
                $bestScore = $candidate['score'];
                $winners = [$candidate['row']];
                continue;
            }
            $comparison = $this->compareScore($candidate['score'], $bestScore);
            if ($comparison > 0) {
                $bestScore = $candidate['score'];
                $winners = [$candidate['row']];
            } elseif ($comparison === 0) {
                $winners[] = $candidate['row'];
            }
        }
        if (count($winners) !== 1) {
            throw new RuntimeException('Payment eligibility rule resolution is ambiguous.');
        }

        return $winners[0];
    }

    /** @param array{int,int,int} $left @param array{int,int,int} $right */
    private function compareScore(array $left, array $right): int
    {
        for ($index = 0; $index < 3; $index++) {
            $comparison = $left[$index] <=> $right[$index];
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    /** @param RuleVersionRow $row */
    private function ruleSpecificity(object $row): int
    {
        $values = [
            $row->account_type, $row->tier_code, $row->identity_status, $row->customer_tag_id, $row->minimum_amount_irr,
            $row->maximum_amount_irr, $row->action, $row->plan_offering_id, $row->product_id,
            $row->sales_server_id, $row->effective_from, $row->effective_until,
        ];

        return count(array_filter($values, static fn (mixed $value): bool => $value !== null));
    }

    private function currentTierCode(Connection $db, int $userId): ?string
    {
        /** @var object{current_tier_id:int|string|null}|null $profile */
        $profile = $db->table('customer_profiles')->where('user_id', $userId)->lockForUpdate()->first(['current_tier_id']);
        if ($profile === null || $profile->current_tier_id === null) {
            return null;
        }
        /** @var object{code:string,is_active:int|bool|string}|null $tier */
        $tier = $db->table('customer_tiers')->where('id', (int) $profile->current_tier_id)->lockForUpdate()->first(['code', 'is_active']);

        return $tier !== null && (bool) $tier->is_active ? $tier->code : null;
    }

    private function currentIdentityStatus(Connection $db, int $userId): ?string
    {
        /** @var object{identity_verification_status:string}|null $profile */
        $profile = $db->table('customer_profiles')->where('user_id', $userId)->lockForUpdate()->first(['identity_verification_status']);

        return $profile?->identity_verification_status;
    }

    /** @return list<int> */
    private function currentTagIds(Connection $db, int $userId): array
    {
        /** @var list<int|string> $assigned */
        $assigned = $db->table('customer_tag_assignments')->where('user_id', $userId)->whereNull('removed_at')->orderBy('tag_id')->lockForUpdate()->pluck('tag_id')->all();
        if ($assigned === []) {
            return [];
        }
        /** @var list<int|string> $active */
        $active = $db->table('customer_tags')->whereIn('id', array_map('intval', $assigned))->where('is_active', true)->orderBy('id')->lockForUpdate()->pluck('id')->all();

        return array_map('intval', $active);
    }

    private function healthAllowsMethod(?ProviderHealth $health, bool $allowDegradedHealth): bool
    {
        return $health === ProviderHealth::Healthy || ($allowDegradedHealth && $health === ProviderHealth::Degraded);
    }

    private function amountWithinLimits(int $amountIrr, int|string|null $minimum, int|string|null $maximum): bool
    {
        $minimumAmount = $minimum === null ? null : $this->nonNegative($minimum, 'Minimum amount');
        $maximumAmount = $maximum === null ? null : $this->nonNegative($maximum, 'Maximum amount');

        return ($minimumAmount === null || $amountIrr >= $minimumAmount)
            && ($maximumAmount === null || $amountIrr <= $maximumAmount);
    }

    /** @param MethodVersionRow $row */
}
