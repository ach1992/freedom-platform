<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement IPG-002 DAT-003 DAT-004 QUA-004 */
    public function up(): void
    {
        DB::statement('ALTER TABLE nowpayments_payment_authorities DROP CONSTRAINT nowpayments_authority_provider_shape_chk');
        DB::statement(<<<'SQL'
ALTER TABLE nowpayments_payment_authorities
ADD CONSTRAINT nowpayments_authority_provider_shape_chk CHECK (
    (`state` = 'initiating'
        AND `provider_payment_id` IS NULL
        AND `provider_status` IS NULL
        AND `create_response_hash` IS NULL)
    OR (`state` IN ('uncertain','failed','manual_review') AND (
        (`provider_payment_id` IS NULL AND `provider_status` IS NULL AND `create_response_hash` IS NULL)
        OR (`provider_payment_id` IS NOT NULL AND `provider_status` IS NOT NULL AND `create_response_hash` IS NOT NULL)
    ))
    OR (`state` IN ('created','finished','expired')
        AND `provider_payment_id` IS NOT NULL
        AND `provider_status` IS NOT NULL
        AND `create_response_hash` IS NOT NULL)
)
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE nowpayments_payment_authorities DROP CONSTRAINT nowpayments_authority_provider_shape_chk');
        DB::statement(<<<'SQL'
ALTER TABLE nowpayments_payment_authorities
ADD CONSTRAINT nowpayments_authority_provider_shape_chk CHECK (
    (`state` IN ('initiating','uncertain') AND (`provider_payment_id` IS NULL OR `create_response_hash` IS NOT NULL))
    OR (`state` IN ('created','manual_review','finished','failed','expired')
        AND `provider_payment_id` IS NOT NULL
        AND `provider_status` IS NOT NULL
        AND `create_response_hash` IS NOT NULL)
)
SQL);
    }
};
