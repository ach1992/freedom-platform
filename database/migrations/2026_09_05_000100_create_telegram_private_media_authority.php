<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement C2C-002 DAT-002 DAT-003 DAT-004 SEC-002 SEC-003 SEC-009 QUA-004 */
    public function up(): void
    {
        Schema::create('telegram_private_media', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('bot_id', 20);
            $table->unsignedBigInteger('update_id');
            $table->foreignId('telegram_account_id')->constrained('telegram_accounts')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('source_kind', 16);
            $table->text('encrypted_file_id');
            $table->text('encrypted_file_unique_id');
            $table->unsignedBigInteger('reported_file_size')->nullable();
            $table->string('storage_path', 191)->unique();
            $table->string('state', 16);
            $table->string('detected_mime', 64)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->char('content_sha256', 64)->nullable();
            $table->string('rejection_code', 64)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['bot_id', 'update_id'], 'telegram_private_media_update_unique');
        });

        DB::statement("ALTER TABLE telegram_private_media ADD CONSTRAINT telegram_private_media_source_chk CHECK (`source_kind` IN ('photo','document'))");
        DB::statement("ALTER TABLE telegram_private_media ADD CONSTRAINT telegram_private_media_state_chk CHECK (`state` IN ('pending','stored','rejected','discarded'))");
        DB::statement(<<<'SQL'
ALTER TABLE telegram_private_media
ADD CONSTRAINT telegram_private_media_payload_chk CHECK (
    (`state` = 'pending'
        AND `detected_mime` IS NULL
        AND `byte_size` IS NULL
        AND `content_sha256` IS NULL
        AND `rejection_code` IS NULL)
    OR
    (`state` = 'stored'
        AND `detected_mime` IN ('image/jpeg','image/png','image/webp')
        AND `byte_size` > 0
        AND CHAR_LENGTH(`content_sha256`) = 64
        AND `rejection_code` IS NULL)
    OR
    (`state` IN ('rejected','discarded')
        AND `detected_mime` IS NULL
        AND `byte_size` IS NULL
        AND `content_sha256` IS NULL
        AND `rejection_code` IS NOT NULL)
)
SQL);
    }

    public function down(): void
    {
        if (DB::table('telegram_private_media')->exists()) {
            throw new RuntimeException('Cannot roll back Telegram private-media authority after records exist.');
        }

        Schema::dropIfExists('telegram_private_media');
    }
};
