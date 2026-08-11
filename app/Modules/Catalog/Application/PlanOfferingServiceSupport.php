<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\CatalogState;
use App\Modules\Catalog\Domain\PlanOfferingDefinition;
use App\Modules\Catalog\Domain\ProductVisibility;
use Illuminate\Database\Connection;
use RuntimeException;

trait PlanOfferingServiceSupport
{
    private function lockedOffering(Connection $connection, int $offeringId): PlanOfferingRecord
    {
        /** @var object{id: int|string, code: string, product_id: int|string, variant_id: int|string|null, sales_server_id: int|string, panel_service_target_id: int|string, service_mode_code: string, service_mode_label_fa: string, service_mode_label_en: ?string, audience: string, server_selection_mode: string, protocol_selection_mode: string, tag_match_mode: string, base_price_irr: int|string, duration_days: int|string, data_allowance_bytes: int|string|null, device_limit: int|string|null, sort_order: int|string, min_purchase_quantity: int|string, max_purchase_quantity: int|string, discount_eligible: bool|int, auto_renew_allowed: bool|int, custom_plan_allowed: bool|int, trial_allowed: bool|int, state: string, visibility: string, version: int|string}|null $row */
        $row = $connection->table('plan_offerings')
            ->where('id', $offeringId)
            ->lockForUpdate()
            ->first([
                'id', 'code', 'product_id', 'variant_id', 'sales_server_id', 'panel_service_target_id',
                'service_mode_code', 'service_mode_label_fa', 'service_mode_label_en', 'audience',
                'server_selection_mode', 'protocol_selection_mode', 'tag_match_mode', 'base_price_irr', 'duration_days',
                'data_allowance_bytes', 'device_limit', 'sort_order', 'min_purchase_quantity',
                'max_purchase_quantity', 'discount_eligible', 'auto_renew_allowed',
                'custom_plan_allowed', 'trial_allowed', 'state', 'visibility', 'version',
            ]);

        if ($row === null) {
            throw new RuntimeException('Plan offering does not exist.');
        }

        return new PlanOfferingRecord(
            (int) $row->id,
            $row->code,
            (int) $row->product_id,
            $row->variant_id === null ? null : (int) $row->variant_id,
            (int) $row->sales_server_id,
            (int) $row->panel_service_target_id,
            $row->service_mode_code,
            $row->service_mode_label_fa,
            $row->service_mode_label_en,
            $row->audience,
            $row->server_selection_mode,
            $row->protocol_selection_mode,
            $row->tag_match_mode,
            (int) $row->base_price_irr,
            (int) $row->duration_days,
            $row->data_allowance_bytes === null ? null : (int) $row->data_allowance_bytes,
            $row->device_limit === null ? null : (int) $row->device_limit,
            (int) $row->sort_order,
            (int) $row->min_purchase_quantity,
            (int) $row->max_purchase_quantity,
            (bool) $row->discount_eligible,
            (bool) $row->auto_renew_allowed,
            (bool) $row->custom_plan_allowed,
            (bool) $row->trial_allowed,
            $row->state,
            $row->visibility,
            (int) $row->version,
        );
    }

    private function assertOfferingVersion(int $currentVersion, int $expectedVersion): void
    {
        if ($currentVersion !== $expectedVersion) {
            throw new RuntimeException('Catalog version conflict.');
        }
    }

    private function storedOfferingState(string $state): CatalogState
    {
        return CatalogState::tryFrom($state) ?? throw new RuntimeException('Stored offering state is invalid.');
    }

    private function storedOfferingVisibility(string $visibility): ProductVisibility
    {
        return ProductVisibility::tryFrom($visibility)
            ?? throw new RuntimeException('Stored offering visibility is invalid.');
    }

    private function definitionHash(PlanOfferingDefinition $definition): string
    {
        return CatalogPayloadHash::make($definition->payload());
    }

    private function currentConfigurationHash(Connection $connection, int $offeringId, int $version): string
    {
        $hash = $connection->table('plan_offering_histories')
            ->where('plan_offering_id', $offeringId)
            ->where('version', $version)
            ->value('to_configuration_hash');

        if (! is_string($hash) || strlen($hash) !== 64) {
            throw new RuntimeException('Stored offering configuration hash is invalid.');
        }

        return $hash;
    }

    private function recordFromDefinition(
        int $offeringId,
        PlanOfferingDefinition $definition,
        CatalogState $state,
        ProductVisibility $visibility,
        int $version,
    ): PlanOfferingRecord {
        return new PlanOfferingRecord(
            $offeringId,
            $definition->code,
            $definition->productId,
            $definition->variantId,
            $definition->salesServerId,
            $definition->serviceTargetId,
            $definition->serviceMode->code,
            $definition->serviceMode->labelFa,
            $definition->serviceMode->labelEn,
            $definition->audience->value,
            $definition->serverSelectionMode->value,
            $definition->protocolSelectionMode->value,
            $definition->tagMatchMode->value,
            $definition->basePriceIrr,
            $definition->durationDays,
            $definition->dataAllowanceBytes,
            $definition->deviceLimit,
            $definition->sortOrder,
            $definition->minPurchaseQuantity,
            $definition->maxPurchaseQuantity,
            $definition->discountEligible,
            $definition->autoRenewAllowed,
            $definition->customPlanAllowed,
            $definition->trialAllowed,
            $state->value,
            $visibility->value,
            $version,
        );
    }

    private function offeringTimestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
