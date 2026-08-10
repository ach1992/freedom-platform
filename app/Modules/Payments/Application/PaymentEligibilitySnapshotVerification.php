<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\PaymentConfigurationState;
use App\Modules\Payments\Domain\PaymentEligibilityEffect;
use App\Modules\Payments\Domain\PaymentMethodKind;
use RuntimeException;

/**
 * @phpstan-type MethodVersionRow object{id:int|string,payment_method_id:int|string,mutation_payload_hash:string,version:int|string,state:string,display_priority:int|string,minimum_amount_irr:int|string|null,maximum_amount_irr:int|string|null,allow_degraded_health:int|bool|string,configuration_snapshot:string,configuration_hash:string,method_public_id:string,method_code:string,kind:string,provider_code:string|null}
 * @phpstan-type RuleVersionRow object{id:int|string,payment_eligibility_rule_id:int|string,payment_method_id:int|string,mutation_payload_hash:string,version:int|string,state:string,effect:string,priority:int|string,is_override:int|bool|string,account_type:string|null,tier_code:string|null,identity_status:string|null,customer_tag_id:int|string|null,minimum_amount_irr:int|string|null,maximum_amount_irr:int|string|null,action:string|null,plan_offering_id:int|string|null,product_id:int|string|null,sales_server_id:int|string|null,effective_from:string|null,effective_until:string|null,configuration_snapshot:string,configuration_hash:string,rule_public_id:string,rule_code:string,method_code:string}
 * @phpstan-type DecisionRow object{id:int|string,public_id:string,decision_key:string,request_payload_hash:string,user_id:int|string,quote_id:int|string,quote_public_id_snapshot:string,action:string,amount_irr:int|string,currency:string,eligible_count:int|string,configuration_snapshot:string,configuration_snapshot_hash:string}
 * @phpstan-type DecisionItemRow object{payment_method_id:int|string,method_public_id_snapshot:string,method_code_snapshot:string,kind_snapshot:string,provider_code_snapshot:string|null,method_version:int|string,method_configuration_hash:string,display_priority:int|string,eligible:int|bool|string,payment_eligibility_rule_id:int|string|null,rule_public_id_snapshot:string|null,rule_code_snapshot:string|null,rule_version:int|string|null,rule_configuration_hash:string|null}
 */
trait PaymentEligibilitySnapshotVerification
{
    /** @param MethodVersionRow $row */
    private function verifyMethodVersion(object $row): void
    {
        $kind = PaymentMethodKind::tryFrom($row->kind);
        $state = PaymentConfigurationState::tryFrom($row->state);
        if ($kind === null || $state === null) {
            throw new RuntimeException('Stored payment method configuration is invalid.');
        }
        $expected = $this->json([
            'allow_degraded_health' => (bool) $row->allow_degraded_health,
            'display_priority' => $this->nonNegative($row->display_priority, 'Payment method display priority'),
            'kind' => $kind->value,
            'maximum_amount_irr' => $row->maximum_amount_irr === null ? null : $this->nonNegative($row->maximum_amount_irr, 'Payment method maximum amount'),
            'method_code' => $row->method_code,
            'minimum_amount_irr' => $row->minimum_amount_irr === null ? null : $this->nonNegative($row->minimum_amount_irr, 'Payment method minimum amount'),
            'provider_code' => $row->provider_code,
            'state' => $state->value,
        ]);
        if (! hash_equals(hash('sha256', $expected), $row->configuration_hash)
            || ! hash_equals(hash('sha256', $row->configuration_snapshot), $row->configuration_hash)) {
            throw new RuntimeException('Stored payment method configuration hash is invalid.');
        }
    }

    /** @param RuleVersionRow $row */
    private function verifyRuleVersion(object $row): void
    {
        $state = PaymentConfigurationState::tryFrom($row->state);
        $effect = PaymentEligibilityEffect::tryFrom($row->effect);
        if ($state === null || $effect === null) {
            throw new RuntimeException('Stored payment eligibility rule configuration is invalid.');
        }
        $expected = $this->json([
            'account_type' => $row->account_type,
            'action' => $row->action,
            'customer_tag_id' => $row->customer_tag_id === null ? null : $this->positive($row->customer_tag_id, 'Rule customer tag ID'),
            'effect' => $effect->value,
            'effective_from' => $row->effective_from,
            'effective_until' => $row->effective_until,
            'identity_status' => $row->identity_status,
            'is_override' => (bool) $row->is_override,
            'maximum_amount_irr' => $row->maximum_amount_irr === null ? null : $this->nonNegative($row->maximum_amount_irr, 'Rule maximum amount'),
            'method_code' => $row->method_code,
            'minimum_amount_irr' => $row->minimum_amount_irr === null ? null : $this->nonNegative($row->minimum_amount_irr, 'Rule minimum amount'),
            'plan_offering_id' => $row->plan_offering_id === null ? null : $this->positive($row->plan_offering_id, 'Rule offering ID'),
            'priority' => $this->nonNegative($row->priority, 'Rule priority'),
            'product_id' => $row->product_id === null ? null : $this->positive($row->product_id, 'Rule product ID'),
            'rule_code' => $row->rule_code,
            'sales_server_id' => $row->sales_server_id === null ? null : $this->positive($row->sales_server_id, 'Rule sales server ID'),
            'state' => $state->value,
            'tier_code' => $row->tier_code,
        ]);
        if (! hash_equals(hash('sha256', $expected), $row->configuration_hash)
            || ! hash_equals(hash('sha256', $row->configuration_snapshot), $row->configuration_hash)) {
            throw new RuntimeException('Stored payment eligibility rule configuration hash is invalid.');
        }
    }

    /**
     * @param array{method:MethodVersionRow,health:string,outcome:string,eligible:bool,rule:RuleVersionRow|null} $item
     * @return array<string, mixed>
     */
    private function decisionItemSnapshot(array $item): array
    {
        $method = $item['method'];
        $rule = $item['rule'];

        return [
            'eligible' => $item['eligible'],
            'health' => $item['health'],
            'kind' => $method->kind,
            'method_code' => $method->method_code,
            'method_configuration_hash' => $method->configuration_hash,
            'method_public_id' => $method->method_public_id,
            'method_version' => $this->positive($method->version, 'Payment method version'),
            'outcome' => $item['outcome'],
            'provider_code' => $method->provider_code,
            'rule' => $rule === null ? null : [
                'configuration_hash' => $rule->configuration_hash,
                'public_id' => $rule->rule_public_id,
                'rule_code' => $rule->rule_code,
                'version' => $this->positive($rule->version, 'Payment eligibility rule version'),
            ],
        ];
    }
}
