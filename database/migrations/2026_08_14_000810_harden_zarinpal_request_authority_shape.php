<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement IPG-001 DAT-002 DAT-003 INT-001 INT-002 QUA-004 */
    public function up(): void
    {
        DB::statement('ALTER TABLE zarinpal_payment_requests DROP CONSTRAINT zarinpal_request_authority_shape_chk');
        DB::statement(<<<'SQL'
ALTER TABLE zarinpal_payment_requests ADD CONSTRAINT zarinpal_request_authority_shape_chk CHECK (
    (`state` = 'initiating' AND `authority` IS NULL AND `authority_received_at` IS NULL)
    OR (`state` IN ('redirectable','verified') AND `authority` IS NOT NULL AND `authority_received_at` IS NOT NULL)
    OR (`state` IN ('uncertain','failed','manual_review') AND (
        (`authority` IS NULL AND `authority_received_at` IS NULL)
        OR (`authority` IS NOT NULL AND `authority_received_at` IS NOT NULL)
    ))
)
SQL);
    }

    public function down(): void
    {
        if (DB::table('zarinpal_payment_requests')->where('state', 'manual_review')->whereNull('authority')->exists()) {
            throw new RuntimeException('Cannot restore the older Zarinpal authority shape after authorityless manual review exists.');
        }

        DB::statement('ALTER TABLE zarinpal_payment_requests DROP CONSTRAINT zarinpal_request_authority_shape_chk');
        DB::statement("ALTER TABLE zarinpal_payment_requests ADD CONSTRAINT zarinpal_request_authority_shape_chk CHECK ((`state` = 'initiating' AND `authority` IS NULL AND `authority_received_at` IS NULL) OR (`state` IN ('uncertain','failed') AND (`authority` IS NULL OR `authority_received_at` IS NOT NULL)) OR (`state` IN ('redirectable','verified','manual_review') AND `authority` IS NOT NULL AND `authority_received_at` IS NOT NULL))");
    }
};
