<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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

    public function test_up_rejects_half_populated_keyboard_history_and_final_check_is_null_safe(): void
    {
        $this->requireMariaDb();
        $migration = $this->completionMigration();
        $publicId = $this->insertTextDraft('keyboard-null-safety');

        try {
            $this->replaceKeyboardConstraintWithPreviousClause();
            DB::table('telegram_administrator_direct_messages')
                ->where('public_id', $publicId)
                ->update([
                    'inline_keyboard_ciphertext' => 'legacy-valid-ciphertext',
                    'inline_keyboard_hash' => null,
                ]);

            self::assertNull(DB::table('telegram_administrator_direct_messages')
                ->where('public_id', $publicId)
                ->value('inline_keyboard_hash'));

            try {
                $migration->up();
                self::fail('Migration preflight must reject a half-populated keyboard integrity state.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString(
                    'cannot install constraint tg_admin_direct_message_keyboard_chk',
                    $exception->getMessage(),
                );
            }

            self::assertSame(
                'legacy-valid-ciphertext',
                DB::table('telegram_administrator_direct_messages')
                    ->where('public_id', $publicId)
                    ->value('inline_keyboard_ciphertext'),
            );
            self::assertNull(DB::table('telegram_administrator_direct_messages')
                ->where('public_id', $publicId)
                ->value('inline_keyboard_hash'));

            DB::table('telegram_administrator_direct_messages')
                ->where('public_id', $publicId)
                ->update(['inline_keyboard_hash' => str_repeat('a', 64)]);
            $migration->up();
            $this->assertCurrentCompletionAuthority();

            self::assertSame(1, DB::table('telegram_administrator_direct_messages')
                ->where('public_id', $publicId)
                ->update([
                    'inline_keyboard_ciphertext' => 'current-valid-ciphertext',
                    'inline_keyboard_hash' => str_repeat('b', 64),
                ]));

            try {
                DB::table('telegram_administrator_direct_messages')
                    ->where('public_id', $publicId)
                    ->update(['inline_keyboard_hash' => null]);
                self::fail('MariaDB must reject ciphertext with a NULL keyboard integrity hash.');
            } catch (QueryException) {
                self::assertSame(
                    str_repeat('b', 64),
                    DB::table('telegram_administrator_direct_messages')
                        ->where('public_id', $publicId)
                        ->value('inline_keyboard_hash'),
                );
            }

            self::assertSame(1, DB::table('telegram_administrator_direct_messages')
                ->where('public_id', $publicId)
                ->update([
                    'inline_keyboard_ciphertext' => null,
                    'inline_keyboard_hash' => null,
                ]));
            self::assertSame(1, DB::table('telegram_administrator_direct_messages')
                ->where('public_id', $publicId)
                ->update([
                    'content_type' => 'forward',
                    'content_length' => 0,
                ]));
        } finally {
            DB::table('telegram_administrator_direct_messages')->where('public_id', $publicId)->delete();
            $this->restoreCurrentCompletionAuthority($migration);
        }
    }

    public function test_rollback_fence_prevents_late_keyboard_loss_across_two_connections_and_reenters(): void
    {
        $this->requireMariaDb();
        $migration = $this->completionMigration();
        $publicId = $this->insertTextDraft('rollback-fence-race');
        $connectionName = 'telegram_admin_direct_message_rollback_race';
        $secondary = $this->secondaryConnection($connectionName);
        $rollback = new \ReflectionMethod($migration, 'rollbackMysql');
        $rollback->setAccessible(true);
        $establishFence = new \ReflectionMethod($migration, 'establishRollbackFence');
        $establishFence->setAccessible(true);

        try {
            try {
                $rollback->invoke($migration, function () use ($secondary, $publicId): void {
                    $updated = $secondary->table('telegram_administrator_direct_messages')
                        ->where('public_id', $publicId)
                        ->update([
                            'inline_keyboard_ciphertext' => 'late-keyboard-ciphertext',
                            'inline_keyboard_hash' => str_repeat('c', 64),
                        ]);
                    self::assertSame(1, $updated);
                });
                self::fail('Rollback must abort before destructive DDL when a keyboard is written after eligibility.');
            } catch (QueryException) {
                self::assertTrue(Schema::hasColumn(
                    'telegram_administrator_direct_messages',
                    'inline_keyboard_ciphertext',
                ));
                self::assertTrue(Schema::hasColumn(
                    'telegram_administrator_direct_messages',
                    'inline_keyboard_hash',
                ));
                self::assertSame(
                    'late-keyboard-ciphertext',
                    DB::table('telegram_administrator_direct_messages')
                        ->where('public_id', $publicId)
                        ->value('inline_keyboard_ciphertext'),
                );
                self::assertSame(
                    str_repeat('c', 64),
                    DB::table('telegram_administrator_direct_messages')
                        ->where('public_id', $publicId)
                        ->value('inline_keyboard_hash'),
                );
                $this->assertCurrentCompletionAuthority();
            }

            $secondary->table('telegram_administrator_direct_messages')
                ->where('public_id', $publicId)
                ->update([
                    'inline_keyboard_ciphertext' => null,
                    'inline_keyboard_hash' => null,
                ]);

            $establishFence->invoke($migration);
            self::assertContains(
                'tg_admin_direct_message_rollback_fence_chk',
                $this->completionConstraintNames(),
            );

            try {
                $secondary->table('telegram_administrator_direct_messages')
                    ->where('public_id', $publicId)
                    ->update([
                        'inline_keyboard_ciphertext' => 'fenced-keyboard-ciphertext',
                        'inline_keyboard_hash' => str_repeat('d', 64),
                    ]);
                self::fail('The durable rollback fence must reject a late keyboard writer.');
            } catch (QueryException) {
                self::assertNull(DB::table('telegram_administrator_direct_messages')
                    ->where('public_id', $publicId)
                    ->value('inline_keyboard_ciphertext'));
                self::assertNull(DB::table('telegram_administrator_direct_messages')
                    ->where('public_id', $publicId)
                    ->value('inline_keyboard_hash'));
            }

            foreach (['forward', 'copy'] as $contentType) {
                try {
                    $secondary->table('telegram_administrator_direct_messages')
                        ->where('public_id', $publicId)
                        ->update([
                            'content_type' => $contentType,
                            'content_length' => 0,
                        ]);
                    self::fail('The durable rollback fence must reject a late source-message writer.');
                } catch (QueryException) {
                    self::assertSame(
                        'text',
                        DB::table('telegram_administrator_direct_messages')
                            ->where('public_id', $publicId)
                            ->value('content_type'),
                    );
                }
            }

            $rollback->invoke($migration);
            $this->assertLegacyCompletionAuthority();

            $migration->down();
            $this->assertLegacyCompletionAuthority();

            $migration->up();
            $this->assertCurrentCompletionAuthority();
        } finally {
            DB::purge($connectionName);
            config(["database.connections.{$connectionName}" => null]);
            DB::table('telegram_administrator_direct_messages')->where('public_id', $publicId)->delete();
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

    private function replaceKeyboardConstraintWithPreviousClause(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE telegram_administrator_direct_messages
    DROP CONSTRAINT tg_admin_direct_message_keyboard_chk,
    ADD CONSTRAINT tg_admin_direct_message_keyboard_chk CHECK (
        inline_keyboard_ciphertext IS NULL AND inline_keyboard_hash IS NULL
        OR content_type <> 'forward'
            AND inline_keyboard_ciphertext IS NOT NULL
            AND OCTET_LENGTH(inline_keyboard_ciphertext) BETWEEN 1 AND 65536
            AND inline_keyboard_hash REGEXP '^[0-9a-f]{64}$'
    )
SQL);
    }

    private function insertTextDraft(string $suffix): string
    {
        $now = now('UTC');
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => $userId,
            'status' => 'active',
            'is_owner' => true,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $publicId = (string) Str::ulid();

        DB::table('telegram_administrator_direct_messages')->insert([
            'public_id' => $publicId,
            'create_request_hash' => hash('sha256', 'migration-create:'.$suffix),
            'actor_administrator_id' => $administratorId,
            'bot_id' => '123456',
            'target_account_public_id' => (string) Str::ulid(),
            'target_telegram_user_id' => '987654321',
            'content_type' => 'text',
            'content_ciphertext' => 'opaque-content-ciphertext',
            'content_integrity_hash' => hash('sha256', 'migration-content:'.$suffix),
            'content_length' => 4,
            'media_public_id' => null,
            'media_detected_mime' => null,
            'media_byte_size' => null,
            'media_content_sha256' => null,
            'inline_keyboard_ciphertext' => null,
            'inline_keyboard_hash' => null,
            'correlation_id' => 'migration-'.$suffix,
            'delivery_operation_public_id' => null,
            'expires_at' => $now->copy()->addHour(),
            'confirmed_at' => null,
            'queued_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $publicId;
    }

    private function secondaryConnection(string $name): Connection
    {
        $default = (string) config('database.default');
        $configuration = config('database.connections.'.$default);
        if (! is_array($configuration)) {
            throw new \RuntimeException('Default database connection configuration is unavailable.');
        }

        config(["database.connections.{$name}" => $configuration]);
        DB::purge($name);

        return DB::connection($name);
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
      'tg_admin_direct_message_keyboard_chk',
      'tg_admin_direct_message_rollback_fence_chk'
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
        $migration->up();
        $this->assertCurrentCompletionAuthority();
    }
}
