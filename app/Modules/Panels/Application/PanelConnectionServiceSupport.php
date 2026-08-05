<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Modules\Panels\Domain\PanelConnectionState;
use App\Modules\Panels\Domain\PanelNetworkPolicy;
use App\Modules\Panels\Domain\PanelProviderType;
use App\Modules\Panels\Domain\TlsConfiguration;
use App\Modules\Panels\Domain\TlsPolicy;
use Illuminate\Database\Connection;
use RuntimeException;

trait PanelConnectionServiceSupport
{
    private function lockedConnection(Connection $connection, int $connectionId): PanelConnectionRecord
    {
        /** @var object{id: int|string, code: string, provider_type: string, name_fa: string, name_en: ?string, base_url: string, tls_policy: string, custom_ca_disk: ?string, custom_ca_path: ?string, certificate_pin_sha256: ?string, network_policy: string, state: string, last_test_status: ?string, last_panel_version: ?string, last_capabilities_hash: ?string, last_tested_at: ?string, version: int|string}|null $row */
        $row = $connection->table('panel_connections')
            ->where('id', $connectionId)
            ->lockForUpdate()
            ->first([
                'id',
                'code',
                'provider_type',
                'name_fa',
                'name_en',
                'base_url',
                'tls_policy',
                'custom_ca_disk',
                'custom_ca_path',
                'certificate_pin_sha256',
                'network_policy',
                'state',
                'last_test_status',
                'last_panel_version',
                'last_capabilities_hash',
                'last_tested_at',
                'version',
            ]);

        if ($row === null) {
            throw new RuntimeException('Panel connection does not exist.');
        }

        return new PanelConnectionRecord(
            (int) $row->id,
            $row->code,
            $row->provider_type,
            $row->name_fa,
            $row->name_en,
            $row->base_url,
            $row->tls_policy,
            $row->custom_ca_disk,
            $row->custom_ca_path,
            $row->certificate_pin_sha256,
            $row->network_policy,
            $row->state,
            $row->last_test_status,
            $row->last_panel_version,
            $row->last_capabilities_hash,
            $row->last_tested_at,
            (int) $row->version,
        );
    }

    private function assertVersion(int $currentVersion, int $expectedVersion): void
    {
        if ($currentVersion !== $expectedVersion) {
            throw new RuntimeException('Panel connection version conflict.');
        }
    }

    private function storedState(string $state): PanelConnectionState
    {
        return PanelConnectionState::tryFrom($state)
            ?? throw new RuntimeException('Stored panel connection state is invalid.');
    }

    private function storedProvider(string $providerType): PanelProviderType
    {
        return PanelProviderType::tryFrom($providerType)
            ?? throw new RuntimeException('Stored panel provider type is invalid.');
    }

    private function storedTls(PanelConnectionRecord $record): TlsConfiguration
    {
        $policy = TlsPolicy::tryFrom($record->tlsPolicy)
            ?? throw new RuntimeException('Stored panel TLS policy is invalid.');

        return new TlsConfiguration(
            $policy,
            $record->customCaDisk,
            $record->customCaPath,
            $record->certificatePinSha256,
        );
    }

    private function storedNetworkPolicy(string $networkPolicy): PanelNetworkPolicy
    {
        return PanelNetworkPolicy::tryFrom($networkPolicy)
            ?? throw new RuntimeException('Stored panel network policy is invalid.');
    }

    /** @return array<string, bool|int|string|null> */
    private function safeRecordState(PanelConnectionRecord $record): array
    {
        return $this->safeState(
            $record->id,
            $record->code,
            $this->storedProvider($record->providerType),
            $this->storedTls($record),
            $this->storedNetworkPolicy($record->networkPolicy),
            $this->storedState($record->state),
            $record->lastTestedAt !== null,
            $record->version,
        );
    }

    /** @return array<string, bool|int|string|null> */
    private function safeState(
        int $connectionId,
        string $code,
        PanelProviderType $providerType,
        TlsConfiguration $tls,
        PanelNetworkPolicy $networkPolicy,
        PanelConnectionState $state,
        bool $tested,
        int $version,
    ): array {
        return [
            'connection_id' => $connectionId,
            'code' => $code,
            'provider_type' => $providerType->value,
            'tls_policy' => $tls->policy->value,
            'custom_ca_configured' => $tls->customCaPath !== null,
            'certificate_pin_configured' => $tls->certificatePinSha256 !== null,
            'network_policy' => $networkPolicy->value,
            'state' => $state->value,
            'configured' => true,
            'tested' => $tested,
            'version' => $version,
        ];
    }

    /**
     * @param  array<string, bool|int|string|null>|null  $before
     * @param  array<string, bool|int|string|null>  $after
     */
    private function history(
        Connection $connection,
        int $connectionId,
        int $version,
        string $action,
        ?array $before,
        array $after,
        PanelChangeContext $context,
    ): void {
        $connection->table('panel_connection_histories')->insert([
            'panel_connection_id' => $connectionId,
            'version' => $version,
            'action' => $action,
            'before_safe_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_safe_data' => json_encode($after, JSON_THROW_ON_ERROR),
            'actor_administrator_id' => $context->actorAdministratorId,
            'reason_code' => $context->reasonCode,
            'reason' => $context->requireReason(),
            'correlation_id' => $context->correlationId,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
