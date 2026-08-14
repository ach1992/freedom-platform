<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement BUY-001 BUY-002 PAY-002 DAT-002 DAT-003 DAT-004 QUA-004 */
    public function up(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_source_type_chk');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_source_type_chk CHECK (`source_type` IN ('purchase','trial','gift','service_code','benefit_code','admin_grant'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_non_purchase_finance_shape_chk CHECK (`source_type` = 'purchase' OR (`purchase_settlement_id` IS NULL AND `purchase_settlement_public_id` IS NULL AND `payment_intent_id` IS NULL AND `payment_intent_public_id` IS NULL AND `settled_amount_irr` IS NULL))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_source_quote_unique UNIQUE (`source_quote_id`)');
    }

    public function down(): void
    {
        if (DB::table('orders')->exists()) {
            throw new RuntimeException('Cannot roll back Order source/Quote hardening while Orders exist.');
        }

        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_source_quote_unique');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_non_purchase_finance_shape_chk');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_source_type_chk');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_source_type_chk CHECK (`source_type` = 'purchase')");
    }
};
