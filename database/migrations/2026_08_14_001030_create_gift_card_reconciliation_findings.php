<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_card_reconciliation_findings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('gift_card_submission_id');
            $table->foreign('gift_card_submission_id', 'gift_card_recon_submission_fk')
                ->references('id')
                ->on('gift_card_submissions')
                ->restrictOnDelete();
            $table->string('finding_key', 128)->unique();
            $table->string('finding_type', 64);
            $table->string('severity', 16);
            $table->string('provider_code', 64)->nullable();
            $table->string('provider_event_id', 191)->nullable();
            $table->string('provider_transaction_id', 191)->nullable();
            $table->char('evidence_hash', 64)->nullable();
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->index(['gift_card_submission_id', 'created_at'], 'gift_card_recon_submission_idx');
        });
        DB::statement("ALTER TABLE gift_card_reconciliation_findings ADD CONSTRAINT gift_card_recon_severity_chk CHECK (`severity` IN ('info','warning','high','critical'))");
        DB::statement('ALTER TABLE gift_card_reconciliation_findings ADD CONSTRAINT gift_card_recon_hash_chk CHECK (`evidence_hash` IS NULL OR CHAR_LENGTH(`evidence_hash`) = 64)');

        DB::unprepared(<<<'SQL'
CREATE TRIGGER gift_card_reconciliation_findings_update_guard
BEFORE UPDATE ON gift_card_reconciliation_findings
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card reconciliation findings are immutable.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER gift_card_reconciliation_findings_delete_guard
BEFORE DELETE ON gift_card_reconciliation_findings
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card reconciliation findings are non-deletable.';
END
SQL);
    }

    public function down(): void
    {
        if (DB::table('gift_card_reconciliation_findings')->exists()) {
            throw new RuntimeException('Cannot roll back gift-card reconciliation authority while findings exist.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_reconciliation_findings_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_reconciliation_findings_update_guard');
        Schema::dropIfExists('gift_card_reconciliation_findings');
    }
};
