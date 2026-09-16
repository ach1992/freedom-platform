<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use RuntimeException;

return new class extends Migration
{
    /** @requirement SUP-001 SUP-002 C2C-002 DAT-002 DAT-003 DAT-004 SEC-002 SEC-003 SEC-009 QUA-004 */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $this->dropCheckIfExists('telegram_private_media_source_chk');
        $this->dropCheckIfExists('telegram_private_media_payload_chk');
        DB::statement("ALTER TABLE telegram_private_media ADD CONSTRAINT telegram_private_media_source_chk CHECK (`source_kind` IN ('photo','video','document'))");
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
        AND `detected_mime` IN ('image/jpeg','image/png','image/webp','video/mp4','application/pdf','text/plain')
        AND `byte_size` BETWEEN 1 AND 20000000
        AND `content_sha256` REGEXP '^[0-9a-f]{64}$'
        AND `association_type` IS NULL
        AND `association_public_id` IS NULL
        AND `rejection_code` IS NULL)
    OR
    (`state` = 'associated'
        AND `detected_mime` IN ('image/jpeg','image/png','image/webp','video/mp4','application/pdf','text/plain')
        AND `byte_size` BETWEEN 1 AND 20000000
        AND `content_sha256` REGEXP '^[0-9a-f]{64}$'
        AND `association_public_id` REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
        AND BINARY `association_public_id` = BINARY UPPER(`association_public_id`)
        AND (
            (`association_type` = 'c2c_manual_submission'
                AND `detected_mime` IN ('image/jpeg','image/png','image/webp'))
            OR
            (`association_type` = 'support_ticket_attachment')
        )
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
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $generalizedRowsExist = DB::table('telegram_private_media')
            ->where(function ($query): void {
                $query->where('source_kind', 'video')
                    ->orWhere(function ($nested): void {
                        $nested->whereNotNull('detected_mime')
                            ->whereNotIn('detected_mime', ['image/jpeg', 'image/png', 'image/webp']);
                    })
                    ->orWhere(function ($nested): void {
                        $nested->whereNotNull('association_type')
                            ->where('association_type', '<>', 'c2c_manual_submission');
                    });
            })
            ->exists();
        if ($generalizedRowsExist) {
            throw new RuntimeException('Cannot restore image-only Telegram private-media constraints while generalized media records exist.');
        }

        $this->dropCheckIfExists('telegram_private_media_source_chk');
        $this->dropCheckIfExists('telegram_private_media_payload_chk');
        DB::statement("ALTER TABLE telegram_private_media ADD CONSTRAINT telegram_private_media_source_chk CHECK (`source_kind` IN ('photo','document'))");
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

    private function dropCheckIfExists(string $constraintName): void
    {
        if (! $this->checkConstraintExists($constraintName)) {
            return;
        }

        DB::statement('ALTER TABLE telegram_private_media DROP CONSTRAINT '.$constraintName);
    }

    private function checkConstraintExists(string $constraintName): bool
    {
        $row = DB::selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND TABLE_NAME = 'telegram_private_media'
  AND CONSTRAINT_NAME = ?
  AND CONSTRAINT_TYPE = 'CHECK'
SQL, [$constraintName]);

        return (int) ($row->aggregate ?? 0) === 1;
    }
};
