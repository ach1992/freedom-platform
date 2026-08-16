<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class InitialProvisioningRecoveryService
{
    private const OPERATION_TYPE = 'initial_provision';

    private const AUTHORITY = 'initial_remote_effect_v1';

    /**
     * Outbox leases are intentionally shorter than the conservative provider-call recovery window.
     * Provisioning sessions currently use a bounded 15-second request timeout, while a coordinator
     * pass can perform several authenticated lookup/create/reconciliation requests. Keep a wide
     * margin so a reclaimed message cannot reinterpret a still-live remote attempt as interrupted.
     */
    private const RUNNING_STALE_AFTER_SECONDS = 600;

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
            /** @var object{id:int|string,operation_key:string,operation_type:string,state:string,state_version:int|string,correlation_id:string,route_selection_id:int|string|null,service_target_id:int|string|null,remote_service_id:?string,attempt_count:int|string,remote_effect_started_at:?string,updated_at:string}|null $operation */
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
                    'attempt_count',
                    'remote_effect_started_at',
                    'updated_at',
                ]);
            if ($operation === null || $operation->operation_type !== self::OPERATION_TYPE) {
                throw new DomainException('Initial provisioning operation does not exist.');
            }

            $state = ProvisioningState::tryFrom($operation->state)
                ?? throw new RuntimeException('Stored provisioning state is invalid.');
            if ($state === ProvisioningState::Running) {
                if (! $this->runningAttemptIsStale(
                    (int) $operation->attempt_count,
                    $operation->remote_effect_started_at,
                    $operation->updated_at,
                )) {
                    return ProvisioningState::Running;
                }

                return $this->markInterruptedUncertain($connection, $operation);
            }
            if ($state !== ProvisioningState::UncertainRemoteResult) {
                return $state;
            }

            return $this->scheduleUncertainRetry($connection, $operation);
        }, 3);
    }

    /** @param object{id:int|string,operation_key:string,state_version:int|string,correlation_id:string,route_selection_id:int|string|null,service_target_id:int|string|null,remote_service_id:?string} $operation */
    private function markInterruptedUncertain(Connection $connection, object $operation): ProvisioningState
    {
        $nextVersion = (int) $operation->state_version + 1;
        $timestamp = $this->timestamp();
        $this->setAuthority($connection, $operation->operation_key, $operation->correlation_id);
        try {
            $updated = $connection->table('provisioning_operations')
                ->where('id', (int) $operation->id)
                ->where('state', ProvisioningState::Running->value)
                ->where('state_version', (int) $operation->state_version)
                ->update([
                    'state' => ProvisioningState::UncertainRemoteResult->value,
                    'state_version' => $nextVersion,
                    'last_result_code' => 'interrupted_running_recovery',
                    'last_result_message' => 'Worker interruption left the remote result uncertain.',
                    'remote_effect_completed_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Interrupted initial provisioning recovery lost its running state.');
            }

            $connection->table('provisioning_remote_effect_events')->insert([
                'provisioning_operation_id' => (int) $operation->id,
                'event_type' => ProvisioningState::UncertainRemoteResult->value,
                'state_version' => $nextVersion,
                'route_selection_id' => $operation->route_selection_id === null ? null : (int) $operation->route_selection_id,
                'service_target_id' => $operation->service_target_id === null ? null : (int) $operation->service_target_id,
                'remote_service_id' => $operation->remote_service_id,
                'result_code' => 'interrupted_running_recovery',
                'correlation_id' => $operation->correlation_id,
                'created_at' => $timestamp,
            ]);
        } finally {
            $this->clearAuthority($connection);
        }

        return ProvisioningState::UncertainRemoteResult;
    }

    /** @param object{id:int|string,operation_key:string,state_version:int|string,correlation_id:string,route_selection_id:int|string|null,service_target_id:int|string|null,remote_service_id:?string} $operation */
    private function scheduleUncertainRetry(Connection $connection, object $operation): ProvisioningState
    {
        $nextVersion = (int) $operation->state_version + 1;
        $this->setAuthority($connection, $operation->operation_key, $operation->correlation_id);
        try {
            $updated = $connection->table('provisioning_operations')
                ->where('id', (int) $operation->id)
                ->where('state', ProvisioningState::UncertainRemoteResult->value)
                ->where('state_version', (int) $operation->state_version)
                ->update([
                    'state' => ProvisioningState::RetryScheduled->value,
                    'state_version' => $nextVersion,
                    'updated_at' => $this->timestamp(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Initial provisioning reconciliation scheduling lost its state.');
            }

            $connection->table('provisioning_remote_effect_events')->insert([
                'provisioning_operation_id' => (int) $operation->id,
                'event_type' => 'reconciliation_scheduled',
                'state_version' => $nextVersion,
                'route_selection_id' => $operation->route_selection_id === null ? null : (int) $operation->route_selection_id,
                'service_target_id' => $operation->service_target_id === null ? null : (int) $operation->service_target_id,
                'remote_service_id' => $operation->remote_service_id,
                'result_code' => 'uncertain_recovery',
                'correlation_id' => $operation->correlation_id,
                'created_at' => $this->timestamp(),
            ]);
        } finally {
            $this->clearAuthority($connection);
        }

        return ProvisioningState::RetryScheduled;
    }

    private function runningAttemptIsStale(
        int $attemptCount,
        ?string $remoteEffectStartedAt,
        string $updatedAt,
    ): bool {
        // The executor intentionally preserves the first remote-effect timestamp across retries.
        // For later attempts, the running claim's updated_at is the durable per-attempt freshness marker.
        $referenceAt = $attemptCount > 1 ? $updatedAt : $remoteEffectStartedAt;
        if ($referenceAt === null || $referenceAt === '') {
            return true;
        }

        $utc = new DateTimeZone('UTC');
        $startedAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $referenceAt, $utc);
        if ($startedAt === false) {
            return true;
        }

        $staleAtOrBefore = $this->clock->now()
            ->setTimezone($utc)
            ->modify('-'.self::RUNNING_STALE_AFTER_SECONDS.' seconds');

        return $startedAt <= $staleAtOrBefore;
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
