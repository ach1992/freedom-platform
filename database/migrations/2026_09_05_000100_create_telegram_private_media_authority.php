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
            $table->string('association_type', 32)->nullable();
            $table->char('association_public_id', 26)->nullable();
            $table->string('rejection_code', 64)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['bot_id', 'update_id'], 'telegram_private_media_update_unique');
        });

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }
        DB::statement("ALTER TABLE telegram_private_media ADD CONSTRAINT telegram_private_media_source_chk CHECK (`source_kind` IN ('photo','document'))");
        DB::statement("ALTER TABLE telegram_private_media ADD CONSTRAINT telegram_private_media_identity_chk CHECK (`public_id` REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$' AND BINARY `public_id` = BINARY UPPER(`public_id`) AND `bot_id` REGEXP '^[1-9][0-9]{5,19}$' AND (`reported_file_size` IS NULL OR `reported_file_size` > 0) AND `storage_path` REGEXP '^receipts/[0-9a-f]{2}/[0-9A-HJKMNP-TV-Z]{26}\\.media$' AND CHAR_LENGTH(`encrypted_file_id`) BETWEEN 1 AND 16384 AND CHAR_LENGTH(`encrypted_file_unique_id`) BETWEEN 1 AND 16384)");
        DB::statement("ALTER TABLE telegram_private_media ADD CONSTRAINT telegram_private_media_state_chk CHECK (`state` IN ('pending','stored','associated','rejected','discarded'))");
        DB::statement(<<<'SQL'
ALTER TABLE telegram_private_media
ADD CONSTRAINT telegram_private_media_payload_chk CHECK (
    (`state` = 'pending'
        AND `detected_mime` IS NULL
        AND `byte_size` IS NULL
        AND `content_sha256` IS NULL
        AND `association_type` IS NULL
        AND `association_public_id` IS NULL
        AND `rejection_code` IS NULL)
    OR
    (`state` = 'stored'
        AND `detected_mime` IN ('image/jpeg','image/png','image/webp')
        AND `byte_size` > 0
        AND `content_sha256` REGEXP '^[0-9a-f]{64}$'
        AND `association_type` IS NULL
        AND `association_public_id` IS NULL
        AND `rejection_code` IS NULL)
    OR
    (`state` = 'associated'
        AND `detected_mime` IN ('image/jpeg','image/png','image/webp')
        AND `byte_size` > 0
        AND `content_sha256` REGEXP '^[0-9a-f]{64}$'
        AND `association_type` = 'c2c_manual_submission'
        AND `association_public_id` REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
        AND BINARY `association_public_id` = BINARY UPPER(`association_public_id`)
        AND `rejection_code` IS NULL)
    OR
    (`state` IN ('rejected','discarded')
        AND `detected_mime` IS NULL
        AND `byte_size` IS NULL
        AND `content_sha256` IS NULL
        AND `association_type` IS NULL
        AND `association_public_id` IS NULL
        AND `rejection_code` REGEXP '^[a-z][a-z0-9_]{2,63}$')
)
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('telegram_private_media')) {
            return;
        }
        if (DB::table('telegram_private_media')->exists()) {
            throw new RuntimeException('Cannot roll back Telegram private-media authority after records exist.');
        }

        Schema::drop('telegram_private_media');
    }
};
