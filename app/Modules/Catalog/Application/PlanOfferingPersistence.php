<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\OfferingOperationPolicy;
use App\Modules\Catalog\Domain\OfferingPackageDefinition;
use App\Modules\Catalog\Domain\PlanOfferingDefinition;
use Illuminate\Database\Connection;

trait PlanOfferingPersistence
{
    private function insertOfferingChildren(
        Connection $connection,
        int $offeringId,
        PlanOfferingDefinition $definition,
        string $now,
    ): void {
        foreach ($definition->tierCodes as $tierCode) {
            $connection->table('plan_offering_tiers')->insert([
                'plan_offering_id' => $offeringId,
                'tier_code' => $tierCode,
                'created_at' => $now,
            ]);
        }
        foreach ($definition->tagIds as $tagId) {
            $connection->table('plan_offering_tags')->insert([
                'plan_offering_id' => $offeringId,
                'customer_tag_id' => $tagId,
                'created_at' => $now,
            ]);
        }
        foreach ($definition->protocols as $protocol) {
            $connection->table('plan_offering_protocol_profiles')->insert([
                'plan_offering_id' => $offeringId,
                'panel_protocol_profile_id' => $protocol->protocolProfileId,
                'customer_selectable' => $protocol->customerSelectable,
                'is_default' => $protocol->default,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach ($definition->requiredCapabilities as $capability) {
            $connection->table('plan_offering_required_capabilities')->insert([
                'plan_offering_id' => $offeringId,
                'capability_code' => $capability,
                'created_at' => $now,
            ]);
        }
        foreach ($definition->operations as $operation) {
            $this->insertOperation($connection, $offeringId, $operation, $now);
        }
        foreach ($definition->packages as $package) {
            $this->insertPackage($connection, $offeringId, $package, $now);
        }
    }

    private function replaceOfferingChildren(
        Connection $connection,
        int $offeringId,
        PlanOfferingDefinition $definition,
        string $now,
    ): void {
        foreach ([
            'plan_offering_tiers',
            'plan_offering_tags',
            'plan_offering_protocol_profiles',
            'plan_offering_required_capabilities',
            'plan_offering_operations',
            'plan_offering_packages',
        ] as $table) {
            $connection->table($table)->where('plan_offering_id', $offeringId)->delete();
        }

        $this->insertOfferingChildren($connection, $offeringId, $definition, $now);
    }

    private function insertOperation(
        Connection $connection,
        int $offeringId,
        OfferingOperationPolicy $operation,
        string $now,
    ): void {
        $connection->table('plan_offering_operations')->insert([
            'plan_offering_id' => $offeringId,
            'operation_code' => $operation->operation->value,
            'customer_enabled' => $operation->customerEnabled,
            'administrator_enabled' => $operation->administratorEnabled,
            'price_irr' => $operation->priceIrr,
            'discount_eligible' => $operation->discountEligible,
            'required_capability_code' => $operation->requiredCapabilityCode,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertPackage(
        Connection $connection,
        int $offeringId,
        OfferingPackageDefinition $package,
        string $now,
    ): void {
        $connection->table('plan_offering_packages')->insert([
            'plan_offering_id' => $offeringId,
            'code' => $package->code,
            'package_type' => $package->type->value,
            'name_fa' => $package->nameFa,
            'name_en' => $package->nameEn,
            'price_irr' => $package->priceIrr,
            'duration_days' => $package->durationDays,
            'data_bytes' => $package->dataBytes,
            'discount_eligible' => $package->discountEligible,
            'sort_order' => $package->sortOrder,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array<string, bool|int|string|null> */
    private function safeOfferingState(
        Connection $connection,
        PlanOfferingRecord $record,
        string $configurationHash,
        string $payloadHash,
    ): array {
        return [
            'request_payload_hash' => $payloadHash,
            'offering_id' => $record->id,
            'code' => $record->code,
            'product_id' => $record->productId,
            'variant_id' => $record->variantId,
            'sales_server_id' => $record->salesServerId,
            'service_target_id' => $record->serviceTargetId,
            'service_mode_code' => $record->serviceModeCode,
            'audience' => $record->audience,
            'server_selection_mode' => $record->serverSelectionMode,
            'protocol_selection_mode' => $record->protocolSelectionMode,
            'tag_match_mode' => $record->tagMatchMode,
            'base_price_irr' => $record->basePriceIrr,
            'duration_days' => $record->durationDays,
            'data_unlimited' => $record->dataAllowanceBytes === null,
            'device_unlimited' => $record->deviceLimit === null,
            'sort_order' => $record->sortOrder,
            'min_purchase_quantity' => $record->minPurchaseQuantity,
            'max_purchase_quantity' => $record->maxPurchaseQuantity,
            'discount_eligible' => $record->discountEligible,
            'auto_renew_allowed' => $record->autoRenewAllowed,
            'custom_plan_allowed' => $record->customPlanAllowed,
            'trial_allowed' => $record->trialAllowed,
            'tier_count' => $connection->table('plan_offering_tiers')->where('plan_offering_id', $record->id)->count(),
            'tag_count' => $connection->table('plan_offering_tags')->where('plan_offering_id', $record->id)->count(),
            'protocol_count' => $connection->table('plan_offering_protocol_profiles')->where('plan_offering_id', $record->id)->count(),
            'capability_count' => $connection->table('plan_offering_required_capabilities')->where('plan_offering_id', $record->id)->count(),
            'operation_count' => $connection->table('plan_offering_operations')->where('plan_offering_id', $record->id)->count(),
            'package_count' => $connection->table('plan_offering_packages')->where('plan_offering_id', $record->id)->count(),
            'state' => $record->state,
            'visibility' => $record->visibility,
            'version' => $record->version,
            'configuration_hash' => $configurationHash,
        ];
    }

    /**
     * @param  array<string, bool|int|string|null>|null  $before
     * @param  array<string, bool|int|string|null>  $after
     */
    private function offeringHistory(
        Connection $connection,
        int $offeringId,
        int $version,
        string $action,
        ?array $before,
        array $after,
        CatalogChangeContext $context,
    ): void {
        $connection->table('plan_offering_histories')->insert([
            'plan_offering_id' => $offeringId,
            'version' => $version,
            'action' => $action,
            'from_state' => $before['state'] ?? null,
            'to_state' => $after['state'],
            'from_visibility' => $before['visibility'] ?? null,
            'to_visibility' => $after['visibility'],
            'from_configuration_hash' => $before['configuration_hash'] ?? null,
            'to_configuration_hash' => $after['configuration_hash'],
            'before_safe_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_safe_data' => json_encode($after, JSON_THROW_ON_ERROR),
            'actor_administrator_id' => $context->actorAdministratorId,
            'reason_code' => $context->reasonCode,
            'reason' => $context->requireReason(),
            'correlation_id' => $context->correlationId,
            'created_at' => $this->offeringTimestamp(),
        ]);
    }
}
