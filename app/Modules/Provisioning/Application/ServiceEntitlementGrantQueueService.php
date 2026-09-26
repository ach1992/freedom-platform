<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Modules\Provisioning\Domain\ServiceMutationType;
use App\Shared\Application\Clock;
use App\Shared\Application\OutboxPublisher;
use App\Shared\Application\SafeOutboxPayload;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-type ServiceRow object{id:int|string,public_id:string,order_id:int|string,order_item_id:int|string,user_id:int|string,service_target_id:int|string|null,remote_service_id:?string,provisioned_at:?string,lifecycle_state:string,lifecycle_version:int|string,remote_identity_generation:int|string,mutation_generation:int|string,remote_deleted_at:?string}
 * @phpstan-type BatchRow object{id:int|string,public_id:string,source_type:string,actor_administrator_id:int|string,reason_code:string,reason:string,data_bytes:int|string|null,duration_days:int|string|null,state:string,correlation_id:string,expires_at:string}
 * @phpstan-type ItemRow object{id:int|string,public_id:string,service_entitlement_grant_batch_id:int|string,service_subscription_id:int|string,service_target_id:int|string,source_mutation_generation:int|string,target_remote_identity_generation:int|string,target_lifecycle_version:int|string,request_key_hash:string,state:string,attempt_count:int|string,provisioning_operation_id:int|string|null}
 * @phpstan-type OperationRow object{id:int|string,public_id:string,operation_type:string,service_subscription_id:int|string,state:string,state_version:int|string,operation_generation:int|string,target_remote_identity_generation:int|string,target_lifecycle_version:int|string,request_key_hash:?string}
 */
final readonly class ServiceEntitlementGrantQueueService
{
    private const PERMISSION = 'services.grant_batch';

    private const QUEUE_AUTHORITY = 'service_entitlement_grant_queue_v1';

    private const BATCH_AUTHORITY = 'service_entitlement_grant_batch_v1';

    /** @var list<string> */
    private const TERMINAL_STATES = ['succeeded', 'failed_final', 'compensated'];

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private OutboxPublisher $outbox,
        private AdministratorPermissionAuthorizer $authorizer,
        private ServiceOperationalAuthorityGuard $authority,
        private ServiceOperationalDatabaseCapability $databaseCapability,
    ) {}

    /** @requirement SVC-012 ADM-002 ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 QUA-004 */
    public function queueItem(string $itemPublicId, ServiceOperationalContext $context): ServiceMutationReceipt
    {
        $this->authority->assertFinalized();
        $this->authorizer->authorize($context->actorAdministratorId, self::PERMISSION);
        if (! Str::isUlid($itemPublicId)) {
            throw new DomainException('Service entitlement grant item public ID is invalid.');
        }

        /** @var object{service_subscription_id:int|string,service_entitlement_grant_batch_id:int|string}|null $locator */
        $locator = $this->database->connection()->table('service_entitlement_grant_items')
            ->where('public_id', $itemPublicId)
            ->first(['service_subscription_id', 'service_entitlement_grant_batch_id']);
        if ($locator === null) {
            throw new DomainException('Service entitlement grant item does not exist.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use ($itemPublicId, $context, $locator): ServiceMutationReceipt {
            $service = $this->lockedService($connection, $this->positiveDatabaseInt($locator->service_subscription_id, 'Service Subscription ID'));

            /** @var BatchRow|null $batch */
            $batch = $connection->table('service_entitlement_grant_batches')
                ->where('id', (int) $locator->service_entitlement_grant_batch_id)
                ->lockForUpdate()
                ->first([
                    'id', 'public_id', 'source_type', 'actor_administrator_id', 'reason_code', 'reason',
                    'data_bytes', 'duration_days', 'state', 'correlation_id', 'expires_at',
                ]);
            if ($batch === null) {
                throw new RuntimeException('Service entitlement grant batch evidence is missing.');
            }

            /** @var ItemRow|null $item */
            $item = $connection->table('service_entitlement_grant_items')
                ->where('public_id', $itemPublicId)
                ->where('service_subscription_id', (int) $service->id)
                ->where('service_entitlement_grant_batch_id', (int) $batch->id)
                ->lockForUpdate()
                ->first([
                    'id', 'public_id', 'service_entitlement_grant_batch_id', 'service_subscription_id', 'service_target_id',
                    'source_mutation_generation', 'target_remote_identity_generation', 'target_lifecycle_version',
                    'request_key_hash', 'state', 'attempt_count', 'provisioning_operation_id',
                ]);
            if ($item === null) {
                throw new DomainException('Service entitlement grant item changed before queueing.');
            }
            $this->assertBatchContext($batch, $context);

            if ($item->provisioning_operation_id !== null) {
                $operation = $this->operationById($connection, (int) $item->provisioning_operation_id, true);
                if ((int) $operation->service_subscription_id !== (int) $service->id) {
                    throw new RuntimeException('Service entitlement grant replay operation does not match its Service.');
                }

                return $this->receipt($service, $operation, true);
            }
            if (! in_array($item->state, ['pending', 'failed'], true)) {
                throw new DomainException('Service entitlement grant item cannot be queued from its current state.');
            }
            if ($batch->state !== 'active') {
                throw new DomainException('Service entitlement grant batch is not active.');
            }
            if ($this->timestamp() >= (string) $batch->expires_at) {
                throw new DomainException('Service entitlement grant preview expired before queueing.');
            }

            $type = $this->grantType($batch);
            $this->assertServiceSnapshot($service, $item);

            $blockingDelivery = $connection->table('service_delivery_effects')
                ->where('blocking_service_subscription_id', (int) $service->id)
                ->first(['id']);
            if ($blockingDelivery !== null) {
                throw new DomainException('Service entitlement grant is blocked by an in-flight, uncertain, or provider-directed delivery boundary.');
            }

            $active = $connection->table('provisioning_operations')
                ->where('service_subscription_id', (int) $service->id)
                ->where('operation_type', '<>', 'initial_provision')
                ->whereNotIn('state', self::TERMINAL_STATES)
                ->lockForUpdate()
                ->first(['id']);
            if ($active !== null) {
                throw new DomainException('Service has an unresolved mutation operation.');
            }

            $generation = $this->nonNegativeDatabaseInt($service->mutation_generation, 'Service mutation generation') + 1;
            if ($generation !== $this->nonNegativeDatabaseInt($item->source_mutation_generation, 'Frozen mutation generation') + 1) {
                throw new DomainException('Service changed after entitlement grant preview; create a fresh preview.');
            }
            $remoteGeneration = $this->positiveDatabaseInt($service->remote_identity_generation, 'Remote identity generation');
            $lifecycleVersion = $this->nonNegativeDatabaseInt($service->lifecycle_version, 'Service lifecycle version');
            $timestamp = $this->timestamp();

            $this->setAuthorities(
                $connection,
                (int) $batch->id,
                (int) $item->id,
                $generation,
                $item->request_key_hash,
                $batch->correlation_id,
            );
            try {
                $updated = $connection->table('service_subscriptions')
                    ->where('id', (int) $service->id)
                    ->where('mutation_generation', $generation - 1)
                    ->where('remote_identity_generation', $remoteGeneration)
                    ->where('lifecycle_version', $lifecycleVersion)
                    ->update([
                        'mutation_generation' => $generation,
                        'updated_at' => $timestamp,
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Service entitlement grant generation claim lost its authority.');
                }

                $operationPublicId = (string) Str::ulid();
                $operationId = (int) $connection->table('provisioning_operations')->insertGetId([
                    'public_id' => $operationPublicId,
                    'operation_key' => 'service-mutation:'.$service->public_id.':'.$generation.':'.$type->value,
                    'operation_type' => $type->value,
                    'order_id' => $this->positiveDatabaseInt($service->order_id, 'Order ID'),
                    'order_item_id' => $this->positiveDatabaseInt($service->order_item_id, 'Order Item ID'),
                    'service_subscription_id' => (int) $service->id,
                    'user_id' => $this->positiveDatabaseInt($service->user_id, 'User ID'),
                    'state' => ProvisioningState::Queued->value,
                    'state_version' => 1,
                    'correlation_id' => $batch->correlation_id,
                    'service_target_id' => $this->positiveDatabaseInt($service->service_target_id, 'Service target ID'),
                    'remote_service_id' => $service->remote_service_id,
                    'operation_generation' => $generation,
                    'target_remote_identity_generation' => $remoteGeneration,
                    'target_lifecycle_version' => $lifecycleVersion,
                    'request_key_hash' => $item->request_key_hash,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                $connection->table('service_entitlement_grant_authorities')->insert([
                    'public_id' => (string) Str::ulid(),
                    'service_entitlement_grant_item_id' => (int) $item->id,
                    'provisioning_operation_id' => $operationId,
                    'service_subscription_id' => (int) $service->id,
                    'actor_administrator_id' => (int) $batch->actor_administrator_id,
                    'source_type' => $batch->source_type,
                    'reason_code' => $batch->reason_code,
                    'reason' => $batch->reason,
                    'action' => $type->value,
                    'duration_days' => $batch->duration_days,
                    'data_bytes' => $batch->data_bytes,
                    'quoted_service_target_id' => (int) $service->service_target_id,
                    'quoted_remote_identity_generation' => $remoteGeneration,
                    'quoted_lifecycle_version' => $lifecycleVersion,
                    'remote_snapshot_hash' => null,
                    'target_expires_at' => null,
                    'target_data_limit_bytes' => null,
                    'targets_resolved_at' => null,
                    'created_at' => $timestamp,
                ]);

                $itemUpdated = $connection->table('service_entitlement_grant_items')
                    ->where('id', (int) $item->id)
                    ->whereNull('provisioning_operation_id')
                    ->whereIn('state', ['pending', 'failed'])
                    ->update([
                        'state' => 'queued',
                        'attempt_count' => (int) $item->attempt_count + 1,
                        'provisioning_operation_id' => $operationId,
                        'result_code' => null,
                        'updated_at' => $timestamp,
                    ]);
                if ($itemUpdated !== 1) {
                    throw new RuntimeException('Service entitlement grant item lost its queue authority.');
                }

                $operation = $this->operationById($connection, $operationId, false);
                $this->outbox->publish(
                    (string) Str::uuid(),
                    ServiceMutationQueueService::OUTBOX_EVENT_KEY_PREFIX.$operation->public_id,
                    ServiceMutationQueueService::OUTBOX_EVENT_TYPE,
                    ServiceMutationQueueService::OUTBOX_AGGREGATE_TYPE,
                    $operation->public_id,
                    new SafeOutboxPayload([
                        'provisioning_operation_public_id' => $operation->public_id,
                    ]),
                    $batch->correlation_id,
                    ServiceMutationQueueService::OUTBOX_CONTRACT_VERSION,
                );

                return $this->receipt($service, $operation, false);
            } finally {
                $this->clearAuthorities($connection);
            }
        }, 3);
    }

    /** @return ServiceRow */
    private function lockedService(Connection $connection, int $id): object
    {
        /** @var ServiceRow|null $service */
        $service = $connection->table('service_subscriptions')
            ->where('id', $id)
            ->lockForUpdate()
            ->first([
                'id', 'public_id', 'order_id', 'order_item_id', 'user_id', 'service_target_id',
                'remote_service_id', 'provisioned_at', 'lifecycle_state', 'lifecycle_version',
                'remote_identity_generation', 'mutation_generation', 'remote_deleted_at',
            ]);
        if ($service === null) {
            throw new DomainException('Service Subscription does not exist.');
        }

        return $service;
    }

    /** @return OperationRow */
    private function operationById(Connection $connection, int $id, bool $lock): object
    {
        $query = $connection->table('provisioning_operations')->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var OperationRow|null $operation */
        $operation = $query->first([
            'id', 'public_id', 'operation_type', 'service_subscription_id', 'state', 'state_version',
            'operation_generation', 'target_remote_identity_generation', 'target_lifecycle_version', 'request_key_hash',
        ]);
        if ($operation === null) {
            throw new RuntimeException('Service entitlement grant Operation evidence is missing.');
        }

        return $operation;
    }

    /** @param BatchRow $batch */
    private function assertBatchContext(object $batch, ServiceOperationalContext $context): void
    {
        if ((int) $batch->actor_administrator_id !== $context->actorAdministratorId
            || ! hash_equals($batch->reason_code, $context->reasonCode)
            || ! hash_equals($batch->reason, $context->reason)) {
            throw new DomainException('Service entitlement grant batch administrator/reason context does not match.');
        }
    }

    /**
     * @param  ServiceRow  $service
     * @param  ItemRow  $item
     */
    private function assertServiceSnapshot(object $service, object $item): void
    {
        if ($service->remote_deleted_at !== null
            || $service->lifecycle_state !== 'active'
            || $service->provisioned_at === null
            || $service->service_target_id === null
            || ! is_string($service->remote_service_id)
            || $service->remote_service_id === '') {
            throw new DomainException('Only an active fully provisioned Service can receive an administrative entitlement grant.');
        }
        if ((int) $service->service_target_id !== (int) $item->service_target_id
            || $this->nonNegativeDatabaseInt($service->mutation_generation, 'Service mutation generation')
                !== $this->nonNegativeDatabaseInt($item->source_mutation_generation, 'Frozen mutation generation')
            || $this->positiveDatabaseInt($service->remote_identity_generation, 'Remote identity generation')
                !== $this->positiveDatabaseInt($item->target_remote_identity_generation, 'Frozen remote identity generation')
            || $this->nonNegativeDatabaseInt($service->lifecycle_version, 'Service lifecycle version')
                !== $this->nonNegativeDatabaseInt($item->target_lifecycle_version, 'Frozen lifecycle version')) {
            throw new DomainException('Service changed after entitlement grant preview; create a fresh preview.');
        }
    }

    /** @param BatchRow $batch */
    private function grantType(object $batch): ServiceMutationType
    {
        $hasData = $batch->data_bytes !== null && (int) $batch->data_bytes > 0;
        $hasDays = $batch->duration_days !== null && (int) $batch->duration_days > 0;

        return match (true) {
            $hasData && $hasDays => ServiceMutationType::GrantDataDays,
            $hasData => ServiceMutationType::GrantData,
            $hasDays => ServiceMutationType::GrantDays,
            default => throw new RuntimeException('Stored Service entitlement grant package is invalid.'),
        };
    }

    /**
     * @param  ServiceRow  $service
     * @param  OperationRow  $operation
     */
    private function receipt(object $service, object $operation, bool $replayed): ServiceMutationReceipt
    {
        $type = ServiceMutationType::tryFrom($operation->operation_type)
            ?? throw new RuntimeException('Stored Service mutation type is invalid.');
        $state = ProvisioningState::tryFrom($operation->state)
            ?? throw new RuntimeException('Stored Service mutation state is invalid.');

        return new ServiceMutationReceipt(
            $service->public_id,
            $operation->public_id,
            $type,
            $this->nonNegativeDatabaseInt($operation->operation_generation, 'Operation generation'),
            $state,
            $this->positiveDatabaseInt($operation->state_version, 'Operation state version'),
            $replayed,
        );
    }

    private function setAuthorities(
        Connection $connection,
        int $batchId,
        int $itemId,
        int $generation,
        string $requestHash,
        string $correlationId,
    ): void {
        $this->databaseCapability->apply($connection);
        $connection->statement('SET @app_service_mutation_authority = ?', [self::QUEUE_AUTHORITY]);
        $connection->statement('SET @app_service_mutation_generation = ?', [$generation]);
        $connection->statement('SET @app_service_mutation_request_hash = ?', [$requestHash]);
        $connection->statement('SET @app_service_mutation_correlation_id = ?', [$correlationId]);
        $connection->statement('SET @app_service_entitlement_grant_item_id = ?', [$itemId]);
        $connection->statement('SET @app_service_entitlement_grant_batch_authority = ?', [self::BATCH_AUTHORITY]);
        $connection->statement('SET @app_service_entitlement_grant_batch_id = ?', [$batchId]);
    }

    private function clearAuthorities(Connection $connection): void
    {
        $connection->statement('SET @app_service_mutation_authority = NULL');
        $connection->statement('SET @app_service_mutation_generation = NULL');
        $connection->statement('SET @app_service_mutation_request_hash = NULL');
        $connection->statement('SET @app_service_mutation_correlation_id = NULL');
        $this->databaseCapability->clear($connection);
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function positiveDatabaseInt(int|string|null $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }

    private function nonNegativeDatabaseInt(int|string $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 0) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }
}
