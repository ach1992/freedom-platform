<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** @requirement COM-001 DAT-002 DAT-003 SEC-002 SEC-003 QUA-004 */
final class TelegramAdministratorDirectMessageCompletionMigrationTest extends TestCase
{
    use DatabaseTruncation;

    public function test_migration_restores_previous_interactive_trigger_on_safe_rollback_and_reapplies_current_authority(): void
    {
        $this->requireMariaDb();
        $migration = $this->completionMigration();
        $this->assertCurrentCompletionAuthority();

        try {
            $migration->down();
            $this->assertLegacyCompletionAuthority();

            $migration->up();
            $this->assertCurrentCompletionAuthority();
        } finally {
            $this->restoreCurrentCompletionAuthority($migration);
        }
    }

    public function test_up_converges_when_keyboard_columns_were_committed_before_constraint_transition(): void
    {
        $this->requireMariaDb();
        $migration = $this->completionMigration();

        try {
            $migration->down();
            $this->assertLegacyCompletionAuthority();
            $this->addKeyboardColumns();

            self::assertTrue(Schema::hasColumn(
                'telegram_administrator_direct_messages',
                'inline_keyboard_ciphertext',
            ));
            self::assertSame(
                [
                    'tg_admin_direct_message_length_chk',
                    'tg_admin_direct_message_media_shape_chk',
                    'tg_admin_direct_message_type_chk',
                ],
                $this->completionConstraintNames(),
            );

            $migration->up();
            $this->assertCurrentCompletionAuthority();
        } finally {
            $this->restoreCurrentCompletionAuthority($migration);
        }
    }

    public function test_up_converges_when_legacy_constraints_were_partially_removed(): void
    {
        $this->requireMariaDb();
        $migration = $this->completionMigration();

        try {
            $migration->down();
            $this->addKeyboardColumns();
            DB::statement(<<<'SQL'
ALTER TABLE telegram_administrator_direct_messages
    DROP CONSTRAINT tg_admin_direct_message_media_shape_chk,
    DROP CONSTRAINT tg_admin_direct_message_length_chk
SQL);

            self::assertSame(
                ['tg_admin_direct_message_type_chk'],
                $this->completionConstraintNames(),
            );

            $migration->up();
            $this->assertCurrentCompletionAuthority();
        } finally {
            $this->restoreCurrentCompletionAuthority($migration);
        }
    }

    public function test_up_converges_when_current_constraints_exist_before_trigger_upgrade(): void
    {
        $this->requireMariaDb();
        $migration = $this->completionMigration();

        try {
            $this->replaceInteractiveTriggerWithLegacyAuthority();
            $this->assertCurrentSchemaWithLegacyInteractiveAuthority();

            $migration->up();
            $this->assertCurrentCompletionAuthority();
        } finally {
            $this->restoreCurrentCompletionAuthority($migration);
        }
    }

    public function test_down_converges_from_partially_removed_current_constraints_and_is_reentrant(): void
    {
        $this->requireMariaDb();
        $migration = $this->completionMigration();

        try {
            $this->replaceInteractiveTriggerWithLegacyAuthority();
            DB::statement(<<<'SQL'
ALTER TABLE telegram_administrator_direct_messages
    DROP CONSTRAINT tg_admin_direct_message_keyboard_chk,
    DROP CONSTRAINT tg_admin_direct_message_media_shape_chk
SQL);

            self::assertSame(
                [
                    'tg_admin_direct_message_length_chk',
                    'tg_admin_direct_message_type_chk',
                ],
                $this->completionConstraintNames(),
            );

            $migration->down();
            $this->assertLegacyCompletionAuthority();

            $migration->down();
            $this->assertLegacyCompletionAuthority();

            $migration->up();
            $this->assertCurrentCompletionAuthority();
        } finally {
            $this->restoreCurrentCompletionAuthority($migration);
        }
    }

    private function requireMariaDb(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram administrator direct-message migration safety requires MariaDB/MySQL.');
        }
    }

    private function completionMigration(): Migration
    {
        return require database_path(
            'migrations/2026_09_19_000200_complete_telegram_administrator_direct_messaging.php',
        );
    }

    private function addKeyboardColumns(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE telegram_administrator_direct_messages
    ADD COLUMN inline_keyboard_ciphertext LONGTEXT NULL AFTER media_content_sha256,
    ADD COLUMN inline_keyboard_hash CHAR(64) NULL AFTER inline_keyboard_ciphertext
SQL);
    }

    private function replaceInteractiveTriggerWithLegacyAuthority(): void
    {
        DB::connection()->unprepared(
            'CREATE OR REPLACE TRIGGER '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::INSERT_TRIGGER
            .' BEFORE INSERT ON '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE
            .' FOR EACH ROW '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::legacyV3InsertTriggerBody(),
        );
    }

    private function assertCurrentCompletionAuthority(): void
    {
        $surface = new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
        self::assertTrue($surface->isReady(DB::connection()));
        self::assertTrue(Schema::hasColumn(
            'telegram_administrator_direct_messages',
            'inline_keyboard_ciphertext',
        ));
        self::assertTrue(Schema::hasColumn(
            'telegram_administrator_direct_messages',
            'inline_keyboard_hash',
        ));
        self::assertSame(
            [
                'tg_admin_direct_message_keyboard_chk',
                'tg_admin_direct_message_length_chk',
                'tg_admin_direct_message_media_shape_chk',
                'tg_admin_direct_message_type_chk',
            ],
            $this->completionConstraintNames(),
        );
    }

    private function assertLegacyCompletionAuthority(): void
    {
        $surface = new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
        self::assertFalse(Schema::hasColumn(
            'telegram_administrator_direct_messages',
            'inline_keyboard_ciphertext',
        ));
        self::assertFalse(Schema::hasColumn(
            'telegram_administrator_direct_messages',
            'inline_keyboard_hash',
        ));
        self::assertTrue($surface->isReady(
            DB::connection(),
            TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::legacyV3InsertTriggerBody(),
        ));
        self::assertFalse($surface->isReady(DB::connection()));
        self::assertSame(
            [
                'tg_admin_direct_message_length_chk',
                'tg_admin_direct_message_media_shape_chk',
                'tg_admin_direct_message_type_chk',
            ],
            $this->completionConstraintNames(),
        );
    }

    private function assertCurrentSchemaWithLegacyInteractiveAuthority(): void
    {
        $surface = new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
        self::assertTrue(Schema::hasColumn(
            'telegram_administrator_direct_messages',
            'inline_keyboard_ciphertext',
        ));
        self::assertTrue(Schema::hasColumn(
            'telegram_administrator_direct_messages',
            'inline_keyboard_hash',
        ));
        self::assertTrue($surface->isReady(
            DB::connection(),
            TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::legacyV3InsertTriggerBody(),
        ));
        self::assertFalse($surface->isReady(DB::connection()));
        self::assertSame(
            [
                'tg_admin_direct_message_keyboard_chk',
                'tg_admin_direct_message_length_chk',
                'tg_admin_direct_message_media_shape_chk',
                'tg_admin_direct_message_type_chk',
            ],
            $this->completionConstraintNames(),
        );
    }

    /** @return list<string> */
    private function completionConstraintNames(): array
    {
        $rows = DB::select(<<<'SQL'
SELECT CONSTRAINT_NAME AS constraint_name
FROM information_schema.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'telegram_administrator_direct_messages'
  AND CONSTRAINT_TYPE = 'CHECK'
  AND CONSTRAINT_NAME IN (
      'tg_admin_direct_message_type_chk',
      'tg_admin_direct_message_length_chk',
      'tg_admin_direct_message_media_shape_chk',
      'tg_admin_direct_message_keyboard_chk'
  )
ORDER BY CONSTRAINT_NAME
SQL);

        return array_map(
            static fn (object $row): string => (string) $row->constraint_name,
            $rows,
        );
    }

    private function restoreCurrentCompletionAuthority(Migration $migration): void
    {
        $surface = new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
        if ($surface->isReady(DB::connection())
            && Schema::hasColumn('telegram_administrator_direct_messages', 'inline_keyboard_ciphertext')
            && Schema::hasColumn('telegram_administrator_direct_messages', 'inline_keyboard_hash')
            && $this->completionConstraintNames() === [
                'tg_admin_direct_message_keyboard_chk',
                'tg_admin_direct_message_length_chk',
                'tg_admin_direct_message_media_shape_chk',
                'tg_admin_direct_message_type_chk',
            ]) {
            return;
        }

        $migration->up();
    }
}
