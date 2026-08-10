<?php

declare(strict_types=1);

namespace App\Modules\Promotions\BenefitCodes\Application;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeCampaignCode;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeDefinition;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeState;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeType;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

trait BenefitCodeManagementSupport
{
    private function writeCampaign(
        string $operation,
        string $mutationKey,
        BenefitCodeCampaignCode $campaignCode,
        BenefitCodeType $type,
        BenefitCodeDefinition $definition,
        AccessChangeContext $context,
    ): BenefitCodeCampaignVersionReceipt {
        $this->assertMutationKey($mutationKey);
        $reason = $context->requireReason();
        $this->authorizer->authorize($context->actorAdministratorId, self::MANAGE_PERMISSION);
        $this->assertDefinitionShape($type, $definition);
        $payloadHash = $this->hash([
            'actor_administrator_id' => $context->actorAdministratorId,
            'campaign_code' => $campaignCode->value,
            'definition' => $this->definitionPayload($definition),
            'operation' => $operation,
            'type' => $type->value,
        ]);

        try {
            return $this->database->connection()->transaction(function (Connection $db) use (
                $operation,
                $mutationKey,
                $campaignCode,
                $type,
                $definition,
                $context,
                $reason,
                $payloadHash,
            ): BenefitCodeCampaignVersionReceipt {
                $existing = $this->campaignVersionByMutationKey($db, $mutationKey, true);
                if ($existing !== null) {
                    return $this->campaignReceipt($existing, $payloadHash, true);
                }

                /** @var object{id:int|string,type:string}|null $campaign */
                $campaign = $db->table('benefit_code_campaigns')->where('campaign_code', $campaignCode->value)->lockForUpdate()->first(['id', 'type']);
                if ($operation === 'create') {
                    if ($campaign !== null) {
                        throw new DomainException('Benefit code campaign already exists.');
                    }
                    $campaignId = (int) $db->table('benefit_code_campaigns')->insertGetId([
                        'public_id' => (string) Str::ulid(),
                        'campaign_code' => $campaignCode->value,
                        'type' => $type->value,
                        'created_at' => $this->timestamp(),
                    ]);
                    $version = 1;
                } else {
                    if ($campaign === null || ! hash_equals($campaign->type, $type->value)) {
                        throw new DomainException('Benefit code campaign does not exist or has invalid identity.');
                    }
                    $campaignId = $this->positive($campaign->id, 'Benefit code campaign ID');
                    /** @var object{version:int|string,state:string}|null $latest */
                    $latest = $db->table('benefit_code_campaign_versions')->where('benefit_code_campaign_id', $campaignId)->orderByDesc('version')->lockForUpdate()->first(['version', 'state']);
                    if ($latest === null) {
                        throw new RuntimeException('Benefit code campaign has no stored configuration.');
                    }
                    if ($latest->state === BenefitCodeState::Archived->value) {
                        throw new DomainException('Archived benefit code campaign cannot be revised.');
                    }
                    $version = $this->positive($latest->version, 'Benefit code campaign version') + 1;
                }

                $references = $this->validatedReferences($db, $type, $definition);
                $configuration = $this->configurationSnapshot($campaignCode->value, $type, $definition, $references);
                $configurationJson = $this->json($configuration);
                $createdAt = $this->timestamp();
                $db->table('benefit_code_campaign_versions')->insert([
                    'benefit_code_campaign_id' => $campaignId,
                    'mutation_key' => $mutationKey,
                    'mutation_payload_hash' => $payloadHash,
                    'version' => $version,
                    'state' => $definition->state->value,
                    'audience' => $definition->audience->value,
                    'single_use' => $definition->singleUse,
                    'total_use_limit' => $definition->totalUseLimit,
                    'per_user_use_limit' => $definition->perUserUseLimit,
                    'effective_from' => $this->date($definition->effectiveFrom),
                    'effective_until' => $this->date($definition->effectiveUntil),
                    'plan_offering_id' => $definition->planOfferingId,
                    'product_id' => $definition->productId,
                    'sales_server_id' => $definition->salesServerId,
                    'wallet_credit_irr' => $definition->walletCreditIrr,
                    'discount_pricing_rule_id' => $references['discount_pricing_rule_id'],
                    'discount_pricing_rule_version_id' => $references['discount_pricing_rule_version_id'],
                    'configuration_snapshot' => $configurationJson,
                    'configuration_hash' => hash('sha256', $configurationJson),
                    'actor_administrator_id' => $context->actorAdministratorId,
                    'reason_code' => $context->reasonCode,
                    'reason' => $reason,
                    'correlation_id' => $context->correlationId,
                    'created_at' => $createdAt,
                ]);

                $created = $this->campaignVersionByMutationKey($db, $mutationKey);
                if ($created === null) {
                    throw new RuntimeException('Benefit code campaign persistence failed.');
                }

                return $this->campaignReceipt($created, $payloadHash, false);
            });
        } catch (QueryException $exception) {
            $existing = $this->campaignVersionByMutationKey($this->database->connection(), $mutationKey);
            if ($existing !== null) {
                return $this->campaignReceipt($existing, $payloadHash, true);
            }

            throw $exception;
        }
    }

    private function assertDefinitionShape(BenefitCodeType $type, BenefitCodeDefinition $definition): void
    {
        if ($type === BenefitCodeType::WalletCredit) {
            if ($definition->walletCreditIrr === null || $definition->discountRuleCode !== null) {
                throw new DomainException('Wallet-credit benefit configuration is invalid.');
            }
        } elseif ($type === BenefitCodeType::FreeService) {
            if ($definition->planOfferingId === null || $definition->walletCreditIrr !== null || $definition->discountRuleCode !== null) {
                throw new DomainException('Free-service benefit configuration is invalid.');
            }
        } elseif ($definition->discountRuleCode === null || $definition->walletCreditIrr !== null) {
            throw new DomainException('Discount-grant benefit configuration is invalid.');
        }
    }

    /** @return array<string, mixed> */
    private function definitionPayload(BenefitCodeDefinition $definition): array
    {
        return [
            'audience' => $definition->audience->value,
            'discount_rule_code' => $definition->discountRuleCode,
            'effective_from' => $this->date($definition->effectiveFrom),
            'effective_until' => $this->date($definition->effectiveUntil),
            'per_user_use_limit' => $definition->perUserUseLimit,
            'plan_offering_id' => $definition->planOfferingId,
            'product_id' => $definition->productId,
            'sales_server_id' => $definition->salesServerId,
            'single_use' => $definition->singleUse,
            'state' => $definition->state->value,
            'total_use_limit' => $definition->totalUseLimit,
            'wallet_credit_irr' => $definition->walletCreditIrr,
        ];
    }

    /** @return array{discount_pricing_rule_id:int|null,discount_pricing_rule_version_id:int|null,discount_rule:array<string,mixed>|null,offering:array<string,mixed>|null} */
    private function validatedReferences(Connection $db, BenefitCodeType $type, BenefitCodeDefinition $definition): array
    {
        $offering = null;
        if ($definition->planOfferingId !== null) {
            /** @var object{id:int|string,code:string,product_id:int|string,sales_server_id:int|string,service_mode_code:string,duration_days:int|string,data_allowance_bytes:int|string|null,device_limit:int|string|null,version:int|string}|null $row */
            $row = $db->table('plan_offerings')->where('id', $definition->planOfferingId)->first([
                'id', 'code', 'product_id', 'sales_server_id', 'service_mode_code', 'duration_days', 'data_allowance_bytes', 'device_limit', 'version',
            ]);
            if ($row === null) {
                throw new DomainException('Benefit code plan offering does not exist.');
            }
            if ($definition->productId !== null && $this->positive($row->product_id, 'Benefit code offering product ID') !== $definition->productId) {
                throw new DomainException('Benefit code product scope does not match its plan offering.');
            }
            if ($definition->salesServerId !== null && $this->positive($row->sales_server_id, 'Benefit code offering server ID') !== $definition->salesServerId) {
                throw new DomainException('Benefit code server scope does not match its plan offering.');
            }
            $offering = [
                'code' => $row->code,
                'data_allowance_bytes' => $row->data_allowance_bytes === null ? null : (int) $row->data_allowance_bytes,
                'device_limit' => $row->device_limit === null ? null : (int) $row->device_limit,
                'duration_days' => $this->positive($row->duration_days, 'Benefit code offering duration'),
                'id' => $this->positive($row->id, 'Benefit code offering ID'),
                'product_id' => $this->positive($row->product_id, 'Benefit code offering product ID'),
                'sales_server_id' => $this->positive($row->sales_server_id, 'Benefit code offering server ID'),
                'service_mode_code' => $row->service_mode_code,
                'version' => $this->positive($row->version, 'Benefit code offering version'),
            ];
        }
        foreach ([['products', $definition->productId, 'product'], ['sales_servers', $definition->salesServerId, 'sales server']] as [$table, $id, $label]) {
            if ($id !== null && ! $db->table($table)->where('id', $id)->exists()) {
                throw new DomainException('Benefit code '.$label.' scope does not exist.');
            }
        }

        $rule = null;
        $ruleId = null;
        $versionId = null;
        if ($type === BenefitCodeType::DiscountGrant) {
            /** @var object{id:int|string,public_id:string,rule_code:string,kind:string,version_id:int|string,version:int|string,state:string,configuration_snapshot:string,configuration_hash:string}|null $row */
            $row = $db->table('pricing_rules as r')
                ->join('pricing_rule_versions as v', 'v.pricing_rule_id', '=', 'r.id')
                ->where('r.rule_code', $definition->discountRuleCode)
                ->where('r.kind', 'promotion')
                ->where('v.state', 'active')
                ->orderByDesc('v.version')
                ->lockForUpdate()
                ->first([
                    'r.id', 'r.public_id', 'r.rule_code', 'r.kind', 'v.id as version_id', 'v.version', 'v.state',
                    'v.configuration_snapshot', 'v.configuration_hash',
                ]);
            if ($row === null) {
                throw new DomainException('Discount-grant benefit requires an active promotion rule version.');
            }
            $this->decodeConfiguration($row->configuration_snapshot, $row->configuration_hash);
            $ruleId = $this->positive($row->id, 'Benefit code discount rule ID');
            $versionId = $this->positive($row->version_id, 'Benefit code discount rule version ID');
            $rule = [
                'configuration' => json_decode($row->configuration_snapshot, true, flags: JSON_THROW_ON_ERROR),
                'configuration_hash' => $row->configuration_hash,
                'public_id' => $row->public_id,
                'rule_code' => $row->rule_code,
                'version' => $this->positive($row->version, 'Benefit code discount rule version'),
            ];
        }

        return [
            'discount_pricing_rule_id' => $ruleId,
            'discount_pricing_rule_version_id' => $versionId,
            'discount_rule' => $rule,
            'offering' => $offering,
        ];
    }

    /** @param array{discount_rule:array<string,mixed>|null,offering:array<string,mixed>|null} $references
     * @return array<string,mixed>
     */
    private function configurationSnapshot(string $campaignCode, BenefitCodeType $type, BenefitCodeDefinition $definition, array $references): array
    {
        return [
            'audience' => $definition->audience->value,
            'benefit' => [
                'discount_rule' => $references['discount_rule'],
                'free_service_offering' => $type === BenefitCodeType::FreeService ? $references['offering'] : null,
                'wallet_credit_irr' => $definition->walletCreditIrr,
            ],
            'campaign_code' => $campaignCode,
            'effective_from' => $this->date($definition->effectiveFrom),
            'effective_until' => $this->date($definition->effectiveUntil),
            'formula_version' => self::SNAPSHOT_VERSION,
            'limits' => [
                'per_user_use_limit' => $definition->perUserUseLimit,
                'single_use' => $definition->singleUse,
                'total_use_limit' => $definition->totalUseLimit,
            ],
            'scope' => [
                'plan_offering_id' => $definition->planOfferingId,
                'product_id' => $definition->productId,
                'sales_server_id' => $definition->salesServerId,
            ],
            'state' => $definition->state->value,
            'type' => $type->value,
        ];
    }

    private function disableStoredCode(string $mutationKey, string $codePublicId, AccessChangeContext $context): BenefitCodeDisableReceipt
    {
        $this->assertMutationKey($mutationKey);
        $this->assertUlid($codePublicId, 'Benefit code public ID');
        $reason = $context->requireReason();
        $this->authorizer->authorize($context->actorAdministratorId, self::MANAGE_PERMISSION);
        $payloadHash = $this->hash([
            'actor_administrator_id' => $context->actorAdministratorId,
            'code_public_id' => $codePublicId,
            'operation' => 'disable_code',
        ]);

        try {
            return $this->database->connection()->transaction(function (Connection $db) use ($mutationKey, $codePublicId, $context, $reason, $payloadHash): BenefitCodeDisableReceipt {
                $existing = $this->disableByKey($db, $mutationKey, true);
                if ($existing !== null) {
                    return $this->disableReceipt($existing, $payloadHash, true);
                }
                /** @var object{id:int|string}|null $code */
                $code = $db->table('benefit_codes')->where('public_id', $codePublicId)->lockForUpdate()->first(['id']);
                if ($code === null) {
                    throw new DomainException('Benefit code does not exist.');
                }
                $codeId = $this->positive($code->id, 'Benefit code ID');
                if ($db->table('benefit_code_disables')->where('benefit_code_id', $codeId)->exists()) {
                    throw new DomainException('Benefit code is already disabled.');
                }
                $db->table('benefit_code_disables')->insert([
                    'public_id' => (string) Str::ulid(),
                    'mutation_key' => $mutationKey,
                    'request_payload_hash' => $payloadHash,
                    'benefit_code_id' => $codeId,
                    'actor_administrator_id' => $context->actorAdministratorId,
                    'reason_code' => $context->reasonCode,
                    'reason' => $reason,
                    'correlation_id' => $context->correlationId,
                    'created_at' => $this->timestamp(),
                ]);
                $created = $this->disableByKey($db, $mutationKey);
                if ($created === null) {
                    throw new RuntimeException('Benefit code disable persistence failed.');
                }

                return $this->disableReceipt($created, $payloadHash, false);
            });
        } catch (QueryException $exception) {
            $existing = $this->disableByKey($this->database->connection(), $mutationKey);
            if ($existing !== null) {
                return $this->disableReceipt($existing, $payloadHash, true);
            }

            throw $exception;
        }
    }

    /** @param object{id:int|string,public_id:string,mutation_key:string,request_payload_hash:string,benefit_code_id:int|string,code_public_id:string} $row */
    private function disableReceipt(object $row, string $payloadHash, bool $replayed): BenefitCodeDisableReceipt
    {
        if (! hash_equals((string) $row->request_payload_hash, $payloadHash)) {
            throw new RuntimeException('Benefit code disable mutation key conflict.');
        }

        return new BenefitCodeDisableReceipt(
            $this->positive($row->id, 'Benefit code disable ID'),
            (string) $row->public_id,
            (string) $row->code_public_id,
            $replayed,
        );
    }
}
