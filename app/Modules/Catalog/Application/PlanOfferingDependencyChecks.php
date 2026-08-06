<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\CatalogState;
use App\Modules\Catalog\Domain\OfferingProtocolAssignment;
use App\Modules\Catalog\Domain\PlanOfferingDefinition;
use App\Modules\Catalog\Domain\ProductVisibility;
use DomainException;
use Illuminate\Database\Connection;

trait PlanOfferingDependencyChecks
{
    private function lockDefinitionDependencies(
        Connection $connection,
        PlanOfferingDefinition $definition,
        bool $operational,
    ): void {
        /** @var object{id: int|string, state: string, visibility: string}|null $product */
        $product = $connection->table('products')
            ->where('id', $definition->productId)
            ->lockForUpdate()
            ->first(['id', 'state', 'visibility']);
        if ($product === null || $product->state === CatalogState::Archived->value) {
            throw new DomainException('Product state does not allow this offering operation.');
        }
        if ($operational && ($product->state !== CatalogState::Active->value || $product->visibility !== ProductVisibility::Visible->value)) {
            throw new DomainException('Offering activation requires an active visible product.');
        }

        if ($definition->variantId !== null) {
            /** @var object{product_id: int|string, state: string}|null $variant */
            $variant = $connection->table('product_variants')
                ->where('id', $definition->variantId)
                ->lockForUpdate()
                ->first(['product_id', 'state']);
            if ($variant === null
                || (int) $variant->product_id !== $definition->productId
                || $variant->state === CatalogState::Archived->value
                || ($operational && $variant->state !== CatalogState::Active->value)
            ) {
                throw new DomainException('Variant state does not allow this offering operation.');
            }
        }

        /** @var object{state: string, visibility: string}|null $server */
        $server = $connection->table('sales_servers')
            ->where('id', $definition->salesServerId)
            ->lockForUpdate()
            ->first(['state', 'visibility']);
        if ($server === null || $server->state === 'archived') {
            throw new DomainException('Sales server state does not allow this offering operation.');
        }
        if ($operational && ($server->state !== 'active' || $server->visibility !== 'listed')) {
            throw new DomainException('Offering activation requires an active listed sales server.');
        }

        /** @var object{panel_connection_id: int|string, state: string, capability_status: string, verified_connection_version: int|string|null}|null $target */
        $target = $connection->table('panel_service_targets')
            ->where('id', $definition->serviceTargetId)
            ->lockForUpdate()
            ->first(['panel_connection_id', 'state', 'capability_status', 'verified_connection_version']);
        if ($target === null || $target->state === 'archived') {
            throw new DomainException('Service target state does not allow this offering operation.');
        }

        /** @var object{state: string, version: int|string}|null $panelConnection */
        $panelConnection = $connection->table('panel_connections')
            ->where('id', (int) $target->panel_connection_id)
            ->lockForUpdate()
            ->first(['state', 'version']);
        if ($panelConnection === null || $panelConnection->state === 'archived') {
            throw new DomainException('Panel connection state does not allow this offering operation.');
        }
        if ($operational && (
            $target->state !== 'active'
            || $target->capability_status !== 'verified'
            || $panelConnection->state !== 'active'
            || (int) $target->verified_connection_version !== (int) $panelConnection->version
        )) {
            throw new DomainException('Offering activation requires verified operational panel dependencies.');
        }

        $this->assertTagsExist($connection, $definition->tagIds);
        $this->assertProtocolsCompatible($connection, $definition, $operational);
        $this->assertCapabilitiesCompatible($connection, $definition, $operational);
    }

    /** @param  list<int>  $tagIds */
    private function assertTagsExist(Connection $connection, array $tagIds): void
    {
        if ($tagIds === []) {
            return;
        }

        $rows = $connection->table('customer_tags')
            ->whereIn('id', $tagIds)
            ->where('is_active', true)
            ->lockForUpdate()
            ->get(['id']);
        if ($rows->count() !== count($tagIds)) {
            throw new DomainException('One or more offering eligibility tags are unavailable.');
        }
    }

    private function assertProtocolsCompatible(
        Connection $connection,
        PlanOfferingDefinition $definition,
        bool $operational,
    ): void {
        $profileIds = array_map(
            static fn (OfferingProtocolAssignment $profile): int => $profile->protocolProfileId,
            $definition->protocols,
        );

        /** @var list<object{id: int|string, state: string}> $profiles */
        $profiles = $connection->table('panel_protocol_profiles')
            ->whereIn('id', $profileIds)
            ->lockForUpdate()
            ->get(['id', 'state'])
            ->all();
        if (count($profiles) !== count($profileIds)) {
            throw new DomainException('One or more offering protocol profiles do not exist.');
        }
        foreach ($profiles as $profile) {
            if ($profile->state === 'archived' || ($operational && $profile->state !== 'active')) {
                throw new DomainException('Protocol profile state does not allow this offering operation.');
            }
        }

        $assignments = $connection->table('panel_target_protocol_profiles')
            ->where('panel_service_target_id', $definition->serviceTargetId)
            ->whereIn('panel_protocol_profile_id', $profileIds)
            ->lockForUpdate()
            ->get(['panel_protocol_profile_id']);
        if ($assignments->count() !== count($profileIds)) {
            throw new DomainException('Offering protocol profile is not assigned to the service target.');
        }
    }

    private function assertCapabilitiesCompatible(
        Connection $connection,
        PlanOfferingDefinition $definition,
        bool $operational,
    ): void {
        $capabilities = $definition->requiredCapabilities;
        foreach ($definition->operations as $operation) {
            if ($operation->requiredCapabilityCode !== null) {
                $capabilities[] = $operation->requiredCapabilityCode;
            }
        }
        $capabilities = array_values(array_unique($capabilities));
        if ($capabilities === []) {
            return;
        }

        $query = $connection->table('panel_target_capabilities')
            ->where('panel_service_target_id', $definition->serviceTargetId)
            ->whereIn('capability_code', $capabilities);
        if ($operational) {
            $query->where('verification_status', 'verified');
        }
        if ($query->lockForUpdate()->get(['capability_code'])->count() !== count($capabilities)) {
            throw new DomainException('Service target does not satisfy offering capability requirements.');
        }
    }

    private function assertOperationalOfferingDependencies(
        Connection $connection,
        PlanOfferingRecord $record,
    ): void {
        /** @var object{state: string, visibility: string}|null $product */
        $product = $connection->table('products')
            ->where('id', $record->productId)
            ->lockForUpdate()
            ->first(['state', 'visibility']);
        if ($product === null || $product->state !== 'active' || $product->visibility !== 'visible') {
            throw new DomainException('Offering activation requires an active visible product.');
        }

        if ($record->variantId !== null) {
            /** @var object{product_id: int|string, state: string}|null $variant */
            $variant = $connection->table('product_variants')
                ->where('id', $record->variantId)
                ->lockForUpdate()
                ->first(['product_id', 'state']);
            if ($variant === null || (int) $variant->product_id !== $record->productId || $variant->state !== 'active') {
                throw new DomainException('Offering activation requires an active matching variant.');
            }
        }

        /** @var object{state: string, visibility: string}|null $server */
        $server = $connection->table('sales_servers')
            ->where('id', $record->salesServerId)
            ->lockForUpdate()
            ->first(['state', 'visibility']);
        if ($server === null || $server->state !== 'active' || $server->visibility !== 'listed') {
            throw new DomainException('Offering activation requires an active listed sales server.');
        }

        /** @var object{panel_connection_id: int|string, state: string, capability_status: string, verified_connection_version: int|string|null}|null $target */
        $target = $connection->table('panel_service_targets')
            ->where('id', $record->serviceTargetId)
            ->lockForUpdate()
            ->first(['panel_connection_id', 'state', 'capability_status', 'verified_connection_version']);
        if ($target === null || $target->state !== 'active' || $target->capability_status !== 'verified') {
            throw new DomainException('Offering activation requires an active verified service target.');
        }

        /** @var object{state: string, version: int|string}|null $panelConnection */
        $panelConnection = $connection->table('panel_connections')
            ->where('id', (int) $target->panel_connection_id)
            ->lockForUpdate()
            ->first(['state', 'version']);
        if ($panelConnection === null
            || $panelConnection->state !== 'active'
            || (int) $target->verified_connection_version !== (int) $panelConnection->version
        ) {
            throw new DomainException('Offering activation requires a tested current panel connection.');
        }

        /** @var list<object{panel_protocol_profile_id: int|string}> $assignments */
        $assignments = $connection->table('plan_offering_protocol_profiles')
            ->where('plan_offering_id', $record->id)
            ->lockForUpdate()
            ->get(['panel_protocol_profile_id'])
            ->all();
        if ($assignments === []) {
            throw new DomainException('Offering activation requires a protocol profile.');
        }
        $profileIds = array_map(static fn (object $row): int => (int) $row->panel_protocol_profile_id, $assignments);
        if ($connection->table('panel_protocol_profiles')
            ->whereIn('id', $profileIds)
            ->where('state', 'active')
            ->lockForUpdate()
            ->get(['id'])
            ->count() !== count($profileIds)
        ) {
            throw new DomainException('Offering activation requires active protocol profiles.');
        }
        if ($connection->table('panel_target_protocol_profiles')
            ->where('panel_service_target_id', $record->serviceTargetId)
            ->whereIn('panel_protocol_profile_id', $profileIds)
            ->lockForUpdate()
            ->get(['panel_protocol_profile_id'])
            ->count() !== count($profileIds)
        ) {
            throw new DomainException('Offering protocol profile is incompatible with its service target.');
        }

        /** @var list<int|string> $requiredRows */
        $requiredRows = $connection->table('plan_offering_required_capabilities')
            ->where('plan_offering_id', $record->id)
            ->pluck('capability_code')
            ->all();
        /** @var list<int|string> $operationRows */
        $operationRows = $connection->table('plan_offering_operations')
            ->where('plan_offering_id', $record->id)
            ->whereNotNull('required_capability_code')
            ->pluck('required_capability_code')
            ->all();
        $capabilities = array_map(
            static fn (int|string $capability): string => (string) $capability,
            array_merge($requiredRows, $operationRows),
        );
        $capabilities = array_values(array_unique($capabilities));
        if ($capabilities !== []
            && $connection->table('panel_target_capabilities')
                ->where('panel_service_target_id', $record->serviceTargetId)
                ->whereIn('capability_code', $capabilities)
                ->where('verification_status', 'verified')
                ->lockForUpdate()
                ->get(['capability_code'])
                ->count() !== count($capabilities)
        ) {
            throw new DomainException('Offering activation requires verified target capabilities.');
        }
    }
}
