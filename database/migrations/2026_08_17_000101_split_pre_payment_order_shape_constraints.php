<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement BUY-001 BUY-002 PAY-002 DAT-002 DAT-003 DAT-004 QUA-004 */
    public function up(): void
    {
        $this->dropConstraintIfExists('orders', 'orders_purchase_shape_chk');
        $this->dropConstraintIfExists('orders', 'orders_purchase_quote_identity_chk');
        $this->dropConstraintIfExists('orders', 'orders_purchase_financial_shape_chk');
        $this->dropConstraintIfExists('orders', 'orders_purchase_captured_shape_chk');

        DB::statement(<<<'SQL'
ALTER TABLE orders ADD CONSTRAINT orders_purchase_quote_identity_chk CHECK (
    `source_type` <> 'purchase'
    OR (
        `source_quote_id` IS NOT NULL
        AND `source_quote_public_id` IS NOT NULL
        AND `source_quote_configuration_hash` IS NOT NULL
        AND `total_amount_irr` > 0
    )
)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE orders ADD CONSTRAINT orders_purchase_financial_shape_chk CHECK (
    `source_type` <> 'purchase'
    OR (
        `state` <> 'awaiting_payment'
        OR (
            `state_version` = 0
            AND `purchase_settlement_id` IS NULL
            AND `purchase_settlement_public_id` IS NULL
            AND `payment_intent_id` IS NULL
            AND `payment_intent_public_id` IS NULL
            AND `settled_amount_irr` IS NULL
            AND `paid_at` IS NULL
        )
    )
)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE orders ADD CONSTRAINT orders_purchase_captured_shape_chk CHECK (
    `source_type` <> 'purchase'
    OR `state` = 'awaiting_payment'
    OR (
        `state_version` >= 1
        AND `purchase_settlement_id` IS NOT NULL
        AND `purchase_settlement_public_id` IS NOT NULL
        AND `payment_intent_id` IS NOT NULL
        AND `payment_intent_public_id` IS NOT NULL
        AND `settled_amount_irr` > 0
        AND `paid_at` IS NOT NULL
    )
)
SQL);
    }

    public function down(): void
    {
        $this->dropConstraintIfExists('orders', 'orders_purchase_quote_identity_chk');
        $this->dropConstraintIfExists('orders', 'orders_purchase_financial_shape_chk');
        $this->dropConstraintIfExists('orders', 'orders_purchase_captured_shape_chk');
        $this->dropConstraintIfExists('orders', 'orders_purchase_shape_chk');

        DB::statement(<<<'SQL'
ALTER TABLE orders ADD CONSTRAINT orders_purchase_shape_chk CHECK (
    `source_type` <> 'purchase'
    OR (
        `source_quote_id` IS NOT NULL
        AND `source_quote_public_id` IS NOT NULL
        AND `source_quote_configuration_hash` IS NOT NULL
        AND `total_amount_irr` > 0
        AND (
            (
                `state` = 'awaiting_payment'
                AND `state_version` = 0
                AND `purchase_settlement_id` IS NULL
                AND `purchase_settlement_public_id` IS NULL
                AND `payment_intent_id` IS NULL
                AND `payment_intent_public_id` IS NULL
                AND `settled_amount_irr` IS NULL
                AND `paid_at` IS NULL
            )
            OR (
                `state` <> 'awaiting_payment'
                AND `purchase_settlement_id` IS NOT NULL
                AND `purchase_settlement_public_id` IS NOT NULL
                AND `payment_intent_id` IS NOT NULL
                AND `payment_intent_public_id` IS NOT NULL
                AND `settled_amount_irr` > 0
                AND `paid_at` IS NOT NULL
            )
        )
    )
)
SQL);
    }

    private function dropConstraintIfExists(string $table, string $constraint): void
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $constraint],
        );
        if ($row !== null && (int) $row->aggregate === 1) {
            DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`");
        }
    }
};
