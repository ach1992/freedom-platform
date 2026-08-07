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
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('code', 128)->unique();
            $table->string('account_class', 16);
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('wallet_bucket', 16)->nullable();
            $table->char('currency', 3)->default('IRR');
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->unique(['owner_user_id', 'wallet_bucket'], 'ledger_account_owner_bucket_unique');
            $table->index(['account_class', 'is_active'], 'ledger_account_class_state_idx');
        });

        Schema::create('ledger_transactions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('command_key', 128)->unique();
            $table->char('payload_hash', 64);
            $table->string('transaction_type', 64);
            $table->bigInteger('expected_total_irr');
            $table->bigInteger('posted_debit_irr')->default(0);
            $table->bigInteger('posted_credit_irr')->default(0);
            $table->unsignedInteger('entry_count')->default(0);
            $table->string('source_type', 64)->nullable();
            $table->string('source_id', 191)->nullable();
            $table->string('correlation_id', 64);
            $table->dateTime('finalized_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['transaction_type', 'created_at'], 'ledger_transaction_type_created_idx');
            $table->index(['source_type', 'source_id'], 'ledger_transaction_source_idx');
            $table->index('correlation_id', 'ledger_transaction_correlation_idx');
        });

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('ledger_transaction_id')->constrained('ledger_transactions')->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('direction', 6);
            $table->bigInteger('amount_irr');
            $table->dateTime('created_at', 6);
            $table->unique(['ledger_transaction_id', 'sequence'], 'ledger_entry_transaction_sequence_unique');
            $table->index(['ledger_account_id', 'created_at'], 'ledger_entry_account_created_idx');
        });

        $this->addChecks();
        $this->createAccountGuards();
        $this->createTransactionGuards();
        $this->createEntryGuards();
    }

    public function down(): void
    {
        $this->dropTriggers();
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('ledger_accounts');
    }

    private function addChecks(): void
    {
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_account_class_chk CHECK (`account_class` IN ('asset', 'liability', 'revenue', 'expense', 'equity'))");
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_account_currency_chk CHECK (`currency` = 'IRR')");
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_account_bucket_chk CHECK (`wallet_bucket` IS NULL OR `wallet_bucket` IN ('cash', 'promotional'))");
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_account_owner_bucket_chk CHECK ((`owner_user_id` IS NULL AND `wallet_bucket` IS NULL) OR (`owner_user_id` IS NOT NULL AND `wallet_bucket` IS NOT NULL AND `account_class` = 'liability'))");
        DB::statement('ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_account_code_chk CHECK (CHAR_LENGTH(TRIM(`code`)) >= 3)');

        DB::statement('ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transaction_amount_chk CHECK (`expected_total_irr` > 0 AND `posted_debit_irr` >= 0 AND `posted_credit_irr` >= 0)');
        DB::statement('ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transaction_hash_chk CHECK (CHAR_LENGTH(`payload_hash`) = 64)');
        DB::statement("ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transaction_source_chk CHECK ((`source_type` IS NULL AND `source_id` IS NULL) OR (`source_type` IS NOT NULL AND `source_id` IS NOT NULL))");
        DB::statement('ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transaction_entry_count_chk CHECK (`entry_count` <= 65535)');

        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entry_direction_chk CHECK (`direction` IN ('debit', 'credit'))");
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entry_amount_chk CHECK (`amount_irr` > 0)');
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entry_sequence_chk CHECK (`sequence` >= 1)');
    }

    private function createAccountGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER ledger_accounts_update_guard
BEFORE UPDATE ON ledger_accounts
FOR EACH ROW
BEGIN
    IF NOT (OLD.code <=> NEW.code)
       OR NOT (OLD.account_class <=> NEW.account_class)
       OR NOT (OLD.owner_user_id <=> NEW.owner_user_id)
       OR NOT (OLD.wallet_bucket <=> NEW.wallet_bucket)
       OR NOT (OLD.currency <=> NEW.currency)
       OR NOT (OLD.created_at <=> NEW.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger account identity is immutable.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER ledger_accounts_delete_guard
BEFORE DELETE ON ledger_accounts
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger accounts are non-deletable.';
END
SQL);
    }

    private function createTransactionGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER ledger_transactions_update_guard
BEFORE UPDATE ON ledger_transactions
FOR EACH ROW
BEGIN
    DECLARE calculated_debit BIGINT DEFAULT 0;
    DECLARE calculated_credit BIGINT DEFAULT 0;
    DECLARE calculated_count INT UNSIGNED DEFAULT 0;

    IF OLD.finalized_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Finalized ledger transactions are immutable.';
    END IF;

    IF NOT (OLD.command_key <=> NEW.command_key)
       OR NOT (OLD.payload_hash <=> NEW.payload_hash)
       OR NOT (OLD.transaction_type <=> NEW.transaction_type)
       OR NOT (OLD.expected_total_irr <=> NEW.expected_total_irr)
       OR NOT (OLD.source_type <=> NEW.source_type)
       OR NOT (OLD.source_id <=> NEW.source_id)
       OR NOT (OLD.correlation_id <=> NEW.correlation_id)
       OR NOT (OLD.created_at <=> NEW.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger transaction identity is immutable.';
    END IF;

    IF NEW.finalized_at IS NOT NULL THEN
        SELECT
            COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount_irr ELSE 0 END), 0),
            COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount_irr ELSE 0 END), 0),
            COUNT(*)
        INTO calculated_debit, calculated_credit, calculated_count
        FROM ledger_entries
        WHERE ledger_transaction_id = OLD.id;

        IF calculated_count < 2
           OR calculated_debit <> calculated_credit
           OR calculated_debit <> NEW.expected_total_irr
           OR calculated_debit <> NEW.posted_debit_irr
           OR calculated_credit <> NEW.posted_credit_irr
           OR calculated_count <> NEW.entry_count THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger transaction is not balanced.';
        END IF;
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER ledger_transactions_delete_guard
BEFORE DELETE ON ledger_transactions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger transactions are non-deletable.';
END
SQL);
    }

    private function createEntryGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER ledger_entries_insert_guard
BEFORE INSERT ON ledger_entries
FOR EACH ROW
BEGIN
    DECLARE transaction_finalized_at DATETIME(6);

    SELECT finalized_at INTO transaction_finalized_at
    FROM ledger_transactions
    WHERE id = NEW.ledger_transaction_id
    FOR UPDATE;

    IF transaction_finalized_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot append to a finalized ledger transaction.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER ledger_entries_after_insert
AFTER INSERT ON ledger_entries
FOR EACH ROW
BEGIN
    UPDATE ledger_transactions
    SET posted_debit_irr = posted_debit_irr + IF(NEW.direction = 'debit', NEW.amount_irr, 0),
        posted_credit_irr = posted_credit_irr + IF(NEW.direction = 'credit', NEW.amount_irr, 0),
        entry_count = entry_count + 1
    WHERE id = NEW.ledger_transaction_id;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER ledger_entries_update_guard
BEFORE UPDATE ON ledger_entries
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entries are append-only.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER ledger_entries_delete_guard
BEFORE DELETE ON ledger_entries
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entries are append-only.';
END
SQL);
    }

    private function dropTriggers(): void
    {
        foreach ([
            'ledger_entries_delete_guard',
            'ledger_entries_update_guard',
            'ledger_entries_after_insert',
            'ledger_entries_insert_guard',
            'ledger_transactions_delete_guard',
            'ledger_transactions_update_guard',
            'ledger_accounts_delete_guard',
            'ledger_accounts_update_guard',
        ] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
    }
};
