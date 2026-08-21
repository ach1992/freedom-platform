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
 * @phpstan-type MutationRecoveryOperation object{id:int|string,operation_key:string,operation_type:string,service_subscription_id:int|string,state:string,state_version:int|string,correlation_id:string,service_target_id:int|string|null,remote_service_id:?string,remote_effect_started_at:?string,updated_at:string,operation_generation:int|string,target_remote_identity_generation:int|string,target_lifecycle_version:int|string}
 * @phpstan-type MutationRecoveryService object{id:int|string,service_target_id:int|string|null,remote_service_id:?string,lifecycle_state:string,lifecycle_version:int|string,remote_identity_generation:int|string,mutation_generation:int|string,remote_deleted_at:?string}
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
            $operation = $this->operationByPublicId($connection, $operationPublicId);
            $type = ServiceMutationType::tryFrom($operation->operation_type)
                ?? throw new DomainException('Service mutation operation does not exist.');
            $state = ProvisioningState::tryFrom($operation->state)
                ?? throw new RuntimeException('Stored Service mutation state is invalid.');

            if (in_array($state, [ProvisioningState::Queued, ProvisioningState::RetryScheduled], true)) {
                $service = $this->serviceById($connection, (int) $operation->service_subscription_id);
                if (! $this->serviceMatchesOperation($service, $operation, $type)) {
                    return $this->transitionStaleQueued($connection, $operation);
                }
            }

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
    private function transitionStaleQueued(Connection $connection, object $operation): ProvisioningState
    {
        $nextVersion = (int) $operation->state_version + 1;
        $now = $this->timestamp();
        $this->setAuthority($connection, $operation);
        try {
            $updated = $connection->table('provisioning_operations')
                ->where('id', (int) $operation->id)
                ->whereIn('state', [ProvisioningState::Queued->value, ProvisioningState::RetryScheduled->value])
                ->where('state_version', (int) $operation->state_version)
                ->update([
                    'state' => ProvisioningState::NeedsReview->value,
                    'state_version' => $nextVersion,
                    'last_result_code' => 'stale_service_before_claim',
                    'last_result_message' => 'Service lifecycle or remote identity changed before remote-effect authority could be claimed.',
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Stale Service mutation rejection lost its queued state.');
            }

            $next = $this->operationById($connection, (int) $operation->id);
            $this->recordEvent($connection, $next, 'stale_service_before_claim', $now);
        } finally {
            $this->clearAuthority($connection);
        }

        return ProvisioningState::NeedsReview;
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

            $next = $this->operationById($connection, (int) $operation->id);
            $this->recordEvent($connection, $next, $resultCode, $now);
        } finally {
            $this->clearAuthority($connection);
        }

        return $nextState;
    }

    /** @return MutationRecoveryOperation */
    private function operationByPublicId(Connection $connection, string $publicId): object
    {
        /** @var MutationRecoveryOperation|null $operation */
        $operation = $connection->table('provisioning_operations')
            ->where('public_id', $publicId)
            ->lockForUpdate()
            ->first($this->operationColumns());
        if ($operation === null) {
            throw new DomainException('Service mutation operation does not exist.');
        }

        return $operation;
    }

    /** @return MutationRecoveryOperation */
    private function operationById(Connection $connection, int $id): object
    {
        /** @var MutationRecoveryOperation|null $operation */
        $operation = $connection->table('provisioning_operations')
            ->where('id', $id)
            ->lockForUpdate()
            ->first($this->operationColumns());
        if ($operation === null) {
            throw new RuntimeException('Recovered Service mutation operation disappeared.');
        }

        return $operation;
    }

    /** @return MutationRecoveryService */
    private function serviceById(Connection $connection, int $id): object
    {
        /** @var MutationRecoveryService|null $service */
        $service = $connection->table('service_subscriptions')
            ->where('id', $id)
            ->lockForUpdate()
            ->first([
                'id', 'service_target_id', 'remote_service_id', 'lifecycle_state', 'lifecycle_version',
                'remote_identity_generation', 'mutation_generation', 'remote_deleted_at',
            ]);
        if ($service === null) {
            throw new RuntimeException('Service Subscription disappeared during mutation recovery.');
        }

        return $service;
    }

    /**
     * @param  MutationRecoveryService  $service
     * @param  MutationRecoveryOperation  $operation
     */
    private function serviceMatchesOperation(object $service, object $operation, ServiceMutationType $type): bool
    {
        if ((int) $service->mutation_generation !== (int) $operation->operation_generation
            || (int) $service->remote_identity_generation !== (int) $operation->target_remote_identity_generation
            || (int) $service->lifecycle_version !== (int) $operation->target_lifecycle_version
            || $service->remote_deleted_at !== null
            || $service->lifecycle_state === 'retired'
            || (int) $service->service_target_id !== (int) $operation->service_target_id
            || ! is_string($service->remote_service_id)
            || ! is_string($operation->remote_service_id)
            || ! hash_equals($service->remote_service_id, $operation->remote_service_id)) {
            return false;
        }

        return match ($type) {
            ServiceMutationType::Suspend => $service->lifecycle_state === 'active',
            ServiceMutationType::Activate => $service->lifecycle_state === 'suspended',
            ServiceMutationType::Delete,
            ServiceMutationType::ResetUsage,
            ServiceMutationType::RotateSubscriptionLink,
            ServiceMutationType::Renew,
            ServiceMutationType::AddData,
            ServiceMutationType::AddDays,
            ServiceMutationType::AddDataDays => in_array($service->lifecycle_state, ['active', 'suspended'], true),
        };
    }

    /** @param MutationRecoveryOperation $operation */
    private function recordEvent(Connection $connection, object $operation, string $resultCode, string $createdAt): void
    {
        $connection->table('provisioning_remote_effect_events')->insert([
            'provisioning_operation_id' => (int) $operation->id,
            'event_type' => $operation->state,
            'state_version' => (int) $operation->state_version,
            'route_selection_id' => null,
            'service_target_id' => $operation->service_target_id === null ? null : (int) $operation->service_target_id,
            'remote_service_id' => $operation->remote_service_id,
            'result_code' => $resultCode,
            'correlation_id' => $operation->correlation_id,
            'created_at' => $createdAt,
        ]);
    }

    /** @return list<string> */
    private function operationColumns(): array
    {
        return [
            'id', 'operation_key', 'operation_type', 'service_subscription_id', 'state', 'state_version', 'correlation_id',
            'service_target_id', 'remote_service_id', 'remote_effect_started_at', 'updated_at', 'operation_generation',
            'target_remote_identity_generation', 'target_lifecycle_version',
        ];
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
