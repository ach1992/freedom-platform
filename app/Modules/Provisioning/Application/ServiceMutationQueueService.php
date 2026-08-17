<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Modules\Provisioning\Domain\ServiceMutationType;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-type ServiceRow object{id:int|string,public_id:string,order_id:int|string,order_item_id:int|string,user_id:int|string,service_target_id:int|string|null,remote_service_id:?string,provisioned_at:?string,lifecycle_state:string,lifecycle_version:int|string,remote_identity_generation:int|string,mutation_generation:int|string,remote_deleted_at:?string}
 * @phpstan-type OperationRow object{id:int|string,public_id:string,operation_type:string,service_subscription_id:int|string,state:string,state_version:int|string,operation_generation:int|string,target_remote_identity_generation:int|string,target_lifecycle_version:int|string,request_key_hash:?string}
 */
final readonly class ServiceMutationQueueService
{
    private const QUEUE_AUTHORITY = 'service_mutation_queue_v1';

    /** @var list<string> */
    private const TERMINAL_STATES = ['succeeded', 'failed_final', 'compensated'];

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    /** @requirement SVC-004 PRV-002 PRV-003 DAT-003 SEC-002 SEC-008 QUA-004 */
    public function queue(
        string $servicePublicId,
        ServiceMutationType $type,
        string $requestKey,
        string $correlationId,
    ): ServiceMutationReceipt {
        $this->assertUlid($servicePublicId, 'Service public ID');
        $requestKeyHash = $this->requestKeyHash($requestKey);
        $this->assertToken($correlationId, 'Service mutation correlation ID', 8, 64);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $servicePublicId,
            $type,
            $requestKeyHash,
            $correlationId,
        ): ServiceMutationReceipt {
            $service = $this->lockedService($connection, $servicePublicId);

            $replayed = $this->operationByRequestHash($connection, (int) $service->id, $requestKeyHash, true);
            if ($replayed !== null) {
                $storedType = ServiceMutationType::tryFrom($replayed->operation_type)
                    ?? throw new RuntimeException('Stored Service mutation type is invalid.');
                if ($storedType !== $type) {
                    throw new DomainException('Service mutation request key was already used for a different operation.');
                }

                return $this->receipt($service, $replayed, true);
            }

            $this->assertServiceMutable($service, $type);
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
            $remoteIdentityGeneration = $this->positiveDatabaseInt($service->remote_identity_generation, 'Remote identity generation');
            $lifecycleVersion = $this->nonNegativeDatabaseInt($service->lifecycle_version, 'Service lifecycle version');
            $timestamp = $this->timestamp();

            $this->setQueueAuthority($connection, $generation, $requestKeyHash, $correlationId);
            try {
                $updated = $connection->table('service_subscriptions')
                    ->where('id', (int) $service->id)
                    ->where('mutation_generation', $generation - 1)
                    ->where('remote_identity_generation', $remoteIdentityGeneration)
                    ->where('lifecycle_version', $lifecycleVersion)
                    ->update([
                        'mutation_generation' => $generation,
                        'updated_at' => $timestamp,
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Service mutation generation claim lost its authority.');
                }

                $operationPublicId = (string) Str::ulid();
                $operationKey = $this->operationKey($service->public_id, $type, $generation);
                $operationId = (int) $connection->table('provisioning_operations')->insertGetId([
                    'public_id' => $operationPublicId,
                    'operation_key' => $operationKey,
                    'operation_type' => $type->value,
                    'order_id' => $this->positiveDatabaseInt($service->order_id, 'Order ID'),
                    'order_item_id' => $this->positiveDatabaseInt($service->order_item_id, 'Order Item ID'),
                    'service_subscription_id' => $this->positiveDatabaseInt($service->id, 'Service Subscription ID'),
                    'user_id' => $this->positiveDatabaseInt($service->user_id, 'User ID'),
                    'state' => ProvisioningState::Queued->value,
                    'state_version' => 1,
                    'correlation_id' => $correlationId,
                    'service_target_id' => $this->positiveDatabaseInt($service->service_target_id, 'Service target ID'),
                    'remote_service_id' => $service->remote_service_id,
                    'operation_generation' => $generation,
                    'target_remote_identity_generation' => $remoteIdentityGeneration,
                    'target_lifecycle_version' => $lifecycleVersion,
                    'request_key_hash' => $requestKeyHash,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                /** @var OperationRow|null $operation */
                $operation = $connection->table('provisioning_operations')->where('id', $operationId)->first([
                    'id', 'public_id', 'operation_type', 'service_subscription_id', 'state', 'state_version',
                    'operation_generation', 'target_remote_identity_generation', 'target_lifecycle_version', 'request_key_hash',
                ]);
                if ($operation === null) {
                    throw new RuntimeException('Service mutation operation disappeared after creation.');
                }

                $service->mutation_generation = $generation;

                return $this->receipt($service, $operation, false);
            } finally {
                $this->clearQueueAuthority($connection);
            }
        }, 3);
    }

    /** @return ServiceRow */
    private function lockedService(Connection $connection, string $publicId): object
    {
        /** @var ServiceRow|null $row */
        $row = $connection->table('service_subscriptions')
            ->where('public_id', $publicId)
            ->lockForUpdate()
            ->first([
                'id', 'public_id', 'order_id', 'order_item_id', 'user_id', 'service_target_id',
                'remote_service_id', 'provisioned_at', 'lifecycle_state', 'lifecycle_version',
                'remote_identity_generation', 'mutation_generation', 'remote_deleted_at',
            ]);
        if ($row === null) {
            throw new DomainException('Service Subscription does not exist.');
        }

        return $row;
    }

    /** @return OperationRow|null */
    private function operationByRequestHash(Connection $connection, int $serviceId, string $requestKeyHash, bool $lock): ?object
    {
        $query = $connection->table('provisioning_operations')
            ->where('service_subscription_id', $serviceId)
            ->where('request_key_hash', $requestKeyHash);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var OperationRow|null $row */
        $row = $query->first([
            'id', 'public_id', 'operation_type', 'service_subscription_id', 'state', 'state_version',
            'operation_generation', 'target_remote_identity_generation', 'target_lifecycle_version', 'request_key_hash',
        ]);

        return $row;
    }

    /** @param ServiceRow $service */
    private function assertServiceMutable(object $service, ServiceMutationType $type): void
    {
        if ($service->remote_deleted_at !== null || $service->lifecycle_state === 'retired') {
            throw new DomainException('Retired Service Subscription cannot accept new mutations.');
        }
        if (! in_array($service->lifecycle_state, ['active', 'suspended'], true)) {
            throw new RuntimeException('Stored Service lifecycle state is invalid.');
        }
        if ($type === ServiceMutationType::Suspend && $service->lifecycle_state !== 'active') {
            throw new DomainException('Only an active Service Subscription can be suspended.');
        }
        if ($type === ServiceMutationType::Activate && $service->lifecycle_state !== 'suspended') {
            throw new DomainException('Only a suspended Service Subscription can be activated.');
        }
        if ($service->service_target_id === null || (int) $service->service_target_id < 1
            || ! is_string($service->remote_service_id) || $service->remote_service_id === ''
            || $service->provisioned_at === null
            || $this->positiveDatabaseInt($service->remote_identity_generation, 'Remote identity generation') < 1) {
            throw new DomainException('Service Subscription is not fully provisioned for remote mutation.');
        }
    }

    /**
     * @param ServiceRow $service
     * @param OperationRow $operation
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

    private function operationKey(string $servicePublicId, ServiceMutationType $type, int $generation): string
    {
        return 'service-mutation:'.$servicePublicId.':'.$generation.':'.$type->value;
    }

    private function requestKeyHash(string $requestKey): string
    {
        $this->assertToken($requestKey, 'Service mutation request key', 8, 128);

        return hash('sha256', $requestKey);
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function setQueueAuthority(Connection $connection, int $generation, string $requestHash, string $correlationId): void
    {
        $connection->statement('SET @app_service_mutation_authority = ?', [self::QUEUE_AUTHORITY]);
        $connection->statement('SET @app_service_mutation_generation = ?', [$generation]);
        $connection->statement('SET @app_service_mutation_request_hash = ?', [$requestHash]);
        $connection->statement('SET @app_service_mutation_correlation_id = ?', [$correlationId]);
    }

    private function clearQueueAuthority(Connection $connection): void
    {
        $connection->statement('SET @app_service_mutation_authority = NULL');
        $connection->statement('SET @app_service_mutation_generation = NULL');
        $connection->statement('SET @app_service_mutation_request_hash = NULL');
        $connection->statement('SET @app_service_mutation_correlation_id = NULL');
    }

    private function assertUlid(string $value, string $label): void
    {
        if (! Str::isUlid($value)) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        if (strlen($value) < $minimum || strlen($value) > $maximum
            || preg_match('/\A[A-Za-z0-9_.:-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
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
