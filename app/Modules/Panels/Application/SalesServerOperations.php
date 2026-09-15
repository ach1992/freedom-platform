<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Modules\Panels\Domain\PanelResourceCode;
use App\Modules\Panels\Domain\PanelResourceState;
use App\Modules\Panels\Domain\SalesServerVisibility;
use DomainException;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use RuntimeException;

trait SalesServerOperations
{
    /** @requirement CAT-002 CAT-004 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function createSalesServer(
        string $code,
        string $nameFa,
        ?string $nameEn,
        ?string $descriptionFa,
        ?string $descriptionEn,
        int $sortOrder,
        PanelChangeContext $context,
    ): PanelMutationReceipt {
        $normalizedCode = PanelResourceCode::fromInput($code)->value;
        $normalizedNameFa = PanelText::required($nameFa);
        $normalizedNameEn = PanelText::optional($nameEn);
        $normalizedDescriptionFa = PanelText::optional($descriptionFa, 2000);
        $normalizedDescriptionEn = PanelText::optional($descriptionEn, 2000);
        $normalizedSortOrder = $this->normalizeSortOrder($sortOrder);
        $payloadHmac = $this->hasher->mutation([
            'code' => $normalizedCode,
            'name_fa' => $normalizedNameFa,
            'name_en' => $normalizedNameEn,
            'description_fa' => $normalizedDescriptionFa,
            'description_en' => $normalizedDescriptionEn,
            'sort_order' => $normalizedSortOrder,
        ]);
        $action = 'panels.sales_server.create';

        return $this->executor->execute(
            $action,
            self::SALES_SERVER_TARGET,
            null,
            $payloadHmac,
            ['panels.manage'],
            $context,
            function (Connection $connection) use (
                $action,
                $normalizedCode,
                $normalizedNameFa,
                $normalizedNameEn,
                $normalizedDescriptionFa,
                $normalizedDescriptionEn,
                $normalizedSortOrder,
                $payloadHmac,
                $context,
            ): PanelMutationReceipt {
                if ($connection->table('sales_servers')->where('code', $normalizedCode)->lockForUpdate()->exists()) {
                    throw new RuntimeException('Sales server code already exists.');
                }

                $now = $this->inventoryTimestamp();
                $serverId = (int) $connection->table('sales_servers')->insertGetId([
                    'code' => $normalizedCode,
                    'name_fa' => $normalizedNameFa,
                    'name_en' => $normalizedNameEn,
                    'description_fa' => $normalizedDescriptionFa,
                    'description_en' => $normalizedDescriptionEn,
                    'state' => PanelResourceState::Disabled->value,
                    'visibility' => SalesServerVisibility::Hidden->value,
                    'sort_order' => $normalizedSortOrder,
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $record = new SalesServerRecord(
                    $serverId,
                    $normalizedCode,
                    $normalizedNameFa,
                    $normalizedNameEn,
                    $normalizedDescriptionFa,
                    $normalizedDescriptionEn,
                    PanelResourceState::Disabled->value,
                    SalesServerVisibility::Hidden->value,
                    $normalizedSortOrder,
                    1,
                );
                $after = $this->safeSalesServerState($record);
                $this->inventoryHistory(
                    $connection,
                    'sales_server_histories',
                    'sales_server_id',
                    $serverId,
                    1,
                    $action,
                    null,
                    $after,
                    $context,
                );

                return $this->audit->record(
                    $connection,
                    $action,
                    self::SALES_SERVER_TARGET,
                    (string) $serverId,
                    $payloadHmac,
                    $context,
                    [],
                    $after,
                    true,
                );
            },
        );
    }

    /** @requirement CAT-002 CAT-004 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function updateSalesServer(
        int $serverId,
        int $expectedVersion,
        string $nameFa,
        ?string $nameEn,
        ?string $descriptionFa,
        ?string $descriptionEn,
        int $sortOrder,
        PanelChangeContext $context,
    ): PanelMutationReceipt {
        PanelInput::positiveId($serverId, 'Sales server ID');
        $normalizedExpectedVersion = PanelInput::expectedVersion($expectedVersion);
        $normalizedNameFa = PanelText::required($nameFa);
        $normalizedNameEn = PanelText::optional($nameEn);
        $normalizedDescriptionFa = PanelText::optional($descriptionFa, 2000);
        $normalizedDescriptionEn = PanelText::optional($descriptionEn, 2000);
        $normalizedSortOrder = $this->normalizeSortOrder($sortOrder);
        $payloadHmac = $this->hasher->mutation([
            'server_id' => $serverId,
            'expected_version' => $normalizedExpectedVersion,
            'name_fa' => $normalizedNameFa,
            'name_en' => $normalizedNameEn,
            'description_fa' => $normalizedDescriptionFa,
            'description_en' => $normalizedDescriptionEn,
            'sort_order' => $normalizedSortOrder,
        ]);
        $action = 'panels.sales_server.update';

        return $this->executor->execute(
            $action,
            self::SALES_SERVER_TARGET,
            (string) $serverId,
            $payloadHmac,
            ['panels.manage'],
            $context,
            function (Connection $connection) use (
                $action,
                $serverId,
                $normalizedExpectedVersion,
                $normalizedNameFa,
                $normalizedNameEn,
                $normalizedDescriptionFa,
                $normalizedDescriptionEn,
                $normalizedSortOrder,
                $payloadHmac,
                $context,
            ): PanelMutationReceipt {
                $record = $this->lockedSalesServer($connection, $serverId);
                $this->assertInventoryVersion($record->version, $normalizedExpectedVersion, 'Sales server');
                if ($this->storedResourceState($record->state) !== PanelResourceState::Disabled) {
                    throw new DomainException('Only a disabled sales server may be edited.');
                }

                $before = $this->safeSalesServerState($record);
                $unchanged = $record->nameFa === $normalizedNameFa
                    && $record->nameEn === $normalizedNameEn
                    && $record->descriptionFa === $normalizedDescriptionFa
                    && $record->descriptionEn === $normalizedDescriptionEn
                    && $record->sortOrder === $normalizedSortOrder;

                if ($unchanged) {
                    return $this->audit->record(
                        $connection,
                        $action,
                        self::SALES_SERVER_TARGET,
                        (string) $serverId,
                        $payloadHmac,
                        $context,
                        $before,
                        $before,
                        false,
                    );
                }

                $nextVersion = $record->version + 1;
                $connection->table('sales_servers')->where('id', $serverId)->update([
                    'name_fa' => $normalizedNameFa,
                    'name_en' => $normalizedNameEn,
                    'description_fa' => $normalizedDescriptionFa,
                    'description_en' => $normalizedDescriptionEn,
                    'sort_order' => $normalizedSortOrder,
                    'version' => $nextVersion,
                    'updated_at' => $this->inventoryTimestamp(),
                ]);

                $updated = new SalesServerRecord(
                    $serverId,
                    $record->code,
                    $normalizedNameFa,
                    $normalizedNameEn,
                    $normalizedDescriptionFa,
                    $normalizedDescriptionEn,
                    $record->state,
                    $record->visibility,
                    $normalizedSortOrder,
                    $nextVersion,
                );
                $after = $this->safeSalesServerState($updated);
                $this->inventoryHistory(
                    $connection,
                    'sales_server_histories',
                    'sales_server_id',
                    $serverId,
                    $nextVersion,
                    $action,
                    $before,
                    $after,
                    $context,
                );

                return $this->audit->record(
                    $connection,
                    $action,
                    self::SALES_SERVER_TARGET,
                    (string) $serverId,
                    $payloadHmac,
                    $context,
                    $before,
                    $after,
                    true,
                );
            },
        );
    }

    public function activateSalesServer(int $serverId, int $expectedVersion, PanelChangeContext $context): PanelMutationReceipt
    {
        return $this->transitionSalesServer($serverId, $expectedVersion, PanelResourceState::Active, 'panels.sales_server.activate', $context);
    }

    public function disableSalesServer(int $serverId, int $expectedVersion, PanelChangeContext $context): PanelMutationReceipt
    {
        return $this->transitionSalesServer($serverId, $expectedVersion, PanelResourceState::Disabled, 'panels.sales_server.disable', $context);
    }

    public function enterSalesServerMaintenance(int $serverId, int $expectedVersion, PanelChangeContext $context): PanelMutationReceipt
    {
        return $this->transitionSalesServer($serverId, $expectedVersion, PanelResourceState::Maintenance, 'panels.sales_server.maintenance', $context);
    }

    public function archiveSalesServer(int $serverId, int $expectedVersion, PanelChangeContext $context): PanelMutationReceipt
    {
        return $this->transitionSalesServer($serverId, $expectedVersion, PanelResourceState::Archived, 'panels.sales_server.archive', $context);
    }

    /** @requirement CAT-002 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function setSalesServerVisibility(
        int $serverId,
        int $expectedVersion,
        SalesServerVisibility $visibility,
        PanelChangeContext $context,
    ): PanelMutationReceipt {
        PanelInput::positiveId($serverId, 'Sales server ID');
        $normalizedExpectedVersion = PanelInput::expectedVersion($expectedVersion);
        $payloadHmac = $this->hasher->mutation([
            'server_id' => $serverId,
            'expected_version' => $normalizedExpectedVersion,
            'visibility' => $visibility->value,
        ]);
        $action = 'panels.sales_server.visibility';

        return $this->executor->execute(
            $action,
            self::SALES_SERVER_TARGET,
            (string) $serverId,
            $payloadHmac,
            ['panels.manage'],
            $context,
            function (Connection $connection) use (
                $action,
                $serverId,
                $normalizedExpectedVersion,
                $visibility,
                $payloadHmac,
                $context,
            ): PanelMutationReceipt {
                $record = $this->lockedSalesServer($connection, $serverId);
                $this->assertInventoryVersion($record->version, $normalizedExpectedVersion, 'Sales server');
                $state = $this->storedResourceState($record->state);
                $visibility->assertCompatibleWith($state);
                if ($state === PanelResourceState::Archived) {
                    throw new DomainException('Archived sales servers are immutable.');
                }

                $before = $this->safeSalesServerState($record);
                if ($record->visibility === $visibility->value) {
                    return $this->audit->record(
                        $connection,
                        $action,
                        self::SALES_SERVER_TARGET,
                        (string) $serverId,
                        $payloadHmac,
                        $context,
                        $before,
                        $before,
                        false,
                    );
                }

                $nextVersion = $record->version + 1;
                $connection->table('sales_servers')->where('id', $serverId)->update([
                    'visibility' => $visibility->value,
                    'version' => $nextVersion,
                    'updated_at' => $this->inventoryTimestamp(),
                ]);
                $after = [
                    ...$before,
                    'visibility' => $visibility->value,
                    'version' => $nextVersion,
                ];
                $this->inventoryHistory(
                    $connection,
                    'sales_server_histories',
                    'sales_server_id',
                    $serverId,
                    $nextVersion,
                    $action,
                    $before,
                    $after,
                    $context,
                );

                return $this->audit->record(
                    $connection,
                    $action,
                    self::SALES_SERVER_TARGET,
                    (string) $serverId,
                    $payloadHmac,
                    $context,
                    $before,
                    $after,
                    true,
                );
            },
        );
    }

    /** @requirement CAT-002 ACL-002 SEC-002 DAT-003 QUA-001 */
    private function transitionSalesServer(
        int $serverId,
        int $expectedVersion,
        PanelResourceState $target,
        string $action,
        PanelChangeContext $context,
    ): PanelMutationReceipt {
        PanelInput::positiveId($serverId, 'Sales server ID');
        $normalizedExpectedVersion = PanelInput::expectedVersion($expectedVersion);
        $payloadHmac = $this->hasher->mutation([
            'server_id' => $serverId,
            'expected_version' => $normalizedExpectedVersion,
            'target_state' => $target->value,
        ]);

        return $this->executor->execute(
            $action,
            self::SALES_SERVER_TARGET,
            (string) $serverId,
            $payloadHmac,
            ['panels.manage'],
            $context,
            function (Connection $connection) use (
                $action,
                $serverId,
                $normalizedExpectedVersion,
                $target,
                $payloadHmac,
                $context,
            ): PanelMutationReceipt {
                $record = $this->lockedSalesServer($connection, $serverId);
                $this->assertInventoryVersion($record->version, $normalizedExpectedVersion, 'Sales server');
                $state = $this->storedResourceState($record->state);
                $state->assertCanTransitionTo($target);
                if ($target !== PanelResourceState::Active
                    && $this->storedServerVisibility($record->visibility) !== SalesServerVisibility::Hidden
                ) {
                    throw new DomainException('A listed sales server must be hidden before leaving active state.');
                }
                if ($target === PanelResourceState::Archived && $state !== PanelResourceState::Disabled) {
                    throw new DomainException('Sales server must be disabled before archival.');
                }

                $before = $this->safeSalesServerState($record);
                $nextVersion = $record->version + 1;
                $connection->table('sales_servers')->where('id', $serverId)->update([
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
                    'sales_server_histories',
                    'sales_server_id',
                    $serverId,
                    $nextVersion,
                    $action,
                    $before,
                    $after,
                    $context,
                );

                return $this->audit->record(
                    $connection,
                    $action,
                    self::SALES_SERVER_TARGET,
                    (string) $serverId,
                    $payloadHmac,
                    $context,
                    $before,
                    $after,
                    true,
                );
            },
        );
    }

    private function normalizeSortOrder(int $sortOrder): int
    {
        if ($sortOrder < 0) {
            throw new InvalidArgumentException('Sales server sort order must not be negative.');
        }

        return $sortOrder;
    }
}
