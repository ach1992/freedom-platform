<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Modules\Provisioning\Domain\ServiceMutationType;
use App\Shared\Application\Clock;
use App\Shared\Application\OutboxPublisher;
use App\Shared\Application\SafeOutboxPayload;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Queues a zero-price Service reconfiguration without fabricating purchase/payment authority.
 * The immutable reconfiguration preview remains the commercial/destination authority; remote
 * execution continues through the existing Service mutation outbox and executor.
 *
 * @phpstan-type ServiceRow object{id:int|string,public_id:string,order_id:int|string,order_item_id:int|string,user_id:int|string,route_selection_id:int|string|null,service_target_id:int|string|null,remote_service_id:?string,provisioned_at:?string,lifecycle_state:string,lifecycle_version:int|string,remote_identity_generation:int|string,mutation_generation:int|string,remote_deleted_at:?string}
 * @phpstan-type OperationRow object{id:int|string,public_id:string,operation_type:string,service_subscription_id:int|string,state:string,state_version:int|string,operation_generation:int|string,target_remote_identity_generation:int|string,target_lifecycle_version:int|string,request_key_hash:?string}
 * @phpstan-type PreviewRow object{id:int|string,public_id:string,actor_user_id:int|string,service_subscription_id:int|string,source_route_selection_id:int|string|null,source_service_target_id:int|string,source_remote_identity_generation:int|string,source_lifecycle_version:int|string,source_mutation_generation:int|string,target_plan_offering_id:int|string,target_route_selection_id:int|string,target_service_target_id:int|string,target_service_target_version:int|string,target_protocol_profile_id:int|string,target_protocol_profile_version:int|string,target_capacity_reservation_id:int|string,target_capacity_reservation_key:string,total_price_irr:int|string,state:string,expires_at:string,target_offering_code:string,target_offering_version:int|string,target_offering_state:string,target_offering_visibility:string,target_selection_plan_offering_id:int|string,target_selection_service_target_id:int|string,target_selection_protocol_profile_id:int|string,target_selection_capacity_reservation_id:int|string,target_reservation_state:string,target_reservation_units:int|string,target_reservation_key:string,target_reservation_version:int|string,target_reservation_expires_at:string,target_reference:string,current_target_version:int|string,target_state:string,target_capability_status:string,target_connection_id:int|string,target_protocol_profile_code:string,current_profile_version:int|string,target_profile_state:string,source_connection_id:int|string,source_reservation_state:?string}
 */
final readonly class ServiceReconfigurationNoChargeQueueService
{
    private const QUEUE_AUTHORITY = 'service_reconfiguration_no_charge_queue_v1';

    /** @var list<string> */
    private const TERMINAL_STATES = ['succeeded', 'failed_final', 'compensated'];

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private OutboxPublisher $outbox,
    ) {}

    /** @requirement SVC-005 PRV-002 PRV-003 ARCH-004 DAT-003 SEC-002 SEC-008 QUA-004 */
    public function queueForSelf(
        int $actorUserId,
        string $previewPublicId,
        string $requestKey,
        string $correlationId,
    ): ServiceMutationReceipt {
        if ($actorUserId < 1) {
            throw new DomainException('Service reconfiguration actor is invalid.');
        }
        $this->assertUlid($previewPublicId, 'Service reconfiguration preview public ID');
        $requestHash = $this->requestKeyHash($requestKey);
        $this->assertToken($correlationId, 'Service reconfiguration correlation ID', 8, 64);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $actorUserId,
            $previewPublicId,
            $requestHash,
            $correlationId,
        ): ServiceMutationReceipt {
            /** @var object{service_subscription_id:int|string}|null $locator */
            $locator = $connection->table('service_reconfiguration_previews')
                ->where('public_id', $previewPublicId)
                ->first(['service_subscription_id']);
            if ($locator === null) {
                throw new DomainException('Service reconfiguration preview does not exist.');
            }

            /** @var ServiceRow|null $service */
            $service = $connection->table('service_subscriptions')
                ->where('id', (int) $locator->service_subscription_id)
                ->lockForUpdate()
                ->first([
                    'id', 'public_id', 'order_id', 'order_item_id', 'user_id', 'route_selection_id',
                    'service_target_id', 'remote_service_id', 'provisioned_at', 'lifecycle_state',
                    'lifecycle_version', 'remote_identity_generation', 'mutation_generation', 'remote_deleted_at',
                ]);
            if ($service === null) {
                throw new RuntimeException('Service reconfiguration target Service disappeared.');
            }

            $preview = $this->preview($connection, $previewPublicId);
            if ((int) $preview->actor_user_id !== $actorUserId
                || (int) $preview->service_subscription_id !== (int) $service->id
                || (int) $service->user_id !== $actorUserId) {
                throw new DomainException('Service reconfiguration preview is not owned by this customer.');
            }

            /** @var object{provisioning_operation_id:int|string,authorization_mode:string}|null $existingAuthority */
            $existingAuthority = $connection->table('service_reconfiguration_authorities')
                ->where('reconfiguration_preview_id', (int) $preview->id)
                ->lockForUpdate()
                ->first(['provisioning_operation_id', 'authorization_mode']);
            if ($existingAuthority !== null) {
                if ($existingAuthority->authorization_mode !== 'no_charge') {
                    throw new DomainException('Service reconfiguration preview is already bound to paid purchase authority.');
                }
                $operation = $this->operation($connection, (int) $existingAuthority->provisioning_operation_id);
                if ($operation->request_key_hash === null || ! hash_equals($operation->request_key_hash, $requestHash)) {
                    throw new DomainException('Service reconfiguration preview is already bound to a different request.');
                }

                return $this->receipt($service, $operation, true);
            }

            /** @var OperationRow|null $sameRequest */
            $sameRequest = $connection->table('provisioning_operations')
                ->where('service_subscription_id', (int) $service->id)
                ->where('request_key_hash', $requestHash)
                ->lockForUpdate()
                ->first([
                    'id', 'public_id', 'operation_type', 'service_subscription_id', 'state', 'state_version',
                    'operation_generation', 'target_remote_identity_generation', 'target_lifecycle_version', 'request_key_hash',
                ]);
            if ($sameRequest !== null) {
                throw new DomainException('Service reconfiguration request key is already bound to another mutation.');
            }

            $this->assertCurrentNoChargeAuthority($service, $preview);

            if ($connection->table('service_delivery_effects')
                ->where('blocking_service_subscription_id', (int) $service->id)
                ->exists()) {
                throw new DomainException('Service reconfiguration is blocked by an unresolved delivery boundary.');
            }
            if ($connection->table('service_initial_delivery_fences')
                ->where('service_subscription_id', (int) $service->id)
                ->exists()) {
                throw new DomainException('Service reconfiguration is blocked until initial delivery is durably scheduled.');
            }
            if ($connection->table('provisioning_operations')
                ->where('service_subscription_id', (int) $service->id)
                ->where('operation_type', '<>', 'initial_provision')
                ->whereNotIn('state', self::TERMINAL_STATES)
                ->lockForUpdate()
                ->exists()) {
                throw new DomainException('Service has an unresolved mutation operation.');
            }

            $generation = $this->nonNegativeInt($service->mutation_generation, 'Service mutation generation') + 1;
            $remoteGeneration = $this->positiveInt($service->remote_identity_generation, 'Service remote identity generation');
            $lifecycleVersion = $this->nonNegativeInt($service->lifecycle_version, 'Service lifecycle version');
            if ((int) $preview->source_mutation_generation !== $generation - 1
                || (int) $preview->source_remote_identity_generation !== $remoteGeneration
                || (int) $preview->source_lifecycle_version !== $lifecycleVersion) {
                throw new DomainException('Service changed after the zero-cost reconfiguration preview.');
            }

            $timestamp = $this->timestamp();
            $this->setQueueAuthority(
                $connection,
                $generation,
                $requestHash,
                $correlationId,
                (int) $preview->id,
            );
            try {
                $updated = $connection->table('service_subscriptions')
                    ->where('id', (int) $service->id)
                    ->where('mutation_generation', $generation - 1)
                    ->where('remote_identity_generation', $remoteGeneration)
                    ->where('lifecycle_version', $lifecycleVersion)
                    ->update(['mutation_generation' => $generation, 'updated_at' => $timestamp]);
                if ($updated !== 1) {
                    throw new RuntimeException('Zero-cost Service reconfiguration generation claim lost its authority.');
                }

                $operationPublicId = (string) Str::ulid();
                $operationId = (int) $connection->table('provisioning_operations')->insertGetId([
                    'public_id' => $operationPublicId,
                    'operation_key' => 'service-mutation:'.$service->public_id.':'.$generation.':reconfigure',
                    'operation_type' => ServiceMutationType::Reconfigure->value,
                    'order_id' => $this->positiveInt($service->order_id, 'Service Order ID'),
                    'order_item_id' => $this->positiveInt($service->order_item_id, 'Service Order Item ID'),
                    'service_subscription_id' => $this->positiveInt($service->id, 'Service Subscription ID'),
                    'user_id' => $this->positiveInt($service->user_id, 'Service user ID'),
                    'state' => ProvisioningState::Queued->value,
                    'state_version' => 1,
                    'correlation_id' => $correlationId,
                    'service_target_id' => $this->positiveInt($service->service_target_id, 'Service target ID'),
                    'remote_service_id' => $service->remote_service_id,
                    'operation_generation' => $generation,
                    'target_remote_identity_generation' => $remoteGeneration,
                    'target_lifecycle_version' => $lifecycleVersion,
                    'request_key_hash' => $requestHash,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                $connection->table('service_reconfiguration_authorities')->insert([
                    'public_id' => (string) Str::ulid(),
                    'provisioning_operation_id' => $operationId,
                    'service_subscription_id' => (int) $service->id,
                    'authorization_mode' => 'no_charge',
                    'source_quote_id' => null,
                    'purchase_order_id' => null,
                    'purchase_order_item_id' => null,
                    'purchase_settlement_id' => null,
                    'payment_intent_id' => null,
                    'reconfiguration_preview_id' => (int) $preview->id,
                    'target_plan_offering_id' => (int) $preview->target_plan_offering_id,
                    'target_plan_offering_code' => $preview->target_offering_code,
                    'target_plan_offering_version' => (int) $preview->target_offering_version,
                    'source_route_selection_id' => $preview->source_route_selection_id === null ? null : (int) $preview->source_route_selection_id,
                    'source_service_target_id' => (int) $preview->source_service_target_id,
                    'target_route_selection_id' => (int) $preview->target_route_selection_id,
                    'target_service_target_id' => (int) $preview->target_service_target_id,
                    'target_service_target_version' => (int) $preview->target_service_target_version,
                    'target_protocol_profile_id' => (int) $preview->target_protocol_profile_id,
                    'target_protocol_profile_version' => (int) $preview->target_protocol_profile_version,
                    'target_capacity_reservation_id' => (int) $preview->target_capacity_reservation_id,
                    'target_capacity_reservation_key' => $preview->target_capacity_reservation_key,
                    'target_reference' => $preview->target_reference,
                    'target_protocol_profile_code' => $preview->target_protocol_profile_code,
                    'quoted_remote_identity_generation' => $remoteGeneration,
                    'quoted_lifecycle_version' => $lifecycleVersion,
                    'quoted_mutation_generation' => $generation - 1,
                    'result_remote_service_id' => null,
                    'remote_result_snapshot_hash' => null,
                    'result_recorded_at' => null,
                    'created_at' => $timestamp,
                ]);

                $operation = $this->operation($connection, $operationId);
                $this->outbox->publish(
                    (string) Str::uuid(),
                    ServiceMutationQueueService::OUTBOX_EVENT_KEY_PREFIX.$operation->public_id,
                    ServiceMutationQueueService::OUTBOX_EVENT_TYPE,
                    ServiceMutationQueueService::OUTBOX_AGGREGATE_TYPE,
                    $operation->public_id,
                    new SafeOutboxPayload(['provisioning_operation_public_id' => $operation->public_id]),
                    $correlationId,
                    ServiceMutationQueueService::OUTBOX_CONTRACT_VERSION,
                );

                return $this->receipt($service, $operation, false);
            } finally {
                $this->clearQueueAuthority($connection);
            }
        }, 3);
    }

    /** @return PreviewRow */
    private function preview(Connection $connection, string $publicId): object
    {
        /** @var PreviewRow|null $row */
        $row = $connection->table('service_reconfiguration_previews as preview')
            ->join('plan_offerings as target_offering', 'target_offering.id', '=', 'preview.target_plan_offering_id')
            ->join('plan_offering_route_selections as target_selection', 'target_selection.id', '=', 'preview.target_route_selection_id')
            ->join('panel_capacity_reservations as target_reservation', 'target_reservation.id', '=', 'preview.target_capacity_reservation_id')
            ->join('panel_service_targets as source_target', 'source_target.id', '=', 'preview.source_service_target_id')
            ->join('panel_service_targets as target_target', 'target_target.id', '=', 'preview.target_service_target_id')
            ->join('panel_protocol_profiles as target_profile', 'target_profile.id', '=', 'preview.target_protocol_profile_id')
            ->leftJoin('plan_offering_route_selections as source_selection', 'source_selection.id', '=', 'preview.source_route_selection_id')
            ->leftJoin('panel_capacity_reservations as source_reservation', 'source_reservation.id', '=', 'source_selection.capacity_reservation_id')
            ->where('preview.public_id', $publicId)
            ->lockForUpdate()
            ->first([
                'preview.id', 'preview.public_id', 'preview.actor_user_id', 'preview.service_subscription_id',
                'preview.source_route_selection_id', 'preview.source_service_target_id',
                'preview.source_remote_identity_generation', 'preview.source_lifecycle_version', 'preview.source_mutation_generation',
                'preview.target_plan_offering_id', 'preview.target_route_selection_id', 'preview.target_service_target_id',
                'preview.target_service_target_version', 'preview.target_protocol_profile_id', 'preview.target_protocol_profile_version',
                'preview.target_capacity_reservation_id', 'preview.target_capacity_reservation_key',
                'preview.total_price_irr', 'preview.state', 'preview.expires_at',
                'target_offering.code as target_offering_code', 'target_offering.version as target_offering_version',
                'target_offering.state as target_offering_state', 'target_offering.visibility as target_offering_visibility',
                'target_selection.plan_offering_id as target_selection_plan_offering_id',
                'target_selection.selected_service_target_id as target_selection_service_target_id',
                'target_selection.panel_protocol_profile_id as target_selection_protocol_profile_id',
                'target_selection.capacity_reservation_id as target_selection_capacity_reservation_id',
                'target_reservation.state as target_reservation_state', 'target_reservation.units as target_reservation_units',
                'target_reservation.reservation_key as target_reservation_key', 'target_reservation.version as target_reservation_version',
                'target_reservation.expires_at as target_reservation_expires_at',
                'target_target.code as target_reference', 'target_target.version as current_target_version',
                'target_target.state as target_state', 'target_target.capability_status as target_capability_status',
                'target_target.panel_connection_id as target_connection_id',
                'target_profile.code as target_protocol_profile_code', 'target_profile.version as current_profile_version',
                'target_profile.state as target_profile_state', 'source_target.panel_connection_id as source_connection_id',
                'source_reservation.state as source_reservation_state',
            ]);
        if ($row === null) {
            throw new DomainException('Service reconfiguration preview does not exist.');
        }

        return $row;
    }

    /** @param ServiceRow $service @param PreviewRow $preview */
    private function assertCurrentNoChargeAuthority(object $service, object $preview): void
    {
        if ((int) $preview->total_price_irr !== 0
            || $preview->state !== 'previewed'
            || new DateTimeImmutable($preview->expires_at) <= $this->clock->now()
            || (int) $preview->source_service_target_id !== (int) $service->service_target_id
            || ! ($preview->source_route_selection_id === null && $service->route_selection_id === null)
                && (int) $preview->source_route_selection_id !== (int) $service->route_selection_id
            || (int) $preview->source_remote_identity_generation !== (int) $service->remote_identity_generation
            || (int) $preview->source_lifecycle_version !== (int) $service->lifecycle_version
            || (int) $preview->source_mutation_generation !== (int) $service->mutation_generation
            || ! in_array($service->lifecycle_state, ['active', 'suspended'], true)
            || $service->remote_deleted_at !== null
            || $service->provisioned_at === null
            || $service->service_target_id === null
            || ! is_string($service->remote_service_id) || $service->remote_service_id === ''
            || $preview->target_offering_state !== 'active'
            || $preview->target_offering_visibility !== 'visible'
            || (int) $preview->target_selection_plan_offering_id !== (int) $preview->target_plan_offering_id
            || (int) $preview->target_selection_service_target_id !== (int) $preview->target_service_target_id
            || (int) $preview->target_selection_protocol_profile_id !== (int) $preview->target_protocol_profile_id
            || (int) $preview->target_selection_capacity_reservation_id !== (int) $preview->target_capacity_reservation_id
            || $preview->target_reservation_state !== 'held'
            || (int) $preview->target_reservation_units !== 1
            || ! hash_equals($preview->target_capacity_reservation_key, $preview->target_reservation_key)
            || new DateTimeImmutable($preview->target_reservation_expires_at) <= $this->clock->now()
            || (int) $preview->current_target_version !== (int) $preview->target_service_target_version
            || $preview->target_state !== 'active'
            || $preview->target_capability_status !== 'verified'
            || (int) $preview->current_profile_version !== (int) $preview->target_protocol_profile_version
            || $preview->target_profile_state !== 'active'
            || (int) $preview->source_connection_id !== (int) $preview->target_connection_id
            || ($preview->source_route_selection_id !== null && $preview->source_reservation_state !== 'committed')) {
            throw new DomainException('Zero-cost Service reconfiguration preview is stale or no longer authoritative.');
        }
    }

    private function setQueueAuthority(
        Connection $connection,
        int $generation,
        string $requestHash,
        string $correlationId,
        int $previewId,
    ): void {
        $connection->statement('SET @app_service_mutation_authority = ?', [self::QUEUE_AUTHORITY]);
        $connection->statement('SET @app_service_mutation_generation = ?', [$generation]);
        $connection->statement('SET @app_service_mutation_request_hash = ?', [$requestHash]);
        $connection->statement('SET @app_service_mutation_correlation_id = ?', [$correlationId]);
        $connection->statement('SET @app_service_reconfiguration_preview_id = ?', [$previewId]);
    }

    private function clearQueueAuthority(Connection $connection): void
    {
        $connection->statement('SET @app_service_mutation_authority = NULL');
        $connection->statement('SET @app_service_mutation_generation = NULL');
        $connection->statement('SET @app_service_mutation_request_hash = NULL');
        $connection->statement('SET @app_service_mutation_correlation_id = NULL');
        $connection->statement('SET @app_service_reconfiguration_preview_id = NULL');
    }

    /** @return OperationRow */
    private function operation(Connection $connection, int $id): object
    {
        /** @var OperationRow|null $row */
        $row = $connection->table('provisioning_operations')->where('id', $id)->lockForUpdate()->first([
            'id', 'public_id', 'operation_type', 'service_subscription_id', 'state', 'state_version',
            'operation_generation', 'target_remote_identity_generation', 'target_lifecycle_version', 'request_key_hash',
        ]);
        if ($row === null) {
            throw new RuntimeException('Zero-cost Service reconfiguration operation disappeared.');
        }

        return $row;
    }

    /** @param ServiceRow $service @param OperationRow $operation */
    private function receipt(object $service, object $operation, bool $replayed): ServiceMutationReceipt
    {
        $state = ProvisioningState::tryFrom((string) $operation->state)
            ?? throw new RuntimeException('Stored zero-cost Service reconfiguration state is invalid.');

        return new ServiceMutationReceipt(
            (string) $service->public_id,
            (string) $operation->public_id,
            ServiceMutationType::Reconfigure,
            $this->nonNegativeInt($operation->operation_generation, 'Operation generation'),
            $state,
            $this->positiveInt($operation->state_version, 'Operation state version'),
            $replayed,
        );
    }

    private function requestKeyHash(string $requestKey): string
    {
        $this->assertToken($requestKey, 'Service reconfiguration request key', 8, 128);

        return hash('sha256', $requestKey);
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function assertUlid(string $value, string $label): void
    {
        if (! Str::isUlid($value)) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        if (strlen($value) < $minimum || strlen($value) > $maximum || preg_match('/\A[A-Za-z0-9_.:-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function positiveInt(int|string|null $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }

    private function nonNegativeInt(int|string $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 0) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }
}
