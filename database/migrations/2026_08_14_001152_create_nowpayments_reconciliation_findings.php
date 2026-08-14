<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement IPG-002 DAT-003 DAT-004 QUA-004 */
    public function up(): void
    {
        Schema::create('nowpayments_reconciliation_findings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('nowpayments_payment_authority_id')->constrained('nowpayments_payment_authorities')->restrictOnDelete();
            $table->string('finding_key', 191)->unique();
            $table->string('code', 64);
            $table->string('severity', 16);
            $table->string('provider_status', 32)->nullable();
            $table->char('evidence_hash', 64);
            $table->dateTime('detected_at', 6);
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->index(['severity', 'detected_at'], 'nowpayments_finding_severity_idx');
        });
        DB::statement("ALTER TABLE nowpayments_reconciliation_findings ADD CONSTRAINT nowpayments_finding_severity_chk CHECK (`severity` IN ('medium','high','critical'))");
        DB::statement('ALTER TABLE nowpayments_reconciliation_findings ADD CONSTRAINT nowpayments_finding_hash_chk CHECK (CHAR_LENGTH(`evidence_hash`) = 64)');

        DB::unprepared(<<<'SQL'
CREATE TRIGGER nowpayments_finding_update_guard
BEFORE UPDATE ON nowpayments_reconciliation_findings
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments reconciliation findings are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER nowpayments_finding_delete_guard
BEFORE DELETE ON nowpayments_reconciliation_findings
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments reconciliation findings are non-deletable.';
END
SQL);
    }

    public function down(): void
    {
        if (DB::table('nowpayments_reconciliation_findings')->exists()) {
            throw new RuntimeException('Cannot roll back NOWPayments findings after reconciliation evidence exists.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS nowpayments_finding_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS nowpayments_finding_update_guard');
        Schema::dropIfExists('nowpayments_reconciliation_findings');
    }
};
