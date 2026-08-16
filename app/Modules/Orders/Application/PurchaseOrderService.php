<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Domain\OrderState;
use App\Shared\Application\Clock;
use App\Shared\Domain\Money;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-type SettlementRow object{id:int|string,public_id:string,payment_intent_id:int|string,user_id:int|string,source_quote_id:int|string,source_quote_public_id:string,amount_irr:int|string,currency:string,settled_at:string}
 * @phpstan-type IntentRow object{id:int|string,public_id:string,purpose:string,user_id:int|string,wallet_account_id:int|string|null,source_quote_id:int|string|null,source_quote_public_id:string|null,source_quote_configuration_hash:string|null,amount_irr:int|string,currency:string,state:string,captured_at:string|null}
 * @phpstan-type QuoteRow object{id:int|string,public_id:string,user_id:int|string,account_type_snapshot:string,plan_offering_id:int|string,offering_code_snapshot:string,offering_version:int|string,offering_configuration_hash:string,base_price_irr:int|string,override_source:string,override_reference_code:string|null,override_price_irr:int|string|null,effective_price_irr:int|string,discount_reference_code:string|null,discount_irr:int|string,final_price_irr:int|string,currency:string,configuration_snapshot:string,configuration_snapshot_hash:string}
 * @phpstan-type OrderRow object{id:int|string,public_id:string,source_type:string,purchase_settlement_id:int|string|null,purchase_settlement_public_id:string|null,payment_intent_id:int|string|null,payment_intent_public_id:string|null,user_id:int|string,source_quote_id:int|string|null,source_quote_public_id:string|null,source_quote_configuration_hash:string|null,state:string,state_version:int|string,total_amount_irr:int|string,settled_amount_irr:int|string|null,currency:string,paid_at:string|null}
 * @phpstan-type OrderItemRow object{id:int|string,public_id:string,order_id:int|string,line_number:int|string,source_quote_id:int|string,source_quote_public_id:string,account_type_snapshot:string,plan_offering_id:int|string,offering_code_snapshot:string,offering_version:int|string,offering_configuration_hash:string,base_price_irr:int|string,override_source:string,override_reference_code:string|null,override_price_irr:int|string|null,effective_price_irr:int|string,discount_reference_code:string|null,discount_irr:int|string,final_price_irr:int|string,currency:string,configuration_snapshot:string,configuration_snapshot_hash:string}
 */
final readonly class PurchaseOrderService
{
    private const SOURCE_TYPE = 'purchase';

    private const DEADLOCK_RETRY_ATTEMPTS = 3;

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    /** @requirement BUY-001 BUY-002 PAY-001 PAY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function openFromQuote(string $quotePublicId, int $actorUserId, string $correlationId): PurchaseOrderOpeningReceipt
    {
        $this->assertUlid($quotePublicId, 'Source Quote public ID');
        if ($actorUserId < 1) {
            throw new DomainException('Order actor user ID is invalid.');
        }
        $this->assertToken($correlationId, 'Order creation correlation ID', 8, 64);

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use ($quotePublicId, $actorUserId, $correlationId): PurchaseOrderOpeningReceipt {
                $quote = $this->quoteByPublicId($connection, $quotePublicId, true);
                if ($quote === null) {
                    throw new DomainException('Purchase Quote does not exist.');
                }
                $this->assertOpenQuoteAuthority($quote, $actorUserId);

                $quoteId = $this->positiveDatabaseInt($quote->id, 'Source Quote ID');
                $existing = $this->orderByQuoteId($connection, $quoteId, true);
                if ($existing !== null) {
                    return $this->openingReplayReceipt($connection, $existing, $quote, $actorUserId);
                }

                $timestamp = $this->timestamp();
                $orderPublicId = (string) Str::ulid();
                $itemPublicId = (string) Str::ulid();
                $commercialAmountIrr = $this->positiveDatabaseInt($quote->final_price_irr, 'Quote final price');
                $orderId = (int) $connection->table('orders')->insertGetId([
                    'public_id' => $orderPublicId,
                    'source_type' => self::SOURCE_TYPE,
                    'purchase_settlement_id' => null,
                    'purchase_settlement_public_id' => null,
                    'payment_intent_id' => null,
                    'payment_intent_public_id' => null,
                    'user_id' => $actorUserId,
                    'source_quote_id' => $quoteId,
                    'source_quote_public_id' => $quote->public_id,
                    'source_quote_configuration_hash' => strtolower($quote->configuration_snapshot_hash),
                    'state' => OrderState::AwaitingPayment->value,
                    'state_version' => 0,
                    'total_amount_irr' => $commercialAmountIrr,
                    'settled_amount_irr' => null,
                    'currency' => $quote->currency,
                    'paid_at' => null,
                    'creation_correlation_id' => $correlationId,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                $this->insertOrderItem($connection, $orderId, $itemPublicId, $quote, $timestamp);
                $this->recordOpenAudit($connection, $orderPublicId, $quote, $actorUserId, $correlationId);

                return new PurchaseOrderOpeningReceipt(
                    $orderId,
                    $orderPublicId,
                    $itemPublicId,
                    $quote->public_id,
                    $actorUserId,
                    OrderState::AwaitingPayment,
                    0,
                    Money::irr($commercialAmountIrr),
                    false,
                );
            }, self::DEADLOCK_RETRY_ATTEMPTS);
        } catch (QueryException $exception) {
            $replay = $this->openingReplayAfterUniqueRace($quotePublicId, $actorUserId);
            if ($replay !== null) {
                return $replay;
            }

            throw $exception;
        }
    }

    /** @requirement BUY-001 BUY-002 PAY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function createFromSettlement(string $settlementPublicId, string $correlationId): PurchaseOrderReceipt
    {
        $this->assertUlid($settlementPublicId, 'Purchase settlement public ID');
        $this->assertToken($correlationId, 'Order creation correlation ID', 8, 64);

        try {
            return $this->materializeSettlement($settlementPublicId, $correlationId);
        } catch (QueryException $exception) {
            $replay = $this->replayAfterUniqueRace($settlementPublicId);
            if ($replay !== null) {
                return $replay;
            }

            if ($this->prePaymentOrderExistsForSettlementQuote($settlementPublicId)) {
                return $this->materializeSettlement($settlementPublicId, $correlationId);
            }

            throw $exception;
        }
    }

    private function materializeSettlement(string $settlementPublicId, string $correlationId): PurchaseOrderReceipt
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($settlementPublicId, $correlationId): PurchaseOrderReceipt {
            $settlement = $this->settlementByPublicId($connection, $settlementPublicId, true);
            if ($settlement === null) {
                throw new DomainException('Purchase settlement does not exist.');
            }

            $existingBySettlement = $this->orderBySettlementId(
                $connection,
                $this->positiveDatabaseInt($settlement->id, 'Purchase settlement ID'),
                true,
            );
            if ($existingBySettlement !== null) {
                return $this->replayReceipt($connection, $existingBySettlement, $settlement);
            }

            $intent = $this->intentById(
                $connection,
                $this->positiveDatabaseInt($settlement->payment_intent_id, 'Payment intent ID'),
                true,
            );
            $quote = $this->quoteById(
                $connection,
                $this->positiveDatabaseInt($settlement->source_quote_id, 'Source Quote ID'),
            );
            $this->assertSettlementAuthority($settlement, $intent, $quote);

            $existingByQuote = $this->orderByQuoteId(
                $connection,
                $this->positiveDatabaseInt($quote->id, 'Source Quote ID'),
                true,
            );
            if ($existingByQuote !== null) {
                return $this->completePrePaymentOrder(
                    $connection,
                    $existingByQuote,
                    $settlement,
                    $intent,
                    $quote,
                    $correlationId,
                );
            }

            return $this->createPaidOrder($connection, $settlement, $intent, $quote, $correlationId);
        }, self::DEADLOCK_RETRY_ATTEMPTS);
    }

    /**
     * @param SettlementRow $settlement
     * @param IntentRow $intent
     * @param QuoteRow $quote
     */
    private function createPaidOrder(Connection $connection, object $settlement, object $intent, object $quote, string $correlationId): PurchaseOrderReceipt
    {
        $timestamp = $this->timestamp();
        $orderPublicId = (string) Str::ulid();
        $itemPublicId = (string) Str::ulid();
        $commercialAmountIrr = $this->positiveDatabaseInt($intent->amount_irr, 'Purchase commercial amount');
        $settledAmountIrr = $this->positiveDatabaseInt($settlement->amount_irr, 'Purchase settled amount');
        $orderId = (int) $connection->table('orders')->insertGetId([
            'public_id' => $orderPublicId,
            'source_type' => self::SOURCE_TYPE,
            'purchase_settlement_id' => $this->positiveDatabaseInt($settlement->id, 'Purchase settlement ID'),
            'purchase_settlement_public_id' => $settlement->public_id,
            'payment_intent_id' => $this->positiveDatabaseInt($intent->id, 'Payment intent ID'),
            'payment_intent_public_id' => $intent->public_id,
            'user_id' => $this->positiveDatabaseInt($settlement->user_id, 'Purchase settlement user ID'),
            'source_quote_id' => $this->positiveDatabaseInt($quote->id, 'Source Quote ID'),
            'source_quote_public_id' => $quote->public_id,
            'source_quote_configuration_hash' => strtolower($quote->configuration_snapshot_hash),
            'state' => OrderState::Paid->value,
            'state_version' => 1,
            'total_amount_irr' => $commercialAmountIrr,
            'settled_amount_irr' => $settledAmountIrr,
            'currency' => $settlement->currency,
            'paid_at' => $settlement->settled_at,
            'creation_correlation_id' => $correlationId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $this->insertOrderItem($connection, $orderId, $itemPublicId, $quote, $timestamp);
        $this->recordPaidAudit($connection, $orderPublicId, $settlement, $intent, $correlationId, true);

        return $this->paidReceipt($orderId, $orderPublicId, $itemPublicId, $settlement, $intent, $quote, false);
    }

    /**
     * @param OrderRow $order
     * @param SettlementRow $settlement
     * @param IntentRow $intent
     * @param QuoteRow $quote
     */
    private function completePrePaymentOrder(
        Connection $connection,
        object $order,
        object $settlement,
        object $intent,
        object $quote,
        string $correlationId,
    ): PurchaseOrderReceipt {
        $orderId = $this->positiveDatabaseInt($order->id, 'Order ID');
        $item = $this->orderItem($connection, $orderId);
        if ($item === null || $connection->table('order_items')->where('order_id', $orderId)->count() !== 1) {
            throw new RuntimeException('Stored pre-payment Order item integrity check failed.');
        }
        $this->assertItemMatchesQuote($item, $quote);

        if ($order->source_type !== self::SOURCE_TYPE
            || (int) $order->user_id !== (int) $settlement->user_id
            || $order->source_quote_id === null
            || (int) $order->source_quote_id !== (int) $quote->id
            || $order->source_quote_public_id === null
            || ! hash_equals($order->source_quote_public_id, $quote->public_id)
            || $order->source_quote_configuration_hash === null
            || ! hash_equals(strtolower($order->source_quote_configuration_hash), strtolower($quote->configuration_snapshot_hash))
            || (int) $order->total_amount_irr !== (int) $intent->amount_irr
            || $order->currency !== $settlement->currency) {
            throw new RuntimeException('Stored pre-payment Order does not match authoritative purchase identity.');
        }

        if ($order->purchase_settlement_id !== null
            || $order->purchase_settlement_public_id !== null
            || $order->payment_intent_id !== null
            || $order->payment_intent_public_id !== null
            || $order->settled_amount_irr !== null
            || $order->paid_at !== null
            || $order->state !== OrderState::AwaitingPayment->value
            || (int) $order->state_version !== 0) {
            throw new RuntimeException('Purchase Order already has conflicting financial or lifecycle authority.');
        }

        $timestamp = $this->timestamp();
        $updated = $connection->table('orders')
            ->where('id', $orderId)
            ->where('state', OrderState::AwaitingPayment->value)
            ->where('state_version', 0)
            ->whereNull('purchase_settlement_id')
            ->whereNull('payment_intent_id')
            ->update([
                'purchase_settlement_id' => $this->positiveDatabaseInt($settlement->id, 'Purchase settlement ID'),
                'purchase_settlement_public_id' => $settlement->public_id,
                'payment_intent_id' => $this->positiveDatabaseInt($intent->id, 'Payment intent ID'),
                'payment_intent_public_id' => $intent->public_id,
                'state' => OrderState::Paid->value,
                'state_version' => 1,
                'settled_amount_irr' => $this->positiveDatabaseInt($settlement->amount_irr, 'Purchase settled amount'),
                'paid_at' => $settlement->settled_at,
                'updated_at' => $timestamp,
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Pre-payment Order settlement transition lost its authoritative state.');
        }

        $connection->table('order_state_histories')->insert([
            'order_id' => $orderId,
            'from_state' => OrderState::AwaitingPayment->value,
            'to_state' => OrderState::Paid->value,
            'from_version' => 0,
            'to_version' => 1,
            'actor_type' => 'system',
            'actor_id' => null,
            'reason_code' => 'authoritative_purchase_settlement',
            'correlation_id' => $correlationId,
            'created_at' => $timestamp,
        ]);

        $this->recordPaidAudit($connection, $order->public_id, $settlement, $intent, $correlationId, false);

        return $this->paidReceipt($orderId, $order->public_id, $item->public_id, $settlement, $intent, $quote, false);
    }

    /** @param QuoteRow $quote */
    private function insertOrderItem(Connection $connection, int $orderId, string $itemPublicId, object $quote, string $timestamp): void
    {
        $connection->table('order_items')->insert([
            'public_id' => $itemPublicId,
            'order_id' => $orderId,
            'line_number' => 1,
            'source_quote_id' => $this->positiveDatabaseInt($quote->id, 'Source Quote ID'),
            'source_quote_public_id' => $quote->public_id,
            'account_type_snapshot' => $quote->account_type_snapshot,
            'plan_offering_id' => $this->positiveDatabaseInt($quote->plan_offering_id, 'Plan Offering ID'),
            'offering_code_snapshot' => $quote->offering_code_snapshot,
            'offering_version' => $this->positiveDatabaseInt($quote->offering_version, 'Offering version'),
            'offering_configuration_hash' => strtolower($quote->offering_configuration_hash),
            'base_price_irr' => $this->nonNegativeDatabaseInt($quote->base_price_irr, 'Quote base price'),
            'override_source' => $quote->override_source,
            'override_reference_code' => $quote->override_reference_code,
            'override_price_irr' => $quote->override_price_irr === null
                ? null
                : $this->nonNegativeDatabaseInt($quote->override_price_irr, 'Quote override price'),
            'effective_price_irr' => $this->nonNegativeDatabaseInt($quote->effective_price_irr, 'Quote effective price'),
            'discount_reference_code' => $quote->discount_reference_code,
            'discount_irr' => $this->nonNegativeDatabaseInt($quote->discount_irr, 'Quote discount'),
            'final_price_irr' => $this->positiveDatabaseInt($quote->final_price_irr, 'Quote final price'),
            'currency' => $quote->currency,
            'configuration_snapshot' => $quote->configuration_snapshot,
            'configuration_snapshot_hash' => strtolower($quote->configuration_snapshot_hash),
            'created_at' => $timestamp,
        ]);
    }

    /** @return SettlementRow|null */
    private function settlementByPublicId(Connection $connection, string $publicId, bool $lock = false): ?object
    {
        $query = $connection->table('purchase_settlements')->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var SettlementRow|null $row */
        $row = $query->first([
            'id', 'public_id', 'payment_intent_id', 'user_id', 'source_quote_id', 'source_quote_public_id',
            'amount_irr', 'currency', 'settled_at',
        ]);

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
            throw new RuntimeException('Purchase settlement payment intent is missing.');
        }

        return $row;
    }

    /** @return QuoteRow */
    private function quoteById(Connection $connection, int $id): object
    {
        /** @var QuoteRow|null $row */
        $row = $connection->table('quotes')->where('id', $id)->first($this->quoteColumns());
        if ($row === null) {
            throw new RuntimeException('Purchase settlement source Quote is missing.');
        }

        return $row;
    }

    /** @return QuoteRow|null */
    private function quoteByPublicId(Connection $connection, string $publicId, bool $lock = false): ?object
    {
        $query = $connection->table('quotes')->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var QuoteRow|null $row */
        $row = $query->first($this->quoteColumns());

        return $row;
    }

    /** @return list<string> */
    private function quoteColumns(): array
    {
        return [
            'id', 'public_id', 'user_id', 'account_type_snapshot', 'plan_offering_id', 'offering_code_snapshot',
            'offering_version', 'offering_configuration_hash', 'base_price_irr', 'override_source',
            'override_reference_code', 'override_price_irr', 'effective_price_irr', 'discount_reference_code',
            'discount_irr', 'final_price_irr', 'currency', 'configuration_snapshot', 'configuration_snapshot_hash',
        ];
    }

    /** @param QuoteRow $quote */
    private function assertOpenQuoteAuthority(object $quote, int $actorUserId): void
    {
        if ((int) $quote->user_id !== $actorUserId
            || ! in_array($quote->account_type_snapshot, ['customer', 'agent'], true)
            || $quote->currency !== 'IRR') {
            throw new DomainException('Purchase Quote is not eligible for this Order actor.');
        }

        $this->positiveDatabaseInt($quote->final_price_irr, 'Quote final price');
        $this->assertSha256($quote->configuration_snapshot_hash, 'Quote configuration hash');
        $this->assertSha256($quote->offering_configuration_hash, 'Offering configuration hash');
    }

    /**
     * @param SettlementRow $settlement
     * @param IntentRow $intent
     * @param QuoteRow $quote
     */
    private function assertSettlementAuthority(object $settlement, object $intent, object $quote): void
    {
        $settlementId = $this->positiveDatabaseInt($settlement->id, 'Purchase settlement ID');
        $intentId = $this->positiveDatabaseInt($intent->id, 'Payment intent ID');
        $quoteId = $this->positiveDatabaseInt($quote->id, 'Source Quote ID');
        $userId = $this->positiveDatabaseInt($settlement->user_id, 'Purchase settlement user ID');
        $this->positiveDatabaseInt($settlement->amount_irr, 'Purchase settled amount');
        $commercialAmountIrr = $this->positiveDatabaseInt($intent->amount_irr, 'Purchase commercial amount');

        if ($settlementId < 1
            || $intentId !== $this->positiveDatabaseInt($settlement->payment_intent_id, 'Purchase settlement payment intent ID')
            || $intent->purpose !== self::SOURCE_TYPE
            || (int) $intent->user_id !== $userId
            || $intent->source_quote_id === null
            || (int) $intent->source_quote_id !== $quoteId
            || $intent->source_quote_public_id === null
            || ! hash_equals($intent->source_quote_public_id, $quote->public_id)
            || (int) $quote->user_id !== $userId
            || (int) $settlement->source_quote_id !== $quoteId
            || ! hash_equals($settlement->source_quote_public_id, $quote->public_id)
            || (int) $quote->final_price_irr !== $commercialAmountIrr
            || $settlement->currency !== 'IRR'
            || $intent->currency !== $settlement->currency
            || $quote->currency !== $settlement->currency
            || $intent->source_quote_configuration_hash === null
            || ! hash_equals(strtolower($intent->source_quote_configuration_hash), strtolower($quote->configuration_snapshot_hash))
            || $intent->captured_at === null
            || ! in_array($intent->state, ['captured', 'refund_pending', 'refunded', 'partially_refunded'], true)) {
            throw new RuntimeException('Purchase settlement does not match authoritative purchase identity.');
        }

        $this->assertSha256($quote->configuration_snapshot_hash, 'Quote configuration hash');
        $this->assertSha256($quote->offering_configuration_hash, 'Offering configuration hash');
        $this->storedDateTime($settlement->settled_at, 'Purchase settlement timestamp');
    }

    /** @return OrderRow|null */
    private function orderBySettlementId(Connection $connection, int $settlementId, bool $lock = false): ?object
    {
        $query = $connection->table('orders')->where('purchase_settlement_id', $settlementId);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var OrderRow|null $row */
        $row = $query->first($this->orderColumns());

        return $row;
    }

    /** @return OrderRow|null */
    private function orderByQuoteId(Connection $connection, int $quoteId, bool $lock = false): ?object
    {
        $query = $connection->table('orders')->where('source_quote_id', $quoteId);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var OrderRow|null $row */
        $row = $query->first($this->orderColumns());

        return $row;
    }

    /** @return list<string> */
    private function orderColumns(): array
    {
        return [
            'id', 'public_id', 'source_type', 'purchase_settlement_id', 'purchase_settlement_public_id',
            'payment_intent_id', 'payment_intent_public_id', 'user_id', 'source_quote_id', 'source_quote_public_id',
            'source_quote_configuration_hash', 'state', 'state_version', 'total_amount_irr', 'settled_amount_irr', 'currency', 'paid_at',
        ];
    }

    /** @return OrderItemRow|null */
    private function orderItem(Connection $connection, int $orderId): ?object
    {
        /** @var OrderItemRow|null $row */
        $row = $connection->table('order_items')->where('order_id', $orderId)->where('line_number', 1)->first([
            'id', 'public_id', 'order_id', 'line_number', 'source_quote_id', 'source_quote_public_id',
            'account_type_snapshot', 'plan_offering_id', 'offering_code_snapshot', 'offering_version',
            'offering_configuration_hash', 'base_price_irr', 'override_source', 'override_reference_code',
            'override_price_irr', 'effective_price_irr', 'discount_reference_code', 'discount_irr', 'final_price_irr',
            'currency', 'configuration_snapshot', 'configuration_snapshot_hash',
        ]);

        return $row;
    }

    /**
     * @param OrderRow $order
     * @param QuoteRow $quote
     */
    private function openingReplayReceipt(Connection $connection, object $order, object $quote, int $actorUserId): PurchaseOrderOpeningReceipt
    {
        $orderId = $this->positiveDatabaseInt($order->id, 'Order ID');
        $item = $this->orderItem($connection, $orderId);
        if ($item === null || $connection->table('order_items')->where('order_id', $orderId)->count() !== 1) {
            throw new RuntimeException('Stored purchase Order item integrity check failed.');
        }
        if ($order->source_type !== self::SOURCE_TYPE
            || (int) $order->user_id !== $actorUserId
            || $order->source_quote_id === null
            || (int) $order->source_quote_id !== (int) $quote->id
            || $order->source_quote_public_id === null
            || ! hash_equals($order->source_quote_public_id, $quote->public_id)
            || $order->source_quote_configuration_hash === null
            || ! hash_equals(strtolower($order->source_quote_configuration_hash), strtolower($quote->configuration_snapshot_hash))
            || (int) $order->total_amount_irr !== (int) $quote->final_price_irr
            || $order->currency !== $quote->currency) {
            throw new RuntimeException('Stored purchase Order does not match immutable Quote identity.');
        }
        $this->assertItemMatchesQuote($item, $quote);

        $state = OrderState::tryFrom($order->state);
        if ($state === null) {
            throw new RuntimeException('Stored purchase Order state is invalid.');
        }

        return new PurchaseOrderOpeningReceipt(
            $orderId,
            $order->public_id,
            $item->public_id,
            $quote->public_id,
            $actorUserId,
            $state,
            $this->nonNegativeDatabaseInt($order->state_version, 'Order state version'),
            Money::irr($this->positiveDatabaseInt($order->total_amount_irr, 'Order commercial amount')),
            true,
        );
    }

    /**
     * @param OrderRow $order
     * @param SettlementRow $settlement
     */
    private function replayReceipt(Connection $connection, object $order, object $settlement): PurchaseOrderReceipt
    {
        $intent = $this->intentById($connection, $this->positiveDatabaseInt($settlement->payment_intent_id, 'Payment intent ID'));
        $quote = $this->quoteById($connection, $this->positiveDatabaseInt($settlement->source_quote_id, 'Source Quote ID'));
        $this->assertSettlementAuthority($settlement, $intent, $quote);

        $orderId = $this->positiveDatabaseInt($order->id, 'Order ID');
        $item = $this->orderItem($connection, $orderId);
        if ($item === null || $connection->table('order_items')->where('order_id', $orderId)->count() !== 1) {
            throw new RuntimeException('Stored purchase Order item integrity check failed.');
        }

        if ($order->source_type !== self::SOURCE_TYPE
            || $order->purchase_settlement_id === null
            || (int) $order->purchase_settlement_id !== (int) $settlement->id
            || $order->purchase_settlement_public_id === null
            || ! hash_equals($order->purchase_settlement_public_id, $settlement->public_id)
            || $order->payment_intent_id === null
            || (int) $order->payment_intent_id !== (int) $intent->id
            || $order->payment_intent_public_id === null
            || ! hash_equals($order->payment_intent_public_id, $intent->public_id)
            || (int) $order->user_id !== (int) $settlement->user_id
            || $order->source_quote_id === null
            || (int) $order->source_quote_id !== (int) $quote->id
            || $order->source_quote_public_id === null
            || ! hash_equals($order->source_quote_public_id, $quote->public_id)
            || $order->source_quote_configuration_hash === null
            || ! hash_equals(strtolower($order->source_quote_configuration_hash), strtolower($quote->configuration_snapshot_hash))
            || $order->state !== OrderState::Paid->value
            || (int) $order->state_version !== 1
            || (int) $order->total_amount_irr !== (int) $intent->amount_irr
            || (int) $order->total_amount_irr !== (int) $quote->final_price_irr
            || $order->settled_amount_irr === null
            || (int) $order->settled_amount_irr !== (int) $settlement->amount_irr
            || $order->currency !== $settlement->currency
            || $order->paid_at === null
            || ! $this->sameInstant($order->paid_at, $settlement->settled_at)) {
            throw new RuntimeException('Stored purchase Order integrity check failed.');
        }

        $this->assertItemMatchesQuote($item, $quote);

        return $this->paidReceipt($orderId, $order->public_id, $item->public_id, $settlement, $intent, $quote, true);
    }

    /**
     * @param SettlementRow $settlement
     * @param IntentRow $intent
     * @param QuoteRow $quote
     */
    private function paidReceipt(
        int $orderId,
        string $orderPublicId,
        string $itemPublicId,
        object $settlement,
        object $intent,
        object $quote,
        bool $replayed,
    ): PurchaseOrderReceipt {
        return new PurchaseOrderReceipt(
            $orderId,
            $orderPublicId,
            $itemPublicId,
            $settlement->public_id,
            $intent->public_id,
            $quote->public_id,
            $this->positiveDatabaseInt($settlement->user_id, 'Purchase settlement user ID'),
            OrderState::Paid,
            1,
            Money::irr($this->positiveDatabaseInt($intent->amount_irr, 'Purchase commercial amount')),
            Money::irr($this->positiveDatabaseInt($settlement->amount_irr, 'Purchase settled amount')),
            $this->storedDateTime($settlement->settled_at, 'Purchase settlement timestamp'),
            $replayed,
        );
    }

    /**
     * @param OrderItemRow $item
     * @param QuoteRow $quote
     */
    private function assertItemMatchesQuote(object $item, object $quote): void
    {
        if ((int) $item->line_number !== 1
            || (int) $item->source_quote_id !== (int) $quote->id
            || ! hash_equals($item->source_quote_public_id, $quote->public_id)
            || $item->account_type_snapshot !== $quote->account_type_snapshot
            || (int) $item->plan_offering_id !== (int) $quote->plan_offering_id
            || $item->offering_code_snapshot !== $quote->offering_code_snapshot
            || (int) $item->offering_version !== (int) $quote->offering_version
            || ! hash_equals(strtolower($item->offering_configuration_hash), strtolower($quote->offering_configuration_hash))
            || (int) $item->base_price_irr !== (int) $quote->base_price_irr
            || $item->override_source !== $quote->override_source
            || $item->override_reference_code !== $quote->override_reference_code
            || ($item->override_price_irr === null) !== ($quote->override_price_irr === null)
            || ($item->override_price_irr !== null && (int) $item->override_price_irr !== (int) $quote->override_price_irr)
            || (int) $item->effective_price_irr !== (int) $quote->effective_price_irr
            || $item->discount_reference_code !== $quote->discount_reference_code
            || (int) $item->discount_irr !== (int) $quote->discount_irr
            || (int) $item->final_price_irr !== (int) $quote->final_price_irr
            || $item->currency !== $quote->currency
            || ! hash_equals(strtolower($item->configuration_snapshot_hash), strtolower($quote->configuration_snapshot_hash))
            || ! hash_equals($item->configuration_snapshot, $quote->configuration_snapshot)) {
            throw new RuntimeException('Stored purchase Order item does not match immutable Quote snapshot.');
        }
    }

    /** @param QuoteRow $quote */
    private function recordOpenAudit(Connection $connection, string $orderPublicId, object $quote, int $actorUserId, string $correlationId): void
    {
        $connection->table('audit_logs')->insert([
            'actor_type' => $quote->account_type_snapshot,
            'actor_id' => $actorUserId,
            'action' => 'order.purchase.opened',
            'target_type' => 'order',
            'target_id' => $orderPublicId,
            'before_safe_data' => null,
            'after_safe_data' => json_encode([
                'source_quote_public_id' => $quote->public_id,
                'commercial_amount_irr' => (int) $quote->final_price_irr,
                'currency' => $quote->currency,
                'state' => OrderState::AwaitingPayment->value,
                'state_version' => 0,
            ], JSON_THROW_ON_ERROR),
            'reason_code' => 'purchase_quote_accepted',
            'reason' => null,
            'correlation_id' => $correlationId,
            'request_fingerprint' => null,
            'created_at' => $this->timestamp(),
        ]);
    }

    /**
     * @param SettlementRow $settlement
     * @param IntentRow $intent
     */
    private function recordPaidAudit(
        Connection $connection,
        string $orderPublicId,
        object $settlement,
        object $intent,
        string $correlationId,
        bool $createdPaid,
    ): void {
        $connection->table('audit_logs')->insert([
            'actor_type' => 'system',
            'actor_id' => null,
            'action' => $createdPaid ? 'order.purchase.created' : 'order.purchase.paid',
            'target_type' => 'order',
            'target_id' => $orderPublicId,
            'before_safe_data' => $createdPaid ? null : json_encode([
                'state' => OrderState::AwaitingPayment->value,
                'state_version' => 0,
            ], JSON_THROW_ON_ERROR),
            'after_safe_data' => json_encode([
                'purchase_settlement_public_id' => $settlement->public_id,
                'source_quote_public_id' => $settlement->source_quote_public_id,
                'commercial_amount_irr' => (int) $intent->amount_irr,
                'settled_amount_irr' => (int) $settlement->amount_irr,
                'currency' => $settlement->currency,
                'state' => OrderState::Paid->value,
                'state_version' => 1,
            ], JSON_THROW_ON_ERROR),
            'reason_code' => 'authoritative_purchase_settlement',
            'reason' => null,
            'correlation_id' => $correlationId,
            'request_fingerprint' => null,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function openingReplayAfterUniqueRace(string $quotePublicId, int $actorUserId): ?PurchaseOrderOpeningReceipt
    {
        $connection = $this->database->connection();
        $quote = $this->quoteByPublicId($connection, $quotePublicId);
        if ($quote === null || (int) $quote->user_id !== $actorUserId) {
            return null;
        }
        $order = $this->orderByQuoteId($connection, $this->positiveDatabaseInt($quote->id, 'Source Quote ID'));
        if ($order === null) {
            return null;
        }

        try {
            return $this->openingReplayReceipt($connection, $order, $quote, $actorUserId);
        } catch (DomainException|RuntimeException) {
            return null;
        }
    }

    private function replayAfterUniqueRace(string $settlementPublicId): ?PurchaseOrderReceipt
    {
        $connection = $this->database->connection();
        $settlement = $this->settlementByPublicId($connection, $settlementPublicId);
        if ($settlement === null) {
            return null;
        }
        $order = $this->orderBySettlementId($connection, $this->positiveDatabaseInt($settlement->id, 'Purchase settlement ID'));
        if ($order === null) {
            return null;
        }

        try {
            return $this->replayReceipt($connection, $order, $settlement);
        } catch (DomainException|RuntimeException) {
            return null;
        }
    }

    private function prePaymentOrderExistsForSettlementQuote(string $settlementPublicId): bool
    {
        $connection = $this->database->connection();
        $settlement = $this->settlementByPublicId($connection, $settlementPublicId);
        if ($settlement === null) {
            return false;
        }

        return $this->orderByQuoteId(
            $connection,
            $this->positiveDatabaseInt($settlement->source_quote_id, 'Source Quote ID'),
        ) !== null;
    }

    private function assertUlid(string $value, string $label): void
    {
        if (strlen($value) !== 26 || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum || preg_match('/\A[A-Za-z0-9._:-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertSha256(string $value, string $label): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/i', $value) !== 1) {
            throw new RuntimeException($label.' is invalid.');
        }
    }

    private function positiveDatabaseInt(int|string|null $value, string $label): int
    {
        if ($value === null || (is_string($value) && preg_match('/\A[0-9]+\z/', $value) !== 1)) {
            throw new RuntimeException($label.' is invalid.');
        }
        $integer = (int) $value;
        if ($integer < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }

    private function nonNegativeDatabaseInt(int|string|null $value, string $label): int
    {
        if ($value === null || (is_string($value) && preg_match('/\A[0-9]+\z/', $value) !== 1)) {
            throw new RuntimeException($label.' is invalid.');
        }
        $integer = (int) $value;
        if ($integer < 0) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }

    private function storedDateTime(string $value, string $label): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception $exception) {
            throw new RuntimeException($label.' is invalid.', previous: $exception);
        }
    }

    private function sameInstant(string $left, string $right): bool
    {
        return $this->storedDateTime($left, 'Order timestamp')->format('U.u')
            === $this->storedDateTime($right, 'Settlement timestamp')->format('U.u');
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
