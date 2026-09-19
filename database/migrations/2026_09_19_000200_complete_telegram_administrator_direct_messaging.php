<?php

declare(strict_types=1);

use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement COM-001 DAT-002 DAT-003 SEC-002 SEC-003 QUA-004 */
    public function up(): void
    {
        Schema::table('telegram_administrator_direct_messages', function (Blueprint $table): void {
            $table->longText('inline_keyboard_ciphertext')->nullable()->after('media_content_sha256');
            $table->char('inline_keyboard_hash', 64)->nullable()->after('inline_keyboard_ciphertext');
        });

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE telegram_administrator_direct_messages DROP CONSTRAINT tg_admin_direct_message_media_shape_chk');
        DB::statement('ALTER TABLE telegram_administrator_direct_messages DROP CONSTRAINT tg_admin_direct_message_length_chk');
        DB::statement('ALTER TABLE telegram_administrator_direct_messages DROP CONSTRAINT tg_admin_direct_message_type_chk');

        DB::statement(<<<'SQL'
ALTER TABLE telegram_administrator_direct_messages
    ADD CONSTRAINT tg_admin_direct_message_type_chk CHECK (
        content_type IN ('text','photo','video','document','forward','copy')
    ),
    ADD CONSTRAINT tg_admin_direct_message_length_chk CHECK (
        (content_type = 'text' AND content_length BETWEEN 1 AND 3500)
        OR (content_type = 'photo' AND content_length BETWEEN 0 AND 1024)
        OR (content_type IN ('video','document','forward','copy') AND content_length = 0)
    ),
    ADD CONSTRAINT tg_admin_direct_message_media_shape_chk CHECK (
        (content_type IN ('text','forward','copy')
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
    ),
    ADD CONSTRAINT tg_admin_direct_message_keyboard_chk CHECK (
        (inline_keyboard_ciphertext IS NULL AND inline_keyboard_hash IS NULL)
        OR
        (content_type <> 'forward'
            AND inline_keyboard_ciphertext IS NOT NULL
            AND OCTET_LENGTH(inline_keyboard_ciphertext) BETWEEN 1 AND 65536
            AND inline_keyboard_hash REGEXP '^[0-9a-f]{64}$')
    )
SQL);

        $this->upgradeInteractivePresentationTrigger();
    }

    public function down(): void
    {
        if (DB::table('telegram_administrator_direct_messages')
            ->whereIn('content_type', ['forward', 'copy'])
            ->exists()
            || DB::table('telegram_administrator_direct_messages')
                ->whereNotNull('inline_keyboard_ciphertext')
                ->exists()) {
            throw new RuntimeException(
                'Administrator direct-message source/button history must be retained; rollback requires no source/button records.',
            );
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->restoreInteractivePresentationTrigger();

            DB::statement('ALTER TABLE telegram_administrator_direct_messages DROP CONSTRAINT tg_admin_direct_message_keyboard_chk');
            DB::statement('ALTER TABLE telegram_administrator_direct_messages DROP CONSTRAINT tg_admin_direct_message_media_shape_chk');
            DB::statement('ALTER TABLE telegram_administrator_direct_messages DROP CONSTRAINT tg_admin_direct_message_length_chk');
            DB::statement('ALTER TABLE telegram_administrator_direct_messages DROP CONSTRAINT tg_admin_direct_message_type_chk');

            DB::statement(<<<'SQL'
ALTER TABLE telegram_administrator_direct_messages
    ADD CONSTRAINT tg_admin_direct_message_type_chk CHECK (
        content_type IN ('text','photo','video','document')
    ),
    ADD CONSTRAINT tg_admin_direct_message_length_chk CHECK (
        (content_type = 'text' AND content_length BETWEEN 1 AND 3500)
        OR (content_type = 'photo' AND content_length BETWEEN 0 AND 1024)
        OR (content_type IN ('video','document') AND content_length = 0)
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
        }

        Schema::table('telegram_administrator_direct_messages', function (Blueprint $table): void {
            $table->dropColumn([
                'inline_keyboard_hash',
                'inline_keyboard_ciphertext',
            ]);
        });
    }

    private function upgradeInteractivePresentationTrigger(): void
    {
        $connection = DB::connection();
        $surface = new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
        if ($surface->isReady($connection)) {
            return;
        }
        if (! $surface->isReady($connection, $surface::legacyV3InsertTriggerBody())) {
            throw new RuntimeException(
                'Telegram direct-message completion cannot upgrade an unrecognized interactive presentation authority.',
            );
        }

        $connection->unprepared(
            'CREATE OR REPLACE TRIGGER '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::INSERT_TRIGGER
            .' BEFORE INSERT ON '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE
            .' FOR EACH ROW '.$surface::insertTriggerBody(),
        );
        if (! $surface->isReady($connection)) {
            throw new RuntimeException(
                'Telegram direct-message completion did not upgrade interactive presentation authority exactly.',
            );
        }
    }

    private function restoreInteractivePresentationTrigger(): void
    {
        $connection = DB::connection();
        $surface = new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
        if ($surface->isReady($connection, $surface::legacyV3InsertTriggerBody())) {
            return;
        }
        if (! $surface->isReady($connection)) {
            throw new RuntimeException(
                'Telegram direct-message rollback cannot restore an unrecognized interactive presentation authority.',
            );
        }

        $connection->unprepared(
            'CREATE OR REPLACE TRIGGER '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::INSERT_TRIGGER
            .' BEFORE INSERT ON '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE
            .' FOR EACH ROW '.$surface::legacyV3InsertTriggerBody(),
        );
        if (! $surface->isReady($connection, $surface::legacyV3InsertTriggerBody())) {
            throw new RuntimeException(
                'Telegram direct-message rollback did not restore the exact v2/v3 interactive presentation authority.',
            );
        }
    }
};
