<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Modules\Panels\Domain\PanelConnectionState;
use DomainException;
use Illuminate\Database\Connection;

trait PanelConnectionLifecycleOperations
{
    /** @requirement PRV-001 ACL-002 SEC-001 SEC-002 DAT-003 QUA-001 */
    public function disable(
        int $connectionId,
        int $expectedVersion,
        PanelChangeContext $context,
    ): PanelMutationReceipt {
        return $this->transition(
            $connectionId,
            $expectedVersion,
            PanelConnectionState::Disabled,
            'panels.connection.disable',
            $context,
        );
    }

    /** @requirement PRV-001 ACL-002 SEC-001 SEC-002 DAT-003 QUA-001 */
    public function archive(
        int $connectionId,
        int $expectedVersion,
        PanelChangeContext $context,
    ): PanelMutationReceipt {
        return $this->transition(
            $connectionId,
            $expectedVersion,
            PanelConnectionState::Archived,
            'panels.connection.archive',
            $context,
        );
    }

    private function transition(
        int $connectionId,
        int $expectedVersion,
        PanelConnectionState $target,
        string $action,
        PanelChangeContext $context,
    ): PanelMutationReceipt {
        PanelInput::positiveId($connectionId, 'Panel connection ID');
        $normalizedExpectedVersion = PanelInput::expectedVersion($expectedVersion);
        $payloadHmac = $this->hasher->mutation([
            'connection_id' => $connectionId,
            'expected_version' => $normalizedExpectedVersion,
            'target_state' => $target->value,
        ]);

        return $this->executor->execute(
            $action,
            self::TARGET_TYPE,
            (string) $connectionId,
            $payloadHmac,
            ['panels.manage'],
            $context,
            function (Connection $connection) use (
                $action,
                $connectionId,
                $normalizedExpectedVersion,
                $target,
                $payloadHmac,
                $context,
            ): PanelMutationReceipt {
                $record = $this->lockedConnection($connection, $connectionId);
                $this->assertVersion($record->version, $normalizedExpectedVersion);
                $state = $this->storedState($record->state);
                $state->assertCanTransitionTo($target);

                if ($target === PanelConnectionState::Archived && $state !== PanelConnectionState::Disabled) {
                    throw new DomainException('Panel connection must be disabled before archival.');
                }

                $before = $this->safeRecordState($record);
                $nextVersion = $record->version + 1;
                $connection->table('panel_connections')->where('id', $connectionId)->update([
                    'state' => $target->value,
                    'version' => $nextVersion,
                    'updated_at' => $this->timestamp(),
                ]);

                $after = [
                    ...$before,
                    'state' => $target->value,
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
