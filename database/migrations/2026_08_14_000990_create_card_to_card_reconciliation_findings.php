<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement C2C-005 DAT-002 DAT-003 DAT-004 QUA-004 */
    public function up(): void
    {
        Schema::create('c2c_reconciliation_findings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->char('finding_key', 64)->unique();
            $table->foreignId('c2c_bank_transaction_id')->nullable()->constrained('c2c_bank_transactions')->restrictOnDelete();
            $table->foreignId('c2c_transaction_match_id')->nullable()->constrained('c2c_transaction_matches')->restrictOnDelete();
            $table->string('provider_code', 64)->nullable();
            $table->string('finding_type', 64);
            $table->string('severity', 16);
            $table->char('evidence_hash', 64);
            $table->string('correlation_id', 64);
            $table->dateTime('detected_at', 6);
            $table->dateTime('created_at', 6);
            $table->index(['finding_type', 'detected_at'], 'c2c_reconciliation_type_detected_idx');
            $table->index(['provider_code', 'detected_at'], 'c2c_reconciliation_provider_detected_idx');
        });

        DB::statement("ALTER TABLE c2c_reconciliation_findings ADD CONSTRAINT c2c_reconciliation_type_chk CHECK (`finding_type` IN ('unlinked_settled','captured_transaction_reversed','captured_transaction_missing','provider_cursor_failure','provider_health_degraded','amount_or_destination_mismatch'))");
        DB::statement("ALTER TABLE c2c_reconciliation_findings ADD CONSTRAINT c2c_reconciliation_severity_chk CHECK (`severity` IN ('warning','high','critical'))");
        DB::statement('ALTER TABLE c2c_reconciliation_findings ADD CONSTRAINT c2c_reconciliation_hash_chk CHECK (CHAR_LENGTH(`finding_key`) = 64 AND CHAR_LENGTH(`evidence_hash`) = 64)');

        DB::unprepared(<<<'SQL'
CREATE TRIGGER c2c_reconciliation_findings_update_guard
BEFORE UPDATE ON c2c_reconciliation_findings
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C reconciliation findings are immutable.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER c2c_reconciliation_findings_delete_guard
BEFORE DELETE ON c2c_reconciliation_findings
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C reconciliation findings are non-deletable.';
END
SQL);
    }

    public function down(): void
    {
        if (DB::table('c2c_reconciliation_findings')->exists()) {
            throw new RuntimeException('Cannot roll back C2C reconciliation findings after evidence exists.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_reconciliation_findings_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_reconciliation_findings_update_guard');
        Schema::dropIfExists('c2c_reconciliation_findings');
    }
};
