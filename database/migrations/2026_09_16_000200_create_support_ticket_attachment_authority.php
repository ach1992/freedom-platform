<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    /** @requirement SUP-001 SUP-002 DAT-003 DAT-004 SEC-002 SEC-003 QUA-004 */
    public function up(): void
    {
        if (! Schema::hasTable('support_ticket_attachments')) {
            Schema::create('support_ticket_attachments', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->ulid('public_id')->unique();
                $table->unsignedBigInteger('ticket_id');
                $table->unsignedBigInteger('actor_user_id');
                $table->string('kind', 16);
                $table->string('detected_mime', 64);
                $table->unsignedBigInteger('byte_size');
                $table->char('content_sha256', 64);
                $table->string('private_media_reference', 191)->unique();
                $table->string('idempotency_key', 128);
                $table->boolean('customer_visible');
                $table->dateTime('created_at', 6);

                $table->foreign('ticket_id', 'support_ticket_attachment_ticket_fk')
                    ->references('id')->on('support_tickets')->restrictOnDelete();
                $table->foreign('actor_user_id', 'support_ticket_attachment_actor_fk')
                    ->references('id')->on('users')->restrictOnDelete();
                $table->unique(['ticket_id', 'idempotency_key'], 'support_ticket_attachment_idempotency_unique');
                $table->index(['ticket_id', 'created_at'], 'support_ticket_attachment_history_idx');
            });
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(<<<'SQL'
ALTER TABLE support_ticket_attachments
ADD CONSTRAINT support_ticket_attachment_identity_chk CHECK (
    `public_id` REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
    AND BINARY `public_id` = BINARY UPPER(`public_id`)
    AND CHAR_LENGTH(`idempotency_key`) BETWEEN 1 AND 128
    AND `idempotency_key` NOT REGEXP '[[:cntrl:]]'
    AND CHAR_LENGTH(`private_media_reference`) BETWEEN 1 AND 191
    AND `private_media_reference` NOT REGEXP '[[:cntrl:]]'
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE support_ticket_attachments
ADD CONSTRAINT support_ticket_attachment_payload_chk CHECK (
    (`kind` = 'image' AND `detected_mime` IN ('image/jpeg','image/png','image/webp'))
    OR (`kind` = 'video' AND `detected_mime` = 'video/mp4')
    OR (`kind` = 'file' AND `detected_mime` IN ('application/pdf','text/plain'))
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE support_ticket_attachments
ADD CONSTRAINT support_ticket_attachment_safety_chk CHECK (
    `byte_size` BETWEEN 1 AND 20000000
    AND `content_sha256` REGEXP '^[0-9a-f]{64}$'
    AND `customer_visible` = 1
)
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER support_ticket_attachments_update_guard
BEFORE UPDATE ON support_ticket_attachments
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Support ticket attachments are append-only.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER support_ticket_attachments_delete_guard
BEFORE DELETE ON support_ticket_attachments
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Support ticket attachments cannot be deleted.';
END
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('support_ticket_attachments')) {
            return;
        }
        if (DB::table('support_ticket_attachments')->exists()) {
            throw new RuntimeException('Cannot roll back Support ticket attachment authority after durable attachments exist.');
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS support_ticket_attachments_delete_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS support_ticket_attachments_update_guard');
        }
        Schema::drop('support_ticket_attachments');
    }
};
