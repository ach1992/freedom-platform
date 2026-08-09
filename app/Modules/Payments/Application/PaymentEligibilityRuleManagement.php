<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\Payments\Domain\PaymentConfigurationState;
use App\Modules\Payments\Domain\PaymentEligibilityRuleDefinition;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-type MethodVersionRow object{id:int|string,payment_method_id:int|string,mutation_payload_hash:string,version:int|string,state:string,display_priority:int|string,minimum_amount_irr:int|string|null,maximum_amount_irr:int|string|null,allow_degraded_health:int|bool|string,configuration_snapshot:string,configuration_hash:string,method_public_id:string,method_code:string,kind:string,provider_code:string|null}
 * @phpstan-type RuleVersionRow object{id:int|string,payment_eligibility_rule_id:int|string,payment_method_id:int|string,mutation_payload_hash:string,version:int|string,state:string,effect:string,priority:int|string,is_override:int|bool|string,account_type:string|null,tier_code:string|null,identity_status:string|null,customer_tag_id:int|string|null,minimum_amount_irr:int|string|null,maximum_amount_irr:int|string|null,action:string|null,plan_offering_id:int|string|null,product_id:int|string|null,sales_server_id:int|string|null,effective_from:string|null,effective_until:string|null,configuration_snapshot:string,configuration_hash:string,rule_public_id:string,rule_code:string,method_code:string}
 * @phpstan-type DecisionRow object{id:int|string,public_id:string,decision_key:string,request_payload_hash:string,user_id:int|string,quote_id:int|string,quote_public_id_snapshot:string,action:string,amount_irr:int|string,currency:string,eligible_count:int|string,configuration_snapshot:string,configuration_snapshot_hash:string}
 * @phpstan-type DecisionItemRow object{payment_method_id:int|string,method_public_id_snapshot:string,method_code_snapshot:string,kind_snapshot:string,provider_code_snapshot:string|null,method_version:int|string,method_configuration_hash:string,display_priority:int|string,eligible:int|bool|string,payment_eligibility_rule_id:int|string|null,rule_public_id_snapshot:string|null,rule_code_snapshot:string|null,rule_version:int|string|null,rule_configuration_hash:string|null}
 */
trait PaymentEligibilityRuleManagement
{
    public function createRule(
        string $mutationKey,
        string $methodCode,
        string $ruleCode,
        PaymentEligibilityRuleDefinition $definition,
        AccessChangeContext $context,
    ): PaymentEligibilityRuleVersionReceipt {
        $this->assertMutationKey($mutationKey);
        $this->assertCode($methodCode, 'Payment method code');
        $this->assertCode($ruleCode, 'Payment eligibility rule code');
        $reason = $context->requireReason();
        $this->authorizer->authorize($context->actorAdministratorId, self::MANAGE_PERMISSION);
        $payloadHash = $this->ruleMutationHash('create_rule', $methodCode, $ruleCode, $definition, $context->actorAdministratorId);

        try {
            return $this->database->connection()->transaction(function (Connection $db) use ($mutationKey, $methodCode, $ruleCode, $definition, $context, $reason, $payloadHash): PaymentEligibilityRuleVersionReceipt {
                $existing = $this->ruleVersionByMutationKey($db, $mutationKey, true);
                if ($existing !== null) {
                    return $this->ruleReceipt($existing, $payloadHash, true);
                }
                /** @var object{id:int|string}|null $method */
                $method = $db->table('payment_methods')->where('method_code', $methodCode)->lockForUpdate()->first(['id']);
                if ($method === null) {
                    throw new DomainException('Payment method does not exist.');
                }
                $methodId = $this->positive($method->id, 'Payment method ID');
                if ($db->table('payment_eligibility_rules')->where('payment_method_id', $methodId)->where('rule_code', $ruleCode)->exists()) {
                    throw new DomainException('Payment eligibility rule code already exists for method.');
                }
                $this->validateRuleReferences($db, $definition);
                $createdAt = $this->timestamp();
                $ruleId = (int) $db->table('payment_eligibility_rules')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'payment_method_id' => $methodId,
                    'rule_code' => $ruleCode,
                    'created_at' => $createdAt,
                ]);
                $this->insertRuleVersion($db, $ruleId, $methodId, $mutationKey, $payloadHash, 1, $methodCode, $ruleCode, $definition, $context, $reason, $createdAt);
                $created = $this->ruleVersionByMutationKey($db, $mutationKey);
                if ($created === null) {
                    throw new RuntimeException('Payment eligibility rule version persistence failed.');
                }

                return $this->ruleReceipt($created, $payloadHash, false);
            });
        } catch (QueryException $exception) {
            $existing = $this->ruleVersionByMutationKey($this->database->connection(), $mutationKey);
            if ($existing !== null) {
                return $this->ruleReceipt($existing, $payloadHash, true);
            }
            throw $exception;
        }
    }

    /** @requirement PAY-001 ACL-002 DAT-003 SEC-001 SEC-002 QUA-001 */
    public function reviseRule(
        string $mutationKey,
        string $methodCode,
        string $ruleCode,
        PaymentEligibilityRuleDefinition $definition,
        AccessChangeContext $context,
    ): PaymentEligibilityRuleVersionReceipt {
        $this->assertMutationKey($mutationKey);
        $this->assertCode($methodCode, 'Payment method code');
        $this->assertCode($ruleCode, 'Payment eligibility rule code');
        $reason = $context->requireReason();
        $this->authorizer->authorize($context->actorAdministratorId, self::MANAGE_PERMISSION);
        $payloadHash = $this->ruleMutationHash('revise_rule', $methodCode, $ruleCode, $definition, $context->actorAdministratorId);

        try {
            return $this->database->connection()->transaction(function (Connection $db) use ($mutationKey, $methodCode, $ruleCode, $definition, $context, $reason, $payloadHash): PaymentEligibilityRuleVersionReceipt {
                $existing = $this->ruleVersionByMutationKey($db, $mutationKey, true);
                if ($existing !== null) {
                    return $this->ruleReceipt($existing, $payloadHash, true);
                }
                /** @var object{id:int|string}|null $method */
                $method = $db->table('payment_methods')->where('method_code', $methodCode)->lockForUpdate()->first(['id']);
                if ($method === null) {
                    throw new DomainException('Payment method does not exist.');
                }
                $methodId = $this->positive($method->id, 'Payment method ID');
                /** @var object{id:int|string}|null $rule */
                $rule = $db->table('payment_eligibility_rules')->where('payment_method_id', $methodId)->where('rule_code', $ruleCode)->lockForUpdate()->first(['id']);
                if ($rule === null) {
                    throw new DomainException('Payment eligibility rule does not exist.');
                }
                $ruleId = $this->positive($rule->id, 'Payment eligibility rule ID');
                /** @var object{version:int|string,state:string}|null $latest */
                $latest = $db->table('payment_eligibility_rule_versions')->where('payment_eligibility_rule_id', $ruleId)->orderByDesc('version')->lockForUpdate()->first(['version', 'state']);
                if ($latest === null) {
                    throw new RuntimeException('Payment eligibility rule has no stored configuration.');
                }
                if ($latest->state === PaymentConfigurationState::Archived->value) {
                    throw new DomainException('Archived payment eligibility rule cannot be revised.');
                }
                $this->validateRuleReferences($db, $definition);
                $version = $this->positive($latest->version, 'Payment eligibility rule version') + 1;
                $createdAt = $this->timestamp();
                $this->insertRuleVersion($db, $ruleId, $methodId, $mutationKey, $payloadHash, $version, $methodCode, $ruleCode, $definition, $context, $reason, $createdAt);
                $created = $this->ruleVersionByMutationKey($db, $mutationKey);
                if ($created === null) {
                    throw new RuntimeException('Payment eligibility rule revision persistence failed.');
                }

                return $this->ruleReceipt($created, $payloadHash, false);
            });
        } catch (QueryException $exception) {
            $existing = $this->ruleVersionByMutationKey($this->database->connection(), $mutationKey);
            if ($existing !== null) {
                return $this->ruleReceipt($existing, $payloadHash, true);
            }
            throw $exception;
        }
    }

    /** @requirement PAY-001 BUY-002 DAT-002 DAT-003 SEC-001 SEC-002 QUA-001 */
    private function insertRuleVersion(
        Connection $db,
        int $ruleId,
        int $methodId,
        string $mutationKey,
        string $payloadHash,
        int $version,
        string $methodCode,
        string $ruleCode,
        PaymentEligibilityRuleDefinition $definition,
        AccessChangeContext $context,
        string $reason,
        string $createdAt,
    ): void {
        $snapshot = array_merge([
            'method_code' => $methodCode,
            'rule_code' => $ruleCode,
        ], $definition->snapshot());
        $snapshotJson = $this->json($snapshot);
        if (strlen($snapshotJson) > 8192) {
            throw new DomainException('Payment eligibility rule configuration exceeds storage boundary.');
        }
        $db->table('payment_eligibility_rule_versions')->insert([
            'payment_eligibility_rule_id' => $ruleId,
            'payment_method_id' => $methodId,
            'mutation_key' => $mutationKey,
            'mutation_payload_hash' => $payloadHash,
            'version' => $version,
            'state' => $definition->state->value,
            'effect' => $definition->effect->value,
            'priority' => $definition->priority,
            'is_override' => $definition->isOverride,
            'account_type' => $definition->accountType,
            'tier_code' => $definition->tierCode,
            'identity_status' => $definition->identityStatus,
            'customer_tag_id' => $definition->customerTagId,
            'minimum_amount_irr' => $definition->minimumAmountIrr,
            'maximum_amount_irr' => $definition->maximumAmountIrr,
            'action' => $definition->action?->value,
            'plan_offering_id' => $definition->planOfferingId,
            'product_id' => $definition->productId,
            'sales_server_id' => $definition->salesServerId,
            'effective_from' => $this->nullableDate($definition->effectiveFrom),
            'effective_until' => $this->nullableDate($definition->effectiveUntil),
            'configuration_snapshot' => $snapshotJson,
            'configuration_hash' => hash('sha256', $snapshotJson),
            'actor_administrator_id' => $context->actorAdministratorId,
            'reason_code' => $context->reasonCode,
            'reason' => $reason,
            'correlation_id' => $context->correlationId,
            'created_at' => $createdAt,
        ]);
    }

    private function ruleMutationHash(string $operation, string $methodCode, string $ruleCode, PaymentEligibilityRuleDefinition $definition, int $administratorId): string
    {
        return $this->hash([
            'actor_administrator_id' => $administratorId,
            'definition' => $definition->snapshot(),
            'method_code' => $methodCode,
            'operation' => $operation,
            'rule_code' => $ruleCode,
        ]);
    }

    private function validateRuleReferences(Connection $db, PaymentEligibilityRuleDefinition $definition): void
    {
        if ($definition->tierCode !== null && ! $db->table('customer_tiers')->where('code', $definition->tierCode)->where('is_active', true)->exists()) {
            throw new DomainException('Payment eligibility tier does not exist or is inactive.');
        }
        if ($definition->customerTagId !== null && ! $db->table('customer_tags')->where('id', $definition->customerTagId)->where('is_active', true)->exists()) {
            throw new DomainException('Payment eligibility customer tag does not exist or is inactive.');
        }
        if ($definition->productId !== null && ! $db->table('products')->where('id', $definition->productId)->exists()) {
            throw new DomainException('Payment eligibility product does not exist.');
        }
        if ($definition->salesServerId !== null && ! $db->table('sales_servers')->where('id', $definition->salesServerId)->exists()) {
            throw new DomainException('Payment eligibility sales server does not exist.');
        }
        if ($definition->planOfferingId === null) {
            return;
        }
        /** @var object{product_id:int|string,sales_server_id:int|string}|null $offering */
        $offering = $db->table('plan_offerings')->where('id', $definition->planOfferingId)->first(['product_id', 'sales_server_id']);
        if ($offering === null) {
            throw new DomainException('Payment eligibility plan offering does not exist.');
        }
        if (($definition->productId !== null && $definition->productId !== $this->positive($offering->product_id, 'Offering product ID'))
            || ($definition->salesServerId !== null && $definition->salesServerId !== $this->positive($offering->sales_server_id, 'Offering server ID'))) {
            throw new DomainException('Payment eligibility compound scope is inconsistent.');
        }
    }

    /** @return list<MethodVersionRow> */
}
