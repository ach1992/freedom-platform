<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\Payments\Domain\PaymentConfigurationState;
use App\Modules\Payments\Domain\PaymentMethodDefinition;
use App\Modules\Payments\Domain\PaymentMethodKind;
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
trait PaymentMethodConfigurationManagement
{
    public function createMethod(
        string $mutationKey,
        string $methodCode,
        PaymentMethodKind $kind,
        ?string $providerCode,
        PaymentMethodDefinition $definition,
        AccessChangeContext $context,
    ): PaymentMethodVersionReceipt {
        $this->assertMutationKey($mutationKey);
        $this->assertCode($methodCode, 'Payment method code');
        $this->assertNullableProviderCode($providerCode);
        $reason = $context->requireReason();
        $this->authorizer->authorize($context->actorAdministratorId, self::MANAGE_PERMISSION);
        $payloadHash = $this->hash([
            'actor_administrator_id' => $context->actorAdministratorId,
            'definition' => $definition->snapshot(),
            'kind' => $kind->value,
            'method_code' => $methodCode,
            'operation' => 'create_method',
            'provider_code' => $providerCode,
        ]);

        try {
            return $this->database->connection()->transaction(function (Connection $db) use (
                $mutationKey,
                $methodCode,
                $kind,
                $providerCode,
                $definition,
                $context,
                $reason,
                $payloadHash,
            ): PaymentMethodVersionReceipt {
                $existing = $this->methodVersionByMutationKey($db, $mutationKey, true);
                if ($existing !== null) {
                    return $this->methodReceipt($existing, $payloadHash, true);
                }
                if ($db->table('payment_methods')->where('method_code', $methodCode)->exists()) {
                    throw new DomainException('Payment method code already exists.');
                }
                $createdAt = $this->timestamp();
                $methodId = (int) $db->table('payment_methods')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'method_code' => $methodCode,
                    'kind' => $kind->value,
                    'provider_code' => $providerCode,
                    'created_at' => $createdAt,
                ]);
                $this->insertMethodVersion($db, $methodId, $mutationKey, $payloadHash, 1, $methodCode, $kind, $providerCode, $definition, $context, $reason, $createdAt);
                $created = $this->methodVersionByMutationKey($db, $mutationKey);
                if ($created === null) {
                    throw new RuntimeException('Payment method version persistence failed.');
                }

                return $this->methodReceipt($created, $payloadHash, false);
            });
        } catch (QueryException $exception) {
            $existing = $this->methodVersionByMutationKey($this->database->connection(), $mutationKey);
            if ($existing !== null) {
                return $this->methodReceipt($existing, $payloadHash, true);
            }
            throw $exception;
        }
    }

    /** @requirement PAY-001 ACL-002 DAT-003 SEC-001 SEC-002 QUA-001 */
    public function reviseMethod(
        string $mutationKey,
        string $methodCode,
        PaymentMethodDefinition $definition,
        AccessChangeContext $context,
    ): PaymentMethodVersionReceipt {
        $this->assertMutationKey($mutationKey);
        $this->assertCode($methodCode, 'Payment method code');
        $reason = $context->requireReason();
        $this->authorizer->authorize($context->actorAdministratorId, self::MANAGE_PERMISSION);
        /** @var object{kind:string,provider_code:string|null}|null $identity */
        $identity = $this->database->connection()->table('payment_methods')->where('method_code', $methodCode)->first(['kind', 'provider_code']);
        $kind = $identity === null ? null : PaymentMethodKind::tryFrom($identity->kind);
        if ($identity === null || $kind === null) {
            throw new DomainException('Payment method does not exist or has invalid identity.');
        }
        $payloadHash = $this->hash([
            'actor_administrator_id' => $context->actorAdministratorId,
            'definition' => $definition->snapshot(),
            'kind' => $kind->value,
            'method_code' => $methodCode,
            'operation' => 'revise_method',
            'provider_code' => $identity->provider_code,
        ]);

        try {
            return $this->database->connection()->transaction(function (Connection $db) use ($mutationKey, $methodCode, $definition, $context, $reason, $payloadHash, $kind): PaymentMethodVersionReceipt {
                $existing = $this->methodVersionByMutationKey($db, $mutationKey, true);
                if ($existing !== null) {
                    return $this->methodReceipt($existing, $payloadHash, true);
                }
                /** @var object{id:int|string,kind:string,provider_code:string|null}|null $method */
                $method = $db->table('payment_methods')->where('method_code', $methodCode)->lockForUpdate()->first(['id', 'kind', 'provider_code']);
                if ($method === null || ! hash_equals($method->kind, $kind->value)) {
                    throw new DomainException('Payment method does not exist or has invalid identity.');
                }
                $methodId = $this->positive($method->id, 'Payment method ID');
                /** @var object{version:int|string,state:string}|null $latest */
                $latest = $db->table('payment_method_versions')->where('payment_method_id', $methodId)->orderByDesc('version')->lockForUpdate()->first(['version', 'state']);
                if ($latest === null) {
                    throw new RuntimeException('Payment method has no stored configuration.');
                }
                if ($latest->state === PaymentConfigurationState::Archived->value) {
                    throw new DomainException('Archived payment method cannot be revised.');
                }
                $version = $this->positive($latest->version, 'Payment method version') + 1;
                $createdAt = $this->timestamp();
                $this->insertMethodVersion($db, $methodId, $mutationKey, $payloadHash, $version, $methodCode, $kind, $method->provider_code, $definition, $context, $reason, $createdAt);
                $created = $this->methodVersionByMutationKey($db, $mutationKey);
                if ($created === null) {
                    throw new RuntimeException('Payment method revision persistence failed.');
                }

                return $this->methodReceipt($created, $payloadHash, false);
            });
        } catch (QueryException $exception) {
            $existing = $this->methodVersionByMutationKey($this->database->connection(), $mutationKey);
            if ($existing !== null) {
                return $this->methodReceipt($existing, $payloadHash, true);
            }
            throw $exception;
        }
    }

    /** @requirement PAY-001 ACL-002 DAT-002 DAT-003 SEC-001 SEC-002 QUA-001 */
    private function insertMethodVersion(
        Connection $db,
        int $methodId,
        string $mutationKey,
        string $payloadHash,
        int $version,
        string $methodCode,
        PaymentMethodKind $kind,
        ?string $providerCode,
        PaymentMethodDefinition $definition,
        AccessChangeContext $context,
        string $reason,
        string $createdAt,
    ): void {
        $snapshot = array_merge([
            'kind' => $kind->value,
            'method_code' => $methodCode,
            'provider_code' => $providerCode,
        ], $definition->snapshot());
        $snapshotJson = $this->json($snapshot);
        if (strlen($snapshotJson) > 8192) {
            throw new DomainException('Payment method configuration exceeds storage boundary.');
        }
        $db->table('payment_method_versions')->insert([
            'payment_method_id' => $methodId,
            'mutation_key' => $mutationKey,
            'mutation_payload_hash' => $payloadHash,
            'version' => $version,
            'state' => $definition->state->value,
            'display_priority' => $definition->displayPriority,
            'minimum_amount_irr' => $definition->minimumAmountIrr,
            'maximum_amount_irr' => $definition->maximumAmountIrr,
            'allow_degraded_health' => $definition->allowDegradedHealth,
            'configuration_snapshot' => $snapshotJson,
            'configuration_hash' => hash('sha256', $snapshotJson),
            'actor_administrator_id' => $context->actorAdministratorId,
            'reason_code' => $context->reasonCode,
            'reason' => $reason,
            'correlation_id' => $context->correlationId,
            'created_at' => $createdAt,
        ]);
    }
}
