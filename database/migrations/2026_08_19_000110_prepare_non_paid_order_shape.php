<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement BUY-001 BUY-002 CAT-006 ADM-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('order_source_authorization_id')
                ->nullable()
                ->unique('orders_source_authorization_unique')
                ->constrained('order_source_authorizations')
                ->restrictOnDelete();
            $table->ulid('order_source_authorization_public_id')
                ->nullable()
                ->unique('orders_source_authorization_public_unique');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('order_source_authorization_id')
                ->nullable()
                ->unique('order_items_source_authorization_unique')
                ->constrained('order_source_authorizations')
                ->restrictOnDelete();
            $table->ulid('order_source_authorization_public_id')
                ->nullable()
                ->unique('order_items_source_authorization_public_unique');
        });

        DB::statement('ALTER TABLE order_items MODIFY `source_quote_id` BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE order_items MODIFY `source_quote_public_id` CHAR(26) NULL');

        $this->replaceConstraint(
            'orders',
            'orders_state_chk',
            "CHECK (`state` IN ('draft','quoted','awaiting_payment','payment_pending_review','authorized','paid','provisioning_queued','provisioning','completed','needs_review','canceled','refund_pending','refunded','partially_refunded'))",
        );
        $this->replaceConstraint(
            'orders',
            'orders_non_purchase_finance_shape_chk',
            <<<'SQL'
CHECK (
    `source_type` = 'purchase'
    OR (
        `purchase_settlement_id` IS NULL
        AND `purchase_settlement_public_id` IS NULL
        AND `payment_intent_id` IS NULL
        AND `payment_intent_public_id` IS NULL
        AND `source_quote_id` IS NULL
        AND `source_quote_public_id` IS NULL
        AND `source_quote_configuration_hash` IS NULL
        AND `settled_amount_irr` IS NULL
        AND `paid_at` IS NULL
        AND `total_amount_irr` = 0
    )
)
SQL,
        );
        $this->replaceConstraint(
            'order_items',
            'order_items_override_source_chk',
            "CHECK (`override_source` IN ('none','account','tier','agent','source'))",
        );

        DB::statement(<<<'SQL'
ALTER TABLE orders
ADD CONSTRAINT orders_source_authorization_shape_chk CHECK (
    (`source_type` = 'purchase'
        AND `order_source_authorization_id` IS NULL
        AND `order_source_authorization_public_id` IS NULL)
    OR
    (`source_type` <> 'purchase'
        AND `order_source_authorization_id` IS NOT NULL
        AND `order_source_authorization_public_id` IS NOT NULL)
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE orders
ADD CONSTRAINT orders_non_paid_lifecycle_chk CHECK (
    `source_type` = 'purchase'
    OR (`state` IN ('authorized','provisioning_queued','provisioning','completed','needs_review','canceled') AND `state_version` >= 0)
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE order_items
ADD CONSTRAINT order_items_source_authority_shape_chk CHECK (
    (`source_quote_id` IS NOT NULL
        AND `source_quote_public_id` IS NOT NULL
        AND `order_source_authorization_id` IS NULL
        AND `order_source_authorization_public_id` IS NULL)
    OR
    (`source_quote_id` IS NULL
        AND `source_quote_public_id` IS NULL
        AND `order_source_authorization_id` IS NOT NULL
        AND `order_source_authorization_public_id` IS NOT NULL
        AND `override_source` = 'source'
        AND `override_reference_code` IS NOT NULL
        AND `override_price_irr` = 0
        AND `effective_price_irr` = 0
        AND `discount_irr` = 0
        AND `final_price_irr` = 0)
)
SQL);
    }

    public function down(): void
    {
        if (DB::table('orders')->whereNotNull('order_source_authorization_id')->exists()
            || DB::table('order_items')->whereNotNull('order_source_authorization_id')->exists()) {
            throw new RuntimeException('Cannot roll back non-paid Order shape after source-authorized Orders exist.');
        }

        DB::statement('ALTER TABLE order_items DROP CONSTRAINT order_items_source_authority_shape_chk');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_non_paid_lifecycle_chk');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_source_authorization_shape_chk');
        $this->replaceConstraint(
            'order_items',
            'order_items_override_source_chk',
            "CHECK (`override_source` IN ('none','account','tier','agent'))",
        );
        $this->replaceConstraint(
            'orders',
            'orders_non_purchase_finance_shape_chk',
            'CHECK (`source_type` = \'purchase\' OR (`purchase_settlement_id` IS NULL AND `purchase_settlement_public_id` IS NULL AND `payment_intent_id` IS NULL AND `payment_intent_public_id` IS NULL AND `settled_amount_irr` IS NULL))',
        );
        $this->replaceConstraint(
            'orders',
            'orders_state_chk',
            "CHECK (`state` IN ('draft','quoted','awaiting_payment','payment_pending_review','paid','provisioning_queued','provisioning','completed','needs_review','canceled','refund_pending','refunded','partially_refunded'))",
        );

        DB::statement('ALTER TABLE order_items MODIFY `source_quote_id` BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE order_items MODIFY `source_quote_public_id` CHAR(26) NOT NULL');

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropForeign(['order_source_authorization_id']);
            $table->dropUnique('order_items_source_authorization_unique');
            $table->dropUnique('order_items_source_authorization_public_unique');
            $table->dropColumn(['order_source_authorization_id', 'order_source_authorization_public_id']);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['order_source_authorization_id']);
            $table->dropUnique('orders_source_authorization_unique');
            $table->dropUnique('orders_source_authorization_public_unique');
            $table->dropColumn(['order_source_authorization_id', 'order_source_authorization_public_id']);
        });
    }

    private function replaceConstraint(string $table, string $name, string $definition): void
    {
        DB::statement(sprintf('ALTER TABLE `%s` DROP CONSTRAINT `%s`', $table, $name));
        DB::statement(sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` %s', $table, $name, $definition));
    }
};
