<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

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
 * @phpstan-type Settlement object{id:int|string,public_id:string,payment_intent_id:int|string,user_id:int|string,source_quote_id:int|string,amount_irr:int|string,currency:string}
 * @phpstan-type Intent object{id:int|string,public_id:string,purpose:string,user_id:int|string,source_quote_id:int|string|null,state:string,captured_at:?string}
 * @phpstan-type PaidOrder object{id:int|string,public_id:string,purchase_settlement_id:int|string|null,payment_intent_id:int|string|null,user_id:int|string,source_quote_id:int|string|null,state:string,state_version:int|string}
 * @phpstan-type PaidItem object{id:int|string,public_id:string,order_id:int|string,source_quote_id:int|string|null}
 * @phpstan-type PackageQuote object{id:int|string,public_id:string,user_id:int|string,action_snapshot:string,service_subscription_id:int|string|null,service_subscription_public_id:?string,service_target_id_snapshot:int|string|null,service_remote_identity_generation_snapshot:int|string|null,service_lifecycle_version_snapshot:int|string|null,service_package_code_snapshot:?string,service_package_duration_days_snapshot:int|string|null,service_package_data_bytes_snapshot:int|string|null}
 * @phpstan-type Service object{id:int|string,public_id:string,user_id:int|string,service_target_id:int|string|null,remote_service_id:?string,provisioned_at:?string,lifecycle_state:string,lifecycle_version:int|string,remote_identity_generation:int|string,mutation_generation:int|string,remote_deleted_at:?string}
 */
final readonly class ServicePurchaseMutationQueueService
{
    private const QUEUE_AUTHORITY = 'service_paid_mutation_queue_v1';

    /** @var list<string> */
    private const TERMINAL_STATES = ['succeeded', 'failed_final', 'compensated'];

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private OutboxPublisher $outbox,
    ) {}

    /** @requirement BUY-002 PAY-002 SVC-003 SVC-004 PRV-002 PRV-003 DAT-003 SEC-002 QUA-004 */
    public function queueFromSettlement(
        string $purchaseSettlementPublicId,
        string $requestKey,
        string $correlationId,
    ): ServiceMutationReceipt {
        $this->assertUlid($purchaseSettlementPublicId, 'Purchase settlement public ID');
        $requestHash = $this->requestKeyHash($requestKey);
        $this->assertToken($correlationId, 'Service purchase mutation correlation ID', 8, 64);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $purchaseSettlementPublicId,
            $requestHash,
            $correlationId,
        ): ServiceMutationReceipt {
            /** @var Settlement|null $settlement */
            $settlement = $connection->table('purchase_settlements')
                ->where('public_id', $purchaseSettlementPublicId)
                ->lockForUpdate()
                ->first(['id', 'public_id', 'payment_intent_id', 'user_id', 'source_quote_id', 'amount_irr', 'currency']);
            if ($settlement === null) {
                throw new DomainException('Purchase settlement does not exist.');
            }

            /** @var Intent|null $intent */
            $intent = $connection->table('payment_intents')
                ->where('id', (int) $settlement->payment_intent_id)
                ->lockForUpdate()
                ->first(['id', 'public_id', 'purpose', 'user_id', 'source_quote_id', 'state', 'captured_at']);
            if ($intent === null) {
                throw new RuntimeException('Captured purchase Payment Intent disappeared.');
            }

            /** @var PaidOrder|null $order */
            $order = $connection->table('orders')
                ->where('purchase_settlement_id', (int) $settlement->id)
                ->lockForUpdate()
                ->first(['id', 'public_id', 'purchase_settlement_id', 'payment_intent_id', 'user_id', 'source_quote_id', 'state', 'state_version']);
            if ($order === null) {
                throw new DomainException('Captured Service package settlement has not materialized its paid Order.');
            }

            /** @var PaidItem|null $item */
            $item = $connection->table('order_items')
                ->where('order_id', (int) $order->id)
                ->lockForUpdate()
                ->first(['id', 'public_id', 'order_id', 'source_quote_id']);
            if ($item === null) {
                throw new RuntimeException('Paid Service package Order Item disappeared.');
            }

            /** @var PackageQuote|null $quote */
            $quote = $connection->table('quotes')
                ->where('id', (int) $settlement->source_quote_id)
                ->lockForUpdate()
                ->first([
                    'id', 'public_id', 'user_id', 'action_snapshot', 'service_subscription_id', 'service_subscription_public_id',
                    'service_target_id_snapshot', 'service_remote_identity_generation_snapshot', 'service_lifecycle_version_snapshot',
                    'service_package_code_snapshot', 'service_package_duration_days_snapshot', 'service_package_data_bytes_snapshot',
                ]);
            if ($quote === null) {
                throw new RuntimeException('Service package Quote disappeared.');
            }
            $type = ServiceMutationType::tryFrom((string) $quote->action_snapshot);
            if ($type === null || ! $type->isPaidEntitlement()) {
                throw new DomainException('Purchase settlement is not a paid Service package action.');
            }

            if ((int) $intent->id !== (int) $settlement->payment_intent_id
                || $intent->purpose !== 'purchase'
                || $intent->state !== 'captured'
                || $intent->captured_at === null
                || (int) $intent->user_id !== (int) $settlement->user_id
                || $intent->source_quote_id === null
                || (int) $intent->source_quote_id !== (int) $quote->id
                || $order->purchase_settlement_id === null
                || (int) $order->purchase_settlement_id !== (int) $settlement->id
                || $order->payment_intent_id === null
                || (int) $order->payment_intent_id !== (int) $intent->id
                || (int) $order->user_id !== (int) $settlement->user_id
                || $order->source_quote_id === null
                || (int) $order->source_quote_id !== (int) $quote->id
                || $order->state !== 'paid'
                || (int) $order->state_version !== 1
                || (int) $item->order_id !== (int) $order->id
                || $item->source_quote_id === null
                || (int) $item->source_quote_id !== (int) $quote->id
                || (int) $quote->user_id !== (int) $settlement->user_id
                || $quote->service_subscription_id === null
                || $quote->service_subscription_public_id === null) {
                throw new DomainException('Paid Service package financial/commercial authority is inconsistent.');
            }

            if ($connection->table('provisioning_financial_invalidations')
                ->where('purchase_settlement_id', (int) $settlement->id)
                ->where('payment_intent_id', (int) $intent->id)
                ->exists()) {
                throw new DomainException('Paid Service package settlement has been financially invalidated.');
            }

            /** @var Service|null $service */
            $service = $connection->table('service_subscriptions')
                ->where('id', (int) $quote->service_subscription_id)
                ->lockForUpdate()
                ->first([
                    'id', 'public_id', 'user_id', 'service_target_id', 'remote_service_id', 'provisioned_at',
                    'lifecycle_state', 'lifecycle_version', 'remote_identity_generation', 'mutation_generation', 'remote_deleted_at',
                ]);
            if ($service === null) {
                throw new RuntimeException('Service package target Service disappeared.');
            }

            if ((int) $service->user_id !== (int) $settlement->user_id
                || ! hash_equals($service->public_id, $quote->service_subscription_public_id)
                || ! in_array($service->lifecycle_state, ['active', 'suspended'], true)
                || $service->remote_deleted_at !== null
                || $service->provisioned_at === null
                || $service->service_target_id === null
                || ! is_string($service->remote_service_id) || $service->remote_service_id === ''
                || $quote->service_target_id_snapshot === null
                || (int) $service->service_target_id !== (int) $quote->service_target_id_snapshot
                || $quote->service_remote_identity_generation_snapshot === null
                || (int) $service->remote_identity_generation !== (int) $quote->service_remote_identity_generation_snapshot
                || $quote->service_lifecycle_version_snapshot === null
                || (int) $service->lifecycle_version !== (int) $quote->service_lifecycle_version_snapshot) {
                throw new DomainException('Service changed after the paid package Quote and requires reconciliation before mutation.');
            }

            /** @var object{provisioning_operation_id:int|string}|null $existingAuthority */
            $existingAuthority = $connection->table('service_paid_mutation_authorities')
                ->where('purchase_order_item_id', (int) $item->id)
                ->lockForUpdate()
                ->first(['provisioning_operation_id']);
            if ($existingAuthority !== null) {
                $operation = $this->operation($connection, (int) $existingAuthority->provisioning_operation_id);
                if ($operation->request_key_hash !== null && ! hash_equals($operation->request_key_hash, $requestHash)) {
                    throw new DomainException('Paid Service package Order is already bound to a different mutation request.');
                }

                return $this->receipt($service, $operation, true);
            }

            $blockingDelivery = $connection->table('service_delivery_effects')
                ->where('blocking_service_subscription_id', (int) $service->id)
                ->first(['id']);
            if ($blockingDelivery !== null) {
                throw new DomainException('Paid Service mutation is blocked by an unresolved delivery boundary.');
            }
            $pendingInitialDelivery = $connection->table('service_initial_delivery_fences')
                ->where('service_subscription_id', (int) $service->id)
                ->first(['service_subscription_id']);
            if ($pendingInitialDelivery !== null) {
                throw new DomainException('Paid Service mutation is blocked until initial delivery is durably scheduled.');
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

            $generation = $this->nonNegativeInt($service->mutation_generation, 'Service mutation generation') + 1;
            $remoteGeneration = $this->positiveInt($service->remote_identity_generation, 'Service remote identity generation');
            $lifecycleVersion = $this->nonNegativeInt($service->lifecycle_version, 'Service lifecycle version');
            $timestamp = $this->timestamp();

            $this->setQueueAuthority($connection, $generation, $requestHash, $correlationId);
            try {
                $updated = $connection->table('service_subscriptions')
                    ->where('id', (int) $service->id)
                    ->where('mutation_generation', $generation - 1)
                    ->where('remote_identity_generation', $remoteGeneration)
                    ->where('lifecycle_version', $lifecycleVersion)
                    ->update(['mutation_generation' => $generation, 'updated_at' => $timestamp]);
                if ($updated !== 1) {
                    throw new RuntimeException('Paid Service mutation generation claim lost its authority.');
                }

                $operationPublicId = (string) Str::ulid();
                $operationId = (int) $connection->table('provisioning_operations')->insertGetId([
                    'public_id' => $operationPublicId,
                    'operation_key' => 'service-mutation:'.$service->public_id.':'.$generation.':'.$type->value,
                    'operation_type' => $type->value,
                    'order_id' => (int) $order->id,
                    'order_item_id' => (int) $item->id,
                    'service_subscription_id' => (int) $service->id,
                    'user_id' => (int) $service->user_id,
                    'state' => ProvisioningState::Queued->value,
                    'state_version' => 1,
                    'correlation_id' => $correlationId,
                    'service_target_id' => (int) $service->service_target_id,
                    'remote_service_id' => $service->remote_service_id,
                    'operation_generation' => $generation,
                    'target_remote_identity_generation' => $remoteGeneration,
                    'target_lifecycle_version' => $lifecycleVersion,
                    'request_key_hash' => $requestHash,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                $connection->table('service_paid_mutation_authorities')->insert([
                    'public_id' => (string) Str::ulid(),
                    'provisioning_operation_id' => $operationId,
                    'service_subscription_id' => (int) $service->id,
                    'source_quote_id' => (int) $quote->id,
                    'purchase_order_id' => (int) $order->id,
                    'purchase_order_item_id' => (int) $item->id,
                    'purchase_settlement_id' => (int) $settlement->id,
                    'payment_intent_id' => (int) $intent->id,
                    'action' => $type->value,
                    'package_code' => $quote->service_package_code_snapshot,
                    'duration_days' => $quote->service_package_duration_days_snapshot,
                    'data_bytes' => $quote->service_package_data_bytes_snapshot,
                    'quoted_service_target_id' => (int) $service->service_target_id,
                    'quoted_remote_identity_generation' => $remoteGeneration,
                    'quoted_lifecycle_version' => $lifecycleVersion,
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
                );

                return $this->receipt($service, $operation, false);
            } finally {
                $this->clearQueueAuthority($connection);
            }
        }, 3);
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

    private function operation(Connection $connection, int $id): object
    {
        $row = $connection->table('provisioning_operations')->where('id', $id)->lockForUpdate()->first([
            'id', 'public_id', 'operation_type', 'service_subscription_id', 'state', 'state_version',
            'operation_generation', 'target_remote_identity_generation', 'target_lifecycle_version', 'request_key_hash',
        ]);
        if ($row === null) {
            throw new RuntimeException('Paid Service mutation operation disappeared.');
        }

        return $row;
    }

    private function receipt(object $service, object $operation, bool $replayed): ServiceMutationReceipt
    {
        $type = ServiceMutationType::tryFrom((string) $operation->operation_type)
            ?? throw new RuntimeException('Stored paid Service mutation type is invalid.');
        $state = ProvisioningState::tryFrom((string) $operation->state)
            ?? throw new RuntimeException('Stored paid Service mutation state is invalid.');

        return new ServiceMutationReceipt(
            (string) $service->public_id,
            (string) $operation->public_id,
            $type,
            $this->nonNegativeInt($operation->operation_generation, 'Operation generation'),
            $state,
            $this->positiveInt($operation->state_version, 'Operation state version'),
            $replayed,
        );
    }

    private function requestKeyHash(string $requestKey): string
    {
        $this->assertToken($requestKey, 'Paid Service mutation request key', 8, 128);

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
