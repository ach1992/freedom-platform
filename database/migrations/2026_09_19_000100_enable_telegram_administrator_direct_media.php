<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement COM-001 DAT-002 DAT-003 DAT-004 SEC-002 SEC-003 SEC-009 QUA-004 */
    public function up(): void
    {
        Schema::table('telegram_administrator_direct_messages', function (Blueprint $table): void {
            $table->ulid('media_public_id')->nullable()->after('content_length');
            $table->string('media_detected_mime', 64)->nullable()->after('media_public_id');
            $table->unsignedInteger('media_byte_size')->nullable()->after('media_detected_mime');
            $table->char('media_content_sha256', 64)->nullable()->after('media_byte_size');
            $table->unique('media_public_id', 'tg_admin_direct_message_media_unique');
        });

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE telegram_administrator_direct_messages DROP CONSTRAINT tg_admin_direct_message_type_chk');
        DB::statement('ALTER TABLE telegram_administrator_direct_messages DROP CONSTRAINT tg_admin_direct_message_length_chk');
        DB::statement(<<<'SQL'
ALTER TABLE telegram_administrator_direct_messages
    ADD CONSTRAINT tg_admin_direct_message_type_chk CHECK (
        content_type IN ('text','photo','video','document')
    ),
    ADD CONSTRAINT tg_admin_direct_message_length_chk CHECK (
        (content_type = 'text' AND content_length BETWEEN 1 AND 3500)
        OR (content_type IN ('photo','video','document') AND content_length BETWEEN 0 AND 1024)
    ),
    ADD CONSTRAINT tg_admin_direct_message_media_shape_chk CHECK (
        (content_type = 'text'
            AND media_public_id IS NULL
            AND media_detected_mime IS NULL
            AND media_byte_size IS NULL
            AND media_content_sha256 IS NULL)
        OR
        (content_type IN ('photo','video','document')
            AND media_public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
            AND BINARY media_public_id = BINARY UPPER(media_public_id)
            AND media_byte_size BETWEEN 1 AND 20000000
            AND media_content_sha256 REGEXP '^[0-9a-f]{64}$'
            AND (
                (content_type = 'photo'
                    AND media_byte_size <= 10000000
                    AND media_detected_mime IN ('image/jpeg','image/png','image/webp'))
                OR (content_type = 'video' AND media_detected_mime = 'video/mp4')
                OR (content_type = 'document' AND media_detected_mime IN (
                    'image/jpeg','image/png','image/webp','video/mp4','application/pdf','text/plain'
                ))
            ))
    )
SQL);

        DB::statement('ALTER TABLE telegram_private_media DROP CONSTRAINT telegram_private_media_payload_chk');
        DB::statement(<<<'SQL'
ALTER TABLE telegram_private_media
ADD CONSTRAINT telegram_private_media_payload_chk CHECK (
    (state = 'pending'
        AND detected_mime IS NULL
        AND byte_size IS NULL
        AND content_sha256 IS NULL
        AND association_type IS NULL
        AND association_public_id IS NULL
        AND rejection_code IS NULL)
    OR
    (state = 'stored'
        AND detected_mime IN ('image/jpeg','image/png','image/webp','video/mp4','application/pdf','text/plain')
        AND byte_size BETWEEN 1 AND 20000000
        AND content_sha256 REGEXP '^[0-9a-f]{64}$'
        AND association_type IS NULL
        AND association_public_id IS NULL
        AND rejection_code IS NULL)
    OR
    (state = 'associated'
        AND detected_mime IN ('image/jpeg','image/png','image/webp','video/mp4','application/pdf','text/plain')
        AND byte_size BETWEEN 1 AND 20000000
        AND content_sha256 REGEXP '^[0-9a-f]{64}$'
        AND association_public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
        AND BINARY association_public_id = BINARY UPPER(association_public_id)
        AND (
            (association_type = 'c2c_manual_submission'
                AND detected_mime IN ('image/jpeg','image/png','image/webp'))
            OR association_type = 'support_ticket_attachment'
            OR association_type = 'administrator_direct_message'
        )
        AND rejection_code IS NULL)
    OR
    (state IN ('rejected','discarded')
        AND detected_mime IS NULL
        AND byte_size IS NULL
        AND content_sha256 IS NULL
        AND association_type IS NULL
        AND association_public_id IS NULL
        AND rejection_code REGEXP '^[a-z][a-z0-9_]{2,63}$')
)
SQL);
    }

    public function down(): void
    {
        if (DB::table('telegram_administrator_direct_messages')
            ->whereIn('content_type', ['photo', 'video', 'document'])
            ->exists()
            || DB::table('telegram_private_media')
                ->where('association_type', 'administrator_direct_message')
                ->exists()) {
            throw new RuntimeException(
                'Administrator direct-message media history must be retained; rollback requires no media records.',
            );
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE telegram_administrator_direct_messages DROP CONSTRAINT tg_admin_direct_message_media_shape_chk');
            DB::statement('ALTER TABLE telegram_administrator_direct_messages DROP CONSTRAINT tg_admin_direct_message_length_chk');
            DB::statement('ALTER TABLE telegram_administrator_direct_messages DROP CONSTRAINT tg_admin_direct_message_type_chk');
            DB::statement(<<<'SQL'
ALTER TABLE telegram_administrator_direct_messages
    ADD CONSTRAINT tg_admin_direct_message_type_chk CHECK (content_type = 'text'),
    ADD CONSTRAINT tg_admin_direct_message_length_chk CHECK (content_length BETWEEN 1 AND 3500)
SQL);

            DB::statement('ALTER TABLE telegram_private_media DROP CONSTRAINT telegram_private_media_payload_chk');
            DB::statement(<<<'SQL'
ALTER TABLE telegram_private_media
ADD CONSTRAINT telegram_private_media_payload_chk CHECK (
    (state = 'pending'
        AND detected_mime IS NULL
        AND byte_size IS NULL
        AND content_sha256 IS NULL
        AND association_type IS NULL
        AND association_public_id IS NULL
        AND rejection_code IS NULL)
    OR
    (state = 'stored'
        AND detected_mime IN ('image/jpeg','image/png','image/webp','video/mp4','application/pdf','text/plain')
        AND byte_size BETWEEN 1 AND 20000000
        AND content_sha256 REGEXP '^[0-9a-f]{64}$'
        AND association_type IS NULL
        AND association_public_id IS NULL
        AND rejection_code IS NULL)
    OR
    (state = 'associated'
        AND detected_mime IN ('image/jpeg','image/png','image/webp','video/mp4','application/pdf','text/plain')
        AND byte_size BETWEEN 1 AND 20000000
        AND content_sha256 REGEXP '^[0-9a-f]{64}$'
        AND association_public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
        AND BINARY association_public_id = BINARY UPPER(association_public_id)
        AND (
            (association_type = 'c2c_manual_submission'
                AND detected_mime IN ('image/jpeg','image/png','image/webp'))
            OR association_type = 'support_ticket_attachment'
        )
        AND rejection_code IS NULL)
    OR
    (state IN ('rejected','discarded')
        AND detected_mime IS NULL
        AND byte_size IS NULL
        AND content_sha256 IS NULL
        AND association_type IS NULL
        AND association_public_id IS NULL
        AND rejection_code REGEXP '^[a-z][a-z0-9_]{2,63}$')
)
SQL);
        }

        Schema::table('telegram_administrator_direct_messages', function (Blueprint $table): void {
            $table->dropUnique('tg_admin_direct_message_media_unique');
            $table->dropColumn([
                'media_public_id',
                'media_detected_mime',
                'media_byte_size',
                'media_content_sha256',
            ]);
        });
    }
};
