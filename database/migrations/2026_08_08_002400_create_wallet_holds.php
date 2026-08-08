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
        Schema::create('wallet_holds', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('hold_key', 128)->unique();
            $table->char('payload_hash', 64);
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->bigInteger('amount_irr');
            $table->string('source_type', 64);
            $table->string('source_id', 191);
            $table->string('status', 16)->default('active');
            $table->dateTime('expires_at', 6);
            $table->foreignId('captured_ledger_transaction_id')->nullable()->constrained('ledger_transactions')->restrictOnDelete();
            $table->dateTime('captured_at', 6)->nullable();
            $table->dateTime('released_at', 6)->nullable();
            $table->string('release_reason', 191)->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['ledger_account_id', 'status'], 'wallet_hold_account_status_idx');
            $table->index(['owner_user_id', 'status'], 'wallet_hold_owner_status_idx');
            $table->index(['status', 'expires_at'], 'wallet_hold_status_expiry_idx');
            $table->index(['source_type', 'source_id'], 'wallet_hold_source_idx');
        });

        DB::statement("ALTER TABLE wallet_holds ADD CONSTRAINT wallet_hold_status_chk CHECK (`status` IN ('active', 'captured', 'released'))");
        DB::statement('ALTER TABLE wallet_holds ADD CONSTRAINT wallet_hold_amount_chk CHECK (`amount_irr` > 0)');
        DB::statement('ALTER TABLE wallet_holds ADD CONSTRAINT wallet_hold_hash_chk CHECK (CHAR_LENGTH(`payload_hash`) = 64)');
        DB::statement("ALTER TABLE wallet_holds ADD CONSTRAINT wallet_hold_state_chk CHECK ((`status` = 'active' AND `captured_ledger_transaction_id` IS NULL AND `captured_at` IS NULL AND `released_at` IS NULL AND `release_reason` IS NULL) OR (`status` = 'captured' AND `captured_ledger_transaction_id` IS NOT NULL AND `captured_at` IS NOT NULL AND `released_at` IS NULL AND `release_reason` IS NULL) OR (`status` = 'released' AND `captured_ledger_transaction_id` IS NULL AND `captured_at` IS NULL AND `released_at` IS NOT NULL AND `release_reason` IS NOT NULL))");

        DB::unprepared(implode("\n", [
            'CREATE TRIGGER wallet_holds_update_guard',
            'BEFORE UPDATE ON wallet_holds',
            'FOR EACH ROW',
            'BEGIN',
            '    IF NOT (OLD.hold_key <=> NEW.hold_key)',
            '       OR NOT (OLD.payload_hash <=> NEW.payload_hash)',
            '       OR NOT (OLD.owner_user_id <=> NEW.owner_user_id)',
            '       OR NOT (OLD.ledger_account_id <=> NEW.ledger_account_id)',
            '       OR NOT (OLD.amount_irr <=> NEW.amount_irr)',
            '       OR NOT (OLD.source_type <=> NEW.source_type)',
            '       OR NOT (OLD.source_id <=> NEW.source_id)',
            '       OR NOT (OLD.expires_at <=> NEW.expires_at)',
            '       OR NOT (OLD.created_at <=> NEW.created_at) THEN',
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet hold identity is immutable.';",
            '    END IF;',
            '',
            "    IF OLD.status <> 'active' THEN",
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Terminal wallet hold is immutable.';",
            '    END IF;',
            '',
            "    IF NEW.status = 'captured' THEN",
            '        IF NEW.captured_ledger_transaction_id IS NULL OR NEW.captured_at IS NULL OR NEW.released_at IS NOT NULL OR NEW.release_reason IS NOT NULL THEN',
            "            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid wallet hold capture state.';",
            '        END IF;',
            "    ELSEIF NEW.status = 'released' THEN",
            '        IF NEW.captured_ledger_transaction_id IS NOT NULL OR NEW.captured_at IS NOT NULL OR NEW.released_at IS NULL OR NEW.release_reason IS NULL OR CHAR_LENGTH(TRIM(NEW.release_reason)) = 0 THEN',
            "            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid wallet hold release state.';",
            '        END IF;',
            '    ELSE',
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet hold transition is invalid.';",
            '    END IF;',
            'END',
        ]));

        DB::unprepared(implode("\n", [
            'CREATE TRIGGER wallet_holds_delete_guard',
            'BEFORE DELETE ON wallet_holds',
            'FOR EACH ROW',
            'BEGIN',
            "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet holds are non-deletable.';",
            'END',
        ]));
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS wallet_holds_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS wallet_holds_update_guard');
        Schema::dropIfExists('wallet_holds');
    }
};
