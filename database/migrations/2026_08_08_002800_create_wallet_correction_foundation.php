<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement WAL-005 DAT-002 DAT-003 DAT-004 ACL-001 ACL-002 QUA-001 */
    public function up(): void
    {
        Schema::create('wallet_correction_previews', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('correction_key', 128)->unique();
            $table->char('payload_hash', 64);
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('wallet_bucket', 32);
            $table->string('direction', 16);
            $table->bigInteger('amount_irr');
            $table->foreignId('requested_by_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->text('note');
            $table->string('related_type', 32)->nullable();
            $table->string('related_id', 191)->nullable();
            $table->bigInteger('preview_ledger_balance_irr');
            $table->bigInteger('preview_active_holds_irr');
            $table->bigInteger('preview_available_balance_irr');
            $table->bigInteger('preview_resulting_ledger_balance_irr');
            $table->bigInteger('preview_resulting_available_balance_irr');
            $table->boolean('approval_required');
            $table->char('confirmation_token', 64);
            $table->dateTime('created_at', 6);
            $table->index(['owner_user_id', 'ledger_account_id', 'created_at'], 'wallet_correction_preview_wallet_idx');
            $table->index(['requested_by_administrator_id', 'created_at'], 'wallet_correction_preview_actor_idx');
        });

        Schema::create('wallet_corrections', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('preview_id')->unique()->constrained('wallet_correction_previews')->restrictOnDelete();
            $table->string('correction_key', 128)->unique();
            $table->char('payload_hash', 64);
            $table->foreignId('requested_by_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->char('approval_id', 26)->nullable();
            $table->foreignId('ledger_transaction_id')->unique()->constrained('ledger_transactions')->restrictOnDelete();
            $table->bigInteger('executed_ledger_balance_before_irr');
            $table->bigInteger('executed_active_holds_irr');
            $table->bigInteger('executed_available_before_irr');
            $table->bigInteger('executed_ledger_balance_after_irr');
            $table->bigInteger('executed_available_after_irr');
            $table->dateTime('created_at', 6);
            $table->index(['requested_by_administrator_id', 'created_at'], 'wallet_correction_actor_idx');
            $table->index('approval_id', 'wallet_correction_approval_idx');
        });

        DB::statement('ALTER TABLE wallet_correction_previews ADD CONSTRAINT wallet_correction_preview_amount_chk CHECK (`amount_irr` > 0)');
        DB::statement("ALTER TABLE wallet_correction_previews ADD CONSTRAINT wallet_correction_preview_direction_chk CHECK (`direction` IN ('credit', 'debit'))");
        DB::statement("ALTER TABLE wallet_correction_previews ADD CONSTRAINT wallet_correction_preview_bucket_chk CHECK (`wallet_bucket` IN ('cash', 'promotional'))");
        DB::statement('ALTER TABLE wallet_correction_previews ADD CONSTRAINT wallet_correction_preview_hash_chk CHECK (CHAR_LENGTH(`payload_hash`) = 64 AND CHAR_LENGTH(`confirmation_token`) = 64)');
        DB::statement('ALTER TABLE wallet_correction_previews ADD CONSTRAINT wallet_correction_preview_balance_chk CHECK (`preview_ledger_balance_irr` >= 0 AND `preview_active_holds_irr` >= 0 AND `preview_available_balance_irr` >= 0 AND `preview_resulting_ledger_balance_irr` >= 0 AND `preview_resulting_available_balance_irr` >= 0 AND `preview_available_balance_irr` = `preview_ledger_balance_irr` - `preview_active_holds_irr`)');
        DB::statement("ALTER TABLE wallet_correction_previews ADD CONSTRAINT wallet_correction_preview_result_chk CHECK ((`direction` = 'credit' AND `preview_resulting_ledger_balance_irr` = `preview_ledger_balance_irr` + `amount_irr` AND `preview_resulting_available_balance_irr` = `preview_available_balance_irr` + `amount_irr`) OR (`direction` = 'debit' AND `preview_resulting_ledger_balance_irr` = `preview_ledger_balance_irr` - `amount_irr` AND `preview_resulting_available_balance_irr` = `preview_available_balance_irr` - `amount_irr`))");
        DB::statement('ALTER TABLE wallet_correction_previews ADD CONSTRAINT wallet_correction_preview_reason_chk CHECK (CHAR_LENGTH(TRIM(`reason_code`)) >= 1 AND CHAR_LENGTH(TRIM(`reason`)) >= 1 AND CHAR_LENGTH(TRIM(`note`)) >= 1)');
        DB::statement("ALTER TABLE wallet_correction_previews ADD CONSTRAINT wallet_correction_preview_related_chk CHECK ((`related_type` IS NULL AND `related_id` IS NULL) OR (`related_type` IN ('ticket', 'order', 'payment') AND `related_id` IS NOT NULL AND CHAR_LENGTH(TRIM(`related_id`)) >= 1))");

        DB::statement('ALTER TABLE wallet_corrections ADD CONSTRAINT wallet_correction_hash_chk CHECK (CHAR_LENGTH(`payload_hash`) = 64)');
        DB::statement('ALTER TABLE wallet_corrections ADD CONSTRAINT wallet_correction_balance_chk CHECK (`executed_ledger_balance_before_irr` >= 0 AND `executed_active_holds_irr` >= 0 AND `executed_available_before_irr` >= 0 AND `executed_ledger_balance_after_irr` >= 0 AND `executed_available_after_irr` >= 0 AND `executed_available_before_irr` = `executed_ledger_balance_before_irr` - `executed_active_holds_irr` AND `executed_available_after_irr` = `executed_ledger_balance_after_irr` - `executed_active_holds_irr`)');
        DB::statement('ALTER TABLE wallet_corrections ADD CONSTRAINT wallet_correction_approval_id_chk CHECK (`approval_id` IS NULL OR CHAR_LENGTH(`approval_id`) = 26)');

        $this->createPreviewGuards();
        $this->createCorrectionGuards();
    }

    public function down(): void
    {
        foreach ([
            'DROP TRIGGER IF EXISTS wallet_corrections_delete_guard',
            'DROP TRIGGER IF EXISTS wallet_corrections_update_guard',
            'DROP TRIGGER IF EXISTS wallet_corrections_insert_guard',
            'DROP TRIGGER IF EXISTS wallet_correction_previews_delete_guard',
            'DROP TRIGGER IF EXISTS wallet_correction_previews_update_guard',
            'DROP TRIGGER IF EXISTS wallet_correction_previews_insert_guard',
        ] as $statement) {
            DB::unprepared($statement);
        }

        Schema::dropIfExists('wallet_corrections');
        Schema::dropIfExists('wallet_correction_previews');
    }

    private function createPreviewGuards(): void
    {
        DB::unprepared(implode("\n", [
            'CREATE TRIGGER wallet_correction_previews_insert_guard',
            'BEFORE INSERT ON wallet_correction_previews',
            'FOR EACH ROW',
            'BEGIN',
            '    DECLARE valid_wallet_count INT DEFAULT 0;',
            '',
            '    SELECT COUNT(*) INTO valid_wallet_count',
            '    FROM ledger_accounts',
            '    WHERE id = NEW.ledger_account_id',
            '      AND owner_user_id = NEW.owner_user_id',
            '      AND wallet_bucket = NEW.wallet_bucket',
            "      AND account_class = 'liability'",
            "      AND currency = 'IRR'",
            '      AND is_active = 1;',
            '',
            '    IF valid_wallet_count <> 1 THEN',
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet correction preview requires an active owned IRR wallet account.';",
            '    END IF;',
            'END',
        ]));

        DB::unprepared(implode("\n", [
            'CREATE TRIGGER wallet_correction_previews_update_guard',
            'BEFORE UPDATE ON wallet_correction_previews',
            'FOR EACH ROW',
            'BEGIN',
            "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet correction previews are immutable.';",
            'END',
        ]));

        DB::unprepared(implode("\n", [
            'CREATE TRIGGER wallet_correction_previews_delete_guard',
            'BEFORE DELETE ON wallet_correction_previews',
            'FOR EACH ROW',
            'BEGIN',
            "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet correction previews are non-deletable.';",
            'END',
        ]));
    }

    private function createCorrectionGuards(): void
    {
        DB::unprepared(implode("\n", [
            'CREATE TRIGGER wallet_corrections_insert_guard',
            'BEFORE INSERT ON wallet_corrections',
            'FOR EACH ROW',
            'BEGIN',
            '    DECLARE valid_preview_count INT DEFAULT 0;',
            '    DECLARE valid_ledger_count INT DEFAULT 0;',
            '',
            '    SELECT COUNT(*) INTO valid_preview_count',
            '    FROM wallet_correction_previews',
            '    WHERE id = NEW.preview_id',
            '      AND correction_key = NEW.correction_key',
            '      AND payload_hash = NEW.payload_hash',
            '      AND requested_by_administrator_id = NEW.requested_by_administrator_id',
            '      AND preview_ledger_balance_irr = NEW.executed_ledger_balance_before_irr',
            '      AND preview_active_holds_irr = NEW.executed_active_holds_irr',
            '      AND preview_available_balance_irr = NEW.executed_available_before_irr',
            '      AND preview_resulting_ledger_balance_irr = NEW.executed_ledger_balance_after_irr',
            '      AND preview_resulting_available_balance_irr = NEW.executed_available_after_irr',
            '      AND ((approval_required = 1 AND NEW.approval_id IS NOT NULL) OR (approval_required = 0 AND NEW.approval_id IS NULL));',
            '',
            '    IF valid_preview_count <> 1 THEN',
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet correction execution does not match its immutable preview or approval requirement.';",
            '    END IF;',
            '',
            '    SELECT COUNT(*) INTO valid_ledger_count',
            '    FROM ledger_transactions t',
            '    INNER JOIN wallet_correction_previews p ON p.id = NEW.preview_id',
            '    WHERE t.id = NEW.ledger_transaction_id',
            "      AND t.transaction_type = 'wallet_correction'",
            "      AND t.source_type = 'wallet_correction'",
            '      AND t.source_id = NEW.correction_key',
            '      AND t.expected_total_irr = p.amount_irr',
            '      AND t.posted_debit_irr = p.amount_irr',
            '      AND t.posted_credit_irr = p.amount_irr',
            '      AND t.entry_count = 2',
            '      AND t.finalized_at IS NOT NULL;',
            '',
            '    IF valid_ledger_count <> 1 THEN',
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet correction requires one finalized balanced compensating ledger transaction.';",
            '    END IF;',
            'END',
        ]));

        DB::unprepared(implode("\n", [
            'CREATE TRIGGER wallet_corrections_update_guard',
            'BEFORE UPDATE ON wallet_corrections',
            'FOR EACH ROW',
            'BEGIN',
            "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Accepted wallet corrections are immutable.';",
            'END',
        ]));

        DB::unprepared(implode("\n", [
            'CREATE TRIGGER wallet_corrections_delete_guard',
            'BEFORE DELETE ON wallet_corrections',
            'FOR EACH ROW',
            'BEGIN',
            "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Accepted wallet corrections are non-deletable.';",
            'END',
        ]));
    }
};
