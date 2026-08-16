<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Shared\Application\Clock;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class InitialProvisioningRecoveryService
{
    private const OPERATION_TYPE = 'initial_provision';

    private const AUTHORITY = 'initial_remote_effect_v1';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    /** @requirement PAY-003 PRV-002 PRV-003 DAT-003 SEC-002 QUA-004 */
    public function prepare(string $operationPublicId): ProvisioningState
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $operationPublicId) !== 1) {
            throw new DomainException('Provisioning operation public ID is invalid.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use ($operationPublicId): ProvisioningState {
            /** @var object{id:int|string,operation_key:string,operation_type:string,state:string,state_version:int|string,correlation_id:string,route_selection_id:int|string|null,service_target_id:int|string|null,remote_service_id:?string}|null $operation */
            $operation = $connection->table('provisioning_operations')
                ->where('public_id', strtoupper($operationPublicId))
                ->lockForUpdate()
                ->first([
                    'id',
                    'operation_key',
                    'operation_type',
                    'state',
                    'state_version',
                    'correlation_id',
                    'route_selection_id',
                    'service_target_id',
                    'remote_service_id',
                ]);
            if ($operation === null || $operation->operation_type !== self::OPERATION_TYPE) {
                throw new DomainException('Initial provisioning operation does not exist.');
            }

            $state = ProvisioningState::tryFrom($operation->state)
                ?? throw new RuntimeException('Stored provisioning state is invalid.');
            if (! in_array($state, [ProvisioningState::Running, ProvisioningState::UncertainRemoteResult], true)) {
                return $state;
            }

            // A persisted Running state can be the residue of a worker crash at any point after the
            // durable effect fence was acquired, including after an unobserved provider mutation.
            // RetryScheduled is safe because the executor reuses the immutable route/remote identity
            // and PanelCreateCoordinator performs authoritative lookup before any create attempt.
            $eventType = $state === ProvisioningState::Running
                ? 'interrupted_recovery_scheduled'
                : 'reconciliation_scheduled';
            $resultCode = $state === ProvisioningState::Running
                ? 'interrupted_running_recovery'
                : 'uncertain_recovery';
            $nextVersion = (int) $operation->state_version + 1;
            $this->setAuthority($connection, $operation->operation_key, $operation->correlation_id);
            try {
                $updated = $connection->table('provisioning_operations')
                    ->where('id', (int) $operation->id)
                    ->where('state', $state->value)
                    ->where('state_version', (int) $operation->state_version)
                    ->update([
                        'state' => ProvisioningState::RetryScheduled->value,
                        'state_version' => $nextVersion,
                        'updated_at' => $this->timestamp(),
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Initial provisioning recovery scheduling lost its state.');
                }

                $connection->table('provisioning_remote_effect_events')->insert([
                    'provisioning_operation_id' => (int) $operation->id,
                    'event_type' => $eventType,
                    'state_version' => $nextVersion,
                    'route_selection_id' => $operation->route_selection_id === null ? null : (int) $operation->route_selection_id,
                    'service_target_id' => $operation->service_target_id === null ? null : (int) $operation->service_target_id,
                    'remote_service_id' => $operation->remote_service_id,
                    'result_code' => $resultCode,
                    'correlation_id' => $operation->correlation_id,
                    'created_at' => $this->timestamp(),
                ]);
            } finally {
                $this->clearAuthority($connection);
            }

            return ProvisioningState::RetryScheduled;
        }, 3);
    }

    private function setAuthority(Connection $connection, string $operationKey, string $correlationId): void
    {
        $connection->statement('SET @app_provisioning_authority = ?, @app_provisioning_operation_key = ?, @app_provisioning_correlation_id = ?', [
            self::AUTHORITY,
            $operationKey,
            $correlationId,
        ]);
    }

    private function clearAuthority(Connection $connection): void
    {
        $connection->statement('SET @app_provisioning_authority = NULL, @app_provisioning_operation_key = NULL, @app_provisioning_correlation_id = NULL');
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
