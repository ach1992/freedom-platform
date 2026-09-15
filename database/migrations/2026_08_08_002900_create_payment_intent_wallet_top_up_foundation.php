<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement PAY-002 PAY-003 WAL-001 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 */
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('creation_key', 128)->unique();
            $table->char('payload_hash', 64);
            $table->string('purpose', 32);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('wallet_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('provider_code', 64);
            $table->bigInteger('amount_irr');
            $table->char('currency', 3)->default('IRR');
            $table->string('state', 32);
            $table->string('creation_correlation_id', 64);
            $table->dateTime('captured_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['user_id', 'created_at'], 'payment_intent_user_created_idx');
            $table->index(['provider_code', 'state', 'created_at'], 'payment_intent_provider_state_idx');
            $table->index(['wallet_account_id', 'state'], 'payment_intent_wallet_state_idx');
        });

        Schema::create('payment_intent_state_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('payment_intent_id')->constrained('payment_intents')->restrictOnDelete();
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->string('reason_code', 64);
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->index(['payment_intent_id', 'created_at'], 'payment_intent_history_idx');
        });

        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('payment_intent_id')->constrained('payment_intents')->restrictOnDelete();
            $table->char('attempt_key', 64);
            $table->string('provider_code', 64);
            $table->string('state', 32);
            $table->dateTime('created_at', 6);
            $table->unique(['payment_intent_id', 'attempt_key'], 'payment_attempt_intent_key_unique');
            $table->index(['payment_intent_id', 'state'], 'payment_attempt_intent_state_idx');
        });

        Schema::create('payment_provider_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('payment_intent_id')->constrained('payment_intents')->restrictOnDelete();
            $table->string('provider_code', 64);
            $table->string('provider_event_id', 191);
            $table->char('event_payload_hash', 64);
            $table->string('provider_transaction_id', 191);
            $table->char('evidence_payload_hash', 64);
            $table->string('evidence_authority', 32);
            $table->string('transaction_status', 32);
            $table->bigInteger('amount_irr');
            $table->char('currency', 3);
            $table->dateTime('occurred_at', 6);
            $table->dateTime('settled_at', 6)->nullable();
            $table->json('safe_evidence');
            $table->dateTime('created_at', 6);
            $table->unique(['provider_code', 'provider_event_id'], 'payment_provider_event_unique');
            $table->index(['payment_intent_id', 'created_at'], 'payment_provider_event_intent_idx');
        });

        Schema::create('payment_provider_transactions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('payment_intent_id')->constrained('payment_intents')->restrictOnDelete();
            $table->foreignId('provider_event_row_id')->unique()->constrained('payment_provider_events')->restrictOnDelete();
            $table->string('provider_code', 64);
            $table->string('provider_transaction_id', 191);
            $table->char('evidence_payload_hash', 64);
            $table->string('transaction_status', 32);
            $table->bigInteger('amount_irr');
            $table->char('currency', 3);
            $table->dateTime('occurred_at', 6);
            $table->dateTime('settled_at', 6);
            $table->dateTime('created_at', 6);
            $table->unique(['provider_code', 'provider_transaction_id'], 'payment_provider_transaction_unique');
            $table->index(['payment_intent_id', 'created_at'], 'payment_provider_transaction_intent_idx');
        });

        Schema::create('wallet_top_up_settlements', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('payment_intent_id')->unique()->constrained('payment_intents')->restrictOnDelete();
            $table->foreignId('provider_transaction_row_id')->unique()->constrained('payment_provider_transactions')->restrictOnDelete();
            $table->foreignId('wallet_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('ledger_transaction_id')->unique()->constrained('ledger_transactions')->restrictOnDelete();
            $table->bigInteger('amount_irr');
            $table->dateTime('created_at', 6);
            $table->index(['wallet_account_id', 'created_at'], 'wallet_top_up_wallet_created_idx');
        });

        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT payment_intent_purpose_chk CHECK (`purpose` = 'wallet_top_up')");
        DB::statement('ALTER TABLE payment_intents ADD CONSTRAINT payment_intent_amount_chk CHECK (`amount_irr` > 0)');
        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT payment_intent_currency_chk CHECK (`currency` = 'IRR')");
        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT payment_intent_state_chk CHECK (`state` IN ('created','awaiting_user_action','submitted','verifying','pending_manual_review','authorized','captured','failed','expired','canceled','refund_pending','refunded','partially_refunded'))");
        DB::statement('ALTER TABLE payment_intents ADD CONSTRAINT payment_intent_hash_chk CHECK (CHAR_LENGTH(`payload_hash`) = 64)');
        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT payment_intent_capture_time_chk CHECK ((`state` = 'captured' AND `captured_at` IS NOT NULL) OR (`state` <> 'captured'))");

        DB::statement("ALTER TABLE payment_intent_state_histories ADD CONSTRAINT payment_intent_history_state_chk CHECK (`to_state` IN ('created','awaiting_user_action','submitted','verifying','pending_manual_review','authorized','captured','failed','expired','canceled','refund_pending','refunded','partially_refunded'))");
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempt_state_chk CHECK (`state` IN ('created','verifying','captured','failed'))");
        DB::statement('ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempt_key_chk CHECK (CHAR_LENGTH(`attempt_key`) = 64)');

        DB::statement('ALTER TABLE payment_provider_events ADD CONSTRAINT payment_provider_event_amount_chk CHECK (`amount_irr` > 0)');
        DB::statement("ALTER TABLE payment_provider_events ADD CONSTRAINT payment_provider_event_currency_chk CHECK (`currency` = 'IRR')");
        DB::statement("ALTER TABLE payment_provider_events ADD CONSTRAINT payment_provider_event_authority_chk CHECK (`evidence_authority` IN ('non_authoritative','authoritative'))");
        DB::statement("ALTER TABLE payment_provider_events ADD CONSTRAINT payment_provider_event_status_chk CHECK (`transaction_status` IN ('pending','authorized','settled','failed','canceled','refunded','reversed','unknown'))");
        DB::statement('ALTER TABLE payment_provider_events ADD CONSTRAINT payment_provider_event_hash_chk CHECK (CHAR_LENGTH(`event_payload_hash`) = 64 AND CHAR_LENGTH(`evidence_payload_hash`) = 64)');

        DB::statement('ALTER TABLE payment_provider_transactions ADD CONSTRAINT payment_provider_transaction_amount_chk CHECK (`amount_irr` > 0)');
        DB::statement("ALTER TABLE payment_provider_transactions ADD CONSTRAINT payment_provider_transaction_currency_chk CHECK (`currency` = 'IRR')");
        DB::statement("ALTER TABLE payment_provider_transactions ADD CONSTRAINT payment_provider_transaction_status_chk CHECK (`transaction_status` = 'settled')");
        DB::statement('ALTER TABLE payment_provider_transactions ADD CONSTRAINT payment_provider_transaction_hash_chk CHECK (CHAR_LENGTH(`evidence_payload_hash`) = 64)');
        DB::statement('ALTER TABLE wallet_top_up_settlements ADD CONSTRAINT wallet_top_up_amount_chk CHECK (`amount_irr` > 0)');

        $this->createIntentGuards();
        $this->createImmutableGuards();
        $this->createSettlementGuard();
    }

    public function down(): void
    {
        foreach ([
            'DROP TRIGGER IF EXISTS wallet_top_up_settlements_delete_guard',
            'DROP TRIGGER IF EXISTS wallet_top_up_settlements_update_guard',
            'DROP TRIGGER IF EXISTS wallet_top_up_settlements_insert_guard',
            'DROP TRIGGER IF EXISTS payment_provider_transactions_delete_guard',
            'DROP TRIGGER IF EXISTS payment_provider_transactions_update_guard',
            'DROP TRIGGER IF EXISTS payment_provider_events_delete_guard',
            'DROP TRIGGER IF EXISTS payment_provider_events_update_guard',
            'DROP TRIGGER IF EXISTS payment_attempts_delete_guard',
            'DROP TRIGGER IF EXISTS payment_attempts_update_guard',
            'DROP TRIGGER IF EXISTS payment_intent_state_histories_delete_guard',
            'DROP TRIGGER IF EXISTS payment_intent_state_histories_update_guard',
            'DROP TRIGGER IF EXISTS payment_intents_delete_guard',
            'DROP TRIGGER IF EXISTS payment_intents_update_guard',
            'DROP TRIGGER IF EXISTS payment_intents_insert_guard',
        ] as $statement) {
            DB::unprepared($statement);
        }

        Schema::dropIfExists('wallet_top_up_settlements');
        Schema::dropIfExists('payment_provider_transactions');
        Schema::dropIfExists('payment_provider_events');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payment_intent_state_histories');
        Schema::dropIfExists('payment_intents');
    }

    private function createIntentGuards(): void
    {
        DB::unprepared(implode("\n", [
            'CREATE TRIGGER payment_intents_insert_guard',
            'BEFORE INSERT ON payment_intents',
            'FOR EACH ROW',
            'BEGIN',
            '    DECLARE valid_wallet_count INT DEFAULT 0;',
            '',
            '    SELECT COUNT(*) INTO valid_wallet_count',
            '    FROM ledger_accounts',
            '    WHERE id = NEW.wallet_account_id',
            '      AND owner_user_id = NEW.user_id',
            "      AND account_class = 'liability'",
            "      AND wallet_bucket = 'cash'",
            "      AND currency = 'IRR'",
            '      AND is_active = 1;',
            '',
            '    IF valid_wallet_count <> 1 THEN',
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet top-up intent requires one active owned IRR cash wallet.';",
            '    END IF;',
            'END',
        ]));

        DB::unprepared(implode("\n", [
            'CREATE TRIGGER payment_intents_update_guard',
            'BEFORE UPDATE ON payment_intents',
            'FOR EACH ROW',
            'BEGIN',
            '    IF NEW.public_id <> OLD.public_id',
            '       OR NEW.creation_key <> OLD.creation_key',
            '       OR NEW.payload_hash <> OLD.payload_hash',
            '       OR NEW.purpose <> OLD.purpose',
            '       OR NEW.user_id <> OLD.user_id',
            '       OR NEW.wallet_account_id <> OLD.wallet_account_id',
            '       OR NEW.provider_code <> OLD.provider_code',
            '       OR NEW.amount_irr <> OLD.amount_irr',
            '       OR NEW.currency <> OLD.currency',
            '       OR NEW.creation_correlation_id <> OLD.creation_correlation_id',
            '       OR NEW.created_at <> OLD.created_at THEN',
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent financial identity is immutable.';",
            '    END IF;',
            '',
            '    IF NEW.state <> OLD.state AND NOT (',
            "        (OLD.state = 'created' AND NEW.state IN ('awaiting_user_action','canceled')) OR",
            "        (OLD.state = 'awaiting_user_action' AND NEW.state IN ('submitted','expired')) OR",
            "        (OLD.state = 'submitted' AND NEW.state IN ('verifying','failed')) OR",
            "        (OLD.state = 'verifying' AND NEW.state IN ('pending_manual_review','authorized','captured','failed')) OR",
            "        (OLD.state = 'pending_manual_review' AND NEW.state IN ('verifying','captured','failed')) OR",
            "        (OLD.state = 'authorized' AND NEW.state = 'captured') OR",
            "        (OLD.state = 'captured' AND NEW.state = 'refund_pending') OR",
            "        (OLD.state = 'refund_pending' AND NEW.state IN ('refunded','partially_refunded')) OR",
            "        (OLD.state = 'partially_refunded' AND NEW.state IN ('refund_pending','refunded'))",
            '    ) THEN',
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent state transition is invalid.';",
            '    END IF;',
            '',
            "    IF NEW.state = 'captured' AND NEW.captured_at IS NULL THEN",
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Captured payment intent requires captured_at.';",
            '    END IF;',
            '    IF OLD.captured_at IS NOT NULL AND NOT (NEW.captured_at <=> OLD.captured_at) THEN',
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent capture timestamp is immutable.';",
            '    END IF;',
            'END',
        ]));

        DB::unprepared(implode("\n", [
            'CREATE TRIGGER payment_intents_delete_guard',
            'BEFORE DELETE ON payment_intents',
            'FOR EACH ROW',
            'BEGIN',
            "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intents are non-deletable.';",
            'END',
        ]));
    }

    private function createImmutableGuards(): void
    {
        foreach ([
            'payment_intent_state_histories' => 'Payment intent histories',
            'payment_attempts' => 'Payment attempts',
            'payment_provider_events' => 'Payment provider events',
            'payment_provider_transactions' => 'Payment provider transactions',
        ] as $table => $label) {
            DB::unprepared(implode("\n", [
                "CREATE TRIGGER {$table}_update_guard",
                "BEFORE UPDATE ON {$table}",
                'FOR EACH ROW',
                'BEGIN',
                "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$label} are immutable.';",
                'END',
            ]));
            DB::unprepared(implode("\n", [
                "CREATE TRIGGER {$table}_delete_guard",
                "BEFORE DELETE ON {$table}",
                'FOR EACH ROW',
                'BEGIN',
                "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$label} are non-deletable.';",
                'END',
            ]));
        }
    }

    private function createSettlementGuard(): void
    {
        DB::unprepared(implode("\n", [
            'CREATE TRIGGER wallet_top_up_settlements_insert_guard',
            'BEFORE INSERT ON wallet_top_up_settlements',
            'FOR EACH ROW',
            'BEGIN',
            '    DECLARE valid_intent_count INT DEFAULT 0;',
            '    DECLARE valid_provider_count INT DEFAULT 0;',
            '    DECLARE valid_ledger_count INT DEFAULT 0;',
            '',
            '    SELECT COUNT(*) INTO valid_intent_count',
            '    FROM payment_intents i',
            '    INNER JOIN ledger_accounts wallet ON wallet.id = i.wallet_account_id',
            '    WHERE i.id = NEW.payment_intent_id',
            "      AND i.purpose = 'wallet_top_up'",
            "      AND i.state = 'captured'",
            '      AND i.captured_at IS NOT NULL',
            '      AND i.wallet_account_id = NEW.wallet_account_id',
            '      AND i.amount_irr = NEW.amount_irr',
            "      AND i.currency = 'IRR'",
            '      AND wallet.owner_user_id = i.user_id',
            "      AND wallet.wallet_bucket = 'cash'",
            "      AND wallet.account_class = 'liability'",
            "      AND wallet.currency = 'IRR'",
            '      AND wallet.is_active = 1;',
            '',
            '    IF valid_intent_count <> 1 THEN',
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet top-up settlement does not match one captured cash-wallet intent.';",
            '    END IF;',
            '',
            '    SELECT COUNT(*) INTO valid_provider_count',
            '    FROM payment_provider_transactions pt',
            '    INNER JOIN payment_intents i ON i.id = pt.payment_intent_id',
            '    WHERE pt.id = NEW.provider_transaction_row_id',
            '      AND pt.payment_intent_id = NEW.payment_intent_id',
            '      AND pt.provider_code = i.provider_code',
            '      AND pt.amount_irr = NEW.amount_irr',
            "      AND pt.currency = 'IRR'",
            "      AND pt.transaction_status = 'settled'",
            '      AND pt.settled_at IS NOT NULL;',
            '',
            '    IF valid_provider_count <> 1 THEN',
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet top-up settlement requires matching authoritative settled provider transaction evidence.';",
            '    END IF;',
            '',
            '    SELECT COUNT(*) INTO valid_ledger_count',
            '    FROM ledger_transactions t',
            '    INNER JOIN payment_intents i ON i.id = NEW.payment_intent_id',
            '    WHERE t.id = NEW.ledger_transaction_id',
            "      AND t.transaction_type = 'wallet_external_top_up'",
            "      AND t.source_type = 'payment_intent'",
            '      AND t.source_id = i.public_id',
            '      AND t.expected_total_irr = NEW.amount_irr',
            '      AND t.posted_debit_irr = NEW.amount_irr',
            '      AND t.posted_credit_irr = NEW.amount_irr',
            '      AND t.entry_count = 2',
            '      AND t.finalized_at IS NOT NULL',
            '      AND EXISTS (',
            '          SELECT 1 FROM ledger_entries wallet_entry',
            '          WHERE wallet_entry.ledger_transaction_id = t.id',
            '            AND wallet_entry.ledger_account_id = NEW.wallet_account_id',
            "            AND wallet_entry.direction = 'credit'",
            '            AND wallet_entry.amount_irr = NEW.amount_irr',
            '      )',
            '      AND EXISTS (',
            '          SELECT 1',
            '          FROM ledger_entries clearing_entry',
            '          INNER JOIN ledger_accounts clearing ON clearing.id = clearing_entry.ledger_account_id',
            '          WHERE clearing_entry.ledger_transaction_id = t.id',
            "            AND clearing.code = 'system.payment.wallet-topup.clearing'",
            "            AND clearing_entry.direction = 'debit'",
            '            AND clearing_entry.amount_irr = NEW.amount_irr',
            '      );',
            '',
            '    IF valid_ledger_count <> 1 THEN',
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet top-up settlement requires the exact finalized cash-wallet ledger effect.';",
            '    END IF;',
            'END',
        ]));

        DB::unprepared(implode("\n", [
            'CREATE TRIGGER wallet_top_up_settlements_update_guard',
            'BEFORE UPDATE ON wallet_top_up_settlements',
            'FOR EACH ROW',
            'BEGIN',
            "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet top-up settlements are immutable.';",
            'END',
        ]));
        DB::unprepared(implode("\n", [
            'CREATE TRIGGER wallet_top_up_settlements_delete_guard',
            'BEFORE DELETE ON wallet_top_up_settlements',
            'FOR EACH ROW',
            'BEGIN',
            "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet top-up settlements are non-deletable.';",
            'END',
        ]));
    }
};
