<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Panels\Application\Contracts\PanelAdapter;
use App\Modules\Panels\Application\PanelAdapterRegistry;
use App\Modules\Panels\Application\PanelAdapterSession;
use App\Modules\Panels\Domain\PanelConnectionState;
use App\Modules\Panels\Domain\PanelCredentials;
use App\Modules\Panels\Domain\PanelEndpoint;
use App\Modules\Panels\Domain\PanelNetworkPolicy;
use App\Modules\Panels\Domain\PanelProviderType;
use App\Modules\Panels\Domain\PanelResourceState;
use App\Modules\Panels\Domain\TlsConfiguration;
use App\Modules\Panels\Domain\TlsPolicy;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

final readonly class ProvisioningPanelAdapterResolver
{
    public function __construct(
        private DatabaseManager $database,
        private StringEncrypter $encrypter,
        private PanelAdapterRegistry $registry,
    ) {}

    /** @requirement PRV-001 PRV-002 PRV-003 SEC-001 SEC-002 */
    public function resolve(int $serviceTargetId): PanelAdapter
    {
        if ($serviceTargetId < 1) {
            throw new RuntimeException('Provisioning service target ID is invalid.');
        }

        /** @var object{target_state:string,panel_connection_id:int|string,provider_type:string,base_url:string,encrypted_credentials:string,tls_policy:string,custom_ca_disk:?string,custom_ca_path:?string,certificate_pin_sha256:?string,network_policy:string,connection_state:string}|null $row */
        $row = $this->database->connection()
            ->table('panel_service_targets as target')
            ->join('panel_connections as connection', 'connection.id', '=', 'target.panel_connection_id')
            ->where('target.id', $serviceTargetId)
            ->first([
                'target.state as target_state',
                'target.panel_connection_id',
                'connection.provider_type',
                'connection.base_url',
                'connection.encrypted_credentials',
                'connection.tls_policy',
                'connection.custom_ca_disk',
                'connection.custom_ca_path',
                'connection.certificate_pin_sha256',
                'connection.network_policy',
                'connection.state as connection_state',
            ]);

        if ($row === null
            || $row->target_state !== PanelResourceState::Active->value
            || $row->connection_state !== PanelConnectionState::Active->value
        ) {
            throw new RuntimeException('Provisioning panel target is not active.');
        }

        $provider = PanelProviderType::tryFrom($row->provider_type)
            ?? throw new RuntimeException('Stored panel provider type is invalid.');
        $tlsPolicy = TlsPolicy::tryFrom($row->tls_policy)
            ?? throw new RuntimeException('Stored panel TLS policy is invalid.');
        $networkPolicy = PanelNetworkPolicy::tryFrom($row->network_policy)
            ?? throw new RuntimeException('Stored panel network policy is invalid.');

        try {
            $decoded = json_decode(
                $this->encrypter->decryptString($row->encrypted_credentials),
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Stored panel credentials are invalid.', 0, $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('Stored panel credentials are invalid.');
        }

        $credentials = PanelCredentials::fromInput($decoded);
        $endpoint = PanelEndpoint::fromInput($row->base_url);
        $endpoint->assertAllowedBy($networkPolicy);
        $tls = new TlsConfiguration(
            $tlsPolicy,
            $row->custom_ca_disk,
            $row->custom_ca_path,
            $row->certificate_pin_sha256,
        );

        return $this->registry->make(
            $provider,
            new PanelAdapterSession(
                endpoint: $endpoint,
                credentials: $credentials,
                tls: $tls,
                networkPolicy: $networkPolicy,
            ),
        );
    }
}
