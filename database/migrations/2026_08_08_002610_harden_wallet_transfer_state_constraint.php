<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement WAL-003 DAT-003 DAT-004 QUA-001 */
    public function up(): void
    {
        DB::statement('ALTER TABLE wallet_transfers DROP CONSTRAINT wallet_transfer_state_chk');
        DB::statement("ALTER TABLE wallet_transfers ADD CONSTRAINT wallet_transfer_state_chk CHECK ((`status` = 'pending_confirmation' AND `confirmation_key` IS NULL AND `ledger_transaction_id` IS NULL AND `confirmed_at` IS NULL AND `completed_at` IS NULL AND `cancelled_at` IS NULL AND `cancel_reason` IS NULL) OR (`status` = 'completed' AND `confirmation_key` IS NOT NULL AND `ledger_transaction_id` IS NOT NULL AND `confirmed_at` IS NOT NULL AND `completed_at` IS NOT NULL AND `cancelled_at` IS NULL AND `cancel_reason` IS NULL) OR (`status` = 'cancelled' AND `confirmation_key` IS NULL AND `ledger_transaction_id` IS NULL AND `confirmed_at` IS NULL AND `completed_at` IS NULL AND `cancelled_at` IS NOT NULL AND `cancel_reason` IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE wallet_transfers DROP CONSTRAINT wallet_transfer_state_chk');
        DB::statement("ALTER TABLE wallet_transfers ADD CONSTRAINT wallet_transfer_state_chk CHECK ((`status` = 'pending_confirmation' AND `confirmation_key` IS NULL AND `ledger_transaction_id` IS NULL AND `confirmed_at` IS NULL AND `completed_at` IS NULL AND `cancelled_at` IS NULL AND `cancel_reason` IS NULL) OR (`status` = 'completed' AND `confirmation_key` IS NOT NULL AND `ledger_transaction_id` IS NOT NULL AND `confirmed_at` IS NOT NULL AND `completed_at` IS NOT NULL AND `cancelled_at` IS NULL AND `cancel_reason` IS NULL) OR (`status` = 'cancelled' AND `ledger_transaction_id` IS NULL AND `completed_at` IS NULL AND `cancelled_at` IS NOT NULL AND `cancel_reason` IS NOT NULL))");
    }
};
