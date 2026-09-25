<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Modules\Provisioning\Domain\ServiceMutationType;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-type BatchRow object{id:int|string,public_id:string,request_key_hash:string,payload_hash:string,source_type:string,actor_administrator_id:int|string,audit_log_id:int|string,reason_code:string,reason:string,selection_mode:string,selected_sales_server_id:int|string|null,data_bytes:int|string|null,duration_days:int|string|null,notify_customers:int|string|bool,state:string,item_count:int|string,queued_count:int|string,succeeded_count:int|string,failed_count:int|string,needs_review_count:int|string,cancelled_count:int|string,correlation_id:string,expires_at:string,items_committed_at:?string,completed_at:?string}
 * @phpstan-type ServiceRow object{id:int|string,public_id:string,service_target_id:int|string|null,lifecycle_state:string,lifecycle_version:int|string,remote_identity_generation:int|string,mutation_generation:int|string,provisioned_at:?string,remote_deleted_at:?string}
 */
final readonly class ServiceEntitlementGrantBatchService
{
    private const PERMISSION = 'services.grant_batch';

    private const BATCH_AUTHORITY = 'service_entitlement_grant_batch_v1';

    private const PREVIEW_TTL_MINUTES = 15;

    private const PROCESS_LIMIT = 50;

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private AdministratorPermissionAuthorizer $authorizer,
        private ServiceOperationalAuthorityGuard $authority,
        private ServiceOperationalDatabaseCapability $databaseCapability,
        private ServiceOperationalAudit $audit,
        private ServiceEntitlementGrantQueueService $queue,
    ) {}

    /**
     * @param  list<string>  $servicePublicIds
     *
     * @requirement SVC-012 ADM-002 ARCH-003 ARCH-004 DAT-003 SEC-002 QUA-004
     */
    public function previewExplicit(
        ServiceOperationalContext $context,
        array $servicePublicIds,
        ?int $dataBytes,
        ?int $durationDays,
        string $sourceType = 'admin_grant',
        bool $notifyCustomers = true,
    ): ServiceEntitlementGrantBatchReceipt {
        $normalized = array_values(array_unique(array_map(
            static fn (string $id): string => strtoupper(trim($id)),
            $servicePublicIds,
        )));
        if ($normalized === [] || count($normalized) > 10000) {
            throw new DomainException('Explicit Service entitlement grant selection is invalid.');
        }
        foreach ($normalized as $publicId) {
            if (! Str::isUlid($publicId)) {
                throw new DomainException('Explicit Service entitlement grant selection contains an invalid Service ID.');
            }
        }
        sort($normalized, SORT_STRING);

        return $this->preview(
            $context,
            'explicit',
            null,
            $normalized,
            $dataBytes,
            $durationDays,
            $sourceType,
            $notifyCustomers,
        );
    }

    /** @requirement SVC-012 ADM-002 ARCH-003 ARCH-004 DAT-003 SEC-002 QUA-004 */
    public function previewServer(
        ServiceOperationalContext $context,
        int $salesServerId,
        ?int $dataBytes,
        ?int $durationDays,
        string $sourceType = 'admin_grant',
        bool $notifyCustomers = true,
    ): ServiceEntitlementGrantBatchReceipt {
        if ($salesServerId < 1) {
            throw new DomainException('Sales Server ID is invalid.');
        }

        return $this->preview(
            $context,
            'server_all',
            $salesServerId,
            [],
            $dataBytes,
            $durationDays,
            $sourceType,
            $notifyCustomers,
        );
    }

    /** @requirement SVC-012 ADM-002 ARCH-003 ARCH-004 DAT-003 SEC-002 QUA-004 */
    public function confirm(string $batchPublicId, ServiceOperationalContext $context): ServiceEntitlementGrantBatchReceipt
    {
        $this->authorize($context);
        $this->assertBatchId($batchPublicId);

        return $this->database->connection()->transaction(function (Connection $connection) use ($batchPublicId, $context): ServiceEntitlementGrantBatchReceipt {
            $batch = $this->lockedBatch($connection, $batchPublicId);
            $this->assertBatchContext($batch, $context);
            if ($batch->state === 'active') {
                return $this->receipt($connection, $batch, true);
            }
            if ($batch->state !== 'previewed') {
                throw new DomainException('Service entitlement grant preview cannot be confirmed from its current state.');
            }
            if ($this->timestamp() >= $batch->expires_at) {
                throw new DomainException('Service entitlement grant preview has expired.');
            }
            $this->assertFrozenItemsCurrent($connection, $batch);

            $this->setBatchAuthority($connection, (int) $batch->id);
            try {
                $updated = $connection->table('service_entitlement_grant_batches')
                    ->where('id', (int) $batch->id)
                    ->where('state', 'previewed')
                    ->update([
                        'state' => 'active',
                        'updated_at' => $this->timestamp(),
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Service entitlement grant confirmation lost its state.');
                }
            } finally {
                $this->clearBatchAuthority($connection);
            }

            return $this->receipt($connection, $this->lockedBatch($connection, $batchPublicId), false);
        }, 3);
    }

    /** @requirement SVC-012 ADM-002 ARCH-003 ARCH-004 DAT-003 SEC-002 QUA-004 */
    public function resume(string $batchPublicId, ServiceOperationalContext $context): ServiceEntitlementGrantBatchReceipt
    {
        $this->authorize($context);
        $this->assertBatchId($batchPublicId);
        $this->reconcile($batchPublicId, $context);

        /** @var BatchRow|null $batch */
        $batch = $this->database->connection()->table('service_entitlement_grant_batches')
            ->where('public_id', $batchPublicId)
            ->first();
        if ($batch === null) {
            throw new DomainException('Service entitlement grant batch does not exist.');
        }
        $this->assertBatchContext($batch, $context);
        if ($batch->state !== 'active') {
            return $this->receipt($this->database->connection(), $batch, true);
        }

        /** @var list<string> $items */
        $items = $this->database->connection()->table('service_entitlement_grant_items')
            ->where('service_entitlement_grant_batch_id', (int) $batch->id)
            ->whereNull('provisioning_operation_id')
            ->whereIn('state', ['pending', 'failed'])
            ->orderBy('position')
            ->limit(self::PROCESS_LIMIT)
            ->pluck('public_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $didWork = false;
        foreach ($items as $itemPublicId) {
            try {
                $this->queue->queueItem($itemPublicId, $context);
                $didWork = true;
            } catch (DomainException $exception) {
                $this->recordQueueFailure($batchPublicId, $itemPublicId, $context, $exception);
                $didWork = true;
            }
        }

        $result = $this->reconcile($batchPublicId, $context);

        return new ServiceEntitlementGrantBatchReceipt(
            $result->batchPublicId,
            $result->state,
            $result->selectionMode,
            $result->selectedSalesServerId,
            $result->itemCount,
            $result->pendingCount,
            $result->queuedCount,
            $result->succeededCount,
            $result->failedCount,
            $result->needsReviewCount,
            $result->cancelledCount,
            $result->dataBytes,
            $result->durationDays,
            $result->notifyCustomers,
            $result->expiresAt,
            ! $didWork,
        );
    }

    public function pause(string $batchPublicId, ServiceOperationalContext $context): ServiceEntitlementGrantBatchReceipt
    {
        return $this->changeState($batchPublicId, $context, 'active', 'paused');
    }

    public function activate(string $batchPublicId, ServiceOperationalContext $context): ServiceEntitlementGrantBatchReceipt
    {
        return $this->changeState($batchPublicId, $context, 'paused', 'active');
    }

    public function cancel(string $batchPublicId, ServiceOperationalContext $context): ServiceEntitlementGrantBatchReceipt
    {
        $this->authorize($context);
        $this->assertBatchId($batchPublicId);

        return $this->database->connection()->transaction(function (Connection $connection) use ($batchPublicId, $context): ServiceEntitlementGrantBatchReceipt {
            $batch = $this->lockedBatch($connection, $batchPublicId);
            $this->assertBatchContext($batch, $context);
            if ($batch->state === 'cancelled') {
                return $this->receipt($connection, $batch, true);
            }
            if ($batch->state === 'completed') {
                throw new DomainException('Completed Service entitlement grant batch cannot be cancelled.');
            }

            $this->setBatchAuthority($connection, (int) $batch->id);
            try {
                $connection->table('service_entitlement_grant_items')
                    ->where('service_entitlement_grant_batch_id', (int) $batch->id)
                    ->whereNull('provisioning_operation_id')
                    ->whereIn('state', ['pending', 'failed'])
                    ->update([
                        'state' => 'cancelled',
                        'result_code' => null,
                        'updated_at' => $this->timestamp(),
                    ]);

                $counts = $this->counts($connection, (int) $batch->id);
                $updated = $connection->table('service_entitlement_grant_batches')
                    ->where('id', (int) $batch->id)
                    ->whereIn('state', ['previewed', 'active', 'paused'])
                    ->update([
                        'state' => 'cancelled',
                        'queued_count' => $counts['queued'],
                        'succeeded_count' => $counts['succeeded'],
                        'failed_count' => $counts['failed'],
                        'needs_review_count' => $counts['needs_review'],
                        'cancelled_count' => $counts['cancelled'],
                        'updated_at' => $this->timestamp(),
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Service entitlement grant cancellation lost its state.');
                }
            } finally {
                $this->clearBatchAuthority($connection);
            }

            return $this->receipt($connection, $this->lockedBatch($connection, $batchPublicId), false);
        }, 3);
    }

    public function status(string $batchPublicId, ServiceOperationalContext $context): ServiceEntitlementGrantBatchReceipt
    {
        $this->authorize($context);
        $this->assertBatchId($batchPublicId);

        return $this->reconcile($batchPublicId, $context);
    }

    /** @requirement SVC-012 ADM-002 DAT-003 SEC-002 */
    public function exportCsv(string $batchPublicId, ServiceOperationalContext $context): string
    {
        $receipt = $this->status($batchPublicId, $context);
        /** @var BatchRow|null $batch */
        $batch = $this->database->connection()->table('service_entitlement_grant_batches')
            ->where('public_id', $receipt->batchPublicId)
            ->first(['id']);
        if ($batch === null) {
            throw new RuntimeException('Service entitlement grant batch disappeared before export.');
        }

        $rows = $this->database->connection()->table('service_entitlement_grant_items as item')
            ->join('service_subscriptions as service', 'service.id', '=', 'item.service_subscription_id')
            ->leftJoin('provisioning_operations as operation', 'operation.id', '=', 'item.provisioning_operation_id')
            ->where('item.service_entitlement_grant_batch_id', (int) $batch->id)
            ->orderBy('item.position')
            ->get([
                'item.position', 'service.public_id as service_public_id', 'item.state', 'item.attempt_count',
                'operation.public_id as operation_public_id', 'operation.state as operation_state', 'item.result_code',
            ]);

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Service entitlement grant export stream is unavailable.');
        }
        try {
            fputcsv($stream, ['position', 'service_public_id', 'state', 'attempt_count', 'operation_public_id', 'operation_state', 'result_code']);
            foreach ($rows as $row) {
                fputcsv($stream, [
                    (string) $row->position,
                    (string) $row->service_public_id,
                    (string) $row->state,
                    (string) $row->attempt_count,
                    $row->operation_public_id === null ? '' : (string) $row->operation_public_id,
                    $row->operation_state === null ? '' : (string) $row->operation_state,
                    $row->result_code === null ? '' : (string) $row->result_code,
                ]);
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
        if (! is_string($csv)) {
            throw new RuntimeException('Service entitlement grant export could not be rendered.');
        }

        return $csv;
    }

    /**
     * @param  list<string>  $explicitServiceIds
     */
    private function preview(
        ServiceOperationalContext $context,
        string $selectionMode,
        ?int $salesServerId,
        array $explicitServiceIds,
        ?int $dataBytes,
        ?int $durationDays,
        string $sourceType,
        bool $notifyCustomers,
    ): ServiceEntitlementGrantBatchReceipt {
        $this->authorize($context);
        [$dataBytes, $durationDays] = $this->normalizePackage($dataBytes, $durationDays);
        if (! in_array($sourceType, ['admin_grant', 'campaign_grant'], true)) {
            throw new DomainException('Service entitlement grant source type is invalid.');
        }

        $payloadHash = hash('sha256', json_encode([
            'selection_mode' => $selectionMode,
            'sales_server_id' => $salesServerId,
            'explicit_service_ids' => $explicitServiceIds,
            'data_bytes' => $dataBytes,
            'duration_days' => $durationDays,
            'source_type' => $sourceType,
            'notify_customers' => $notifyCustomers,
        ], JSON_THROW_ON_ERROR));

        /** @var BatchRow|null $existing */
        $existing = $this->database->connection()->table('service_entitlement_grant_batches')
            ->where('request_key_hash', $context->requestHash())
            ->first();
        if ($existing !== null) {
            $this->assertPreviewReplay($existing, $context, $payloadHash);

            return $this->receipt($this->database->connection(), $existing, true);
        }

        $type = $this->grantType($dataBytes, $durationDays);
        $services = $this->eligibleServices($selectionMode, $salesServerId, $explicitServiceIds, $type);
        if ($services === []) {
            throw new DomainException('Service entitlement grant selection has no eligible active Services.');
        }
        if ($selectionMode === 'explicit' && count($services) !== count($explicitServiceIds)) {
            throw new DomainException('At least one explicitly selected Service is unavailable, stale, or lacks the required verified Panel capabilities.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $context,
            $selectionMode,
            $salesServerId,
            $dataBytes,
            $durationDays,
            $sourceType,
            $notifyCustomers,
            $payloadHash,
            $services,
        ): ServiceEntitlementGrantBatchReceipt {
            /** @var BatchRow|null $replayed */
            $replayed = $connection->table('service_entitlement_grant_batches')
                ->where('request_key_hash', $context->requestHash())
                ->lockForUpdate()
                ->first();
            if ($replayed !== null) {
                $this->assertPreviewReplay($replayed, $context, $payloadHash);

                return $this->receipt($connection, $replayed, true);
            }

            $batchPublicId = (string) Str::ulid();
            $timestamp = $this->timestamp();
            $expiresAt = $this->clock->now()
                ->setTimezone(new \DateTimeZone('UTC'))
                ->modify('+'.self::PREVIEW_TTL_MINUTES.' minutes')
                ->format('Y-m-d H:i:s.u');
            $auditId = $this->audit->record(
                $connection,
                'service.operational.entitlement_grant.previewed',
                'service_entitlement_grant_batch',
                $batchPublicId,
                $context,
                ['state' => null, 'item_count' => 0, 'payload_hash' => null],
                ['state' => 'previewed', 'item_count' => count($services), 'payload_hash' => $payloadHash],
            );

            $this->setBatchAuthority($connection, null);
            try {
                $batchId = (int) $connection->table('service_entitlement_grant_batches')->insertGetId([
                    'public_id' => $batchPublicId,
                    'request_key_hash' => $context->requestHash(),
                    'payload_hash' => $payloadHash,
                    'source_type' => $sourceType,
                    'actor_administrator_id' => $context->actorAdministratorId,
                    'audit_log_id' => $auditId,
                    'reason_code' => $context->reasonCode,
                    'reason' => $context->reason,
                    'selection_mode' => $selectionMode,
                    'selected_sales_server_id' => $salesServerId,
                    'data_bytes' => $dataBytes,
                    'duration_days' => $durationDays,
                    'notify_customers' => $notifyCustomers,
                    'state' => 'previewed',
                    'item_count' => count($services),
                    'queued_count' => 0,
                    'succeeded_count' => 0,
                    'failed_count' => 0,
                    'needs_review_count' => 0,
                    'cancelled_count' => 0,
                    'correlation_id' => $context->correlationId,
                    'expires_at' => $expiresAt,
                    'items_committed_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                    'completed_at' => null,
                ]);
                $this->setBatchAuthority($connection, $batchId);

                foreach ($services as $position => $service) {
                    $connection->table('service_entitlement_grant_items')->insert([
                        'public_id' => (string) Str::ulid(),
                        'service_entitlement_grant_batch_id' => $batchId,
                        'position' => $position + 1,
                        'service_subscription_id' => (int) $service->id,
                        'service_target_id' => $this->positiveDatabaseInt($service->service_target_id, 'Service target ID'),
                        'source_mutation_generation' => $this->nonNegativeDatabaseInt($service->mutation_generation, 'Service mutation generation'),
                        'target_remote_identity_generation' => $this->positiveDatabaseInt($service->remote_identity_generation, 'Remote identity generation'),
                        'target_lifecycle_version' => $this->nonNegativeDatabaseInt($service->lifecycle_version, 'Service lifecycle version'),
                        'request_key_hash' => hash('sha256', $context->requestHash().'|'.$payloadHash.'|'.($position + 1).'|'.$service->public_id),
                        'state' => 'pending',
                        'attempt_count' => 0,
                        'provisioning_operation_id' => null,
                        'result_code' => null,
                        'customer_notified_at' => null,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                }

                $committed = $connection->table('service_entitlement_grant_batches')
                    ->where('id', $batchId)
                    ->whereNull('items_committed_at')
                    ->update([
                        'items_committed_at' => $this->timestamp(),
                        'updated_at' => $this->timestamp(),
                    ]);
                if ($committed !== 1) {
                    throw new RuntimeException('Service entitlement grant item family did not finalize.');
                }
            } finally {
                $this->clearBatchAuthority($connection);
            }

            return $this->receipt($connection, $this->lockedBatch($connection, $batchPublicId), false);
        }, 3);
    }

    /**
     * @param  list<string>  $explicitServiceIds
     * @return list<ServiceRow>
     */
    private function eligibleServices(
        string $selectionMode,
        ?int $salesServerId,
        array $explicitServiceIds,
        ServiceMutationType $type,
    ): array {
        $query = $this->database->connection()->table('service_subscriptions as service')
            ->join('panel_service_targets as target', 'target.id', '=', 'service.service_target_id')
            ->where('service.lifecycle_state', 'active')
            ->whereNull('service.remote_deleted_at')
            ->whereNotNull('service.provisioned_at')
            ->whereNotNull('service.remote_service_id')
            ->where('target.state', 'active')
            ->where('target.capability_status', 'verified');

        if ($selectionMode === 'server_all') {
            $query->join('plan_offering_route_selections as route', 'route.id', '=', 'service.route_selection_id')
                ->where('route.selected_sales_server_id', $salesServerId);
        } else {
            $query->whereIn('service.public_id', $explicitServiceIds);
        }

        /** @var list<ServiceRow> $candidates */
        $candidates = $query->orderBy('service.id')->get([
            'service.id', 'service.public_id', 'service.service_target_id', 'service.lifecycle_state',
            'service.lifecycle_version', 'service.remote_identity_generation', 'service.mutation_generation',
            'service.provisioned_at', 'service.remote_deleted_at',
        ])->all();

        $capabilityCache = [];
        $eligible = [];
        foreach ($candidates as $service) {
            $targetId = $this->positiveDatabaseInt($service->service_target_id, 'Service target ID');
            if (! array_key_exists($targetId, $capabilityCache)) {
                $verified = $this->database->connection()->table('panel_target_capabilities')
                    ->where('panel_service_target_id', $targetId)
                    ->where('verification_status', 'verified')
                    ->whereIn('capability_code', $type->panelCapabilities())
                    ->distinct()
                    ->pluck('capability_code')
                    ->map(static fn (mixed $capability): string => (string) $capability)
                    ->all();
                $capabilityCache[$targetId] = count(array_unique($verified)) === count($type->panelCapabilities());
            }
            if ($capabilityCache[$targetId]) {
                $eligible[] = $service;
            }
        }

        return $eligible;
    }

    private function reconcile(string $batchPublicId, ServiceOperationalContext $context): ServiceEntitlementGrantBatchReceipt
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($batchPublicId, $context): ServiceEntitlementGrantBatchReceipt {
            $batch = $this->lockedBatch($connection, $batchPublicId);
            $this->assertBatchContext($batch, $context);

            $items = $connection->table('service_entitlement_grant_items')
                ->where('service_entitlement_grant_batch_id', (int) $batch->id)
                ->where('state', 'queued')
                ->whereNotNull('provisioning_operation_id')
                ->lockForUpdate()
                ->get(['id', 'provisioning_operation_id']);

            $this->setBatchAuthority($connection, (int) $batch->id);
            try {
                foreach ($items as $item) {
                    /** @var object{state:string,last_result_code:?string}|null $operation */
                    $operation = $connection->table('provisioning_operations')
                        ->where('id', (int) $item->provisioning_operation_id)
                        ->first(['state', 'last_result_code']);
                    if ($operation === null) {
                        throw new RuntimeException('Service entitlement grant Operation disappeared during reconciliation.');
                    }

                    $next = match ($operation->state) {
                        ProvisioningState::Succeeded->value => 'succeeded',
                        ProvisioningState::FailedFinal->value, ProvisioningState::Compensated->value => 'failed',
                        ProvisioningState::NeedsReview->value => 'needs_review',
                        default => null,
                    };
                    if ($next === null) {
                        continue;
                    }
                    $connection->table('service_entitlement_grant_items')
                        ->where('id', (int) $item->id)
                        ->where('state', 'queued')
                        ->update([
                            'state' => $next,
                            'result_code' => $operation->last_result_code ?? $operation->state,
                            'updated_at' => $this->timestamp(),
                        ]);
                }

                $counts = $this->counts($connection, (int) $batch->id);
                $retryableFailures = (int) $connection->table('service_entitlement_grant_items')
                    ->where('service_entitlement_grant_batch_id', (int) $batch->id)
                    ->where('state', 'failed')
                    ->whereNull('provisioning_operation_id')
                    ->count();
                $terminal = $counts['succeeded'] + ($counts['failed'] - $retryableFailures)
                    + $counts['needs_review'] + $counts['cancelled'];
                $nextState = $batch->state;
                $completedAt = $batch->completed_at;
                if (in_array($batch->state, ['active', 'paused'], true)
                    && $counts['queued'] === 0
                    && $counts['pending'] === 0
                    && $retryableFailures === 0
                    && $terminal === (int) $batch->item_count) {
                    $nextState = 'completed';
                    $completedAt = $this->timestamp();
                }

                $connection->table('service_entitlement_grant_batches')
                    ->where('id', (int) $batch->id)
                    ->update([
                        'state' => $nextState,
                        'queued_count' => $counts['queued'],
                        'succeeded_count' => $counts['succeeded'],
                        'failed_count' => $counts['failed'],
                        'needs_review_count' => $counts['needs_review'],
                        'cancelled_count' => $counts['cancelled'],
                        'completed_at' => $completedAt,
                        'updated_at' => $this->timestamp(),
                    ]);
            } finally {
                $this->clearBatchAuthority($connection);
            }

            return $this->receipt($connection, $this->lockedBatch($connection, $batchPublicId), false);
        }, 3);
    }

    private function recordQueueFailure(
        string $batchPublicId,
        string $itemPublicId,
        ServiceOperationalContext $context,
        DomainException $exception,
    ): void {
        $this->database->connection()->transaction(function (Connection $connection) use ($batchPublicId, $itemPublicId, $context): void {
            $batch = $this->lockedBatch($connection, $batchPublicId);
            $this->assertBatchContext($batch, $context);
            /** @var object{id:int|string,state:string,provisioning_operation_id:int|string|null}|null $item */
            $item = $connection->table('service_entitlement_grant_items')
                ->where('service_entitlement_grant_batch_id', (int) $batch->id)
                ->where('public_id', $itemPublicId)
                ->lockForUpdate()
                ->first(['id', 'state', 'provisioning_operation_id']);
            if ($item === null || $item->provisioning_operation_id !== null
                || ! in_array($item->state, ['pending', 'failed'], true)) {
                return;
            }

            $code = 'queue_blocked';
            $this->setBatchAuthority($connection, (int) $batch->id);
            try {
                $connection->table('service_entitlement_grant_items')
                    ->where('id', (int) $item->id)
                    ->whereNull('provisioning_operation_id')
                    ->update([
                        'state' => 'failed',
                        'result_code' => $code,
                        'updated_at' => $this->timestamp(),
                    ]);
            } finally {
                $this->clearBatchAuthority($connection);
            }
        }, 3);
    }

    private function changeState(
        string $batchPublicId,
        ServiceOperationalContext $context,
        string $from,
        string $to,
    ): ServiceEntitlementGrantBatchReceipt {
        $this->authorize($context);
        $this->assertBatchId($batchPublicId);

        return $this->database->connection()->transaction(function (Connection $connection) use ($batchPublicId, $context, $from, $to): ServiceEntitlementGrantBatchReceipt {
            $batch = $this->lockedBatch($connection, $batchPublicId);
            $this->assertBatchContext($batch, $context);
            if ($batch->state === $to) {
                return $this->receipt($connection, $batch, true);
            }
            if ($batch->state !== $from) {
                throw new DomainException('Service entitlement grant batch cannot make the requested state transition.');
            }

            $this->setBatchAuthority($connection, (int) $batch->id);
            try {
                $updated = $connection->table('service_entitlement_grant_batches')
                    ->where('id', (int) $batch->id)
                    ->where('state', $from)
                    ->update([
                        'state' => $to,
                        'updated_at' => $this->timestamp(),
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Service entitlement grant state transition lost its authority.');
                }
            } finally {
                $this->clearBatchAuthority($connection);
            }

            return $this->receipt($connection, $this->lockedBatch($connection, $batchPublicId), false);
        }, 3);
    }

    /** @param BatchRow $batch */
    private function assertFrozenItemsCurrent(Connection $connection, object $batch): void
    {
        $stale = $connection->table('service_entitlement_grant_items as item')
            ->join('service_subscriptions as service', 'service.id', '=', 'item.service_subscription_id')
            ->where('item.service_entitlement_grant_batch_id', (int) $batch->id)
            ->where(function ($query): void {
                $query->whereColumn('item.service_target_id', '<>', 'service.service_target_id')
                    ->orWhereColumn('item.source_mutation_generation', '<>', 'service.mutation_generation')
                    ->orWhereColumn('item.target_remote_identity_generation', '<>', 'service.remote_identity_generation')
                    ->orWhereColumn('item.target_lifecycle_version', '<>', 'service.lifecycle_version')
                    ->orWhere('service.lifecycle_state', '<>', 'active')
                    ->orWhereNotNull('service.remote_deleted_at')
                    ->orWhereNull('service.provisioned_at')
                    ->orWhereNull('service.remote_service_id');
            })
            ->exists();
        if ($stale) {
            throw new DomainException('Service entitlement grant preview became stale; create a fresh preview.');
        }
    }

    /** @return BatchRow */
    private function lockedBatch(Connection $connection, string $publicId): object
    {
        /** @var BatchRow|null $batch */
        $batch = $connection->table('service_entitlement_grant_batches')
            ->where('public_id', $publicId)
            ->lockForUpdate()
            ->first();
        if ($batch === null) {
            throw new DomainException('Service entitlement grant batch does not exist.');
        }

        return $batch;
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

    /** @param BatchRow $batch */
    private function assertPreviewReplay(object $batch, ServiceOperationalContext $context, string $payloadHash): void
    {
        $this->assertBatchContext($batch, $context);
        if (! hash_equals($batch->payload_hash, $payloadHash)) {
            throw new DomainException('Service entitlement grant request key was already used for different preview inputs.');
        }
    }

    /** @return array{0:?int,1:?int} */
    private function normalizePackage(?int $dataBytes, ?int $durationDays): array
    {
        if ($dataBytes !== null && $dataBytes < 1) {
            throw new DomainException('Service entitlement grant data bytes must be positive.');
        }
        if ($durationDays !== null && $durationDays < 1) {
            throw new DomainException('Service entitlement grant duration days must be positive.');
        }
        if ($dataBytes === null && $durationDays === null) {
            throw new DomainException('Service entitlement grant requires data, days, or both.');
        }

        return [$dataBytes, $durationDays];
    }

    private function grantType(?int $dataBytes, ?int $durationDays): ServiceMutationType
    {
        return match (true) {
            $dataBytes !== null && $durationDays !== null => ServiceMutationType::GrantDataDays,
            $dataBytes !== null => ServiceMutationType::GrantData,
            default => ServiceMutationType::GrantDays,
        };
    }

    /** @return array{pending:int,queued:int,succeeded:int,failed:int,needs_review:int,cancelled:int} */
    private function counts(Connection $connection, int $batchId): array
    {
        $counts = [
            'pending' => 0,
            'queued' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'needs_review' => 0,
            'cancelled' => 0,
        ];
        foreach ($connection->table('service_entitlement_grant_items')
            ->where('service_entitlement_grant_batch_id', $batchId)
            ->selectRaw('state, COUNT(*) AS aggregate')
            ->groupBy('state')
            ->get() as $row) {
            if (array_key_exists((string) $row->state, $counts)) {
                $counts[(string) $row->state] = (int) $row->aggregate;
            }
        }

        return $counts;
    }

    /**
     * @param  BatchRow  $batch
     */
    private function receipt(Connection $connection, object $batch, bool $replayed): ServiceEntitlementGrantBatchReceipt
    {
        $counts = $this->counts($connection, (int) $batch->id);

        return new ServiceEntitlementGrantBatchReceipt(
            $batch->public_id,
            $batch->state,
            $batch->selection_mode,
            $batch->selected_sales_server_id === null ? null : (int) $batch->selected_sales_server_id,
            (int) $batch->item_count,
            $counts['pending'],
            $counts['queued'],
            $counts['succeeded'],
            $counts['failed'],
            $counts['needs_review'],
            $counts['cancelled'],
            $batch->data_bytes === null ? null : (int) $batch->data_bytes,
            $batch->duration_days === null ? null : (int) $batch->duration_days,
            (bool) $batch->notify_customers,
            $batch->expires_at,
            $replayed,
        );
    }

    private function authorize(ServiceOperationalContext $context): void
    {
        $this->authority->assertFinalized();
        $this->authorizer->authorize($context->actorAdministratorId, self::PERMISSION);
    }

    private function assertBatchId(string $batchPublicId): void
    {
        if (! Str::isUlid($batchPublicId)) {
            throw new DomainException('Service entitlement grant batch public ID is invalid.');
        }
    }

    private function setBatchAuthority(Connection $connection, ?int $batchId): void
    {
        $this->databaseCapability->apply($connection);
        $connection->statement('SET @app_service_entitlement_grant_batch_authority = ?', [self::BATCH_AUTHORITY]);
        if ($batchId === null) {
            $connection->statement('SET @app_service_entitlement_grant_batch_id = NULL');
        } else {
            $connection->statement('SET @app_service_entitlement_grant_batch_id = ?', [$batchId]);
        }
    }

    private function clearBatchAuthority(Connection $connection): void
    {
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
