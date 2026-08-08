<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement WAL-003 DAT-002 DAT-003 DAT-004 QUA-001 */
    public function up(): void
    {
        Schema::create('wallet_transfers', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('transfer_key', 128)->unique();
            $table->char('payload_hash', 64);
            $table->foreignId('sender_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->char('recipient_public_id', 26);
            $table->foreignId('sender_wallet_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('recipient_wallet_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('wallet_bucket', 16);
            $table->bigInteger('amount_irr');
            $table->bigInteger('fee_irr');
            $table->bigInteger('total_debit_irr');
            $table->bigInteger('policy_minimum_irr');
            $table->bigInteger('policy_maximum_irr');
            $table->bigInteger('policy_daily_limit_irr');
            $table->bigInteger('policy_fixed_fee_irr');
            $table->unsignedSmallInteger('policy_fee_basis_points');
            $table->date('policy_business_date');
            $table->foreignId('wallet_hold_id')->unique()->constrained('wallet_holds')->restrictOnDelete();
            $table->string('status', 32)->default('pending_confirmation');
            $table->string('confirmation_key', 128)->nullable();
            $table->foreignId('ledger_transaction_id')->nullable()->unique()->constrained('ledger_transactions')->restrictOnDelete();
            $table->dateTime('confirmation_expires_at', 6);
            $table->dateTime('confirmed_at', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->dateTime('cancelled_at', 6)->nullable();
            $table->string('cancel_reason', 191)->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['sender_user_id', 'policy_business_date', 'status'], 'wallet_transfer_sender_day_state_idx');
            $table->index(['recipient_user_id', 'created_at'], 'wallet_transfer_recipient_created_idx');
            $table->index(['sender_wallet_account_id', 'status'], 'wallet_transfer_sender_wallet_state_idx');
        });

        DB::statement("ALTER TABLE wallet_transfers ADD CONSTRAINT wallet_transfer_status_chk CHECK (`status` IN ('pending_confirmation', 'completed', 'cancelled'))");
        DB::statement("ALTER TABLE wallet_transfers ADD CONSTRAINT wallet_transfer_bucket_chk CHECK (`wallet_bucket` IN ('cash', 'promotional'))");
        DB::statement('ALTER TABLE wallet_transfers ADD CONSTRAINT wallet_transfer_amounts_chk CHECK (`amount_irr` > 0 AND `fee_irr` >= 0 AND `total_debit_irr` > 0 AND `total_debit_irr` = `amount_irr` + `fee_irr`)');
        DB::statement('ALTER TABLE wallet_transfers ADD CONSTRAINT wallet_transfer_policy_chk CHECK (`policy_minimum_irr` > 0 AND `policy_maximum_irr` >= `policy_minimum_irr` AND `policy_daily_limit_irr` >= `policy_maximum_irr` AND `policy_fixed_fee_irr` >= 0 AND `policy_fee_basis_points` <= 10000)');
        DB::statement('ALTER TABLE wallet_transfers ADD CONSTRAINT wallet_transfer_users_chk CHECK (`sender_user_id` <> `recipient_user_id`)');
        DB::statement('ALTER TABLE wallet_transfers ADD CONSTRAINT wallet_transfer_accounts_chk CHECK (`sender_wallet_account_id` <> `recipient_wallet_account_id`)');
        DB::statement('ALTER TABLE wallet_transfers ADD CONSTRAINT wallet_transfer_hash_chk CHECK (CHAR_LENGTH(`payload_hash`) = 64 AND CHAR_LENGTH(`recipient_public_id`) = 26)');
        DB::statement("ALTER TABLE wallet_transfers ADD CONSTRAINT wallet_transfer_state_chk CHECK ((`status` = 'pending_confirmation' AND `confirmation_key` IS NULL AND `ledger_transaction_id` IS NULL AND `confirmed_at` IS NULL AND `completed_at` IS NULL AND `cancelled_at` IS NULL AND `cancel_reason` IS NULL) OR (`status` = 'completed' AND `confirmation_key` IS NOT NULL AND `ledger_transaction_id` IS NOT NULL AND `confirmed_at` IS NOT NULL AND `completed_at` IS NOT NULL AND `cancelled_at` IS NULL AND `cancel_reason` IS NULL) OR (`status` = 'cancelled' AND `ledger_transaction_id` IS NULL AND `completed_at` IS NULL AND `cancelled_at` IS NOT NULL AND `cancel_reason` IS NOT NULL))");

        DB::unprepared(implode("\n", [
            'CREATE TRIGGER wallet_transfers_update_guard',
            'BEFORE UPDATE ON wallet_transfers',
            'FOR EACH ROW',
            'BEGIN',
            '    IF NOT (OLD.transfer_key <=> NEW.transfer_key)',
            '       OR NOT (OLD.payload_hash <=> NEW.payload_hash)',
            '       OR NOT (OLD.sender_user_id <=> NEW.sender_user_id)',
            '       OR NOT (OLD.recipient_user_id <=> NEW.recipient_user_id)',
            '       OR NOT (OLD.recipient_public_id <=> NEW.recipient_public_id)',
            '       OR NOT (OLD.sender_wallet_account_id <=> NEW.sender_wallet_account_id)',
            '       OR NOT (OLD.recipient_wallet_account_id <=> NEW.recipient_wallet_account_id)',
            '       OR NOT (OLD.wallet_bucket <=> NEW.wallet_bucket)',
            '       OR NOT (OLD.amount_irr <=> NEW.amount_irr)',
            '       OR NOT (OLD.fee_irr <=> NEW.fee_irr)',
            '       OR NOT (OLD.total_debit_irr <=> NEW.total_debit_irr)',
            '       OR NOT (OLD.policy_minimum_irr <=> NEW.policy_minimum_irr)',
            '       OR NOT (OLD.policy_maximum_irr <=> NEW.policy_maximum_irr)',
            '       OR NOT (OLD.policy_daily_limit_irr <=> NEW.policy_daily_limit_irr)',
            '       OR NOT (OLD.policy_fixed_fee_irr <=> NEW.policy_fixed_fee_irr)',
            '       OR NOT (OLD.policy_fee_basis_points <=> NEW.policy_fee_basis_points)',
            '       OR NOT (OLD.policy_business_date <=> NEW.policy_business_date)',
            '       OR NOT (OLD.wallet_hold_id <=> NEW.wallet_hold_id)',
            '       OR NOT (OLD.confirmation_expires_at <=> NEW.confirmation_expires_at)',
            '       OR NOT (OLD.created_at <=> NEW.created_at) THEN',
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet transfer identity and policy snapshot are immutable.';",
            '    END IF;',
            '',
            "    IF OLD.status <> 'pending_confirmation' THEN",
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Terminal wallet transfer is immutable.';",
            '    END IF;',
            '',
            "    IF NEW.status = 'completed' THEN",
            '        IF NEW.confirmation_key IS NULL OR NEW.ledger_transaction_id IS NULL OR NEW.confirmed_at IS NULL OR NEW.completed_at IS NULL OR NEW.cancelled_at IS NOT NULL OR NEW.cancel_reason IS NOT NULL THEN',
            "            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet transfer completion state is invalid.';",
            '        END IF;',
            "    ELSEIF NEW.status = 'cancelled' THEN",
            '        IF NEW.ledger_transaction_id IS NOT NULL OR NEW.completed_at IS NOT NULL OR NEW.cancelled_at IS NULL OR NEW.cancel_reason IS NULL OR CHAR_LENGTH(TRIM(NEW.cancel_reason)) = 0 THEN',
            "            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet transfer cancellation state is invalid.';",
            '        END IF;',
            '    ELSE',
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet transfer transition is invalid.';",
            '    END IF;',
            'END',
        ]));

        DB::unprepared(implode("\n", [
            'CREATE TRIGGER wallet_transfers_delete_guard',
            'BEFORE DELETE ON wallet_transfers',
            'FOR EACH ROW',
            'BEGIN',
            "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet transfers are non-deletable.';",
            'END',
        ]));
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS wallet_transfers_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS wallet_transfers_update_guard');
        Schema::dropIfExists('wallet_transfers');
    }
};
