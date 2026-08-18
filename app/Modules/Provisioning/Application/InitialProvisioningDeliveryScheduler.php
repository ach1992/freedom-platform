<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Modules\Provisioning\Domain\ServiceDeliveryPurpose;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class InitialProvisioningDeliveryScheduler
{
    private const FENCE_AUTHORITY = 'initial_delivery_fence_v1';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private ServiceDeliveryAttemptQueueService $delivery,
    ) {}

    /** @requirement SVC-002 PRV-002 ARCH-004 DAT-003 SEC-002 QUA-004 */
    public function establishFence(string $operationPublicId): void
    {
        $this->assertOperationPublicId($operationPublicId);

        $conflictingInitialAttempt = $this->database->connection()->transaction(
            function (Connection $connection) use ($operationPublicId): bool {
                $row = $this->operationService($connection, $operationPublicId, true);
                $expectedRequestHash = hash('sha256', 'initial-delivery:'.$operationPublicId);
                /** @var \Illuminate\Support\Collection<int, object{request_key_hash:string,correlation_id:string}> $initialAttempts */
                $initialAttempts = $connection->table('service_delivery_attempts')
                    ->where('service_subscription_id', (int) $row->service_id)
                    ->where('purpose', ServiceDeliveryPurpose::Initial->value)
                    ->lockForUpdate()
                    ->get(['request_key_hash', 'correlation_id']);

                $deterministicAttemptExists = false;
                $conflictingAttemptExists = false;
                foreach ($initialAttempts as $attempt) {
                    if (hash_equals($expectedRequestHash, (string) $attempt->request_key_hash)
                        && hash_equals((string) $row->correlation_id, (string) $attempt->correlation_id)) {
                        $deterministicAttemptExists = true;
                    } else {
                        $conflictingAttemptExists = true;
                    }
                }

                if ($deterministicAttemptExists && ! $conflictingAttemptExists) {
                    $this->releaseFenceRow($connection, $row);

                    return false;
                }

                $existing = $connection->table('service_initial_delivery_fences')
                    ->where('service_subscription_id', (int) $row->service_id)
                    ->lockForUpdate()
                    ->first(['provisioning_operation_id']);
                if ($existing !== null) {
                    if ((int) $existing->provisioning_operation_id !== (int) $row->operation_id) {
                        throw new RuntimeException('Initial delivery scheduling fence is bound to another provisioning operation.');
                    }

                    return $conflictingAttemptExists;
                }

                $this->setFenceAuthority($connection, $row);
                try {
                    $connection->table('service_initial_delivery_fences')->insert([
                        'service_subscription_id' => (int) $row->service_id,
                        'provisioning_operation_id' => (int) $row->operation_id,
                        'created_at' => $this->timestamp(),
                    ]);
                } finally {
                    $this->clearFenceAuthority($connection);
                }

                return $conflictingAttemptExists;
            },
            3,
        );

        if ($conflictingInitialAttempt) {
            throw new RuntimeException('Existing initial Delivery Attempt conflicts with deterministic initial provisioning authority.');
        }
    }

    public function releaseFence(string $operationPublicId): void
    {
        $this->assertOperationPublicId($operationPublicId);

        $this->database->connection()->transaction(function (Connection $connection) use ($operationPublicId): void {
            $this->releaseFenceRow($connection, $this->operationService($connection, $operationPublicId, true));
        }, 3);
    }

    public function schedule(string $operationPublicId, string $correlationId): ServiceDeliveryAttemptReceipt
    {
        $this->assertOperationPublicId($operationPublicId);
        $this->establishFence($operationPublicId);

        /** @var object{service_public_id:string}|null $row */
        $row = $this->database->connection()->table('provisioning_operations as operation')
            ->join('service_subscriptions as service', 'service.id', '=', 'operation.service_subscription_id')
            ->where('operation.public_id', $operationPublicId)
            ->where('operation.operation_type', 'initial_provision')
            ->where('operation.state', ProvisioningState::Succeeded->value)
            ->first(['service.public_id as service_public_id']);
        if ($row === null || ! Str::isUlid($row->service_public_id)) {
            throw new DomainException('Successful initial provisioning Service identity is unavailable for delivery scheduling.');
        }

        $receipt = $this->delivery->queue(
            $row->service_public_id,
            ServiceDeliveryPurpose::Initial,
            'initial-delivery:'.$operationPublicId,
            $correlationId,
        );
        $this->releaseFence($operationPublicId);

        return $receipt;
    }

    /** @return object{operation_id:int|string,service_id:int|string,service_public_id:string,correlation_id:string} */
    private function operationService(Connection $connection, string $operationPublicId, bool $lock): object
    {
        $query = $connection->table('provisioning_operations as operation')
            ->join('service_subscriptions as service', 'service.id', '=', 'operation.service_subscription_id')
            ->where('operation.public_id', $operationPublicId)
            ->where('operation.operation_type', 'initial_provision');
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var object{operation_id:int|string,service_id:int|string,service_public_id:string,correlation_id:string}|null $row */
        $row = $query->first([
            'operation.id as operation_id',
            'operation.correlation_id as correlation_id',
            'service.id as service_id',
            'service.public_id as service_public_id',
        ]);
        if ($row === null || ! Str::isUlid($row->service_public_id)) {
            throw new DomainException('Initial provisioning operation Service identity is unavailable.');
        }

        return $row;
    }

    /** @param object{operation_id:int|string,service_id:int|string,service_public_id:string,correlation_id:string} $row */
    private function releaseFenceRow(Connection $connection, object $row): void
    {
        $fence = $connection->table('service_initial_delivery_fences')
            ->where('service_subscription_id', (int) $row->service_id)
            ->lockForUpdate()
            ->first(['provisioning_operation_id']);
        if ($fence === null) {
            return;
        }
        if ((int) $fence->provisioning_operation_id !== (int) $row->operation_id) {
            throw new RuntimeException('Initial delivery scheduling fence is bound to another provisioning operation.');
        }

        $this->setFenceAuthority($connection, $row);
        try {
            $deleted = $connection->table('service_initial_delivery_fences')
                ->where('service_subscription_id', (int) $row->service_id)
                ->where('provisioning_operation_id', (int) $row->operation_id)
                ->delete();
            if ($deleted !== 1) {
                throw new RuntimeException('Initial delivery scheduling fence could not be released.');
            }
        } finally {
            $this->clearFenceAuthority($connection);
        }
    }

    /** @param object{operation_id:int|string,service_id:int|string,service_public_id:string,correlation_id:string} $row */
    private function setFenceAuthority(Connection $connection, object $row): void
    {
        $connection->statement('SET @app_initial_delivery_fence_authority = ?', [self::FENCE_AUTHORITY]);
        $connection->statement('SET @app_initial_delivery_fence_service_id = ?', [(int) $row->service_id]);
        $connection->statement('SET @app_initial_delivery_fence_operation_id = ?', [(int) $row->operation_id]);
    }

    private function clearFenceAuthority(Connection $connection): void
    {
        $connection->statement('SET @app_initial_delivery_fence_authority = NULL');
        $connection->statement('SET @app_initial_delivery_fence_service_id = NULL');
        $connection->statement('SET @app_initial_delivery_fence_operation_id = NULL');
    }

    private function assertOperationPublicId(string $operationPublicId): void
    {
        if (! Str::isUlid($operationPublicId)) {
            throw new DomainException('Initial provisioning operation public ID is invalid for delivery scheduling.');
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
