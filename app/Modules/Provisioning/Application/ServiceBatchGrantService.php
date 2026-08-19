<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Orders\Application\NonPaidOrderService;
use App\Modules\Orders\Application\OrderSourceAuthorizationService;
use App\Shared\Application\Clock;
use DateInterval;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type BatchRow object{id:int|string,public_id:string,request_key_hash:string,payload_hash:string,reason_code:string,actor_administrator_id:int|string,state:string,item_count:int|string,succeeded_count:int|string,failed_count:int|string,correlation_id:string,items_committed_at:?string,completed_at:?string}
 * @phpstan-type BatchItemRow object{id:int|string,public_id:string,service_batch_grant_id:int|string,position:int|string,user_id:int|string,plan_offering_id:int|string,request_key_hash:string,state:string,attempt_count:int|string,claim_token:?string,claim_expires_at:?string,order_source_authorization_id:int|string|null,order_id:int|string|null,service_subscription_id:int|string|null,provisioning_operation_id:int|string|null,error_code:?string,correlation_id:string}
 */
final readonly class ServiceBatchGrantService
{
    private const PERMISSION = 'services.grant_batch';

    private const BATCH_AUTHORITY = 'service_batch_grant_v1';

    private const MAX_ITEMS = 50;

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private AdministratorPermissionAuthorizer $authorizer,
        private ServiceOperationalAuthorityGuard $authority,
        private ServiceOperationalDatabaseCapability $databaseCapability,
        private OrderSourceAuthorizationService $sourceAuthorizations,
        private NonPaidOrderService $orders,
        private InitialProvisioningQueueService $provisioning,
        private ServiceOperationalAudit $audit,
    ) {}

    /**
     * @param  list<array{user_id:int,plan_offering_id:int}>  $items
     *
     * @requirement SVC-011 SVC-012 ADM-002 ARCH-003 ARCH-004 DAT-003 SEC-002 QUA-004
     */
    public function create(ServiceOperationalContext $context, array $items): ServiceBatchGrantReceipt
    {
        $this->authority->assertFinalized();
        $this->authorizer->authorize($context->actorAdministratorId, self::PERMISSION);
        $normalized = $this->normalizedItems($items);
        $payloadHash = $this->payloadHash($context, $normalized);

        return $this->database->connection()->transaction(function (Connection $connection) use ($context, $normalized, $payloadHash): ServiceBatchGrantReceipt {
            /** @var BatchRow|null $existing */
            $existing = $connection->table('service_batch_grants')->where('request_key_hash', $context->requestHash())->lockForUpdate()->first();
            if ($existing !== null) {
                $this->assertBatchReplay($connection, $existing, $context, $normalized, $payloadHash);

                return $this->receipt($existing, true);
            }

            $batchPublicId = (string) Str::ulid();
            $timestamp = $this->timestamp();
            $auditId = $this->audit->record(
                $connection,
                'service.operational.batch.created',
                'service_batch_grant',
                $batchPublicId,
                $context,
                ['state' => null, 'item_count' => 0, 'payload_hash' => null],
                ['state' => 'active', 'item_count' => count($normalized), 'payload_hash' => $payloadHash],
            );
            $this->setBatchAuthority($connection);
            try {
                $batchId = (int) $connection->table('service_batch_grants')->insertGetId([
                    'public_id' => $batchPublicId,
                    'request_key_hash' => $context->requestHash(),
                    'payload_hash' => $payloadHash,
                    'reason_code' => $context->reasonCode,
                    'actor_administrator_id' => $context->actorAdministratorId,
                    'audit_log_id' => $auditId,
                    'state' => 'active',
                    'item_count' => count($normalized),
                    'succeeded_count' => 0,
                    'failed_count' => 0,
                    'correlation_id' => $context->correlationId,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                    'items_committed_at' => null,
                    'completed_at' => null,
                ]);
                foreach ($normalized as $position => $item) {
                    $connection->table('service_batch_grant_items')->insert([
                        'public_id' => (string) Str::ulid(),
                        'service_batch_grant_id' => $batchId,
                        'position' => $position + 1,
                        'user_id' => $item['user_id'],
                        'plan_offering_id' => $item['plan_offering_id'],
                        'request_key_hash' => $this->itemRequestHash($context->requestHash(), $payloadHash, $position + 1, $item),
                        'state' => 'pending',
                        'attempt_count' => 0,
                        'claim_token' => null,
                        'claim_expires_at' => null,
                        'order_source_authorization_id' => null,
                        'order_id' => null,
                        'service_subscription_id' => null,
                        'provisioning_operation_id' => null,
                        'error_code' => null,
                        'correlation_id' => $context->correlationId,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                }
                $committed = $connection->table('service_batch_grants')
                    ->where('id', $batchId)
                    ->whereNull('items_committed_at')
                    ->update([
                        'items_committed_at' => $this->timestamp(),
                        'updated_at' => $this->timestamp(),
                    ]);
                if ($committed !== 1) {
                    throw new RuntimeException('Service batch grant item family did not finalize.');
                }
            } finally {
                $this->clearBatchAuthority($connection);
            }

            /** @var BatchRow|null $created */
            $created = $connection->table('service_batch_grants')->where('id', $batchId)->first();
            if ($created === null) {
                throw new RuntimeException('Service batch grant disappeared after creation.');
            }

            return $this->receipt($created, false);
        }, 3);
    }

    /** @requirement SVC-011 SVC-012 ARCH-003 ARCH-004 DAT-003 SEC-002 QUA-004 */
    public function resume(string $batchPublicId, ServiceOperationalContext $context): ServiceBatchGrantReceipt
    {
        $this->authority->assertFinalized();
        $this->authorizer->authorize($context->actorAdministratorId, self::PERMISSION);
        if (! Str::isUlid($batchPublicId)) {
            throw new DomainException('Service batch grant public ID is invalid.');
        }

        $didWork = false;
        $attemptedItemIds = [];
        for ($iteration = 0; $iteration < self::MAX_ITEMS; $iteration++) {
            $claim = $this->claimNext($batchPublicId, $context, $attemptedItemIds);
            if ($claim === null) {
                break;
            }
            $didWork = true;
            $attemptedItemIds[] = (int) $claim->id;
            $this->processClaim($claim, $context);
        }

        /** @var BatchRow|null $batch */
        $batch = $this->database->connection()->table('service_batch_grants')->where('public_id', $batchPublicId)->first();
        if ($batch === null) {
            throw new DomainException('Service batch grant does not exist.');
        }
        $this->assertBatchContext($batch, $context);

        return $this->receipt($batch, ! $didWork);
    }

    public function pause(string $batchPublicId, ServiceOperationalContext $context): ServiceBatchGrantReceipt
    {
        return $this->changeBatchState($batchPublicId, $context, 'active', 'paused');
    }

    public function activate(string $batchPublicId, ServiceOperationalContext $context): ServiceBatchGrantReceipt
    {
        return $this->changeBatchState($batchPublicId, $context, 'paused', 'active');
    }

    public function cancel(string $batchPublicId, ServiceOperationalContext $context): ServiceBatchGrantReceipt
    {
        $this->authority->assertFinalized();
        $this->authorizer->authorize($context->actorAdministratorId, self::PERMISSION);
        if (! Str::isUlid($batchPublicId)) {
            throw new DomainException('Service batch grant public ID is invalid.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use ($batchPublicId, $context): ServiceBatchGrantReceipt {
            /** @var BatchRow|null $batch */
            $batch = $connection->table('service_batch_grants')->where('public_id', $batchPublicId)->lockForUpdate()->first();
            if ($batch === null) {
                throw new DomainException('Service batch grant does not exist.');
            }
            $this->assertBatchContext($batch, $context);
            if ($batch->state === 'cancelled') {
                return $this->receipt($batch, true);
            }
            if ($batch->state === 'completed') {
                throw new DomainException('Completed Service batch grant cannot be cancelled.');
            }
            if ($connection->table('service_batch_grant_items')
                ->where('service_batch_grant_id', (int) $batch->id)
                ->where('state', 'processing')
                ->where('claim_expires_at', '>', $this->timestamp())
                ->exists()) {
                throw new DomainException('Service batch grant has an active processing claim.');
            }
            if ($connection->table('service_batch_grant_items')
                ->where('service_batch_grant_id', (int) $batch->id)
                ->where('state', '<>', 'succeeded')
                ->where('attempt_count', '>', 0)
                ->exists()) {
                throw new DomainException('Service batch grant has started unfinished work and must be resumed or reconciled before cancellation.');
            }
            if ($connection->table('service_batch_grant_items')
                ->where('service_batch_grant_id', (int) $batch->id)
                ->where('state', '<>', 'succeeded')
                ->where(function ($query): void {
                    $query->whereNotNull('order_source_authorization_id')
                        ->orWhereNotNull('order_id')
                        ->orWhereNotNull('service_subscription_id')
                        ->orWhereNotNull('provisioning_operation_id');
                })
                ->exists()) {
                throw new DomainException('Service batch grant has partial downstream authority and must be resumed or reconciled before cancellation.');
            }

            $this->setBatchAuthority($connection);
            try {
                $connection->table('service_batch_grant_items')
                    ->where('service_batch_grant_id', (int) $batch->id)
                    ->whereIn('state', ['pending', 'failed', 'processing'])
                    ->update([
                        'state' => 'cancelled',
                        'claim_token' => null,
                        'claim_expires_at' => null,
                        'error_code' => null,
                        'updated_at' => $this->timestamp(),
                    ]);
                $succeeded = $connection->table('service_batch_grant_items')->where('service_batch_grant_id', (int) $batch->id)->where('state', 'succeeded')->count();
                $failed = $connection->table('service_batch_grant_items')->where('service_batch_grant_id', (int) $batch->id)->where('state', 'failed')->count();
                $connection->table('service_batch_grants')->where('id', (int) $batch->id)->update([
                    'state' => 'cancelled',
                    'succeeded_count' => $succeeded,
                    'failed_count' => $failed,
                    'updated_at' => $this->timestamp(),
                ]);
            } finally {
                $this->clearBatchAuthority($connection);
            }

            /** @var BatchRow|null $cancelled */
            $cancelled = $connection->table('service_batch_grants')->where('id', (int) $batch->id)->first();
            if ($cancelled === null) {
                throw new RuntimeException('Service batch grant disappeared after cancellation.');
            }

            return $this->receipt($cancelled, false);
        }, 3);
    }

    /**
     * @param  list<int>  $excludedItemIds
     * @return BatchItemRow|null
     */
    private function claimNext(string $batchPublicId, ServiceOperationalContext $context, array $excludedItemIds): ?object
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($batchPublicId, $context, $excludedItemIds): ?object {
            /** @var BatchRow|null $batch */
            $batch = $connection->table('service_batch_grants')->where('public_id', $batchPublicId)->lockForUpdate()->first();
            if ($batch === null) {
                throw new DomainException('Service batch grant does not exist.');
            }
            $this->assertBatchContext($batch, $context);
            if ($batch->state === 'completed' || $batch->state === 'cancelled') {
                return null;
            }
            if ($batch->state !== 'active') {
                throw new DomainException('Service batch grant is not active.');
            }

            $itemQuery = $connection->table('service_batch_grant_items')
                ->where('service_batch_grant_id', (int) $batch->id);
            if ($excludedItemIds !== []) {
                $itemQuery->whereNotIn('id', $excludedItemIds);
            }
            /** @var BatchItemRow|null $item */
            $item = $itemQuery
                ->where(function ($query): void {
                    $query->whereIn('state', ['pending', 'failed'])
                        ->orWhere(function ($query): void {
                            $query->where('state', 'processing')->where('claim_expires_at', '<=', $this->timestamp());
                        });
                })
                ->orderBy('position')
                ->lockForUpdate()
                ->first();
            if ($item === null) {
                return null;
            }

            $claimToken = (string) Str::ulid();
            $claimExpiresAt = $this->clock->now()->add(new DateInterval('PT5M'))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $this->setBatchAuthority($connection);
            try {
                $updated = $connection->table('service_batch_grant_items')->where('id', (int) $item->id)->update([
                    'state' => 'processing',
                    'attempt_count' => (int) $item->attempt_count + 1,
                    'claim_token' => $claimToken,
                    'claim_expires_at' => $claimExpiresAt,
                    'error_code' => null,
                    'updated_at' => $this->timestamp(),
                ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Service batch item claim was lost.');
                }
                $this->refreshBatchCounts($connection, (int) $item->service_batch_grant_id);
            } finally {
                $this->clearBatchAuthority($connection);
            }

            /** @var BatchItemRow|null $claimed */
            $claimed = $connection->table('service_batch_grant_items')->where('id', (int) $item->id)->first();

            return $claimed;
        }, 3);
    }

    /** @param BatchItemRow $item */
    private function processClaim(object $item, ServiceOperationalContext $context): void
    {
        if (! is_string($item->claim_token) || $item->claim_token === '') {
            throw new RuntimeException('Service batch item claim token is unavailable.');
        }

        [$batchPublicId, $acceptedReasonCode] = $this->batchExecutionIdentity((int) $item->service_batch_grant_id);
        $sourceAuthorizationId = null;
        $orderId = null;
        $serviceSubscriptionId = null;
        $provisioningOperationId = null;
        try {
            $source = $this->sourceAuthorizations->authorizeAdministratorGrant(
                'service-batch:'.$batchPublicId.':'.$item->public_id,
                $context->actorAdministratorId,
                (int) $item->user_id,
                (int) $item->plan_offering_id,
                $acceptedReasonCode,
                $item->correlation_id,
            );
            $sourceAuthorizationId = $source->authorizationId;
            $order = $this->orders->materialize($source->publicId, $item->correlation_id);
            $orderId = $order->orderId;
            $queue = $this->provisioning->queueInitial($order->orderPublicId, $item->correlation_id);
            $serviceSubscriptionId = $queue->serviceSubscriptionId;
            $provisioningOperationId = $queue->provisioningOperationId;
        } catch (Throwable $exception) {
            $this->finishClaim(
                $item,
                $sourceAuthorizationId,
                $orderId,
                $serviceSubscriptionId,
                $provisioningOperationId,
                $this->safeErrorCode($exception),
            );

            return;
        }

        $this->finishClaim(
            $item,
            $sourceAuthorizationId,
            $orderId,
            $serviceSubscriptionId,
            $provisioningOperationId,
            null,
        );
    }

    /** @param BatchItemRow $item */
    private function finishClaim(
        object $item,
        ?int $sourceAuthorizationId,
        ?int $orderId,
        ?int $serviceSubscriptionId,
        ?int $provisioningOperationId,
        ?string $errorCode,
    ): void {
        $this->database->connection()->transaction(function (Connection $connection) use ($item, $sourceAuthorizationId, $orderId, $serviceSubscriptionId, $provisioningOperationId, $errorCode): void {
            $batch = $connection->table('service_batch_grants')
                ->where('id', (int) $item->service_batch_grant_id)
                ->lockForUpdate()
                ->first(['id']);
            if ($batch === null) {
                throw new RuntimeException('Service batch grant disappeared while finalizing claim.');
            }

            /** @var BatchItemRow|null $locked */
            $locked = $connection->table('service_batch_grant_items')->where('id', (int) $item->id)->lockForUpdate()->first();
            if ($locked === null) {
                throw new RuntimeException('Service batch item disappeared while finalizing claim.');
            }
            if ($locked->state === 'succeeded') {
                return;
            }
            if ($locked->state !== 'processing' || ! is_string($locked->claim_token)
                || ! hash_equals($locked->claim_token, (string) $item->claim_token)) {
                throw new RuntimeException('Service batch item claim is stale.');
            }

            $this->setBatchAuthority($connection);
            try {
                $updated = $connection->table('service_batch_grant_items')->where('id', (int) $locked->id)->update($errorCode === null ? [
                    'state' => 'succeeded',
                    'claim_token' => null,
                    'claim_expires_at' => null,
                    'order_source_authorization_id' => $sourceAuthorizationId,
                    'order_id' => $orderId,
                    'service_subscription_id' => $serviceSubscriptionId,
                    'provisioning_operation_id' => $provisioningOperationId,
                    'error_code' => null,
                    'updated_at' => $this->timestamp(),
                ] : [
                    'state' => 'failed',
                    'claim_token' => null,
                    'claim_expires_at' => null,
                    'order_source_authorization_id' => $sourceAuthorizationId,
                    'order_id' => $orderId,
                    'service_subscription_id' => $serviceSubscriptionId,
                    'provisioning_operation_id' => $provisioningOperationId,
                    'error_code' => $errorCode,
                    'updated_at' => $this->timestamp(),
                ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Service batch item finalization was lost.');
                }
                $this->refreshBatchCounts($connection, (int) $locked->service_batch_grant_id);
            } finally {
                $this->clearBatchAuthority($connection);
            }
        }, 3);
    }

    private function refreshBatchCounts(Connection $connection, int $batchId): void
    {
        $succeeded = $connection->table('service_batch_grant_items')->where('service_batch_grant_id', $batchId)->where('state', 'succeeded')->count();
        $failed = $connection->table('service_batch_grant_items')->where('service_batch_grant_id', $batchId)->where('state', 'failed')->count();
        $total = $connection->table('service_batch_grant_items')->where('service_batch_grant_id', $batchId)->count();
        /** @var object{state:string,item_count:int|string,items_committed_at:?string}|null $batch */
        $batch = $connection->table('service_batch_grants')->where('id', $batchId)->first(['state', 'item_count', 'items_committed_at']);
        if ($batch === null || ! is_string($batch->state) || ! in_array($batch->state, ['active', 'paused'], true)) {
            throw new RuntimeException('Service batch grant cannot refresh counts from its current state.');
        }
        if ($batch->items_committed_at === null || $total !== (int) $batch->item_count) {
            throw new RuntimeException('Service batch grant item family is incomplete.');
        }
        $state = $succeeded === $total ? 'completed' : $batch->state;
        $connection->table('service_batch_grants')->where('id', $batchId)->update([
            'state' => $state,
            'succeeded_count' => $succeeded,
            'failed_count' => $failed,
            'updated_at' => $this->timestamp(),
            'completed_at' => $state === 'completed' ? $this->timestamp() : null,
        ]);
    }

    private function changeBatchState(string $batchPublicId, ServiceOperationalContext $context, string $from, string $to): ServiceBatchGrantReceipt
    {
        $this->authority->assertFinalized();
        $this->authorizer->authorize($context->actorAdministratorId, self::PERMISSION);
        if (! Str::isUlid($batchPublicId)) {
            throw new DomainException('Service batch grant public ID is invalid.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use ($batchPublicId, $context, $from, $to): ServiceBatchGrantReceipt {
            /** @var BatchRow|null $batch */
            $batch = $connection->table('service_batch_grants')->where('public_id', $batchPublicId)->lockForUpdate()->first();
            if ($batch === null) {
                throw new DomainException('Service batch grant does not exist.');
            }
            $this->assertBatchContext($batch, $context);
            if ($batch->state === $to) {
                return $this->receipt($batch, true);
            }
            if ($batch->state !== $from) {
                throw new DomainException('Service batch grant cannot change state from its current state.');
            }
            if ($from === 'active' && $to === 'paused'
                && $connection->table('service_batch_grant_items')
                    ->where('service_batch_grant_id', (int) $batch->id)
                    ->where('state', 'processing')
                    ->where('claim_expires_at', '>', $this->timestamp())
                    ->exists()) {
                throw new DomainException('Service batch grant cannot pause while an item claim is active.');
            }
            $this->setBatchAuthority($connection);
            try {
                $updated = $connection->table('service_batch_grants')->where('id', (int) $batch->id)->where('state', $from)->update([
                    'state' => $to,
                    'updated_at' => $this->timestamp(),
                ]);
            } finally {
                $this->clearBatchAuthority($connection);
            }
            if ($updated !== 1) {
                throw new RuntimeException('Service batch grant state transition was lost.');
            }
            /** @var BatchRow|null $changed */
            $changed = $connection->table('service_batch_grants')->where('id', (int) $batch->id)->first();
            if ($changed === null) {
                throw new RuntimeException('Service batch grant disappeared after state transition.');
            }

            return $this->receipt($changed, false);
        }, 3);
    }

    /**
     * @param  list<array{user_id:int,plan_offering_id:int}>  $items
     * @return list<array{user_id:int,plan_offering_id:int}>
     */
    private function normalizedItems(array $items): array
    {
        if ($items === [] || count($items) > self::MAX_ITEMS) {
            throw new DomainException('Service batch grant must contain between 1 and 50 items.');
        }
        $normalized = [];
        $seen = [];
        foreach ($items as $item) {
            if (! isset($item['user_id'], $item['plan_offering_id']) || $item['user_id'] < 1 || $item['plan_offering_id'] < 1) {
                throw new DomainException('Service batch grant item identity is invalid.');
            }
            $key = $item['user_id'].':'.$item['plan_offering_id'];
            if (isset($seen[$key])) {
                throw new DomainException('Service batch grant contains a duplicate target.');
            }
            $seen[$key] = true;
            $normalized[] = ['user_id' => $item['user_id'], 'plan_offering_id' => $item['plan_offering_id']];
        }

        return $normalized;
    }

    /** @param list<array{user_id:int,plan_offering_id:int}> $items */
    private function payloadHash(ServiceOperationalContext $context, array $items): string
    {
        return hash('sha256', json_encode([
            'actor_administrator_id' => $context->actorAdministratorId,
            'items' => $items,
            'reason_code' => $context->reasonCode,
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array{user_id:int,plan_offering_id:int} $item */
    private function itemRequestHash(string $batchRequestHash, string $payloadHash, int $position, array $item): string
    {
        return hash('sha256', $batchRequestHash.':'.$payloadHash.':'.$position.':'.$item['user_id'].':'.$item['plan_offering_id']);
    }

    /**
     * @param  BatchRow  $batch
     * @param  list<array{user_id:int,plan_offering_id:int}>  $items
     */
    private function assertBatchReplay(Connection $connection, object $batch, ServiceOperationalContext $context, array $items, string $payloadHash): void
    {
        $this->assertBatchContext($batch, $context);
        if (! hash_equals($batch->payload_hash, $payloadHash) || (int) $batch->item_count !== count($items)) {
            throw new DomainException('Service batch grant request fingerprint conflicts with existing evidence.');
        }
        $rows = $connection->table('service_batch_grant_items')->where('service_batch_grant_id', (int) $batch->id)->orderBy('position')->get([
            'position', 'user_id', 'plan_offering_id', 'request_key_hash',
        ]);
        if ($rows->count() !== count($items)) {
            throw new RuntimeException('Service batch grant item evidence is incomplete.');
        }
        foreach ($rows as $index => $row) {
            $item = $items[$index];
            if ((int) $row->position !== $index + 1
                || (int) $row->user_id !== $item['user_id']
                || (int) $row->plan_offering_id !== $item['plan_offering_id']
                || ! hash_equals((string) $row->request_key_hash, $this->itemRequestHash($context->requestHash(), $payloadHash, $index + 1, $item))) {
                throw new DomainException('Service batch grant request fingerprint conflicts with existing evidence.');
            }
        }
    }

    /** @param BatchRow $batch */
    private function assertBatchContext(object $batch, ServiceOperationalContext $context): void
    {
        if ((int) $batch->actor_administrator_id !== $context->actorAdministratorId
            || ! hash_equals($batch->request_key_hash, $context->requestHash())
            || ! hash_equals($batch->correlation_id, $context->correlationId)
            || ! hash_equals($batch->reason_code, $context->reasonCode)
            || $batch->items_committed_at === null) {
            throw new DomainException('Service batch grant request identity conflicts with existing evidence.');
        }
    }

    /** @return array{string,string} */
    private function batchExecutionIdentity(int $batchId): array
    {
        /** @var object{public_id:string,reason_code:string,items_committed_at:?string}|null $batch */
        $batch = $this->database->connection()->table('service_batch_grants')->where('id', $batchId)->first([
            'public_id', 'reason_code', 'items_committed_at',
        ]);
        if ($batch === null || ! Str::isUlid($batch->public_id) || $batch->items_committed_at === null
            || preg_match('/\A[a-z0-9_.-]{1,64}\z/', $batch->reason_code) !== 1) {
            throw new RuntimeException('Service batch grant execution identity is unavailable.');
        }

        return [$batch->public_id, $batch->reason_code];
    }

    private function safeErrorCode(Throwable $exception): string
    {
        return $exception instanceof DomainException ? 'domain_rejected' : 'processing_failed';
    }

    /** @param BatchRow $batch */
    private function receipt(object $batch, bool $replayed): ServiceBatchGrantReceipt
    {
        return new ServiceBatchGrantReceipt(
            (int) $batch->id,
            $batch->public_id,
            $batch->state,
            (int) $batch->item_count,
            (int) $batch->succeeded_count,
            (int) $batch->failed_count,
            $replayed,
        );
    }

    private function setBatchAuthority(Connection $connection): void
    {
        $this->databaseCapability->apply($connection);
        try {
            $connection->statement('SET @app_service_batch_authority = ?', [self::BATCH_AUTHORITY]);
        } catch (\Throwable $exception) {
            $this->databaseCapability->clear($connection);
            throw $exception;
        }
    }

    private function clearBatchAuthority(Connection $connection): void
    {
        try {
            $this->databaseCapability->clear($connection);
        } finally {
            $connection->statement('SET @app_service_batch_authority = NULL');
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
