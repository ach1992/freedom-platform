<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Modules\Panels\Domain\TargetCapacityState;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

final readonly class TargetCapacityService
{
    private const TARGET_TYPE = 'panel_target_capacity';

    public function __construct(
        private PanelMutationExecutor $executor,
        private PanelMutationAudit $audit,
        private PanelPayloadHasher $hasher,
        private Clock $clock,
    ) {}

    /** @requirement CAT-008 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function create(int $serviceTargetId, int $hardLimit, PanelChangeContext $context): PanelMutationReceipt
    {
        PanelInput::positiveId($serviceTargetId, 'Panel service target ID');
        $limit = CapacityInput::hardLimit($hardLimit);
        $payloadHmac = $this->hasher->mutation(['service_target_id' => $serviceTargetId, 'hard_limit' => $limit]);
        $action = 'panels.target_capacity.create';

        return $this->executor->execute(
            $action,
            self::TARGET_TYPE,
            null,
            $payloadHmac,
            ['panels.manage'],
            $context,
            function (Connection $connection) use ($action, $serviceTargetId, $limit, $payloadHmac, $context): PanelMutationReceipt {
                /** @var object{state: string}|null $target */
                $target = $connection->table('panel_service_targets')->where('id', $serviceTargetId)->lockForUpdate()->first(['state']);
                if ($target === null || $target->state === 'archived') {
                    throw new DomainException('Service target state does not allow capacity configuration.');
                }
                if ($connection->table('panel_target_capacities')->where('panel_service_target_id', $serviceTargetId)->lockForUpdate()->exists()) {
                    throw new RuntimeException('Target capacity already exists.');
                }

                $now = $this->timestamp();
                $capacityId = (int) $connection->table('panel_target_capacities')->insertGetId([
                    'panel_service_target_id' => $serviceTargetId,
                    'hard_limit' => $limit,
                    'held_units' => 0,
                    'committed_units' => 0,
                    'state' => TargetCapacityState::Disabled->value,
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $record = new TargetCapacityRecord($capacityId, $serviceTargetId, $limit, 0, 0, TargetCapacityState::Disabled->value, 1);
                $after = $this->safeState($record);
                $this->history($connection, $record, $action, null, $after, $context);

                return $this->audit->record($connection, $action, self::TARGET_TYPE, (string) $capacityId, $payloadHmac, $context, [], $after, true);
            },
        );
    }

    /** @requirement CAT-008 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function setHardLimit(int $capacityId, int $expectedVersion, int $hardLimit, PanelChangeContext $context): PanelMutationReceipt
    {
        PanelInput::positiveId($capacityId, 'Target capacity ID');
        $version = PanelInput::expectedVersion($expectedVersion);
        $limit = CapacityInput::hardLimit($hardLimit);
        $payloadHmac = $this->hasher->mutation(['capacity_id' => $capacityId, 'expected_version' => $version, 'hard_limit' => $limit]);
        $action = 'panels.target_capacity.limit';

        return $this->executor->execute(
            $action,
            self::TARGET_TYPE,
            (string) $capacityId,
            $payloadHmac,
            ['panels.manage'],
            $context,
            function (Connection $connection) use ($action, $capacityId, $version, $limit, $payloadHmac, $context): PanelMutationReceipt {
                $record = $this->lockedCapacity($connection, $capacityId);
                $this->assertVersion($record, $version);
                if ($limit < $record->heldUnits + $record->committedUnits) {
                    throw new DomainException('Capacity hard limit cannot be below allocated units.');
                }

                $before = $this->safeState($record);
                if ($record->hardLimit === $limit) {
                    return $this->audit->record($connection, $action, self::TARGET_TYPE, (string) $capacityId, $payloadHmac, $context, $before, $before, false);
                }

                $updated = new TargetCapacityRecord(
                    $record->id,
                    $record->serviceTargetId,
                    $limit,
                    $record->heldUnits,
                    $record->committedUnits,
                    $record->state,
                    $record->version + 1,
                );
                $connection->table('panel_target_capacities')->where('id', $capacityId)->update([
                    'hard_limit' => $limit,
                    'version' => $updated->version,
                    'updated_at' => $this->timestamp(),
                ]);
                $after = $this->safeState($updated);
                $this->history($connection, $updated, $action, $before, $after, $context);

                return $this->audit->record($connection, $action, self::TARGET_TYPE, (string) $capacityId, $payloadHmac, $context, $before, $after, true);
            },
        );
    }

    public function enable(int $capacityId, int $expectedVersion, PanelChangeContext $context): PanelMutationReceipt
    {
        return $this->setState($capacityId, $expectedVersion, TargetCapacityState::Enabled, 'panels.target_capacity.enable', $context);
    }

    public function disable(int $capacityId, int $expectedVersion, PanelChangeContext $context): PanelMutationReceipt
    {
        return $this->setState($capacityId, $expectedVersion, TargetCapacityState::Disabled, 'panels.target_capacity.disable', $context);
    }

    private function setState(
        int $capacityId,
        int $expectedVersion,
        TargetCapacityState $state,
        string $action,
        PanelChangeContext $context,
    ): PanelMutationReceipt {
        PanelInput::positiveId($capacityId, 'Target capacity ID');
        $version = PanelInput::expectedVersion($expectedVersion);
        $payloadHmac = $this->hasher->mutation(['capacity_id' => $capacityId, 'expected_version' => $version, 'state' => $state->value]);

        return $this->executor->execute(
            $action,
            self::TARGET_TYPE,
            (string) $capacityId,
            $payloadHmac,
            ['panels.manage'],
            $context,
            function (Connection $connection) use ($action, $capacityId, $version, $state, $payloadHmac, $context): PanelMutationReceipt {
                $record = $this->lockedCapacity($connection, $capacityId);
                $this->assertVersion($record, $version);
                $before = $this->safeState($record);
                if ($record->state === $state->value) {
                    return $this->audit->record($connection, $action, self::TARGET_TYPE, (string) $capacityId, $payloadHmac, $context, $before, $before, false);
                }

                $updated = new TargetCapacityRecord(
                    $record->id,
                    $record->serviceTargetId,
                    $record->hardLimit,
                    $record->heldUnits,
                    $record->committedUnits,
                    $state->value,
                    $record->version + 1,
                );
                $connection->table('panel_target_capacities')->where('id', $capacityId)->update([
                    'state' => $state->value,
                    'version' => $updated->version,
                    'updated_at' => $this->timestamp(),
                ]);
                $after = $this->safeState($updated);
                $this->history($connection, $updated, $action, $before, $after, $context);

                return $this->audit->record($connection, $action, self::TARGET_TYPE, (string) $capacityId, $payloadHmac, $context, $before, $after, true);
            },
        );
    }

    private function lockedCapacity(Connection $connection, int $capacityId): TargetCapacityRecord
    {
        /** @var object{id: int|string, panel_service_target_id: int|string, hard_limit: int|string, held_units: int|string, committed_units: int|string, state: string, version: int|string}|null $row */
        $row = $connection->table('panel_target_capacities')->where('id', $capacityId)->lockForUpdate()->first([
            'id', 'panel_service_target_id', 'hard_limit', 'held_units', 'committed_units', 'state', 'version',
        ]);
        if ($row === null) {
            throw new RuntimeException('Target capacity does not exist.');
        }

        return new TargetCapacityRecord(
            (int) $row->id,
            (int) $row->panel_service_target_id,
            (int) $row->hard_limit,
            (int) $row->held_units,
            (int) $row->committed_units,
            $row->state,
            (int) $row->version,
        );
    }

    private function assertVersion(TargetCapacityRecord $record, int $expectedVersion): void
    {
        if ($record->version !== $expectedVersion) {
            throw new RuntimeException('Target capacity version conflict.');
        }
    }

    /** @return array<string, bool|int|string|null> */
    private function safeState(TargetCapacityRecord $record): array
    {
        return [
            'capacity_id' => $record->id,
            'service_target_id' => $record->serviceTargetId,
            'hard_limit' => $record->hardLimit,
            'held_units' => $record->heldUnits,
            'committed_units' => $record->committedUnits,
            'available_units' => $record->availableUnits(),
            'state' => $record->state,
            'version' => $record->version,
        ];
    }

    /**
     * @param  array<string, bool|int|string|null>|null  $before
     * @param  array<string, bool|int|string|null>  $after
     */
    private function history(
        Connection $connection,
        TargetCapacityRecord $record,
        string $action,
        ?array $before,
        array $after,
        PanelChangeContext $context,
    ): void {
        $connection->table('panel_target_capacity_histories')->insert([
            'panel_target_capacity_id' => $record->id,
            'version' => $record->version,
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
