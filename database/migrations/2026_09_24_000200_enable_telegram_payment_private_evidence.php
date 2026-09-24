<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement GFT-002 USDT-003 DAT-002 DAT-003 DAT-004 SEC-002 SEC-003 SEC-009 QUA-004 */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

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
            OR (association_type = 'gift_card_submission'
                AND detected_mime IN ('image/jpeg','image/png','image/webp'))
            OR (association_type = 'usdt_txid_submission'
                AND detected_mime IN ('image/jpeg','image/png','image/webp'))
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
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (DB::table('telegram_private_media')
            ->whereIn('association_type', ['gift_card_submission', 'usdt_txid_submission'])
            ->exists()) {
            throw new RuntimeException(
                'Payment private-media associations must be retained; rollback requires no Gift Card or USDT evidence records.',
            );
        }

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
};
