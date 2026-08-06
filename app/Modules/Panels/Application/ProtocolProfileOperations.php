<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Modules\Panels\Domain\PanelResourceCode;
use App\Modules\Panels\Domain\PanelResourceState;
use App\Modules\Panels\Domain\ProtocolProfileDefinition;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

trait ProtocolProfileOperations
{
    /** @requirement CAT-004 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function createProtocolProfile(
        string $code,
        string $nameFa,
        ?string $nameEn,
        ProtocolProfileDefinition $definition,
        PanelChangeContext $context,
    ): PanelMutationReceipt {
        $normalizedCode = PanelResourceCode::fromInput($code)->value;
        $normalizedNameFa = PanelText::required($nameFa);
        $normalizedNameEn = PanelText::optional($nameEn);
        $payloadHmac = $this->hasher->mutation([
            'code' => $normalizedCode,
            'name_fa' => $normalizedNameFa,
            'name_en' => $normalizedNameEn,
            'definition' => $definition->toArray(),
        ]);
        $action = 'panels.protocol_profile.create';

        return $this->executor->execute(
            $action,
            self::PROTOCOL_PROFILE_TARGET,
            null,
            $payloadHmac,
            ['panels.manage'],
            $context,
            function (Connection $connection) use (
                $action,
                $normalizedCode,
                $normalizedNameFa,
                $normalizedNameEn,
                $definition,
                $payloadHmac,
                $context,
            ): PanelMutationReceipt {
                if ($connection->table('panel_protocol_profiles')->where('code', $normalizedCode)->lockForUpdate()->exists()) {
                    throw new RuntimeException('Protocol profile code already exists.');
                }

                $now = $this->inventoryTimestamp();
                $profileId = (int) $connection->table('panel_protocol_profiles')->insertGetId([
                    'code' => $normalizedCode,
                    'name_fa' => $normalizedNameFa,
                    'name_en' => $normalizedNameEn,
                    ...$definition->toArray(),
                    'state' => PanelResourceState::Disabled->value,
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $record = new ProtocolProfileRecord(
                    $profileId,
                    $normalizedCode,
                    $normalizedNameFa,
                    $normalizedNameEn,
                    $definition->protocolFamily,
                    $definition->transport,
                    $definition->securityLayer,
                    $definition->host,
                    $definition->sni,
                    $definition->path,
                    $definition->port,
                    $definition->flow,
                    PanelResourceState::Disabled->value,
                    1,
                );
                $after = $this->safeProtocolProfileState($record);
                $this->inventoryHistory(
                    $connection,
                    'panel_protocol_profile_histories',
                    'panel_protocol_profile_id',
                    $profileId,
                    1,
                    $action,
                    null,
                    $after,
                    $context,
                );

                return $this->audit->record(
                    $connection,
                    $action,
                    self::PROTOCOL_PROFILE_TARGET,
                    (string) $profileId,
                    $payloadHmac,
                    $context,
                    [],
                    $after,
                    true,
                );
            },
        );
    }

    /** @requirement CAT-004 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function updateProtocolProfile(
        int $profileId,
        int $expectedVersion,
        string $nameFa,
        ?string $nameEn,
        ProtocolProfileDefinition $definition,
        PanelChangeContext $context,
    ): PanelMutationReceipt {
        PanelInput::positiveId($profileId, 'Protocol profile ID');
        $normalizedExpectedVersion = PanelInput::expectedVersion($expectedVersion);
        $normalizedNameFa = PanelText::required($nameFa);
        $normalizedNameEn = PanelText::optional($nameEn);
        $payloadHmac = $this->hasher->mutation([
            'profile_id' => $profileId,
            'expected_version' => $normalizedExpectedVersion,
            'name_fa' => $normalizedNameFa,
            'name_en' => $normalizedNameEn,
            'definition' => $definition->toArray(),
        ]);
        $action = 'panels.protocol_profile.update';

        return $this->executor->execute(
            $action,
            self::PROTOCOL_PROFILE_TARGET,
            (string) $profileId,
            $payloadHmac,
            ['panels.manage'],
            $context,
            function (Connection $connection) use (
                $action,
                $profileId,
                $normalizedExpectedVersion,
                $normalizedNameFa,
                $normalizedNameEn,
                $definition,
                $payloadHmac,
                $context,
            ): PanelMutationReceipt {
                $record = $this->lockedProtocolProfile($connection, $profileId);
                $this->assertInventoryVersion($record->version, $normalizedExpectedVersion, 'Protocol profile');
                if ($this->storedResourceState($record->state) !== PanelResourceState::Disabled) {
                    throw new DomainException('Only a disabled protocol profile may be edited.');
                }

                $before = $this->safeProtocolProfileState($record);
                $unchanged = $record->nameFa === $normalizedNameFa
                    && $record->nameEn === $normalizedNameEn
                    && $record->protocolFamily === $definition->protocolFamily
                    && $record->transport === $definition->transport
                    && $record->securityLayer === $definition->securityLayer
                    && $record->host === $definition->host
                    && $record->sni === $definition->sni
                    && $record->path === $definition->path
                    && $record->port === $definition->port
                    && $record->flow === $definition->flow;

                if ($unchanged) {
                    return $this->audit->record(
                        $connection,
                        $action,
                        self::PROTOCOL_PROFILE_TARGET,
                        (string) $profileId,
                        $payloadHmac,
                        $context,
                        $before,
                        $before,
                        false,
                    );
                }

                $nextVersion = $record->version + 1;
                $connection->table('panel_protocol_profiles')->where('id', $profileId)->update([
                    'name_fa' => $normalizedNameFa,
                    'name_en' => $normalizedNameEn,
                    ...$definition->toArray(),
                    'version' => $nextVersion,
                    'updated_at' => $this->inventoryTimestamp(),
                ]);

                $after = $this->safeProtocolProfileState(new ProtocolProfileRecord(
                    $profileId,
                    $record->code,
                    $normalizedNameFa,
                    $normalizedNameEn,
                    $definition->protocolFamily,
                    $definition->transport,
                    $definition->securityLayer,
                    $definition->host,
                    $definition->sni,
                    $definition->path,
                    $definition->port,
                    $definition->flow,
                    PanelResourceState::Disabled->value,
                    $nextVersion,
                ));
                $this->inventoryHistory(
                    $connection,
                    'panel_protocol_profile_histories',
                    'panel_protocol_profile_id',
                    $profileId,
                    $nextVersion,
                    $action,
                    $before,
                    $after,
                    $context,
                );

                return $this->audit->record(
                    $connection,
                    $action,
                    self::PROTOCOL_PROFILE_TARGET,
                    (string) $profileId,
                    $payloadHmac,
                    $context,
                    $before,
                    $after,
                    true,
                );
            },
        );
    }

    public function activateProtocolProfile(
        int $profileId,
        int $expectedVersion,
        PanelChangeContext $context,
    ): PanelMutationReceipt {
        return $this->transitionProtocolProfile(
            $profileId,
            $expectedVersion,
            PanelResourceState::Active,
            'panels.protocol_profile.activate',
            $context,
        );
    }

    public function disableProtocolProfile(
        int $profileId,
        int $expectedVersion,
        PanelChangeContext $context,
    ): PanelMutationReceipt {
        return $this->transitionProtocolProfile(
            $profileId,
            $expectedVersion,
            PanelResourceState::Disabled,
            'panels.protocol_profile.disable',
            $context,
        );
    }

    public function archiveProtocolProfile(
        int $profileId,
        int $expectedVersion,
        PanelChangeContext $context,
    ): PanelMutationReceipt {
        return $this->transitionProtocolProfile(
            $profileId,
            $expectedVersion,
            PanelResourceState::Archived,
            'panels.protocol_profile.archive',
            $context,
        );
    }

    /** @requirement CAT-004 ACL-002 SEC-002 DAT-003 QUA-001 */
    private function transitionProtocolProfile(
        int $profileId,
        int $expectedVersion,
        PanelResourceState $target,
        string $action,
        PanelChangeContext $context,
    ): PanelMutationReceipt {
        PanelInput::positiveId($profileId, 'Protocol profile ID');
        $normalizedExpectedVersion = PanelInput::expectedVersion($expectedVersion);
        $payloadHmac = $this->hasher->mutation([
            'profile_id' => $profileId,
            'expected_version' => $normalizedExpectedVersion,
            'target_state' => $target->value,
        ]);

        return $this->executor->execute(
            $action,
            self::PROTOCOL_PROFILE_TARGET,
            (string) $profileId,
            $payloadHmac,
            ['panels.manage'],
            $context,
            function (Connection $connection) use (
                $action,
                $profileId,
                $normalizedExpectedVersion,
                $target,
                $payloadHmac,
                $context,
            ): PanelMutationReceipt {
                $record = $this->lockedProtocolProfile($connection, $profileId);
                $this->assertInventoryVersion($record->version, $normalizedExpectedVersion, 'Protocol profile');
                $state = $this->storedResourceState($record->state);
                $state->assertCanTransitionTo($target);

                if ($target === PanelResourceState::Archived
                    && $connection->table('panel_target_protocol_profiles')
                        ->join(
                            'panel_service_targets',
                            'panel_service_targets.id',
                            '=',
                            'panel_target_protocol_profiles.panel_service_target_id',
                        )
                        ->where('panel_target_protocol_profiles.panel_protocol_profile_id', $profileId)
                        ->where('panel_service_targets.state', '<>', PanelResourceState::Archived->value)
                        ->exists()
                ) {
                    throw new DomainException('Protocol profile is assigned to a non-archived service target.');
                }

                $before = $this->safeProtocolProfileState($record);
                $nextVersion = $record->version + 1;
                $connection->table('panel_protocol_profiles')->where('id', $profileId)->update([
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
                    'panel_protocol_profile_histories',
                    'panel_protocol_profile_id',
                    $profileId,
                    $nextVersion,
                    $action,
                    $before,
                    $after,
                    $context,
                );

                return $this->audit->record(
                    $connection,
                    $action,
                    self::PROTOCOL_PROFILE_TARGET,
                    (string) $profileId,
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
