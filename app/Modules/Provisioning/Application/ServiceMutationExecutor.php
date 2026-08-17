<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Panels\Application\Contracts\PanelAdapter;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\Contracts\PanelOperationResult;
use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Modules\Provisioning\Domain\ServiceMutationType;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type MutationOperation object{id:int|string,public_id:string,operation_key:string,operation_type:string,service_subscription_id:int|string,state:string,state_version:int|string,correlation_id:string,effect_fence_key:?string,service_target_id:int|string|null,attempt_count:int|string,last_result_code:?string,last_result_message:?string,remote_service_id:?string,remote_effect_started_at:?string,remote_effect_completed_at:?string,operation_generation:int|string,target_remote_identity_generation:int|string,target_lifecycle_version:int|string,request_key_hash:?string}
 * @phpstan-type MutationService object{id:int|string,public_id:string,service_target_id:int|string|null,remote_service_id:?string,lifecycle_state:string,lifecycle_version:int|string,remote_identity_generation:int|string,mutation_generation:int|string,remote_deleted_at:?string}
 */
final readonly class ServiceMutationExecutor
{
    private const EFFECT_AUTHORITY = 'service_mutation_effect_v1';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private ProvisioningPanelAdapterResolver $adapters,
    ) {}

    /** @requirement SVC-004 PRV-001 PRV-002 PRV-003 DAT-003 SEC-002 SEC-008 QUA-004 */
    public function execute(string $operationPublicId): ServiceMutationReceipt
    {
        $operation = $this->operationByPublicId($operationPublicId);
        $type = $this->mutationType($operation->operation_type);
        $state = $this->state($operation->state);

        if ($this->isTerminal($state)) {
            return $this->receipt($operation, $type, true);
        }
        if ($state === ProvisioningState::UncertainRemoteResult || $state === ProvisioningState::NeedsReview) {
            throw new DomainException('Service mutation requires reconciliation before automatic execution can continue.');
        }
        if ($state === ProvisioningState::Running) {
            throw new DomainException('Service mutation is already running and requires reconciliation before another provider attempt.');
        }
        if (! in_array($state, [ProvisioningState::Queued, ProvisioningState::RetryScheduled], true)) {
            throw new DomainException('Service mutation operation is not executable automatically.');
        }

        $operation = $this->claim($operation);
        $service = $this->serviceById((int) $operation->service_subscription_id);
        if (! $this->serviceMatchesOperation($service, $operation, $type)) {
            return $this->finalize(
                $operation,
                $type,
                ProvisioningState::NeedsReview,
                'stale_service_after_claim',
                'Service lifecycle or remote identity changed after the effect claim.',
            );
        }

        try {
            $targetId = $this->positiveDatabaseInt($operation->service_target_id, 'Service target ID');
            $adapter = $this->adapters->resolve($targetId);
        } catch (Throwable) {
            return $this->finalize(
                $operation,
                $type,
                ProvisioningState::RetryScheduled,
                'panel_runtime_unavailable',
                'Panel runtime is unavailable for Service mutation.',
            );
        }

        if (! $adapter->capabilities()->supports($type->panelCapability())) {
            return $this->finalize(
                $operation,
                $type,
                ProvisioningState::FailedFinal,
                'panel_capability_missing',
                'Panel does not support the requested Service mutation.',
            );
        }

        $connection = $this->database->connection();
        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('Service mutation provider boundary cannot run inside a database transaction.');
        }

        $operation = $this->markProviderBoundary($operation, $type);
        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('Service mutation provider boundary cannot run inside a database transaction.');
        }

        try {
            $result = $this->invoke(
                $adapter,
                $type,
                $this->requiredString($operation->effect_fence_key, 'Service mutation effect fence'),
                $this->requiredString($operation->remote_service_id, 'Remote Service ID'),
            );
        } catch (Throwable) {
            return $this->finalize(
                $operation,
                $type,
                ProvisioningState::UncertainRemoteResult,
                'remote_effect_exception',
                'Panel mutation result is uncertain after a provider exception.',
            );
        }

        return $this->applyResult($operation, $type, $result);
    }

    /** @param MutationOperation $locator @return MutationOperation */
    private function claim(object $locator): object
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($locator): object {
            $locked = $this->operationById($connection, (int) $locator->id, true);
            $state = $this->state($locked->state);
            if ($state === ProvisioningState::Running) {
                throw new DomainException('Service mutation remote-effect authority is already claimed.');
            }
            if (! in_array($state, [ProvisioningState::Queued, ProvisioningState::RetryScheduled], true)) {
                throw new DomainException('Service mutation cannot acquire remote-effect authority.');
            }

            $type = $this->mutationType($locked->operation_type);
            $service = $this->serviceByIdOn($connection, (int) $locked->service_subscription_id, true);
            if (! $this->serviceMatchesOperation($service, $locked, $type)) {
                throw new DomainException('Service mutation lifecycle or remote identity is no longer authoritative.');
            }

            $now = $this->timestamp();
            $fence = $locked->effect_fence_key ?? hash('sha256', 'service-mutation-effect:'.$locked->operation_key);
            $this->setEffectAuthority($connection, $locked);
            try {
                $updated = $connection->table('provisioning_operations')
                    ->where('id', (int) $locked->id)
                    ->where('state', $state->value)
                    ->where('state_version', (int) $locked->state_version)
                    ->whereNull('remote_effect_started_at')
                    ->update([
                        'state' => ProvisioningState::Running->value,
                        'state_version' => (int) $locked->state_version + 1,
                        'effect_fence_key' => $fence,
                        'attempt_count' => (int) $locked->attempt_count + 1,
                        'remote_effect_completed_at' => null,
                        'updated_at' => $now,
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Service mutation remote-effect claim lost its state.');
                }
                $next = $this->operationById($connection, (int) $locked->id, true);
                $this->recordEvent($connection, $next, 'claimed', null);

                return $next;
            } finally {
                $this->clearEffectAuthority($connection);
            }
        }, 3);
    }

    /** @param MutationOperation $locator @return MutationOperation */
    private function markProviderBoundary(object $locator, ServiceMutationType $type): object
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($locator, $type): object {
            $operation = $this->operationById($connection, (int) $locator->id, true);
            if ($this->state($operation->state) !== ProvisioningState::Running
                || $operation->remote_effect_started_at !== null
                || $operation->remote_effect_completed_at !== null) {
                throw new DomainException('Service mutation provider boundary cannot be entered from the current state.');
            }

            $service = $this->serviceByIdOn($connection, (int) $operation->service_subscription_id, true);
            if (! $this->serviceMatchesOperation($service, $operation, $type)) {
                throw new DomainException('Service mutation lifecycle or remote identity is no longer authoritative at provider boundary.');
            }

            $now = $this->timestamp();
            $this->setEffectAuthority($connection, $operation);
            try {
                $updated = $connection->table('provisioning_operations')
                    ->where('id', (int) $operation->id)
                    ->where('state', ProvisioningState::Running->value)
                    ->where('state_version', (int) $operation->state_version)
                    ->whereNull('remote_effect_started_at')
                    ->update([
                        'state_version' => (int) $operation->state_version + 1,
                        'remote_effect_started_at' => $now,
                        'updated_at' => $now,
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Service mutation provider boundary lost its authority.');
                }

                return $this->operationById($connection, (int) $operation->id, true);
            } finally {
                $this->clearEffectAuthority($connection);
            }
        }, 3);
    }

    /** @param MutationOperation $operation */
    private function applyResult(object $operation, ServiceMutationType $type, PanelOperationResult $result): ServiceMutationReceipt
    {
        $state = match ($result->outcome) {
            PanelOperationOutcome::Success => ProvisioningState::Succeeded,
            PanelOperationOutcome::DefinitiveFailure => ProvisioningState::FailedFinal,
            PanelOperationOutcome::RetryableFailure, PanelOperationOutcome::UncertainResult => ProvisioningState::UncertainRemoteResult,
        };
        $fallbackCode = match ($result->outcome) {
            PanelOperationOutcome::Success => 'panel_success',
            PanelOperationOutcome::DefinitiveFailure => 'panel_definitive_failure',
            PanelOperationOutcome::RetryableFailure => 'panel_retryable_after_boundary',
            PanelOperationOutcome::UncertainResult => 'panel_uncertain_result',
        };
        $fallbackMessage = $result->outcome === PanelOperationOutcome::RetryableFailure
            ? 'Panel reported a retryable outcome after provider boundary; remote result is uncertain.'
            : 'Panel Service mutation completed with a normalized result.';

        return $this->finalize(
            $operation,
            $type,
            $state,
            $result->providerCode ?? $fallbackCode,
            $result->safeMessage ?? $fallbackMessage,
        );
    }

    /** @param MutationOperation $locator */
    private function finalize(
        object $locator,
        ServiceMutationType $type,
        ProvisioningState $state,
        string $resultCode,
        string $safeMessage,
    ): ServiceMutationReceipt {
        return $this->database->connection()->transaction(function (Connection $connection) use (
            $locator,
            $type,
            $state,
            $resultCode,
            $safeMessage,
        ): ServiceMutationReceipt {
            $operation = $this->operationById($connection, (int) $locator->id, true);
            $stored = $this->state($operation->state);
            if ($this->isTerminal($stored)) {
                return $this->receipt($operation, $type, true);
            }
            if ($stored !== ProvisioningState::Running) {
                throw new DomainException('Service mutation result cannot finalize from its current state.');
            }

            $service = $this->serviceByIdOn($connection, (int) $operation->service_subscription_id, true);
            $authoritative = $this->serviceMatchesOperation($service, $operation, $type);
            if (! $authoritative) {
                $state = ProvisioningState::NeedsReview;
                $resultCode = 'stale_service_at_finalize';
                $safeMessage = 'Service lifecycle or remote identity changed before finalization.';
            }

            $now = $this->timestamp();
            $completedAt = $operation->remote_effect_started_at === null ? null : $now;
            $this->setEffectAuthority($connection, $operation);
            try {
                $updated = $connection->table('provisioning_operations')
                    ->where('id', (int) $operation->id)
                    ->where('state', ProvisioningState::Running->value)
                    ->where('state_version', (int) $operation->state_version)
                    ->update([
                        'state' => $state->value,
                        'state_version' => (int) $operation->state_version + 1,
                        'last_result_code' => $this->resultCode($resultCode),
                        'last_result_message' => $this->safeMessage($safeMessage),
                        'remote_effect_completed_at' => $completedAt,
                        'updated_at' => $now,
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Service mutation finalization lost its state.');
                }

                $next = $this->operationById($connection, (int) $operation->id, true);
                if ($authoritative && $state === ProvisioningState::Succeeded) {
                    $this->applySuccessfulLifecycleTransition($connection, $service, $next, $type, $now);
                }
                $this->recordEvent($connection, $next, $state->value, $this->resultCode($resultCode));

                return $this->receipt($next, $type, false);
            } finally {
                $this->clearEffectAuthority($connection);
            }
        }, 3);
    }

    /** @param MutationService $service @param MutationOperation $operation */
    private function applySuccessfulLifecycleTransition(
        Connection $connection,
        object $service,
        object $operation,
        ServiceMutationType $type,
        string $now,
    ): void {
        if (in_array($type, [ServiceMutationType::ResetUsage, ServiceMutationType::RotateSubscriptionLink], true)) {
            return;
        }

        $expectedStates = match ($type) {
            ServiceMutationType::Suspend => ['active'],
            ServiceMutationType::Activate => ['suspended'],
            ServiceMutationType::Delete => ['active', 'suspended'],
            default => throw new RuntimeException('Unsupported Service lifecycle transition.'),
        };
        $nextState = match ($type) {
            ServiceMutationType::Suspend => 'suspended',
            ServiceMutationType::Activate => 'active',
            ServiceMutationType::Delete => 'retired',
            default => throw new RuntimeException('Unsupported Service lifecycle transition.'),
        };
        $updates = [
            'lifecycle_state' => $nextState,
            'lifecycle_version' => $this->nonNegativeDatabaseInt($service->lifecycle_version, 'Service lifecycle version') + 1,
            'updated_at' => $now,
        ];
        if ($type === ServiceMutationType::Delete) {
            $updates['remote_deleted_at'] = $now;
        }

        $query = $connection->table('service_subscriptions')
            ->where('id', (int) $service->id)
            ->where('mutation_generation', $this->nonNegativeDatabaseInt($operation->operation_generation, 'Operation generation'))
            ->where('remote_identity_generation', $this->positiveDatabaseInt($operation->target_remote_identity_generation, 'Target remote identity generation'))
            ->where('lifecycle_version', $this->nonNegativeDatabaseInt($operation->target_lifecycle_version, 'Target lifecycle version'))
            ->whereIn('lifecycle_state', $expectedStates)
            ->where('service_target_id', $this->positiveDatabaseInt($operation->service_target_id, 'Service target ID'))
            ->where('remote_service_id', $this->requiredString($operation->remote_service_id, 'Remote Service ID'))
            ->whereNull('remote_deleted_at');
        $updated = $query->update($updates);
        if ($updated !== 1) {
            throw new RuntimeException('Service lifecycle transition lost its authoritative generation.');
        }
    }

    /** @param MutationService $service @param MutationOperation $operation */
    private function serviceMatchesOperation(object $service, object $operation, ServiceMutationType $type): bool
    {
        if ($this->nonNegativeDatabaseInt($service->mutation_generation, 'Service mutation generation')
                !== $this->nonNegativeDatabaseInt($operation->operation_generation, 'Operation generation')
            || $this->positiveDatabaseInt($service->remote_identity_generation, 'Remote identity generation')
                !== $this->positiveDatabaseInt($operation->target_remote_identity_generation, 'Target remote identity generation')
            || $this->nonNegativeDatabaseInt($service->lifecycle_version, 'Service lifecycle version')
                !== $this->nonNegativeDatabaseInt($operation->target_lifecycle_version, 'Target lifecycle version')
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
            ServiceMutationType::RotateSubscriptionLink => in_array($service->lifecycle_state, ['active', 'suspended'], true),
        };
    }

    private function invoke(PanelAdapter $adapter, ServiceMutationType $type, string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return match ($type) {
            ServiceMutationType::ResetUsage => $adapter->resetUsage($idempotencyKey, $remoteId),
            ServiceMutationType::Suspend => $adapter->suspend($idempotencyKey, $remoteId),
            ServiceMutationType::Activate => $adapter->activate($idempotencyKey, $remoteId),
            ServiceMutationType::Delete => $adapter->delete($idempotencyKey, $remoteId),
            ServiceMutationType::RotateSubscriptionLink => $adapter->rotateSubscriptionLink($idempotencyKey, $remoteId),
        };
    }

    /** @return MutationOperation */
    private function operationByPublicId(string $publicId): object
    {
        if (! Str::isUlid($publicId)) {
            throw new DomainException('Service mutation operation public ID is invalid.');
        }
        /** @var MutationOperation|null $row */
        $row = $this->database->connection()->table('provisioning_operations')
            ->where('public_id', $publicId)
            ->first($this->operationColumns());
        if ($row === null) {
            throw new DomainException('Service mutation operation does not exist.');
        }
        $this->mutationType($row->operation_type);

        return $row;
    }

    /** @return MutationOperation */
    private function operationById(Connection $connection, int $id, bool $lock): object
    {
        $query = $connection->table('provisioning_operations')->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var MutationOperation|null $row */
        $row = $query->first($this->operationColumns());
        if ($row === null) {
            throw new RuntimeException('Service mutation operation disappeared.');
        }
        $this->mutationType($row->operation_type);

        return $row;
    }

    /** @return MutationService */
    private function serviceById(int $id): object
    {
        return $this->serviceByIdOn($this->database->connection(), $id, false);
    }

    /** @return MutationService */
    private function serviceByIdOn(Connection $connection, int $id, bool $lock): object
    {
        $query = $connection->table('service_subscriptions')->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var MutationService|null $row */
        $row = $query->first([
            'id', 'public_id', 'service_target_id', 'remote_service_id', 'lifecycle_state', 'lifecycle_version',
            'remote_identity_generation', 'mutation_generation', 'remote_deleted_at',
        ]);
        if ($row === null) {
            throw new RuntimeException('Service Subscription disappeared during mutation execution.');
        }

        return $row;
    }

    /** @param MutationOperation $operation */
    private function recordEvent(Connection $connection, object $operation, string $eventType, ?string $resultCode): void
    {
        $connection->table('provisioning_remote_effect_events')->insert([
            'provisioning_operation_id' => (int) $operation->id,
            'event_type' => $eventType,
            'state_version' => (int) $operation->state_version,
            'route_selection_id' => null,
            'service_target_id' => (int) $operation->service_target_id,
            'remote_service_id' => $operation->remote_service_id,
            'result_code' => $resultCode,
            'correlation_id' => $operation->correlation_id,
            'created_at' => $this->timestamp(),
        ]);
    }

    /** @param MutationOperation $operation */
    private function receipt(object $operation, ServiceMutationType $type, bool $replayed): ServiceMutationReceipt
    {
        $service = $this->serviceById((int) $operation->service_subscription_id);

        return new ServiceMutationReceipt(
            $service->public_id,
            $operation->public_id,
            $type,
            $this->nonNegativeDatabaseInt($operation->operation_generation, 'Operation generation'),
            $this->state($operation->state),
            $this->positiveDatabaseInt($operation->state_version, 'Operation state version'),
            $replayed,
        );
    }

    /** @return list<string> */
    private function operationColumns(): array
    {
        return [
            'id', 'public_id', 'operation_key', 'operation_type', 'service_subscription_id', 'state', 'state_version',
            'correlation_id', 'effect_fence_key', 'service_target_id', 'attempt_count', 'last_result_code',
            'last_result_message', 'remote_service_id', 'remote_effect_started_at', 'remote_effect_completed_at',
            'operation_generation', 'target_remote_identity_generation', 'target_lifecycle_version', 'request_key_hash',
        ];
    }

    private function mutationType(string $value): ServiceMutationType
    {
        return ServiceMutationType::tryFrom($value)
            ?? throw new DomainException('Provisioning operation is not a Service mutation.');
    }

    private function state(string $value): ProvisioningState
    {
        return ProvisioningState::tryFrom($value)
            ?? throw new RuntimeException('Stored Service mutation state is invalid.');
    }

    private function isTerminal(ProvisioningState $state): bool
    {
        return in_array($state, [ProvisioningState::Succeeded, ProvisioningState::FailedFinal, ProvisioningState::Compensated], true);
    }

    /** @param MutationOperation $operation */
    private function setEffectAuthority(Connection $connection, object $operation): void
    {
        $connection->statement('SET @app_provisioning_authority = ?', [self::EFFECT_AUTHORITY]);
        $connection->statement('SET @app_provisioning_operation_key = ?', [$operation->operation_key]);
        $connection->statement('SET @app_provisioning_correlation_id = ?', [$operation->correlation_id]);
        $connection->statement('SET @app_service_mutation_generation = ?', [(int) $operation->operation_generation]);
    }

    private function clearEffectAuthority(Connection $connection): void
    {
        $connection->statement('SET @app_provisioning_authority = NULL');
        $connection->statement('SET @app_provisioning_operation_key = NULL');
        $connection->statement('SET @app_provisioning_correlation_id = NULL');
        $connection->statement('SET @app_service_mutation_generation = NULL');
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function resultCode(string $value): string
    {
        $normalized = strtolower(trim($value));
        if (preg_match('/\A[a-z0-9_.:-]{1,64}\z/', $normalized) !== 1) {
            return 'normalized_panel_result';
        }

        return $normalized;
    }

    private function safeMessage(string $value): string
    {
        $normalized = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '');
        if ($normalized === '') {
            return 'Service mutation completed without a provider message.';
        }

        return mb_substr($normalized, 0, 512);
    }

    private function requiredString(?string $value, string $label): string
    {
        if (! is_string($value) || $value === '') {
            throw new RuntimeException($label.' is unavailable.');
        }

        return $value;
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
