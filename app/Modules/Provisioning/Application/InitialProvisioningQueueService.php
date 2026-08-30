<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Orders\Domain\OrderSourceType;
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
 * @phpstan-type OrderLocator object{id:int|string,public_id:string,source_type:string,purchase_settlement_id:int|string|null,payment_intent_id:int|string|null,order_source_authorization_id:int|string|null,order_source_authorization_public_id:string|null}
 * @phpstan-type SettlementRow object{id:int|string,public_id:string,payment_intent_id:int|string,user_id:int|string,source_quote_id:int|string,source_quote_public_id:string,amount_irr:int|string,currency:string,settled_at:string}
 * @phpstan-type IntentRow object{id:int|string,public_id:string,purpose:string,user_id:int|string,wallet_account_id:int|string|null,source_quote_id:int|string|null,source_quote_public_id:string|null,source_quote_configuration_hash:string|null,amount_irr:int|string,currency:string,state:string,captured_at:string|null}
 * @phpstan-type SourceAuthorizationRow object{id:int|string,public_id:string,source_type:string,user_id:int|string,plan_offering_id:int|string,configuration_snapshot_hash:string}
 * @phpstan-type OrderRow object{id:int|string,public_id:string,source_type:string,purchase_settlement_id:int|string|null,purchase_settlement_public_id:string|null,payment_intent_id:int|string|null,payment_intent_public_id:string|null,order_source_authorization_id:int|string|null,order_source_authorization_public_id:string|null,user_id:int|string,source_quote_id:int|string|null,source_quote_public_id:string|null,source_quote_configuration_hash:string|null,state:string,state_version:int|string,total_amount_irr:int|string,settled_amount_irr:int|string|null,currency:string,paid_at:string|null}
 * @phpstan-type OrderItemRow object{id:int|string,public_id:string,order_id:int|string,line_number:int|string,source_quote_id:int|string|null,source_quote_public_id:string|null,order_source_authorization_id:int|string|null,order_source_authorization_public_id:string|null,configuration_snapshot_hash:string}
 * @phpstan-type ServiceRow object{id:int|string,public_id:string,order_id:int|string,order_item_id:int|string,user_id:int|string,creation_correlation_id:string}
 * @phpstan-type OperationRow object{id:int|string,public_id:string,operation_key:string,operation_type:string,order_id:int|string,order_item_id:int|string,service_subscription_id:int|string,user_id:int|string,state:string,state_version:int|string,correlation_id:string}
 * @phpstan-type OutboxRow object{id:string,event_key:string,event_type:string,contract_version:int|string,aggregate_type:string,aggregate_id:string,payload:string,payload_hash:string,correlation_id:string}
 */
final readonly class InitialProvisioningQueueService
{
    private const PURCHASE_SOURCE_TYPE = 'purchase';

    private const OPERATION_TYPE = 'initial_provision';

    public const OUTBOX_EVENT_TYPE = 'provisioning.initial.requested';

    public const OUTBOX_CONTRACT_VERSION = 1;

    public const OUTBOX_AGGREGATE_TYPE = 'provisioning_operation';

    private const DEADLOCK_RETRY_ATTEMPTS = 3;

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private OutboxPublisher $outbox,
    ) {}

    /** @requirement BUY-001 CAT-006 ADM-002 PAY-002 PAY-003 PRV-002 PRV-003 ARCH-003 ARCH-004 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 QUA-001 QUA-004 */
    public function queueInitial(string $orderPublicId, string $correlationId): ProvisioningQueueReceipt
    {
        $this->assertUlid($orderPublicId, 'Order public ID');
        $this->assertToken($correlationId, 'Provisioning correlation ID', 8, 64);

        return $this->database->connection()->transaction(function (Connection $connection) use ($orderPublicId, $correlationId): ProvisioningQueueReceipt {
            $locator = $this->orderLocator($connection, $orderPublicId);
            if ($locator === null) {
                throw new DomainException('Order does not exist.');
            }

            if ($locator->source_type === self::PURCHASE_SOURCE_TYPE) {
                return $this->queuePurchase($connection, $locator, $correlationId);
            }

            $sourceType = OrderSourceType::tryFrom($locator->source_type);
            if ($sourceType === null || ! $this->isSupportedNonPaidSource($sourceType)) {
                throw new DomainException('Order source is not enabled for initial provisioning.');
            }

            return $this->queueNonPaid($connection, $locator, $sourceType, $correlationId);
        }, self::DEADLOCK_RETRY_ATTEMPTS);
    }

    /** @param OrderLocator $locator */
    private function queuePurchase(Connection $connection, object $locator, string $correlationId): ProvisioningQueueReceipt
    {
        if ($locator->purchase_settlement_id === null || $locator->payment_intent_id === null) {
            throw new DomainException('Only authoritative purchase Orders can queue financial provisioning.');
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
        $item = $this->singleOrderItem($connection, $this->positiveDatabaseInt($order->id, 'Order ID'), true);

        $existingOperation = $this->operationByItem($connection, $this->positiveDatabaseInt($item->id, 'Order Item ID'), true);
        if ($existingOperation !== null) {
            return $this->replayPurchaseReceipt($connection, $order, $item, $settlement, $intent, $existingOperation);
        }

        $this->assertNewPurchaseQueueAuthority($connection, $order, $item, $settlement, $intent);

        return $this->createQueueEffect(
            $connection,
            $order,
            $item,
            $correlationId,
            OrderState::Paid,
            1,
            2,
        );
    }

    /** @param OrderLocator $locator */
    private function queueNonPaid(
        Connection $connection,
        object $locator,
        OrderSourceType $sourceType,
        string $correlationId,
    ): ProvisioningQueueReceipt {
        if ($locator->order_source_authorization_id === null || $locator->order_source_authorization_public_id === null) {
            throw new DomainException('Zero-cost Order is missing source authorization authority.');
        }

        // Source-authorized Orders have no financial locks. Lock immutable source authority first, then Order and Item.
        $authorization = $this->sourceAuthorizationById(
            $connection,
            $this->positiveDatabaseInt($locator->order_source_authorization_id, 'Order source authorization ID'),
            true,
        );
        $order = $this->orderById($connection, $this->positiveDatabaseInt($locator->id, 'Order ID'), true);
        $item = $this->singleOrderItem($connection, $this->positiveDatabaseInt($order->id, 'Order ID'), true);

        $existingOperation = $this->operationByItem($connection, $this->positiveDatabaseInt($item->id, 'Order Item ID'), true);
        if ($existingOperation !== null) {
            return $this->replayNonPaidReceipt($connection, $order, $item, $authorization, $sourceType, $existingOperation);
        }

        $this->assertNewNonPaidQueueAuthority($order, $item, $authorization, $sourceType);

        return $this->createQueueEffect(
            $connection,
            $order,
            $item,
            $correlationId,
            OrderState::Authorized,
            0,
            1,
        );
    }

    /**
     * @param  OrderRow  $order
     * @param  OrderItemRow  $item
     */
    private function createQueueEffect(
        Connection $connection,
        object $order,
        object $item,
        string $correlationId,
        OrderState $fromState,
        int $fromVersion,
        int $toVersion,
    ): ProvisioningQueueReceipt {
        $timestamp = $this->timestamp();
        $orderId = $this->positiveDatabaseInt($order->id, 'Order ID');
        $itemId = $this->positiveDatabaseInt($item->id, 'Order Item ID');
        $userId = $this->positiveDatabaseInt($order->user_id, 'Order user ID');

        $servicePublicId = (string) Str::ulid();
        $serviceId = (int) $connection->table('service_subscriptions')->insertGetId([
            'public_id' => $servicePublicId,
            'order_id' => $orderId,
            'order_item_id' => $itemId,
            'user_id' => $userId,
            'creation_correlation_id' => $correlationId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $operationPublicId = (string) Str::ulid();
        $operationId = (int) $connection->table('provisioning_operations')->insertGetId([
            'public_id' => $operationPublicId,
            'operation_key' => $this->operationKey($item->public_id),
            'operation_type' => self::OPERATION_TYPE,
            'order_id' => $orderId,
            'order_item_id' => $itemId,
            'service_subscription_id' => $serviceId,
            'user_id' => $userId,
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
            self::OUTBOX_EVENT_TYPE,
            self::OUTBOX_AGGREGATE_TYPE,
            $operationPublicId,
            $payload,
            $correlationId,
            self::OUTBOX_CONTRACT_VERSION,
        );

        $updated = $connection->table('orders')
            ->where('id', $orderId)
            ->where('state', $fromState->value)
            ->where('state_version', $fromVersion)
            ->update([
                'state' => OrderState::ProvisioningQueued->value,
                'state_version' => $toVersion,
                'updated_at' => $timestamp,
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Order provisioning transition lost its authoritative state.');
        }

        $released = $connection->table('outbox_messages')
            ->where('id', $eventId)
            ->where('event_type', self::OUTBOX_EVENT_TYPE)
            ->where('dispatch_state', 'authority_pending')
            ->update([
                'dispatch_state' => 'pending',
                'updated_at' => $timestamp,
            ]);
        if ($released !== 1) {
            throw new RuntimeException('Initial provisioning Outbox command did not release from final Order authority.');
        }

        $this->recordAudit(
            $connection,
            $order->public_id,
            $item->public_id,
            $servicePublicId,
            $operationPublicId,
            $eventId,
            $correlationId,
            $fromState,
            $fromVersion,
            $toVersion,
        );

        return new ProvisioningQueueReceipt(
            $orderId,
            $order->public_id,
            $item->public_id,
            $serviceId,
            $servicePublicId,
            $operationId,
            $operationPublicId,
            $eventId,
            OrderState::ProvisioningQueued,
            $toVersion,
            ProvisioningState::Queued,
            1,
            false,
        );
    }

    /** @return OrderLocator|null */
    private function orderLocator(Connection $connection, string $publicId): ?object
    {
        /** @var OrderLocator|null $row */
        $row = $connection->table('orders')->where('public_id', $publicId)->first([
            'id', 'public_id', 'source_type', 'purchase_settlement_id', 'payment_intent_id',
            'order_source_authorization_id', 'order_source_authorization_public_id',
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

    /** @return SourceAuthorizationRow */
    private function sourceAuthorizationById(Connection $connection, int $id, bool $lock = false): object
    {
        $query = $connection->table('order_source_authorizations')->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var SourceAuthorizationRow|null $row */
        $row = $query->first([
            'id', 'public_id', 'source_type', 'user_id', 'plan_offering_id', 'configuration_snapshot_hash',
        ]);
        if ($row === null) {
            throw new RuntimeException('Order source authorization is unavailable.');
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
            'payment_intent_id', 'payment_intent_public_id', 'order_source_authorization_id', 'order_source_authorization_public_id',
            'user_id', 'source_quote_id', 'source_quote_public_id', 'source_quote_configuration_hash',
            'state', 'state_version', 'total_amount_irr', 'settled_amount_irr', 'currency', 'paid_at',
        ]);
        if ($row === null) {
            throw new RuntimeException('Order disappeared while acquiring queue authority.');
        }

        return $row;
    }

    /** @return OrderItemRow */
    private function singleOrderItem(Connection $connection, int $orderId, bool $lock = false): object
    {
        $query = $connection->table('order_items')->where('order_id', $orderId)->where('line_number', 1);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var OrderItemRow|null $row */
        $row = $query->first([
            'id', 'public_id', 'order_id', 'line_number', 'source_quote_id', 'source_quote_public_id',
            'order_source_authorization_id', 'order_source_authorization_public_id', 'configuration_snapshot_hash',
        ]);
        if ($row === null || $connection->table('order_items')->where('order_id', $orderId)->count() !== 1) {
            throw new RuntimeException('Order must contain exactly one authoritative Order Item for single-item initial provisioning.');
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
        $row = $connection->table('service_subscriptions')
            ->where('id', $id)
            ->lockForUpdate()
            ->first(['id', 'public_id', 'order_id', 'order_item_id', 'user_id', 'creation_correlation_id']);
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
            ->lockForUpdate()
            ->first(['id', 'event_key', 'event_type', 'contract_version', 'aggregate_type', 'aggregate_id', 'payload', 'payload_hash', 'correlation_id']);
        if ($row === null) {
            throw new RuntimeException('Provisioning Operation durable Outbox command is unavailable.');
        }

        return $row;
    }

    /**
     * @param  OrderRow  $order
     * @param  OrderItemRow  $item
     * @param  SettlementRow  $settlement
     * @param  IntentRow  $intent
     */
    private function assertNewPurchaseQueueAuthority(Connection $connection, object $order, object $item, object $settlement, object $intent): void
    {
        $quoteAction = $order->source_quote_id === null
            ? null
            : $connection->table('quotes')->where('id', (int) $order->source_quote_id)->value('action_snapshot');

        if ($quoteAction !== 'purchase'
            || $order->source_type !== self::PURCHASE_SOURCE_TYPE
            || $order->order_source_authorization_id !== null
            || $order->order_source_authorization_public_id !== null
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
            || $intent->purpose !== self::PURCHASE_SOURCE_TYPE
            || $intent->wallet_account_id !== null
            || $intent->state !== PaymentIntentState::Captured->value
            || $intent->captured_at === null
            || $order->state !== OrderState::Paid->value
            || (int) $order->state_version !== 1
            || $order->source_quote_id === null
            || (int) $order->source_quote_id !== (int) $settlement->source_quote_id
            || $intent->source_quote_id === null
            || (int) $intent->source_quote_id !== (int) $order->source_quote_id
            || $item->source_quote_id === null
            || (int) $item->source_quote_id !== (int) $order->source_quote_id
            || $item->order_source_authorization_id !== null
            || $item->order_source_authorization_public_id !== null
            || $order->source_quote_public_id === null
            || ! hash_equals($order->source_quote_public_id, $settlement->source_quote_public_id)
            || $intent->source_quote_public_id === null
            || ! hash_equals($intent->source_quote_public_id, $order->source_quote_public_id)
            || $item->source_quote_public_id === null
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
     * @param  OrderRow  $order
     * @param  OrderItemRow  $item
     * @param  SourceAuthorizationRow  $authorization
     */
    private function assertNewNonPaidQueueAuthority(
        object $order,
        object $item,
        object $authorization,
        OrderSourceType $sourceType,
    ): void {
        if ($order->source_type !== $sourceType->value
            || ! $this->isSupportedNonPaidSource($sourceType)
            || $order->purchase_settlement_id !== null
            || $order->purchase_settlement_public_id !== null
            || $order->payment_intent_id !== null
            || $order->payment_intent_public_id !== null
            || $order->source_quote_id !== null
            || $order->source_quote_public_id !== null
            || $order->source_quote_configuration_hash !== null
            || $order->order_source_authorization_id === null
            || (int) $order->order_source_authorization_id !== (int) $authorization->id
            || $order->order_source_authorization_public_id === null
            || ! hash_equals($order->order_source_authorization_public_id, $authorization->public_id)
            || $authorization->source_type !== $sourceType->value
            || (int) $authorization->user_id !== (int) $order->user_id
            || $order->state !== OrderState::Authorized->value
            || (int) $order->state_version !== 0
            || (int) $order->total_amount_irr !== 0
            || $order->settled_amount_irr !== null
            || $order->currency !== 'IRR'
            || $order->paid_at !== null
            || $item->source_quote_id !== null
            || $item->source_quote_public_id !== null
            || $item->order_source_authorization_id === null
            || (int) $item->order_source_authorization_id !== (int) $authorization->id
            || $item->order_source_authorization_public_id === null
            || ! hash_equals($item->order_source_authorization_public_id, $authorization->public_id)
            || ! hash_equals(strtolower($item->configuration_snapshot_hash), strtolower($authorization->configuration_snapshot_hash))) {
            throw new DomainException('Zero-cost Order is not currently eligible for initial provisioning.');
        }
    }

    /**
     * @param  OrderRow  $order
     * @param  OrderItemRow  $item
     * @param  SettlementRow  $settlement
     * @param  IntentRow  $intent
     * @param  OperationRow  $operation
     */
    private function replayPurchaseReceipt(
        Connection $connection,
        object $order,
        object $item,
        object $settlement,
        object $intent,
        object $operation,
    ): ProvisioningQueueReceipt {
        if ($order->source_type !== self::PURCHASE_SOURCE_TYPE
            || $order->purchase_settlement_id === null
            || (int) $order->purchase_settlement_id !== (int) $settlement->id
            || $order->payment_intent_id === null
            || (int) $order->payment_intent_id !== (int) $intent->id
            || (int) $settlement->payment_intent_id !== (int) $intent->id) {
            throw new RuntimeException('Stored purchase provisioning authority is inconsistent.');
        }

        return $this->replayQueueEffect($connection, $order, $item, $operation, 2);
    }

    /**
     * @param  OrderRow  $order
     * @param  OrderItemRow  $item
     * @param  SourceAuthorizationRow  $authorization
     * @param  OperationRow  $operation
     */
    private function replayNonPaidReceipt(
        Connection $connection,
        object $order,
        object $item,
        object $authorization,
        OrderSourceType $sourceType,
        object $operation,
    ): ProvisioningQueueReceipt {
        if ($order->source_type !== $sourceType->value
            || ! $this->isSupportedNonPaidSource($sourceType)
            || $order->order_source_authorization_id === null
            || (int) $order->order_source_authorization_id !== (int) $authorization->id
            || $order->order_source_authorization_public_id === null
            || ! hash_equals($order->order_source_authorization_public_id, $authorization->public_id)
            || $authorization->source_type !== $sourceType->value
            || (int) $authorization->user_id !== (int) $order->user_id
            || $item->order_source_authorization_id === null
            || (int) $item->order_source_authorization_id !== (int) $authorization->id
            || $item->order_source_authorization_public_id === null
            || ! hash_equals($item->order_source_authorization_public_id, $authorization->public_id)) {
            throw new RuntimeException('Stored zero-cost provisioning authority is inconsistent.');
        }

        return $this->replayQueueEffect($connection, $order, $item, $operation, 1);
    }

    /**
     * @param  OrderRow  $order
     * @param  OrderItemRow  $item
     * @param  OperationRow  $operation
     */
    private function replayQueueEffect(
        Connection $connection,
        object $order,
        object $item,
        object $operation,
        int $expectedOrderVersion,
    ): ProvisioningQueueReceipt {
        $service = $this->serviceById($connection, $this->positiveDatabaseInt($operation->service_subscription_id, 'Service Subscription ID'));
        $outbox = $this->outboxByOperation($connection, $operation->public_id);
        $expectedPayload = $this->outboxPayload($order->public_id, $item->public_id, $service->public_id, $operation->public_id);
        $expectedPayloadJson = $expectedPayload->json();
        $storedPayloadHash = hash('sha256', $outbox->payload);

        if ($order->state !== OrderState::ProvisioningQueued->value
            || (int) $order->state_version !== $expectedOrderVersion
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
            || $outbox->event_type !== self::OUTBOX_EVENT_TYPE
            || (int) $outbox->contract_version !== self::OUTBOX_CONTRACT_VERSION
            || $outbox->aggregate_type !== self::OUTBOX_AGGREGATE_TYPE
            || ! hash_equals($outbox->aggregate_id, $operation->public_id)
            || ! hash_equals($outbox->event_key, $this->eventKey($operation->public_id))
            || ! hash_equals($outbox->correlation_id, $operation->correlation_id)
            || ! hash_equals($outbox->payload, $expectedPayloadJson)
            || ! hash_equals($outbox->payload_hash, $storedPayloadHash)
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
            $expectedOrderVersion,
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
        OrderState $fromState,
        int $fromVersion,
        int $toVersion,
    ): void {
        $connection->table('audit_logs')->insert([
            'actor_type' => 'system',
            'actor_id' => null,
            'action' => 'order.initial_provisioning.queued',
            'target_type' => 'order',
            'target_id' => $orderPublicId,
            'before_safe_data' => json_encode([
                'state' => $fromState->value,
                'state_version' => $fromVersion,
            ], JSON_THROW_ON_ERROR),
            'after_safe_data' => json_encode([
                'state' => OrderState::ProvisioningQueued->value,
                'state_version' => $toVersion,
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

    private function isSupportedNonPaidSource(OrderSourceType $sourceType): bool
    {
        return in_array($sourceType, [
            OrderSourceType::Trial,
            OrderSourceType::BenefitCode,
            OrderSourceType::AdministratorGrant,
        ], true);
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
