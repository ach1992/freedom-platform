<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Orders\Domain\OrderState;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Shared\Application\Clock;
use App\Shared\Application\OutboxPublisher;
use App\Shared\Application\SafeOutboxPayload;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-type OrderLocator object{id:int|string,public_id:string,source_type:string,purchase_settlement_id:int|string|null,payment_intent_id:int|string|null}
 * @phpstan-type SettlementRow object{id:int|string,public_id:string,payment_intent_id:int|string,user_id:int|string,source_quote_id:int|string,source_quote_public_id:string,amount_irr:int|string,currency:string,settled_at:string}
 * @phpstan-type IntentRow object{id:int|string,public_id:string,purpose:string,user_id:int|string,wallet_account_id:int|string|null,source_quote_id:int|string|null,source_quote_public_id:string|null,source_quote_configuration_hash:string|null,amount_irr:int|string,currency:string,state:string,captured_at:string|null}
 * @phpstan-type OrderRow object{id:int|string,public_id:string,source_type:string,purchase_settlement_id:int|string|null,purchase_settlement_public_id:string|null,payment_intent_id:int|string|null,payment_intent_public_id:string|null,user_id:int|string,source_quote_id:int|string|null,source_quote_public_id:string|null,source_quote_configuration_hash:string|null,state:string,state_version:int|string,total_amount_irr:int|string,settled_amount_irr:int|string|null,currency:string,paid_at:string|null}
 * @phpstan-type OrderItemRow object{id:int|string,public_id:string,order_id:int|string,line_number:int|string,source_quote_id:int|string,source_quote_public_id:string}
 * @phpstan-type ServiceRow object{id:int|string,public_id:string,order_id:int|string,order_item_id:int|string,user_id:int|string,creation_correlation_id:string}
 * @phpstan-type OperationRow object{id:int|string,public_id:string,operation_key:string,operation_type:string,order_id:int|string,order_item_id:int|string,service_subscription_id:int|string,user_id:int|string,state:string,state_version:int|string,correlation_id:string}
 * @phpstan-type OutboxRow object{id:string,event_key:string,event_type:string,aggregate_type:string,aggregate_id:string,payload:string,payload_hash:string,correlation_id:string}
 */
final readonly class InitialProvisioningQueueService
{
    private const SOURCE_TYPE = 'purchase';
    private const OPERATION_TYPE = 'initial_provision';
    private const EVENT_TYPE = 'provisioning.initial.requested';
    private const AGGREGATE_TYPE = 'provisioning_operation';
    private const DEADLOCK_RETRY_ATTEMPTS = 3;

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private OutboxPublisher $outbox,
    ) {}

    /** @requirement BUY-001 PAY-002 PAY-003 PRV-002 PRV-003 ARCH-003 ARCH-004 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 QUA-001 QUA-004 */
    public function queueInitial(string $orderPublicId, string $correlationId): ProvisioningQueueReceipt
    {
        $this->assertUlid($orderPublicId, 'Order public ID');
        $this->assertToken($correlationId, 'Provisioning correlation ID', 8, 64);

        return $this->database->connection()->transaction(function (Connection $connection) use ($orderPublicId, $correlationId): ProvisioningQueueReceipt {
            $locator = $this->orderLocator($connection, $orderPublicId);
            if ($locator === null) {
                throw new DomainException('Purchase Order does not exist.');
            }
            if ($locator->source_type !== self::SOURCE_TYPE
                || $locator->purchase_settlement_id === null
                || $locator->payment_intent_id === null) {
                throw new DomainException('Only authoritative purchase Orders can queue initial provisioning.');
            }

            // Preserve the repository-wide financial lock order: settlement -> payment intent -> Order -> Item.
            $settlement = $this->settlementById(
                $connection,
                $this->positiveDatabaseInt($locator->purchase_settlement_id, 'Purchase settlement ID'),
                true,
            );
            $intent = $this->intentById(
                $connection,
                $this->positiveDatabaseInt($settlement->payment_intent_id, 'Payment intent ID'),
                true,
            );
            $order = $this->orderById($connection, $this->positiveDatabaseInt($locator->id, 'Order ID'), true);
            $item = $this->orderItem($connection, $this->positiveDatabaseInt($order->id, 'Order ID'), true);

            $existingOperation = $this->operationByItem($connection, $this->positiveDatabaseInt($item->id, 'Order Item ID'), true);
            if ($existingOperation !== null) {
                return $this->replayReceipt($connection, $order, $item, $settlement, $intent, $existingOperation);
            }

            $this->assertNewQueueAuthority($order, $item, $settlement, $intent);

            $timestamp = $this->timestamp();
            $servicePublicId = (string) Str::ulid();
            $serviceId = (int) $connection->table('service_subscriptions')->insertGetId([
                'public_id' => $servicePublicId,
                'order_id' => $this->positiveDatabaseInt($order->id, 'Order ID'),
                'order_item_id' => $this->positiveDatabaseInt($item->id, 'Order Item ID'),
                'user_id' => $this->positiveDatabaseInt($order->user_id, 'Order user ID'),
                'creation_correlation_id' => $correlationId,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $operationPublicId = (string) Str::ulid();
            $operationKey = $this->operationKey($item->public_id);
            $operationId = (int) $connection->table('provisioning_operations')->insertGetId([
                'public_id' => $operationPublicId,
                'operation_key' => $operationKey,
                'operation_type' => self::OPERATION_TYPE,
                'order_id' => $this->positiveDatabaseInt($order->id, 'Order ID'),
                'order_item_id' => $this->positiveDatabaseInt($item->id, 'Order Item ID'),
                'service_subscription_id' => $serviceId,
                'user_id' => $this->positiveDatabaseInt($order->user_id, 'Order user ID'),
                'state' => ProvisioningState::Queued->value,
                'state_version' => 1,
                'correlation_id' => $correlationId,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $payload = $this->outboxPayload($order->public_id, $item->public_id, $servicePublicId, $operationPublicId);
            $eventId = $this->outbox->publish(
                (string) Str::uuid(),
                $this->eventKey($operationPublicId),
                self::EVENT_TYPE,
                self::AGGREGATE_TYPE,
                $operationPublicId,
                $payload,
                $correlationId,
            );

            $updated = $connection->table('orders')
                ->where('id', $this->positiveDatabaseInt($order->id, 'Order ID'))
                ->where('state', OrderState::Paid->value)
                ->where('state_version', 1)
                ->update([
                    'state' => OrderState::ProvisioningQueued->value,
                    'state_version' => 2,
                    'updated_at' => $timestamp,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Purchase Order provisioning transition lost its authoritative state.');
            }

            $this->recordAudit(
                $connection,
                $order->public_id,
                $item->public_id,
                $servicePublicId,
                $operationPublicId,
                $eventId,
                $correlationId,
            );

            return new ProvisioningQueueReceipt(
                $this->positiveDatabaseInt($order->id, 'Order ID'),
                $order->public_id,
                $item->public_id,
                $serviceId,
                $servicePublicId,
                $operationId,
                $operationPublicId,
                $eventId,
                OrderState::ProvisioningQueued,
                2,
                ProvisioningState::Queued,
                1,
                false,
            );
        }, self::DEADLOCK_RETRY_ATTEMPTS);
    }

    /** @return OrderLocator|null */
    private function orderLocator(Connection $connection, string $publicId): ?object
    {
        /** @var OrderLocator|null $row */
        $row = $connection->table('orders')->where('public_id', $publicId)->first([
            'id', 'public_id', 'source_type', 'purchase_settlement_id', 'payment_intent_id',
        ]);

        return $row;
    }

    /** @return SettlementRow */
    private function settlementById(Connection $connection, int $id, bool $lock = false): object
    {
        $query = $connection->table('purchase_settlements')->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var SettlementRow|null $row */
        $row = $query->first([
            'id', 'public_id', 'payment_intent_id', 'user_id', 'source_quote_id', 'source_quote_public_id',
            'amount_irr', 'currency', 'settled_at',
        ]);
        if ($row === null) {
            throw new RuntimeException('Purchase Order settlement is unavailable.');
        }

        return $row;
    }

    /** @return IntentRow */
    private function intentById(Connection $connection, int $id, bool $lock = false): object
    {
        $query = $connection->table('payment_intents')->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var IntentRow|null $row */
        $row = $query->first([
            'id', 'public_id', 'purpose', 'user_id', 'wallet_account_id', 'source_quote_id',
            'source_quote_public_id', 'source_quote_configuration_hash', 'amount_irr', 'currency', 'state', 'captured_at',
        ]);
        if ($row === null) {
            throw new RuntimeException('Purchase Order payment intent is unavailable.');
        }

        return $row;
    }

    /** @return OrderRow */
    private function orderById(Connection $connection, int $id, bool $lock = false): object
    {
        $query = $connection->table('orders')->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var OrderRow|null $row */
        $row = $query->first([
            'id', 'public_id', 'source_type', 'purchase_settlement_id', 'purchase_settlement_public_id',
            'payment_intent_id', 'payment_intent_public_id', 'user_id', 'source_quote_id', 'source_quote_public_id',
            'source_quote_configuration_hash', 'state', 'state_version', 'total_amount_irr', 'settled_amount_irr',
            'currency', 'paid_at',
        ]);
        if ($row === null) {
            throw new RuntimeException('Purchase Order disappeared while acquiring queue authority.');
        }

        return $row;
    }

    /** @return OrderItemRow */
    private function orderItem(Connection $connection, int $orderId, bool $lock = false): object
    {
        $query = $connection->table('order_items')->where('order_id', $orderId)->where('line_number', 1);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var OrderItemRow|null $row */
        $row = $query->first(['id', 'public_id', 'order_id', 'line_number', 'source_quote_id', 'source_quote_public_id']);
        if ($row === null || $connection->table('order_items')->where('order_id', $orderId)->count() !== 1) {
            throw new RuntimeException('Purchase Order must contain exactly one authoritative Order Item in the current model.');
        }

        return $row;
    }

    /** @return OperationRow|null */
    private function operationByItem(Connection $connection, int $orderItemId, bool $lock = false): ?object
    {
        $query = $connection->table('provisioning_operations')
            ->where('order_item_id', $orderItemId)
            ->where('operation_type', self::OPERATION_TYPE);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var OperationRow|null $row */
        $row = $query->first([
            'id', 'public_id', 'operation_key', 'operation_type', 'order_id', 'order_item_id',
            'service_subscription_id', 'user_id', 'state', 'state_version', 'correlation_id',
        ]);

        return $row;
    }

    /** @return ServiceRow */
    private function serviceById(Connection $connection, int $id): object
    {
        /** @var ServiceRow|null $row */
        $row = $connection->table('service_subscriptions')->where('id', $id)->first([
            'id', 'public_id', 'order_id', 'order_item_id', 'user_id', 'creation_correlation_id',
        ]);
        if ($row === null) {
            throw new RuntimeException('Provisioning Operation Service Subscription is unavailable.');
        }

        return $row;
    }

    /** @return OutboxRow */
    private function outboxByOperation(Connection $connection, string $operationPublicId): object
    {
        /** @var OutboxRow|null $row */
        $row = $connection->table('outbox_messages')
            ->where('event_key', $this->eventKey($operationPublicId))
            ->first(['id', 'event_key', 'event_type', 'aggregate_type', 'aggregate_id', 'payload', 'payload_hash', 'correlation_id']);
        if ($row === null) {
            throw new RuntimeException('Provisioning Operation durable Outbox command is unavailable.');
        }

        return $row;
    }

    /**
     * @param OrderRow $order
     * @param OrderItemRow $item
     * @param SettlementRow $settlement
     * @param IntentRow $intent
     */
    private function assertNewQueueAuthority(object $order, object $item, object $settlement, object $intent): void
    {
        if ($order->source_type !== self::SOURCE_TYPE
            || $order->purchase_settlement_id === null
            || (int) $order->purchase_settlement_id !== (int) $settlement->id
            || $order->purchase_settlement_public_id === null
            || ! hash_equals($order->purchase_settlement_public_id, $settlement->public_id)
            || $order->payment_intent_id === null
            || (int) $order->payment_intent_id !== (int) $intent->id
            || $order->payment_intent_public_id === null
            || ! hash_equals($order->payment_intent_public_id, $intent->public_id)
            || (int) $settlement->payment_intent_id !== (int) $intent->id
            || (int) $order->user_id !== (int) $settlement->user_id
            || (int) $intent->user_id !== (int) $order->user_id
            || $intent->purpose !== self::SOURCE_TYPE
            || $intent->wallet_account_id !== null
            || $intent->state !== PaymentIntentState::Captured->value
            || $intent->captured_at === null
            || $order->state !== OrderState::Paid->value
            || (int) $order->state_version !== 1
            || $order->source_quote_id === null
            || (int) $order->source_quote_id !== (int) $settlement->source_quote_id
            || $intent->source_quote_id === null
            || (int) $intent->source_quote_id !== (int) $order->source_quote_id
            || (int) $item->source_quote_id !== (int) $order->source_quote_id
            || $order->source_quote_public_id === null
            || ! hash_equals($order->source_quote_public_id, $settlement->source_quote_public_id)
            || $intent->source_quote_public_id === null
            || ! hash_equals($intent->source_quote_public_id, $order->source_quote_public_id)
            || ! hash_equals($item->source_quote_public_id, $order->source_quote_public_id)
            || $order->source_quote_configuration_hash === null
            || $intent->source_quote_configuration_hash === null
            || ! hash_equals(strtolower($intent->source_quote_configuration_hash), strtolower($order->source_quote_configuration_hash))
            || (int) $intent->amount_irr !== (int) $order->total_amount_irr
            || $order->settled_amount_irr === null
            || (int) $settlement->amount_irr !== (int) $order->settled_amount_irr
            || $intent->currency !== $order->currency
            || $settlement->currency !== $order->currency
            || $order->paid_at === null) {
            throw new DomainException('Purchase Order is not currently eligible for initial provisioning.');
        }
    }

    /**
     * @param OrderRow $order
     * @param OrderItemRow $item
     * @param SettlementRow $settlement
     * @param IntentRow $intent
     * @param OperationRow $operation
     */
    private function replayReceipt(
        Connection $connection,
        object $order,
        object $item,
        object $settlement,
        object $intent,
        object $operation,
    ): ProvisioningQueueReceipt {
        $service = $this->serviceById($connection, $this->positiveDatabaseInt($operation->service_subscription_id, 'Service Subscription ID'));
        $outbox = $this->outboxByOperation($connection, $operation->public_id);
        $expectedPayload = $this->outboxPayload($order->public_id, $item->public_id, $service->public_id, $operation->public_id);

        if ($order->source_type !== self::SOURCE_TYPE
            || $order->purchase_settlement_id === null
            || (int) $order->purchase_settlement_id !== (int) $settlement->id
            || $order->payment_intent_id === null
            || (int) $order->payment_intent_id !== (int) $intent->id
            || (int) $settlement->payment_intent_id !== (int) $intent->id
            || $order->state !== OrderState::ProvisioningQueued->value
            || (int) $order->state_version !== 2
            || (int) $item->order_id !== (int) $order->id
            || (int) $service->order_id !== (int) $order->id
            || (int) $service->order_item_id !== (int) $item->id
            || (int) $service->user_id !== (int) $order->user_id
            || $operation->operation_type !== self::OPERATION_TYPE
            || ! hash_equals($operation->operation_key, $this->operationKey($item->public_id))
            || (int) $operation->order_id !== (int) $order->id
            || (int) $operation->order_item_id !== (int) $item->id
            || (int) $operation->service_subscription_id !== (int) $service->id
            || (int) $operation->user_id !== (int) $order->user_id
            || $operation->state !== ProvisioningState::Queued->value
            || (int) $operation->state_version !== 1
            || $outbox->event_type !== self::EVENT_TYPE
            || $outbox->aggregate_type !== self::AGGREGATE_TYPE
            || ! hash_equals($outbox->aggregate_id, $operation->public_id)
            || ! hash_equals($outbox->event_key, $this->eventKey($operation->public_id))
            || ! hash_equals($outbox->payload_hash, $expectedPayload->hash())) {
            throw new RuntimeException('Stored initial provisioning queue authority is inconsistent.');
        }

        return new ProvisioningQueueReceipt(
            $this->positiveDatabaseInt($order->id, 'Order ID'),
            $order->public_id,
            $item->public_id,
            $this->positiveDatabaseInt($service->id, 'Service Subscription ID'),
            $service->public_id,
            $this->positiveDatabaseInt($operation->id, 'Provisioning Operation ID'),
            $operation->public_id,
            $outbox->id,
            OrderState::ProvisioningQueued,
            2,
            ProvisioningState::Queued,
            1,
            true,
        );
    }

    private function outboxPayload(
        string $orderPublicId,
        string $itemPublicId,
        string $servicePublicId,
        string $operationPublicId,
    ): SafeOutboxPayload {
        return new SafeOutboxPayload([
            'order_public_id' => $orderPublicId,
            'order_item_public_id' => $itemPublicId,
            'provisioning_operation_public_id' => $operationPublicId,
            'service_subscription_public_id' => $servicePublicId,
        ]);
    }

    private function operationKey(string $orderItemPublicId): string
    {
        return 'initial-provision:'.$orderItemPublicId;
    }

    private function eventKey(string $operationPublicId): string
    {
        return 'provisioning.initial.requested:'.$operationPublicId;
    }

    private function recordAudit(
        Connection $connection,
        string $orderPublicId,
        string $itemPublicId,
        string $servicePublicId,
        string $operationPublicId,
        string $eventId,
        string $correlationId,
    ): void {
        $connection->table('audit_logs')->insert([
            'actor_type' => 'system',
            'actor_id' => null,
            'action' => 'order.initial_provisioning.queued',
            'target_type' => 'order',
            'target_id' => $orderPublicId,
            'before_safe_data' => json_encode([
                'state' => OrderState::Paid->value,
                'state_version' => 1,
            ], JSON_THROW_ON_ERROR),
            'after_safe_data' => json_encode([
                'state' => OrderState::ProvisioningQueued->value,
                'state_version' => 2,
                'order_item_public_id' => $itemPublicId,
                'service_subscription_public_id' => $servicePublicId,
                'provisioning_operation_public_id' => $operationPublicId,
                'outbox_event_id' => $eventId,
            ], JSON_THROW_ON_ERROR),
            'reason_code' => 'initial_provisioning_queued',
            'reason' => null,
            'correlation_id' => $correlationId,
            'request_fingerprint' => null,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function assertUlid(string $value, string $label): void
    {
        if (! Str::isUlid($value)) {
            throw new DomainException($label.' must be a valid ULID.');
        }
    }

    private function assertToken(string $value, string $label, int $min, int $max): void
    {
        $length = strlen($value);
        if ($length < $min || $length > $max || preg_match('/^[A-Za-z0-9._:-]+$/D', $value) !== 1) {
            throw new DomainException($label.' contains invalid characters or length.');
        }
    }

    private function positiveDatabaseInt(int|string|null $value, string $label): int
    {
        if (is_int($value)) {
            $normalized = $value;
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $normalized = (int) $value;
        } else {
            throw new RuntimeException($label.' is invalid.');
        }
        if ($normalized < 1) {
            throw new RuntimeException($label.' must be positive.');
        }

        return $normalized;
    }
}
