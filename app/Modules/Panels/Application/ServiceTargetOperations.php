<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Modules\Panels\Domain\PanelResourceCode;
use App\Modules\Panels\Domain\PanelResourceState;
use App\Modules\Panels\Domain\PanelTargetKind;
use App\Modules\Panels\Domain\ServiceTargetConfiguration;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

trait ServiceTargetOperations
{
    /**
     * @param  list<string>  $capabilityCodes
     * @param  list<int>  $protocolProfileIds
     * @param  list<int>  $customerSelectableProfileIds
     *
     * @requirement CAT-002 CAT-004 PRV-001 ACL-002 ACL-003 SEC-001 SEC-002 DAT-003 QUA-001
     */
    public function createServiceTarget(
        string $approvalId,
        int $panelConnectionId,
        string $code,
        PanelTargetKind $kind,
        string $nameFa,
        ?string $nameEn,
        ServiceTargetConfiguration $configuration,
        int $configurationKeyVersion,
        array $capabilityCodes,
        array $protocolProfileIds,
        array $customerSelectableProfileIds,
        PanelChangeContext $context,
    ): PanelMutationReceipt {
        $normalizedApprovalId = PanelInput::approvalId($approvalId);
        $normalizedConnectionId = PanelInput::positiveId($panelConnectionId, 'Panel connection ID');
        $normalizedCode = PanelResourceCode::fromInput($code)->value;
        $normalizedNameFa = PanelText::required($nameFa);
        $normalizedNameEn = PanelText::optional($nameEn);
        $normalizedKeyVersion = PanelInput::credentialKeyVersion($configurationKeyVersion);
        $capabilities = $this->normalizeCapabilities($capabilityCodes);
        $profiles = $this->profileSelectionMap($protocolProfileIds, $customerSelectableProfileIds);
        $payloadHmac = $this->hasher->mutation([
            'approval_id' => $normalizedApprovalId,
            'panel_connection_id' => $normalizedConnectionId,
            'code' => $normalizedCode,
            'kind' => $kind->value,
            'name_fa' => $normalizedNameFa,
            'name_en' => $normalizedNameEn,
            'configuration' => $configuration->toArray(),
            'configuration_key_version' => $normalizedKeyVersion,
            'capabilities' => $capabilities,
            'protocol_profiles' => $profiles,
        ]);
        $action = 'panels.service_target.create';

        return $this->executor->execute(
            $action,
            self::SERVICE_TARGET,
            null,
            $payloadHmac,
            ['panels.manage', 'panels.manage_secrets'],
            $context,
            function (Connection $connection) use (
                $normalizedApprovalId,
                $action,
                $normalizedConnectionId,
                $normalizedCode,
                $kind,
                $normalizedNameFa,
                $normalizedNameEn,
                $configuration,
                $normalizedKeyVersion,
                $capabilities,
                $profiles,
                $payloadHmac,
                $context,
            ): PanelMutationReceipt {
                $this->approvals->consume(
                    $normalizedApprovalId,
                    $action,
                    'panel_service_target_code',
                    $normalizedCode,
                    $context,
                );

                /** @var object{state: string}|null $panelConnection */
                $panelConnection = $connection->table('panel_connections')
                    ->where('id', $normalizedConnectionId)
                    ->lockForUpdate()
                    ->first(['state']);
                if ($panelConnection === null) {
                    throw new RuntimeException('Panel connection does not exist.');
                }
                if ($panelConnection->state === PanelResourceState::Archived->value) {
                    throw new DomainException('Archived panel connections cannot receive service targets.');
                }

                if ($connection->table('panel_service_targets')->where('code', $normalizedCode)->lockForUpdate()->exists()) {
                    throw new RuntimeException('Panel service target code already exists.');
                }
                $this->assertProfilesExist($connection, $profiles);

                $now = $this->inventoryTimestamp();
                $configurationHash = $this->hasher->mutation([
                    'service_target_configuration' => $configuration->toArray(),
                ]);
                $targetId = (int) $connection->table('panel_service_targets')->insertGetId([
                    'panel_connection_id' => $normalizedConnectionId,
                    'code' => $normalizedCode,
                    'kind' => $kind->value,
                    'name_fa' => $normalizedNameFa,
                    'name_en' => $normalizedNameEn,
                    'encrypted_configuration' => $this->encrypter->encryptString($configuration->canonicalJson),
                    'configuration_hash' => $configurationHash,
                    'configuration_key_version' => $normalizedKeyVersion,
                    'state' => PanelResourceState::Disabled->value,
                    'capability_status' => 'declared',
                    'capability_evidence_hash' => null,
                    'capability_verified_at' => null,
                    'verified_connection_version' => null,
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($capabilities as $capability) {
                    $connection->table('panel_target_capabilities')->insert([
                        'panel_service_target_id' => $targetId,
                        'capability_code' => $capability,
                        'verification_status' => 'declared',
                        'evidence_hash' => null,
                        'verified_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
                foreach ($profiles as $profileId => $customerSelectable) {
                    $connection->table('panel_target_protocol_profiles')->insert([
                        'panel_service_target_id' => $targetId,
                        'panel_protocol_profile_id' => $profileId,
                        'customer_selectable' => $customerSelectable,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $record = new ServiceTargetRecord(
                    $targetId,
                    $normalizedConnectionId,
                    $normalizedCode,
                    $kind->value,
                    $normalizedNameFa,
                    $normalizedNameEn,
                    $configurationHash,
                    $normalizedKeyVersion,
                    PanelResourceState::Disabled->value,
                    'declared',
                    1,
                );
                $after = $this->safeServiceTargetState($connection, $record);
                $this->inventoryHistory(
                    $connection,
                    'panel_service_target_histories',
                    'panel_service_target_id',
                    $targetId,
                    1,
                    $action,
                    null,
                    $after,
                    $context,
                );

                return $this->audit->record(
                    $connection,
                    $action,
                    self::SERVICE_TARGET,
                    (string) $targetId,
                    $payloadHmac,
                    $context,
                    [],
                    $after,
                    true,
                );
            },
        );
    }

    /**
     * @param  list<string>  $capabilityCodes
     * @param  list<int>  $protocolProfileIds
     * @param  list<int>  $customerSelectableProfileIds
     *
     * @requirement CAT-002 CAT-004 PRV-001 ACL-002 ACL-003 SEC-001 SEC-002 DAT-003 QUA-001
     */
    public function updateServiceTarget(
        string $approvalId,
        int $targetId,
        int $expectedVersion,
        PanelTargetKind $kind,
        string $nameFa,
        ?string $nameEn,
        ServiceTargetConfiguration $configuration,
        int $configurationKeyVersion,
        array $capabilityCodes,
        array $protocolProfileIds,
        array $customerSelectableProfileIds,
        PanelChangeContext $context,
    ): PanelMutationReceipt {
        $normalizedApprovalId = PanelInput::approvalId($approvalId);
        PanelInput::positiveId($targetId, 'Panel service target ID');
        $normalizedExpectedVersion = PanelInput::expectedVersion($expectedVersion);
        $normalizedNameFa = PanelText::required($nameFa);
        $normalizedNameEn = PanelText::optional($nameEn);
        $normalizedKeyVersion = PanelInput::credentialKeyVersion($configurationKeyVersion);
        $capabilities = $this->normalizeCapabilities($capabilityCodes);
        $profiles = $this->profileSelectionMap($protocolProfileIds, $customerSelectableProfileIds);
        $payloadHmac = $this->hasher->mutation([
            'approval_id' => $normalizedApprovalId,
            'target_id' => $targetId,
            'expected_version' => $normalizedExpectedVersion,
            'kind' => $kind->value,
            'name_fa' => $normalizedNameFa,
            'name_en' => $normalizedNameEn,
            'configuration' => $configuration->toArray(),
            'configuration_key_version' => $normalizedKeyVersion,
            'capabilities' => $capabilities,
            'protocol_profiles' => $profiles,
        ]);
        $action = 'panels.service_target.update';

        return $this->executor->execute(
            $action,
            self::SERVICE_TARGET,
            (string) $targetId,
            $payloadHmac,
            ['panels.manage', 'panels.manage_secrets'],
            $context,
            function (Connection $connection) use (
                $normalizedApprovalId,
                $action,
                $targetId,
                $normalizedExpectedVersion,
                $kind,
                $normalizedNameFa,
                $normalizedNameEn,
                $configuration,
                $normalizedKeyVersion,
                $capabilities,
                $profiles,
                $payloadHmac,
                $context,
            ): PanelMutationReceipt {
                $record = $this->lockedServiceTarget($connection, $targetId);
                $this->assertInventoryVersion($record->version, $normalizedExpectedVersion, 'Panel service target');
                if ($this->storedResourceState($record->state) !== PanelResourceState::Disabled) {
                    throw new DomainException('Only a disabled service target may be edited.');
                }
                $this->assertProfilesExist($connection, $profiles);

                $this->approvals->consume(
                    $normalizedApprovalId,
                    $action,
                    self::SERVICE_TARGET,
                    (string) $targetId,
                    $context,
                );

                $before = $this->safeServiceTargetState($connection, $record);
                $nextVersion = $record->version + 1;
                $configurationHash = $this->hasher->mutation([
                    'service_target_configuration' => $configuration->toArray(),
                ]);
                $now = $this->inventoryTimestamp();
                $connection->table('panel_service_targets')->where('id', $targetId)->update([
                    'kind' => $kind->value,
                    'name_fa' => $normalizedNameFa,
                    'name_en' => $normalizedNameEn,
                    'encrypted_configuration' => $this->encrypter->encryptString($configuration->canonicalJson),
                    'configuration_hash' => $configurationHash,
                    'configuration_key_version' => $normalizedKeyVersion,
                    'capability_status' => 'declared',
                    'capability_evidence_hash' => null,
                    'capability_verified_at' => null,
                    'verified_connection_version' => null,
                    'version' => $nextVersion,
                    'updated_at' => $now,
                ]);
                $connection->table('panel_target_capabilities')
                    ->where('panel_service_target_id', $targetId)
                    ->delete();
                $connection->table('panel_target_protocol_profiles')
                    ->where('panel_service_target_id', $targetId)
                    ->delete();

                foreach ($capabilities as $capability) {
                    $connection->table('panel_target_capabilities')->insert([
                        'panel_service_target_id' => $targetId,
                        'capability_code' => $capability,
                        'verification_status' => 'declared',
                        'evidence_hash' => null,
                        'verified_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
                foreach ($profiles as $profileId => $customerSelectable) {
                    $connection->table('panel_target_protocol_profiles')->insert([
                        'panel_service_target_id' => $targetId,
                        'panel_protocol_profile_id' => $profileId,
                        'customer_selectable' => $customerSelectable,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $updated = new ServiceTargetRecord(
                    $targetId,
                    $record->panelConnectionId,
                    $record->code,
                    $kind->value,
                    $normalizedNameFa,
                    $normalizedNameEn,
                    $configurationHash,
                    $normalizedKeyVersion,
                    PanelResourceState::Disabled->value,
                    'declared',
                    $nextVersion,
                );
                $after = $this->safeServiceTargetState($connection, $updated);
                $this->inventoryHistory(
                    $connection,
                    'panel_service_target_histories',
                    'panel_service_target_id',
                    $targetId,
                    $nextVersion,
                    $action,
                    $before,
                    $after,
                    $context,
                );

                return $this->audit->record(
                    $connection,
                    $action,
                    self::SERVICE_TARGET,
                    (string) $targetId,
                    $payloadHmac,
                    $context,
                    $before,
                    $after,
                    true,
                );
            },
        );
    }

    public function disableServiceTarget(
        int $targetId,
        int $expectedVersion,
        PanelChangeContext $context,
    ): PanelMutationReceipt {
        return $this->transitionServiceTarget(
            $targetId,
            $expectedVersion,
            PanelResourceState::Disabled,
            'panels.service_target.disable',
            $context,
        );
    }

    public function archiveServiceTarget(
        int $targetId,
        int $expectedVersion,
        PanelChangeContext $context,
    ): PanelMutationReceipt {
        return $this->transitionServiceTarget(
            $targetId,
            $expectedVersion,
            PanelResourceState::Archived,
            'panels.service_target.archive',
            $context,
        );
    }

    /** @requirement CAT-002 CAT-004 PRV-001 ACL-002 SEC-002 DAT-003 QUA-001 */
    private function transitionServiceTarget(
        int $targetId,
        int $expectedVersion,
        PanelResourceState $target,
        string $action,
        PanelChangeContext $context,
    ): PanelMutationReceipt {
        PanelInput::positiveId($targetId, 'Panel service target ID');
        $normalizedExpectedVersion = PanelInput::expectedVersion($expectedVersion);
        $payloadHmac = $this->hasher->mutation([
            'target_id' => $targetId,
            'expected_version' => $normalizedExpectedVersion,
            'target_state' => $target->value,
        ]);

        return $this->executor->execute(
            $action,
            self::SERVICE_TARGET,
            (string) $targetId,
            $payloadHmac,
            ['panels.manage'],
            $context,
            function (Connection $connection) use (
                $action,
                $targetId,
                $normalizedExpectedVersion,
                $target,
                $payloadHmac,
                $context,
            ): PanelMutationReceipt {
                $record = $this->lockedServiceTarget($connection, $targetId);
                $this->assertInventoryVersion($record->version, $normalizedExpectedVersion, 'Panel service target');
                $state = $this->storedResourceState($record->state);
                $state->assertCanTransitionTo($target);
                if ($target === PanelResourceState::Archived && $state !== PanelResourceState::Disabled) {
                    throw new DomainException('Panel service target must be disabled before archival.');
                }

                $before = $this->safeServiceTargetState($connection, $record);
                $nextVersion = $record->version + 1;
                $connection->table('panel_service_targets')->where('id', $targetId)->update([
                    'state' => $target->value,
                    'version' => $nextVersion,
                    'updated_at' => $this->inventoryTimestamp(),
                ]);

                $after = [
                    ...$before,
                    'state' => $target->value,
                    'version' => $nextVersion,
                ];
                $this->inventoryHistory(
                    $connection,
                    'panel_service_target_histories',
                    'panel_service_target_id',
                    $targetId,
                    $nextVersion,
                    $action,
                    $before,
                    $after,
                    $context,
                );

                return $this->audit->record(
                    $connection,
                    $action,
                    self::SERVICE_TARGET,
                    (string) $targetId,
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
