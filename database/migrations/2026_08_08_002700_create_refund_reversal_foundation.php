<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement WAL-004 DAT-002 DAT-003 DAT-004 ACL-001 ACL-002 SEC-002 QUA-001 */
    public function up(): void
    {
        Schema::create('ledger_refundability', function (Blueprint $table): void {
            $table->foreignId('ledger_transaction_id')->primary()->constrained('ledger_transactions')->restrictOnDelete();
            $table->bigInteger('refundable_total_irr');
            $table->string('default_destination', 32);
            $table->dateTime('created_at', 6);
        });

        Schema::create('refunds', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('refund_key', 128)->unique();
            $table->char('payload_hash', 64);
            $table->foreignId('source_ledger_transaction_id')->constrained('ledger_transactions')->restrictOnDelete();
            $table->bigInteger('source_refundable_total_irr');
            $table->string('default_destination', 32);
            $table->string('destination', 32);
            $table->boolean('destination_overridden')->default(false);
            $table->bigInteger('amount_irr');
            $table->foreignId('requested_by_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->string('request_fingerprint', 128);
            $table->string('manual_external_reference', 191)->nullable();
            $table->string('manual_external_evidence_reference', 191)->nullable();
            $table->foreignId('ledger_transaction_id')->unique()->constrained('ledger_transactions')->restrictOnDelete();
            $table->dateTime('created_at', 6);
            $table->index(['source_ledger_transaction_id', 'created_at'], 'refund_source_created_idx');
            $table->index(['requested_by_administrator_id', 'created_at'], 'refund_actor_created_idx');
        });

        Schema::create('refund_allocations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('refund_id')->constrained('refunds')->restrictOnDelete();
            $table->foreignId('source_ledger_entry_id')->constrained('ledger_entries')->restrictOnDelete();
            $table->bigInteger('amount_irr');
            $table->dateTime('created_at', 6);
            $table->unique(['refund_id', 'source_ledger_entry_id'], 'refund_allocation_refund_entry_unique');
            $table->index(['source_ledger_entry_id', 'refund_id'], 'refund_allocation_source_entry_idx');
        });

        DB::statement('ALTER TABLE ledger_refundability ADD CONSTRAINT ledger_refundability_amount_chk CHECK (`refundable_total_irr` > 0)');
        DB::statement("ALTER TABLE ledger_refundability ADD CONSTRAINT ledger_refundability_destination_chk CHECK (`default_destination` IN ('wallet', 'manual_external'))");

        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refund_amount_chk CHECK (`amount_irr` > 0 AND `source_refundable_total_irr` > 0 AND `amount_irr` <= `source_refundable_total_irr`)');
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refund_hash_chk CHECK (CHAR_LENGTH(`payload_hash`) = 64)');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refund_destination_chk CHECK (`default_destination` IN ('wallet', 'manual_external') AND `destination` IN ('wallet', 'manual_external'))");
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refund_override_chk CHECK ((`destination_overridden` = 0 AND `destination` = `default_destination`) OR (`destination_overridden` = 1 AND `destination` <> `default_destination`))');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refund_manual_evidence_chk CHECK ((`destination` = 'wallet' AND `manual_external_reference` IS NULL AND `manual_external_evidence_reference` IS NULL) OR (`destination` = 'manual_external' AND `manual_external_reference` IS NOT NULL AND CHAR_LENGTH(TRIM(`manual_external_reference`)) >= 3 AND `manual_external_evidence_reference` IS NOT NULL AND CHAR_LENGTH(TRIM(`manual_external_evidence_reference`)) >= 3))");
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refund_reason_chk CHECK (CHAR_LENGTH(TRIM(`reason_code`)) >= 1 AND CHAR_LENGTH(TRIM(`reason`)) >= 1)');
        DB::statement('ALTER TABLE refund_allocations ADD CONSTRAINT refund_allocation_amount_chk CHECK (`amount_irr` > 0)');

        $this->createRefundabilityGuards();
        $this->createRefundGuards();
        $this->createAllocationGuards();
    }

    public function down(): void
    {
        foreach ([
            'DROP TRIGGER IF EXISTS refund_allocations_delete_guard',
            'DROP TRIGGER IF EXISTS refund_allocations_update_guard',
            'DROP TRIGGER IF EXISTS refund_allocations_insert_guard',
            'DROP TRIGGER IF EXISTS refunds_delete_guard',
            'DROP TRIGGER IF EXISTS refunds_update_guard',
            'DROP TRIGGER IF EXISTS ledger_refundability_delete_guard',
            'DROP TRIGGER IF EXISTS ledger_refundability_update_guard',
            'DROP TRIGGER IF EXISTS ledger_refundability_insert_guard',
        ] as $statement) {
            DB::unprepared($statement);
        }

        Schema::dropIfExists('refund_allocations');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('ledger_refundability');
    }

    private function createRefundabilityGuards(): void
    {
        DB::unprepared(implode("\n", [
            'CREATE TRIGGER ledger_refundability_insert_guard',
            'BEFORE INSERT ON ledger_refundability',
            'FOR EACH ROW',
            'BEGIN',
            '    DECLARE eligible_parent_count INT DEFAULT 0;',
            '',
            '    SELECT COUNT(*) INTO eligible_parent_count',
            '    FROM ledger_transactions',
            '    WHERE id = NEW.ledger_transaction_id',
            '      AND finalized_at IS NOT NULL',
            '      AND expected_total_irr >= NEW.refundable_total_irr;',
            '',
            '    IF eligible_parent_count <> 1 THEN',
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refundability requires a finalized ledger transaction and a bounded refundable total.';",
            '    END IF;',
            'END',
        ]));

        DB::unprepared(implode("\n", [
            'CREATE TRIGGER ledger_refundability_update_guard',
            'BEFORE UPDATE ON ledger_refundability',
            'FOR EACH ROW',
            'BEGIN',
            "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger refundability is immutable.';",
            'END',
        ]));

        DB::unprepared(implode("\n", [
            'CREATE TRIGGER ledger_refundability_delete_guard',
            'BEFORE DELETE ON ledger_refundability',
            'FOR EACH ROW',
            'BEGIN',
            "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger refundability is non-deletable.';",
            'END',
        ]));
    }

    private function createRefundGuards(): void
    {
        DB::unprepared(implode("\n", [
            'CREATE TRIGGER refunds_update_guard',
            'BEFORE UPDATE ON refunds',
            'FOR EACH ROW',
            'BEGIN',
            "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Accepted refunds are immutable.';",
            'END',
        ]));

        DB::unprepared(implode("\n", [
            'CREATE TRIGGER refunds_delete_guard',
            'BEFORE DELETE ON refunds',
            'FOR EACH ROW',
            'BEGIN',
            "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Accepted refunds are non-deletable.';",
            'END',
        ]));
    }

    private function createAllocationGuards(): void
    {
        DB::unprepared(implode("\n", [
            'CREATE TRIGGER refund_allocations_insert_guard',
            'BEFORE INSERT ON refund_allocations',
            'FOR EACH ROW',
            'BEGIN',
            '    DECLARE valid_source_count INT DEFAULT 0;',
            '',
            '    SELECT COUNT(*) INTO valid_source_count',
            '    FROM refunds r',
            '    INNER JOIN ledger_entries e',
            '        ON e.ledger_transaction_id = r.source_ledger_transaction_id',
            '       AND e.id = NEW.source_ledger_entry_id',
            '    WHERE r.id = NEW.refund_id',
            '      AND e.amount_irr >= NEW.amount_irr;',
            '',
            '    IF valid_source_count <> 1 THEN',
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund allocation must reference a bounded entry from its source transaction.';",
            '    END IF;',
            'END',
        ]));

        DB::unprepared(implode("\n", [
            'CREATE TRIGGER refund_allocations_update_guard',
            'BEFORE UPDATE ON refund_allocations',
            'FOR EACH ROW',
            'BEGIN',
            "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund allocations are immutable.';",
            'END',
        ]));

        DB::unprepared(implode("\n", [
            'CREATE TRIGGER refund_allocations_delete_guard',
            'BEFORE DELETE ON refund_allocations',
            'FOR EACH ROW',
            'BEGIN',
            "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund allocations are non-deletable.';",
            'END',
        ]));
    }
};
