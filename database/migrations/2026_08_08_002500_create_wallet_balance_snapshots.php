<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement WAL-002 DAT-002 DAT-003 DAT-004 QUA-001 */
    public function up(): void
    {
        Schema::create('wallet_balance_snapshots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->bigInteger('ledger_balance_irr');
            $table->bigInteger('active_holds_irr');
            $table->bigInteger('available_balance_irr');
            $table->foreignId('last_finalized_ledger_entry_id')->nullable()->constrained('ledger_entries')->restrictOnDelete();
            $table->foreignId('last_active_hold_id')->nullable()->constrained('wallet_holds')->restrictOnDelete();
            $table->char('source_fingerprint', 64);
            $table->string('comparison_status', 16);
            $table->unsignedBigInteger('compared_snapshot_id')->nullable();
            $table->dateTime('calculated_at', 6);
            $table->dateTime('created_at', 6);
            $table->index(['ledger_account_id', 'id'], 'wallet_snapshot_account_id_idx');
            $table->index(['ledger_account_id', 'comparison_status'], 'wallet_snapshot_account_status_idx');
        });

        Schema::table('wallet_balance_snapshots', function (Blueprint $table): void {
            $table->foreign('compared_snapshot_id', 'wallet_snapshot_compared_fk')
                ->references('id')
                ->on('wallet_balance_snapshots')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE wallet_balance_snapshots ADD CONSTRAINT wallet_snapshot_amounts_chk CHECK (`ledger_balance_irr` >= 0 AND `active_holds_irr` >= 0 AND `available_balance_irr` >= 0 AND `active_holds_irr` <= `ledger_balance_irr` AND `available_balance_irr` = `ledger_balance_irr` - `active_holds_irr`)');
        DB::statement('ALTER TABLE wallet_balance_snapshots ADD CONSTRAINT wallet_snapshot_hash_chk CHECK (CHAR_LENGTH(`source_fingerprint`) = 64)');
        DB::statement("ALTER TABLE wallet_balance_snapshots ADD CONSTRAINT wallet_snapshot_status_chk CHECK (`comparison_status` IN ('initial', 'matched', 'refreshed'))");
        DB::statement("ALTER TABLE wallet_balance_snapshots ADD CONSTRAINT wallet_snapshot_compare_chk CHECK ((`comparison_status` = 'initial' AND `compared_snapshot_id` IS NULL) OR (`comparison_status` IN ('matched', 'refreshed') AND `compared_snapshot_id` IS NOT NULL))");

        DB::unprepared(implode("\n", [
            'CREATE TRIGGER wallet_balance_snapshots_update_guard',
            'BEFORE UPDATE ON wallet_balance_snapshots',
            'FOR EACH ROW',
            'BEGIN',
            "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet balance snapshots are immutable.';",
            'END',
        ]));

        DB::unprepared(implode("\n", [
            'CREATE TRIGGER wallet_balance_snapshots_delete_guard',
            'BEFORE DELETE ON wallet_balance_snapshots',
            'FOR EACH ROW',
            'BEGIN',
            "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet balance snapshots are non-deletable.';",
            'END',
        ]));
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS wallet_balance_snapshots_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS wallet_balance_snapshots_update_guard');
        Schema::dropIfExists('wallet_balance_snapshots');
    }
};
