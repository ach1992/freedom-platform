<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Modules\Provisioning\Domain\ServiceMutationType;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-type MutationRecoveryOperation object{id:int|string,operation_key:string,operation_type:string,state:string,state_version:int|string,correlation_id:string,service_target_id:int|string|null,remote_service_id:?string,remote_effect_started_at:?string,updated_at:string,operation_generation:int|string}
 */
final readonly class ServiceMutationRecoveryService
{
    private const EFFECT_AUTHORITY = 'service_mutation_effect_v1';

    private const RUNNING_STALE_AFTER_SECONDS = 120;

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    /** @requirement SVC-004 PRV-002 PRV-003 ARCH-004 DAT-003 QUA-004 */
    public function prepare(string $operationPublicId): ProvisioningState
    {
        if (! Str::isUlid($operationPublicId)) {
            throw new DomainException('Service mutation operation public ID is invalid.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use ($operationPublicId): ProvisioningState {
            /** @var MutationRecoveryOperation|null $operation */
            $operation = $connection->table('provisioning_operations')
                ->where('public_id', $operationPublicId)
                ->lockForUpdate()
                ->first([
                    'id', 'operation_key', 'operation_type', 'state', 'state_version', 'correlation_id',
                    'service_target_id', 'remote_service_id', 'remote_effect_started_at', 'updated_at', 'operation_generation',
                ]);
            if ($operation === null || ServiceMutationType::tryFrom($operation->operation_type) === null) {
                throw new DomainException('Service mutation operation does not exist.');
            }

            $state = ProvisioningState::tryFrom($operation->state)
                ?? throw new RuntimeException('Stored Service mutation state is invalid.');
            if ($state !== ProvisioningState::Running || ! $this->runningAttemptIsStale($operation->updated_at)) {
                return $state;
            }

            if ($operation->remote_effect_started_at === null) {
                return $this->transitionInterrupted(
                    $connection,
                    $operation,
                    ProvisioningState::RetryScheduled,
                    'interrupted_pre_boundary_recovery',
                    'Worker interruption occurred before the Service mutation provider boundary.',
                    null,
                );
            }

            $now = $this->timestamp();

            return $this->transitionInterrupted(
                $connection,
                $operation,
                ProvisioningState::UncertainRemoteResult,
                'interrupted_remote_effect_recovery',
                'Worker interruption left the Service mutation remote result uncertain.',
                $now,
            );
        }, 3);
    }

    /**
     * @param  MutationRecoveryOperation  $operation
     */
    private function transitionInterrupted(
        Connection $connection,
        object $operation,
        ProvisioningState $nextState,
        string $resultCode,
        string $safeMessage,
        ?string $completedAt,
    ): ProvisioningState {
        $nextVersion = (int) $operation->state_version + 1;
        $now = $this->timestamp();
        $this->setAuthority($connection, $operation);
        try {
            $updated = $connection->table('provisioning_operations')
                ->where('id', (int) $operation->id)
                ->where('state', ProvisioningState::Running->value)
                ->where('state_version', (int) $operation->state_version)
                ->update([
                    'state' => $nextState->value,
                    'state_version' => $nextVersion,
                    'last_result_code' => $resultCode,
                    'last_result_message' => $safeMessage,
                    'remote_effect_completed_at' => $completedAt,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Interrupted Service mutation recovery lost its running state.');
            }

            /** @var MutationRecoveryOperation|null $next */
            $next = $connection->table('provisioning_operations')
                ->where('id', (int) $operation->id)
                ->first([
                    'id', 'operation_key', 'operation_type', 'state', 'state_version', 'correlation_id',
                    'service_target_id', 'remote_service_id', 'remote_effect_started_at', 'updated_at', 'operation_generation',
                ]);
            if ($next === null) {
                throw new RuntimeException('Recovered Service mutation operation disappeared.');
            }

            $connection->table('provisioning_remote_effect_events')->insert([
                'provisioning_operation_id' => (int) $next->id,
                'event_type' => $nextState->value,
                'state_version' => (int) $next->state_version,
                'route_selection_id' => null,
                'service_target_id' => $next->service_target_id === null ? null : (int) $next->service_target_id,
                'remote_service_id' => $next->remote_service_id,
                'result_code' => $resultCode,
                'correlation_id' => $next->correlation_id,
                'created_at' => $now,
            ]);
        } finally {
            $this->clearAuthority($connection);
        }

        return $nextState;
    }

    private function runningAttemptIsStale(string $updatedAt): bool
    {
        $utc = new DateTimeZone('UTC');
        $attemptUpdatedAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $updatedAt, $utc);
        if ($attemptUpdatedAt === false) {
            return true;
        }

        $staleAtOrBefore = $this->clock->now()
            ->setTimezone($utc)
            ->modify('-'.self::RUNNING_STALE_AFTER_SECONDS.' seconds');

        return $attemptUpdatedAt <= $staleAtOrBefore;
    }

    /** @param MutationRecoveryOperation $operation */
    private function setAuthority(Connection $connection, object $operation): void
    {
        $connection->statement('SET @app_provisioning_authority = ?', [self::EFFECT_AUTHORITY]);
        $connection->statement('SET @app_provisioning_operation_key = ?', [$operation->operation_key]);
        $connection->statement('SET @app_provisioning_correlation_id = ?', [$operation->correlation_id]);
        $connection->statement('SET @app_service_mutation_generation = ?', [(int) $operation->operation_generation]);
    }

    private function clearAuthority(Connection $connection): void
    {
        $connection->statement('SET @app_provisioning_authority = NULL');
        $connection->statement('SET @app_provisioning_operation_key = NULL');
        $connection->statement('SET @app_provisioning_correlation_id = NULL');
        $connection->statement('SET @app_service_mutation_generation = NULL');
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
