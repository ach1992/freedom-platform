<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\PaymentConfigurationState;
use App\Modules\Payments\Domain\PaymentEligibilityAction;
use App\Modules\Payments\Domain\PaymentEligibilityEffect;
use App\Modules\Payments\Domain\PaymentMethodKind;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * @phpstan-type MethodVersionRow object{id:int|string,payment_method_id:int|string,mutation_payload_hash:string,version:int|string,state:string,display_priority:int|string,minimum_amount_irr:int|string|null,maximum_amount_irr:int|string|null,allow_degraded_health:int|bool|string,configuration_snapshot:string,configuration_hash:string,method_public_id:string,method_code:string,kind:string,provider_code:string|null}
 * @phpstan-type RuleVersionRow object{id:int|string,payment_eligibility_rule_id:int|string,payment_method_id:int|string,mutation_payload_hash:string,version:int|string,state:string,effect:string,priority:int|string,is_override:int|bool|string,account_type:string|null,tier_code:string|null,identity_status:string|null,customer_tag_id:int|string|null,minimum_amount_irr:int|string|null,maximum_amount_irr:int|string|null,action:string|null,plan_offering_id:int|string|null,product_id:int|string|null,sales_server_id:int|string|null,effective_from:string|null,effective_until:string|null,configuration_snapshot:string,configuration_hash:string,rule_public_id:string,rule_code:string,method_code:string}
 * @phpstan-type DecisionRow object{id:int|string,public_id:string,decision_key:string,request_payload_hash:string,user_id:int|string,quote_id:int|string,quote_public_id_snapshot:string,action:string,amount_irr:int|string,currency:string,eligible_count:int|string,configuration_snapshot:string,configuration_snapshot_hash:string}
 * @phpstan-type DecisionItemRow object{payment_method_id:int|string,method_public_id_snapshot:string,method_code_snapshot:string,kind_snapshot:string,provider_code_snapshot:string|null,method_version:int|string,method_configuration_hash:string,display_priority:int|string,eligible:int|bool|string,payment_eligibility_rule_id:int|string|null,rule_public_id_snapshot:string|null,rule_code_snapshot:string|null,rule_version:int|string|null,rule_configuration_hash:string|null}
 */
trait PaymentEligibilityPersistence
{
    private function methodVersionByMutationKey(Connection $db, string $key, bool $lock = false): ?object
    {
        $query = $db->table('payment_method_versions as v')->join('payment_methods as m', 'm.id', '=', 'v.payment_method_id')->where('v.mutation_key', $key);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var MethodVersionRow|null $row */
        $row = $query->first([
            'v.id', 'v.payment_method_id', 'v.mutation_payload_hash', 'v.version', 'v.state', 'v.display_priority',
            'v.minimum_amount_irr', 'v.maximum_amount_irr', 'v.allow_degraded_health', 'v.configuration_snapshot',
            'v.configuration_hash', 'm.public_id as method_public_id', 'm.method_code', 'm.kind', 'm.provider_code',
        ]);

        return $row;
    }

    /** @return RuleVersionRow|null */
    private function ruleVersionByMutationKey(Connection $db, string $key, bool $lock = false): ?object
    {
        $query = $db->table('payment_eligibility_rule_versions as v')
            ->join('payment_eligibility_rules as r', 'r.id', '=', 'v.payment_eligibility_rule_id')
            ->join('payment_methods as m', 'm.id', '=', 'r.payment_method_id')
            ->where('v.mutation_key', $key);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var RuleVersionRow|null $row */
        $row = $query->first([
            'v.id', 'v.payment_eligibility_rule_id', 'v.payment_method_id', 'v.mutation_payload_hash', 'v.version',
            'v.state', 'v.effect', 'v.priority', 'v.is_override', 'v.account_type', 'v.tier_code', 'v.identity_status', 'v.customer_tag_id',
            'v.minimum_amount_irr', 'v.maximum_amount_irr', 'v.action', 'v.plan_offering_id', 'v.product_id',
            'v.sales_server_id', 'v.effective_from', 'v.effective_until', 'v.configuration_snapshot', 'v.configuration_hash',
            'r.public_id as rule_public_id', 'r.rule_code', 'm.method_code',
        ]);

        return $row;
    }

    /** @return DecisionRow|null */
    private function decisionByKey(Connection $db, string $key, bool $lock = false): ?object
    {
        $query = $db->table('payment_eligibility_decisions')->where('decision_key', $key);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var DecisionRow|null $row */
        $row = $query->first($this->decisionColumns());

        return $row;
    }

    /** @return DecisionRow|null */
    private function decisionById(Connection $db, int $id): ?object
    {
        /** @var DecisionRow|null $row */
        $row = $db->table('payment_eligibility_decisions')->where('id', $id)->first($this->decisionColumns());

        return $row;
    }

    /** @return list<string> */
    private function decisionColumns(): array
    {
        return [
            'id', 'public_id', 'decision_key', 'request_payload_hash', 'user_id', 'quote_id',
            'quote_public_id_snapshot', 'action', 'amount_irr', 'currency', 'eligible_count',
            'configuration_snapshot', 'configuration_snapshot_hash',
        ];
    }

    /** @param MethodVersionRow $row */
    private function methodReceipt(object $row, string $payloadHash, bool $replayed): PaymentMethodVersionReceipt
    {
        if (! hash_equals($row->mutation_payload_hash, $payloadHash)) {
            throw new RuntimeException('Payment method mutation key conflict.');
        }
        $kind = PaymentMethodKind::tryFrom($row->kind) ?? throw new RuntimeException('Stored payment method kind is invalid.');
        $state = PaymentConfigurationState::tryFrom($row->state) ?? throw new RuntimeException('Stored payment method state is invalid.');
        $this->verifyMethodVersion($row);

        return new PaymentMethodVersionReceipt(
            $this->positive($row->payment_method_id, 'Payment method ID'),
            $row->method_public_id,
            $row->method_code,
            $kind,
            $row->provider_code,
            $this->positive($row->id, 'Payment method version ID'),
            $this->positive($row->version, 'Payment method version'),
            $state,
            $this->nonNegative($row->display_priority, 'Payment method display priority'),
            $row->minimum_amount_irr === null ? null : $this->nonNegative($row->minimum_amount_irr, 'Payment method minimum amount'),
            $row->maximum_amount_irr === null ? null : $this->nonNegative($row->maximum_amount_irr, 'Payment method maximum amount'),
            (bool) $row->allow_degraded_health,
            $row->configuration_hash,
            $replayed,
        );
    }

    /** @param RuleVersionRow $row */
    private function ruleReceipt(object $row, string $payloadHash, bool $replayed): PaymentEligibilityRuleVersionReceipt
    {
        if (! hash_equals($row->mutation_payload_hash, $payloadHash)) {
            throw new RuntimeException('Payment eligibility rule mutation key conflict.');
        }
        $state = PaymentConfigurationState::tryFrom($row->state) ?? throw new RuntimeException('Stored payment eligibility rule state is invalid.');
        $effect = PaymentEligibilityEffect::tryFrom($row->effect) ?? throw new RuntimeException('Stored payment eligibility effect is invalid.');
        $this->verifyRuleVersion($row);

        return new PaymentEligibilityRuleVersionReceipt(
            $this->positive($row->payment_eligibility_rule_id, 'Payment eligibility rule ID'),
            $row->rule_public_id,
            $row->method_code,
            $row->rule_code,
            $this->positive($row->id, 'Payment eligibility rule version ID'),
            $this->positive($row->version, 'Payment eligibility rule version'),
            $state,
            $effect,
            $this->nonNegative($row->priority, 'Payment eligibility rule priority'),
            (bool) $row->is_override,
            $this->ruleSpecificity($row),
            $row->configuration_hash,
            $replayed,
        );
    }

    /** @param DecisionRow $row */
    private function decisionReceipt(Connection $db, object $row, string $requestHash, bool $replayed): PaymentEligibilityDecisionReceipt
    {
        if (! hash_equals($row->request_payload_hash, $requestHash)) {
            throw new RuntimeException('Payment eligibility decision key conflict.');
        }
        if (! hash_equals(hash('sha256', $row->configuration_snapshot), $row->configuration_snapshot_hash)) {
            throw new RuntimeException('Stored payment eligibility decision snapshot hash is invalid.');
        }
        $action = PaymentEligibilityAction::tryFrom($row->action)
            ?? throw new RuntimeException('Stored payment eligibility action is invalid.');
        /** @var list<DecisionItemRow> $items */
        $items = $db->table('payment_eligibility_decision_items')
            ->where('decision_id', $this->positive($row->id, 'Payment eligibility decision ID'))
            ->where('eligible', true)
            ->orderByDesc('display_priority')
            ->orderBy('method_code_snapshot')
            ->get([
                'payment_method_id', 'method_public_id_snapshot', 'method_code_snapshot', 'kind_snapshot',
                'provider_code_snapshot', 'method_version', 'method_configuration_hash', 'display_priority',
                'eligible', 'payment_eligibility_rule_id', 'rule_public_id_snapshot', 'rule_code_snapshot',
                'rule_version', 'rule_configuration_hash',
            ])->all();
        $eligible = [];
        foreach ($items as $item) {
            $kind = PaymentMethodKind::tryFrom($item->kind_snapshot) ?? throw new RuntimeException('Stored eligible payment method kind is invalid.');
            if ($item->payment_eligibility_rule_id === null || $item->rule_public_id_snapshot === null || $item->rule_code_snapshot === null || $item->rule_version === null || $item->rule_configuration_hash === null) {
                throw new RuntimeException('Stored eligible payment method lacks rule provenance.');
            }
            $eligible[] = new EligiblePaymentMethodReceipt(
                $this->positive($item->payment_method_id, 'Payment method ID'),
                $item->method_public_id_snapshot,
                $item->method_code_snapshot,
                $kind,
                $item->provider_code_snapshot,
                $this->positive($item->method_version, 'Payment method version'),
                $item->method_configuration_hash,
                $this->nonNegative($item->display_priority, 'Payment method display priority'),
                $this->positive($item->payment_eligibility_rule_id, 'Payment eligibility rule ID'),
                $item->rule_public_id_snapshot,
                $item->rule_code_snapshot,
                $this->positive($item->rule_version, 'Payment eligibility rule version'),
                $item->rule_configuration_hash,
            );
        }
        if (count($eligible) !== $this->nonNegative($row->eligible_count, 'Payment eligibility count')) {
            throw new RuntimeException('Stored payment eligibility count is inconsistent.');
        }

        return new PaymentEligibilityDecisionReceipt(
            $this->positive($row->id, 'Payment eligibility decision ID'),
            $row->public_id,
            $row->decision_key,
            $this->positive($row->user_id, 'Payment eligibility user ID'),
            $row->quote_public_id_snapshot,
            $action,
            $this->nonNegative($row->amount_irr, 'Payment eligibility amount'),
            $row->currency,
            $eligible,
            $row->configuration_snapshot_hash,
            $replayed,
        );
    }
}
