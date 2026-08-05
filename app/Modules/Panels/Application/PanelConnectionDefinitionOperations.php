<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Modules\Panels\Domain\PanelCode;
use App\Modules\Panels\Domain\PanelConnectionState;
use App\Modules\Panels\Domain\PanelCredentials;
use App\Modules\Panels\Domain\PanelEndpoint;
use App\Modules\Panels\Domain\PanelNetworkPolicy;
use App\Modules\Panels\Domain\PanelProviderType;
use App\Modules\Panels\Domain\TlsConfiguration;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

trait PanelConnectionDefinitionOperations
{
    /**
     * @param  array<string, mixed>  $credentials
     *
     * @requirement PRV-001 ACL-002 ACL-003 SEC-001 SEC-002 DAT-003 QUA-001
     */
    public function create(
        string $approvalId,
        string $code,
        PanelProviderType $providerType,
        string $nameFa,
        ?string $nameEn,
        string $baseUrl,
        array $credentials,
        int $credentialKeyVersion,
        TlsConfiguration $tls,
        PanelNetworkPolicy $networkPolicy,
        PanelChangeContext $context,
    ): PanelMutationReceipt {
        $normalizedApprovalId = PanelInput::approvalId($approvalId);
        $normalizedCode = PanelCode::fromInput($code)->value;
        $normalizedNameFa = PanelText::required($nameFa);
        $normalizedNameEn = PanelText::optional($nameEn);
        $endpoint = PanelEndpoint::fromInput($baseUrl);
        $endpoint->assertAllowedBy($networkPolicy);
        $credentialSet = PanelCredentials::fromInput($credentials);
        $normalizedKeyVersion = PanelInput::credentialKeyVersion($credentialKeyVersion);

        $payloadHmac = $this->hasher->mutation([
            'approval_id' => $normalizedApprovalId,
            'code' => $normalizedCode,
            'provider_type' => $providerType->value,
            'name_fa' => $normalizedNameFa,
            'name_en' => $normalizedNameEn,
            'base_url' => $endpoint->value,
            'credentials' => $credentialSet->values,
            'credential_key_version' => $normalizedKeyVersion,
            'tls_policy' => $tls->policy->value,
            'custom_ca_disk' => $tls->customCaDisk,
            'custom_ca_path' => $tls->customCaPath,
            'certificate_pin_sha256' => $tls->certificatePinSha256,
            'network_policy' => $networkPolicy->value,
        ]);
        $action = 'panels.connection.create';

        return $this->executor->execute(
            $action,
            self::TARGET_TYPE,
            null,
            $payloadHmac,
            ['panels.manage', 'panels.manage_secrets'],
            $context,
            function (Connection $connection) use (
                $normalizedApprovalId,
                $action,
                $normalizedCode,
                $providerType,
                $normalizedNameFa,
                $normalizedNameEn,
                $endpoint,
                $credentialSet,
                $normalizedKeyVersion,
                $tls,
                $networkPolicy,
                $payloadHmac,
                $context,
            ): PanelMutationReceipt {
                $this->approvals->consume(
                    $normalizedApprovalId,
                    $action,
                    'panel_connection_code',
                    $normalizedCode,
                    $context,
                );

                if ($connection->table('panel_connections')->where('code', $normalizedCode)->lockForUpdate()->exists()) {
                    throw new RuntimeException('Panel connection code already exists.');
                }

                $now = $this->timestamp();
                $connectionId = (int) $connection->table('panel_connections')->insertGetId([
                    'code' => $normalizedCode,
                    'provider_type' => $providerType->value,
                    'name_fa' => $normalizedNameFa,
                    'name_en' => $normalizedNameEn,
                    'base_url' => $endpoint->value,
                    'encrypted_credentials' => $this->encrypter->encryptString($credentialSet->canonicalJson),
                    'credential_key_version' => $normalizedKeyVersion,
                    'tls_policy' => $tls->policy->value,
                    'custom_ca_disk' => $tls->customCaDisk,
                    'custom_ca_path' => $tls->customCaPath,
                    'certificate_pin_sha256' => $tls->certificatePinSha256,
                    'network_policy' => $networkPolicy->value,
                    'state' => PanelConnectionState::Disabled->value,
                    'last_test_status' => null,
                    'last_panel_version' => null,
                    'last_capabilities_hash' => null,
                    'last_tested_at' => null,
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $after = $this->safeState(
                    $connectionId,
                    $normalizedCode,
                    $providerType,
                    $tls,
                    $networkPolicy,
                    PanelConnectionState::Disabled,
                    false,
                    1,
                );
                $this->history($connection, $connectionId, 1, $action, null, $after, $context);

                return $this->audit->record(
                    $connection,
                    $action,
                    self::TARGET_TYPE,
                    (string) $connectionId,
                    $payloadHmac,
                    $context,
                    [],
                    $after,
                    true,
                );
            },
        );
    }

    /** @requirement PRV-001 ACL-002 ACL-003 SEC-001 SEC-002 DAT-003 QUA-001 */
    public function reconfigure(
        string $approvalId,
        int $connectionId,
        int $expectedVersion,
        string $nameFa,
        ?string $nameEn,
        string $baseUrl,
        TlsConfiguration $tls,
        PanelNetworkPolicy $networkPolicy,
        PanelChangeContext $context,
    ): PanelMutationReceipt {
        $normalizedApprovalId = PanelInput::approvalId($approvalId);
        PanelInput::positiveId($connectionId, 'Panel connection ID');
        $normalizedExpectedVersion = PanelInput::expectedVersion($expectedVersion);
        $normalizedNameFa = PanelText::required($nameFa);
        $normalizedNameEn = PanelText::optional($nameEn);
        $endpoint = PanelEndpoint::fromInput($baseUrl);
        $endpoint->assertAllowedBy($networkPolicy);

        $payloadHmac = $this->hasher->mutation([
            'approval_id' => $normalizedApprovalId,
            'connection_id' => $connectionId,
            'expected_version' => $normalizedExpectedVersion,
            'name_fa' => $normalizedNameFa,
            'name_en' => $normalizedNameEn,
            'base_url' => $endpoint->value,
            'tls_policy' => $tls->policy->value,
            'custom_ca_disk' => $tls->customCaDisk,
            'custom_ca_path' => $tls->customCaPath,
            'certificate_pin_sha256' => $tls->certificatePinSha256,
            'network_policy' => $networkPolicy->value,
        ]);
        $action = 'panels.connection.reconfigure';

        return $this->executor->execute(
            $action,
            self::TARGET_TYPE,
            (string) $connectionId,
            $payloadHmac,
            ['panels.manage', 'panels.manage_secrets'],
            $context,
            function (Connection $connection) use (
                $normalizedApprovalId,
                $action,
                $connectionId,
                $normalizedExpectedVersion,
                $normalizedNameFa,
                $normalizedNameEn,
                $endpoint,
                $tls,
                $networkPolicy,
                $payloadHmac,
                $context,
            ): PanelMutationReceipt {
                $record = $this->lockedConnection($connection, $connectionId);
                $this->assertVersion($record->version, $normalizedExpectedVersion);
                if ($this->storedState($record->state) === PanelConnectionState::Archived) {
                    throw new DomainException('Archived panel connections are immutable.');
                }

                $this->approvals->consume(
                    $normalizedApprovalId,
                    $action,
                    self::TARGET_TYPE,
                    (string) $connectionId,
                    $context,
                );

                $before = $this->safeRecordState($record);
                $nextVersion = $record->version + 1;
                $connection->table('panel_connections')->where('id', $connectionId)->update([
                    'name_fa' => $normalizedNameFa,
                    'name_en' => $normalizedNameEn,
                    'base_url' => $endpoint->value,
                    'tls_policy' => $tls->policy->value,
                    'custom_ca_disk' => $tls->customCaDisk,
                    'custom_ca_path' => $tls->customCaPath,
                    'certificate_pin_sha256' => $tls->certificatePinSha256,
                    'network_policy' => $networkPolicy->value,
                    'state' => PanelConnectionState::Disabled->value,
                    'last_test_status' => null,
                    'last_panel_version' => null,
                    'last_capabilities_hash' => null,
                    'last_tested_at' => null,
                    'version' => $nextVersion,
                    'updated_at' => $this->timestamp(),
                ]);

                $after = $this->safeState(
                    $connectionId,
                    $record->code,
                    $this->storedProvider($record->providerType),
                    $tls,
                    $networkPolicy,
                    PanelConnectionState::Disabled,
                    false,
                    $nextVersion,
                );
                $this->history($connection, $connectionId, $nextVersion, $action, $before, $after, $context);

                return $this->audit->record(
                    $connection,
                    $action,
                    self::TARGET_TYPE,
                    (string) $connectionId,
                    $payloadHmac,
                    $context,
                    $before,
                    $after,
                    true,
                );
            },
        );
    }

    /**
     * @param  array<string, mixed>  $credentials
     *
     * @requirement PRV-001 ACL-002 ACL-003 SEC-001 SEC-002 DAT-003 QUA-001
     */
    public function rotateCredentials(
        string $approvalId,
        int $connectionId,
        int $expectedVersion,
        array $credentials,
        int $credentialKeyVersion,
        PanelChangeContext $context,
    ): PanelMutationReceipt {
        $normalizedApprovalId = PanelInput::approvalId($approvalId);
        PanelInput::positiveId($connectionId, 'Panel connection ID');
        $normalizedExpectedVersion = PanelInput::expectedVersion($expectedVersion);
        $credentialSet = PanelCredentials::fromInput($credentials);
        $normalizedKeyVersion = PanelInput::credentialKeyVersion($credentialKeyVersion);

        $payloadHmac = $this->hasher->mutation([
            'approval_id' => $normalizedApprovalId,
            'connection_id' => $connectionId,
            'expected_version' => $normalizedExpectedVersion,
            'credentials' => $credentialSet->values,
            'credential_key_version' => $normalizedKeyVersion,
        ]);
        $action = 'panels.connection.rotate_credentials';

        return $this->executor->execute(
            $action,
            self::TARGET_TYPE,
            (string) $connectionId,
            $payloadHmac,
            ['panels.manage', 'panels.manage_secrets'],
            $context,
            function (Connection $connection) use (
                $normalizedApprovalId,
                $action,
                $connectionId,
                $normalizedExpectedVersion,
                $credentialSet,
                $normalizedKeyVersion,
                $payloadHmac,
                $context,
            ): PanelMutationReceipt {
                $record = $this->lockedConnection($connection, $connectionId);
                $this->assertVersion($record->version, $normalizedExpectedVersion);
                if ($this->storedState($record->state) === PanelConnectionState::Archived) {
                    throw new DomainException('Archived panel connections are immutable.');
                }

                $this->approvals->consume(
                    $normalizedApprovalId,
                    $action,
                    self::TARGET_TYPE,
                    (string) $connectionId,
                    $context,
                );

                $before = $this->safeRecordState($record);
                $nextVersion = $record->version + 1;
                $connection->table('panel_connections')->where('id', $connectionId)->update([
                    'encrypted_credentials' => $this->encrypter->encryptString($credentialSet->canonicalJson),
                    'credential_key_version' => $normalizedKeyVersion,
                    'state' => PanelConnectionState::Disabled->value,
                    'last_test_status' => null,
                    'last_panel_version' => null,
                    'last_capabilities_hash' => null,
                    'last_tested_at' => null,
                    'version' => $nextVersion,
                    'updated_at' => $this->timestamp(),
                ]);

                $after = [
                    ...$before,
                    'state' => PanelConnectionState::Disabled->value,
                    'tested' => false,
                    'version' => $nextVersion,
                ];
                $this->history($connection, $connectionId, $nextVersion, $action, $before, $after, $context);

                return $this->audit->record(
                    $connection,
                    $action,
                    self::TARGET_TYPE,
                    (string) $connectionId,
                    $payloadHmac,
                    $context,
                    $before,
                    $after,
                    true,
                );
            },
        );
    }
}
