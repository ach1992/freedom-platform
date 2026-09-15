<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Modules\Panels\Domain\PanelCapabilityCode;
use App\Modules\Panels\Domain\PanelResourceState;
use App\Modules\Panels\Domain\SalesServerVisibility;
use Illuminate\Database\Connection;
use RuntimeException;

trait PanelInventoryServiceSupport
{
    private function lockedProtocolProfile(Connection $connection, int $profileId): ProtocolProfileRecord
    {
        /** @var object{id: int|string, code: string, name_fa: string, name_en: ?string, protocol_family: string, transport: ?string, security_layer: ?string, host: ?string, sni: ?string, path: ?string, port: int|string|null, flow: ?string, state: string, version: int|string}|null $row */
        $row = $connection->table('panel_protocol_profiles')
            ->where('id', $profileId)
            ->lockForUpdate()
            ->first([
                'id', 'code', 'name_fa', 'name_en', 'protocol_family', 'transport',
                'security_layer', 'host', 'sni', 'path', 'port', 'flow', 'state', 'version',
            ]);

        if ($row === null) {
            throw new RuntimeException('Protocol profile does not exist.');
        }

        return new ProtocolProfileRecord(
            (int) $row->id,
            $row->code,
            $row->name_fa,
            $row->name_en,
            $row->protocol_family,
            $row->transport,
            $row->security_layer,
            $row->host,
            $row->sni,
            $row->path,
            $row->port === null ? null : (int) $row->port,
            $row->flow,
            $row->state,
            (int) $row->version,
        );
    }

    private function lockedServiceTarget(Connection $connection, int $targetId): ServiceTargetRecord
    {
        /** @var object{id: int|string, panel_connection_id: int|string, code: string, kind: string, name_fa: string, name_en: ?string, configuration_hash: string, configuration_key_version: int|string, state: string, capability_status: string, version: int|string}|null $row */
        $row = $connection->table('panel_service_targets')
            ->where('id', $targetId)
            ->lockForUpdate()
            ->first([
                'id', 'panel_connection_id', 'code', 'kind', 'name_fa', 'name_en',
                'configuration_hash', 'configuration_key_version', 'state', 'capability_status', 'version',
            ]);

        if ($row === null) {
            throw new RuntimeException('Panel service target does not exist.');
        }

        return new ServiceTargetRecord(
            (int) $row->id,
            (int) $row->panel_connection_id,
            $row->code,
            $row->kind,
            $row->name_fa,
            $row->name_en,
            $row->configuration_hash,
            (int) $row->configuration_key_version,
            $row->state,
            $row->capability_status,
            (int) $row->version,
        );
    }

    private function lockedSalesServer(Connection $connection, int $serverId): SalesServerRecord
    {
        /** @var object{id: int|string, code: string, name_fa: string, name_en: ?string, description_fa: ?string, description_en: ?string, state: string, visibility: string, sort_order: int|string, version: int|string}|null $row */
        $row = $connection->table('sales_servers')
            ->where('id', $serverId)
            ->lockForUpdate()
            ->first([
                'id', 'code', 'name_fa', 'name_en', 'description_fa', 'description_en',
                'state', 'visibility', 'sort_order', 'version',
            ]);

        if ($row === null) {
            throw new RuntimeException('Sales server does not exist.');
        }

        return new SalesServerRecord(
            (int) $row->id,
            $row->code,
            $row->name_fa,
            $row->name_en,
            $row->description_fa,
            $row->description_en,
            $row->state,
            $row->visibility,
            (int) $row->sort_order,
            (int) $row->version,
        );
    }

    private function assertInventoryVersion(int $currentVersion, int $expectedVersion, string $resource): void
    {
        if ($currentVersion !== $expectedVersion) {
            throw new RuntimeException("{$resource} version conflict.");
        }
    }

    private function storedResourceState(string $state): PanelResourceState
    {
        return PanelResourceState::tryFrom($state)
            ?? throw new RuntimeException('Stored panel resource state is invalid.');
    }

    private function storedServerVisibility(string $visibility): SalesServerVisibility
    {
        return SalesServerVisibility::tryFrom($visibility)
            ?? throw new RuntimeException('Stored sales server visibility is invalid.');
    }

    /**
     * @param  list<string>  $capabilities
     * @return list<string>
     */
    private function normalizeCapabilities(array $capabilities): array
    {
        $normalized = [];
        foreach ($capabilities as $capability) {
            $normalized[] = PanelCapabilityCode::fromInput($capability)->value;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);
        if ($normalized === []) {
            throw new RuntimeException('At least one target capability is required.');
        }

        return $normalized;
    }

    /**
     * @param  list<int>  $profileIds
     * @return list<int>
     */
    private function normalizeProfileIds(array $profileIds): array
    {
        $normalized = [];
        foreach ($profileIds as $profileId) {
            $normalized[] = PanelInput::positiveId($profileId, 'Protocol profile ID');
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_NUMERIC);
        if ($normalized === []) {
            throw new RuntimeException('At least one protocol profile is required.');
        }

        return $normalized;
    }

    /**
     * @param  list<int>  $profileIds
     * @param  list<int>  $customerSelectableProfileIds
     * @return array<int, bool>
     */
    private function profileSelectionMap(array $profileIds, array $customerSelectableProfileIds): array
    {
        $profiles = $this->normalizeProfileIds($profileIds);
        $selectable = $customerSelectableProfileIds === []
            ? []
            : $this->normalizeProfileIds($customerSelectableProfileIds);

        foreach ($selectable as $profileId) {
            if (! in_array($profileId, $profiles, true)) {
                throw new RuntimeException('Customer-selectable protocol profile is not assigned to the target.');
            }
        }

        $map = [];
        foreach ($profiles as $profileId) {
            $map[$profileId] = in_array($profileId, $selectable, true);
        }

        return $map;
    }

    /** @param  array<int, bool>  $profiles */
    private function assertProfilesExist(Connection $connection, array $profiles): void
    {
        /** @var list<object{id: int|string, state: string}> $rows */
        $rows = $connection->table('panel_protocol_profiles')
            ->whereIn('id', array_keys($profiles))
            ->lockForUpdate()
            ->get(['id', 'state'])
            ->all();

        if (count($rows) !== count($profiles)) {
            throw new RuntimeException('One or more protocol profiles do not exist.');
        }

        foreach ($rows as $row) {
            $profileId = (int) $row->id;
            if ($row->state === PanelResourceState::Archived->value) {
                throw new RuntimeException('Archived protocol profiles cannot be assigned to a target.');
            }
            if (($profiles[$profileId] ?? false) && $row->state !== PanelResourceState::Active->value) {
                throw new RuntimeException('Customer-selectable protocol profiles must be active.');
            }
        }
    }

    /** @return array<string, bool|int|string|null> */
    private function safeProtocolProfileState(ProtocolProfileRecord $record): array
    {
        return [
            'profile_id' => $record->id,
            'code' => $record->code,
            'protocol_family' => $record->protocolFamily,
            'transport_configured' => $record->transport !== null,
            'security_configured' => $record->securityLayer !== null,
            'host_configured' => $record->host !== null,
            'sni_configured' => $record->sni !== null,
            'path_configured' => $record->path !== null,
            'port_configured' => $record->port !== null,
            'flow_configured' => $record->flow !== null,
            'state' => $record->state,
            'version' => $record->version,
        ];
    }

    /** @return array<string, bool|int|string|null> */
    private function safeServiceTargetState(Connection $connection, ServiceTargetRecord $record): array
    {
        $capabilityCount = $connection->table('panel_target_capabilities')
            ->where('panel_service_target_id', $record->id)
            ->count();
        $profileCount = $connection->table('panel_target_protocol_profiles')
            ->where('panel_service_target_id', $record->id)
            ->count();
        $selectableCount = $connection->table('panel_target_protocol_profiles')
            ->where('panel_service_target_id', $record->id)
            ->where('customer_selectable', true)
            ->count();

        return [
            'target_id' => $record->id,
            'panel_connection_id' => $record->panelConnectionId,
            'code' => $record->code,
            'kind' => $record->kind,
            'configuration_present' => true,
            'configuration_key_version' => $record->configurationKeyVersion,
            'capability_status' => $record->capabilityStatus,
            'capability_count' => $capabilityCount,
            'protocol_profile_count' => $profileCount,
            'customer_selectable_profile_count' => $selectableCount,
            'state' => $record->state,
            'version' => $record->version,
        ];
    }

    /** @return array<string, bool|int|string|null> */
    private function safeSalesServerState(SalesServerRecord $record): array
    {
        return [
            'server_id' => $record->id,
            'code' => $record->code,
            'state' => $record->state,
            'visibility' => $record->visibility,
            'sort_order' => $record->sortOrder,
            'version' => $record->version,
        ];
    }

    /**
     * @param  array<string, bool|int|string|null>|null  $before
     * @param  array<string, bool|int|string|null>  $after
     */
    private function inventoryHistory(
        Connection $connection,
        string $table,
        string $foreignKey,
        int $resourceId,
        int $version,
        string $action,
        ?array $before,
        array $after,
        PanelChangeContext $context,
    ): void {
        $row = [
            'version' => $version,
            'action' => $action,
            'before_safe_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_safe_data' => json_encode($after, JSON_THROW_ON_ERROR),
            'actor_administrator_id' => $context->actorAdministratorId,
            'reason_code' => $context->reasonCode,
            'reason' => $context->requireReason(),
            'correlation_id' => $context->correlationId,
            'created_at' => $this->inventoryTimestamp(),
        ];

        match ($table.'|'.$foreignKey) {
            'panel_protocol_profile_histories|panel_protocol_profile_id' => $connection->table('panel_protocol_profile_histories')->insert([
                'panel_protocol_profile_id' => $resourceId,
                ...$row,
            ]),
            'panel_service_target_histories|panel_service_target_id' => $connection->table('panel_service_target_histories')->insert([
                'panel_service_target_id' => $resourceId,
                ...$row,
            ]),
            'sales_server_histories|sales_server_id' => $connection->table('sales_server_histories')->insert([
                'sales_server_id' => $resourceId,
                ...$row,
            ]),
            default => throw new RuntimeException('Unsupported panel inventory history target.'),
        };
    }

    private function inventoryTimestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
