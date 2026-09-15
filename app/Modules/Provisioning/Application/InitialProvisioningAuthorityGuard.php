<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Orders\Domain\OrderSourceType;
use App\Modules\Orders\Domain\OrderState;
use App\Modules\Payments\Domain\PaymentIntentState;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Revalidates the durable authority that permits an initial provisioning remote effect.
 *
 * Purchase Orders retain the accepted settlement -> PaymentIntent -> Order -> Item lock order.
 * Zero-cost Orders use source authorization -> Order -> Item. No synthetic payment evidence is
 * created for trial, benefit-code, or administrator-grant sources.
 */
final readonly class InitialProvisioningAuthorityGuard
{
    /**
     * @param  object{order_id:int|string,order_item_id:int|string,user_id:int|string}  $operation
     */
    public function lockAndAssert(Connection $connection, object $operation): void
    {
        /** @var object{source_type:string,purchase_settlement_id:int|string|null,payment_intent_id:int|string|null,order_source_authorization_id:int|string|null}|null $locator */
        $locator = $connection->table('orders')
            ->where('id', (int) $operation->order_id)
            ->first([
                'source_type',
                'purchase_settlement_id',
                'payment_intent_id',
                'order_source_authorization_id',
            ]);
        if ($locator === null) {
            throw new RuntimeException('Provisioning Order is unavailable.');
        }

        $sourceType = OrderSourceType::tryFrom($locator->source_type)
            ?? throw new DomainException('Provisioning Order source is unsupported.');

        if ($sourceType === OrderSourceType::Purchase) {
            $this->lockAndAssertPurchase($connection, $operation, $locator);

            return;
        }

        if (! $this->isSupportedZeroCostSource($sourceType)) {
            throw new DomainException('Provisioning Order source is not enabled for remote execution.');
        }

        $this->lockAndAssertZeroCost($connection, $operation, $locator, $sourceType);
    }

    /**
     * @param  object{order_id:int|string,order_item_id:int|string,user_id:int|string}  $operation
     * @param  object{purchase_settlement_id:int|string|null,payment_intent_id:int|string|null}  $locator
     */
    private function lockAndAssertPurchase(Connection $connection, object $operation, object $locator): void
    {
        if ($locator->purchase_settlement_id === null || $locator->payment_intent_id === null) {
            throw new DomainException('Provisioning purchase financial identity is unavailable.');
        }

        /** @var object{id:int|string,payment_intent_id:int|string,user_id:int|string,source_quote_id:int|string}|null $settlement */
        $settlement = $connection->table('purchase_settlements')
            ->where('id', (int) $locator->purchase_settlement_id)
            ->lockForUpdate()
            ->first(['id', 'payment_intent_id', 'user_id', 'source_quote_id']);
        if ($settlement === null) {
            throw new DomainException('Provisioning purchase settlement is unavailable.');
        }

        /** @var object{id:int|string,purpose:string,user_id:int|string,source_quote_id:int|string|null,state:string,captured_at:?string}|null $intent */
        $intent = $connection->table('payment_intents')
            ->where('id', (int) $settlement->payment_intent_id)
            ->lockForUpdate()
            ->first(['id', 'purpose', 'user_id', 'source_quote_id', 'state', 'captured_at']);
        if ($intent === null) {
            throw new DomainException('Provisioning payment intent is unavailable.');
        }

        /** @var object{id:int|string,source_type:string,purchase_settlement_id:int|string|null,payment_intent_id:int|string|null,order_source_authorization_id:int|string|null,user_id:int|string,source_quote_id:int|string|null,state:string,state_version:int|string}|null $order */
        $order = $connection->table('orders')
            ->where('id', (int) $operation->order_id)
            ->lockForUpdate()
            ->first([
                'id',
                'source_type',
                'purchase_settlement_id',
                'payment_intent_id',
                'order_source_authorization_id',
                'user_id',
                'source_quote_id',
                'state',
                'state_version',
            ]);
        if ($order === null) {
            throw new RuntimeException('Provisioning Order disappeared.');
        }

        /** @var object{id:int|string,order_id:int|string,source_quote_id:int|string|null,order_source_authorization_id:int|string|null}|null $item */
        $item = $connection->table('order_items')
            ->where('id', (int) $operation->order_item_id)
            ->lockForUpdate()
            ->first(['id', 'order_id', 'source_quote_id', 'order_source_authorization_id']);
        if ($item === null) {
            throw new RuntimeException('Provisioning Order Item disappeared.');
        }

        $quoteAction = $order->source_quote_id === null
            ? null
            : $connection->table('quotes')->where('id', (int) $order->source_quote_id)->value('action_snapshot');

        if ($quoteAction !== 'purchase'
            || $order->source_type !== OrderSourceType::Purchase->value
            || $order->order_source_authorization_id !== null
            || (int) $order->purchase_settlement_id !== (int) $settlement->id
            || (int) $order->payment_intent_id !== (int) $intent->id
            || (int) $settlement->payment_intent_id !== (int) $intent->id
            || (int) $order->user_id !== (int) $operation->user_id
            || (int) $settlement->user_id !== (int) $operation->user_id
            || (int) $intent->user_id !== (int) $operation->user_id
            || $order->source_quote_id === null
            || $item->source_quote_id === null
            || $item->order_source_authorization_id !== null
            || (int) $item->order_id !== (int) $order->id
            || (int) $item->source_quote_id !== (int) $order->source_quote_id
            || (int) $settlement->source_quote_id !== (int) $order->source_quote_id
            || $intent->source_quote_id === null
            || (int) $intent->source_quote_id !== (int) $order->source_quote_id
            || $intent->purpose !== 'purchase'
            || $intent->state !== PaymentIntentState::Captured->value
            || $intent->captured_at === null
            || $order->state !== OrderState::ProvisioningQueued->value
            || (int) $order->state_version !== 2
            || $connection->table('provisioning_financial_invalidations')
                ->where('purchase_settlement_id', (int) $settlement->id)
                ->where('payment_intent_id', (int) $intent->id)
                ->exists()
        ) {
            throw new DomainException('Initial provisioning purchase authority is not currently captured and valid.');
        }
    }

    /**
     * @param  object{order_id:int|string,order_item_id:int|string,user_id:int|string}  $operation
     * @param  object{purchase_settlement_id:int|string|null,payment_intent_id:int|string|null,order_source_authorization_id:int|string|null}  $locator
     */
    private function lockAndAssertZeroCost(
        Connection $connection,
        object $operation,
        object $locator,
        OrderSourceType $sourceType,
    ): void {
        if ($locator->purchase_settlement_id !== null
            || $locator->payment_intent_id !== null
            || $locator->order_source_authorization_id === null
        ) {
            throw new DomainException('Provisioning zero-cost Order authority shape is invalid.');
        }

        /** @var object{id:int|string,public_id:string,source_type:string,user_id:int|string,plan_offering_id:int|string,configuration_snapshot_hash:string}|null $authorization */
        $authorization = $connection->table('order_source_authorizations')
            ->where('id', (int) $locator->order_source_authorization_id)
            ->lockForUpdate()
            ->first([
                'id',
                'public_id',
                'source_type',
                'user_id',
                'plan_offering_id',
                'configuration_snapshot_hash',
            ]);
        if ($authorization === null) {
            throw new DomainException('Provisioning source authorization is unavailable.');
        }

        /** @var object{id:int|string,source_type:string,purchase_settlement_id:int|string|null,payment_intent_id:int|string|null,order_source_authorization_id:int|string|null,order_source_authorization_public_id:string|null,user_id:int|string,source_quote_id:int|string|null,state:string,state_version:int|string,total_amount_irr:int|string,settled_amount_irr:int|string|null,paid_at:?string}|null $order */
        $order = $connection->table('orders')
            ->where('id', (int) $operation->order_id)
            ->lockForUpdate()
            ->first([
                'id',
                'source_type',
                'purchase_settlement_id',
                'payment_intent_id',
                'order_source_authorization_id',
                'order_source_authorization_public_id',
                'user_id',
                'source_quote_id',
                'state',
                'state_version',
                'total_amount_irr',
                'settled_amount_irr',
                'paid_at',
            ]);
        if ($order === null) {
            throw new RuntimeException('Provisioning zero-cost Order disappeared.');
        }

        /** @var object{id:int|string,order_id:int|string,source_quote_id:int|string|null,order_source_authorization_id:int|string|null,order_source_authorization_public_id:string|null,plan_offering_id:int|string,configuration_snapshot_hash:string,override_source:string,final_price_irr:int|string}|null $item */
        $item = $connection->table('order_items')
            ->where('id', (int) $operation->order_item_id)
            ->lockForUpdate()
            ->first([
                'id',
                'order_id',
                'source_quote_id',
                'order_source_authorization_id',
                'order_source_authorization_public_id',
                'plan_offering_id',
                'configuration_snapshot_hash',
                'override_source',
                'final_price_irr',
            ]);
        if ($item === null) {
            throw new RuntimeException('Provisioning zero-cost Order Item disappeared.');
        }

        if ($authorization->source_type !== $sourceType->value
            || $order->source_type !== $sourceType->value
            || (int) $authorization->user_id !== (int) $operation->user_id
            || (int) $order->user_id !== (int) $operation->user_id
            || (int) $order->order_source_authorization_id !== (int) $authorization->id
            || $order->order_source_authorization_public_id === null
            || ! hash_equals($order->order_source_authorization_public_id, $authorization->public_id)
            || $order->purchase_settlement_id !== null
            || $order->payment_intent_id !== null
            || $order->source_quote_id !== null
            || (int) $order->total_amount_irr !== 0
            || $order->settled_amount_irr !== null
            || $order->paid_at !== null
            || $order->state !== OrderState::ProvisioningQueued->value
            || (int) $order->state_version !== 1
            || (int) $item->order_id !== (int) $order->id
            || $item->source_quote_id !== null
            || (int) $item->order_source_authorization_id !== (int) $authorization->id
            || $item->order_source_authorization_public_id === null
            || ! hash_equals($item->order_source_authorization_public_id, $authorization->public_id)
            || (int) $item->plan_offering_id !== (int) $authorization->plan_offering_id
            || ! hash_equals(strtolower($item->configuration_snapshot_hash), strtolower($authorization->configuration_snapshot_hash))
            || $item->override_source !== 'source'
            || (int) $item->final_price_irr !== 0
        ) {
            throw new DomainException('Initial provisioning zero-cost source authority is not currently valid.');
        }
    }

    private function isSupportedZeroCostSource(OrderSourceType $sourceType): bool
    {
        return in_array($sourceType, [
            OrderSourceType::Trial,
            OrderSourceType::BenefitCode,
            OrderSourceType::AdminGrant,
        ], true);
    }
}
