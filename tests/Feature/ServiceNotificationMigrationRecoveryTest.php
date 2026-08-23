<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/** @requirement SVC-013 SVC-014 DAT-003 DAT-004 QUA-004 */
final class ServiceNotificationMigrationRecoveryTest extends TestCase
{
    use DatabaseTruncation;

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_23_000200_enable_service_notification_threshold_authority.php');
        $this->migration = $migration;
    }

    public function test_missing_delivery_purpose_constraint_is_recovered_without_replaying_a_drop(): void
    {
        $this->migration->down();
        DB::statement('ALTER TABLE service_delivery_attempts DROP CONSTRAINT service_delivery_attempts_purpose_chk');
        self::assertSame(0, $this->constraintCount());

        try {
            $this->migration->up();

            self::assertSame(1, $this->constraintCount());
            self::assertStringContainsString("'notification'", $this->constraintClause());
            self::assertTrue(Schema::hasTable('service_notification_states'));
            self::assertTrue(Schema::hasTable('service_notification_delivery_bindings'));
            self::assertTrue(Schema::hasTable('service_notification_events'));
            self::assertSame(1, $this->triggerCount('service_delivery_notification_attempt_capability_guard'));
            self::assertSame(1, $this->triggerCount('service_delivery_notification_effect_insert_capability_guard'));
            self::assertSame(1, $this->triggerCount('service_delivery_notification_effect_update_capability_guard'));
        } finally {
            if (! Schema::hasTable('service_notification_states')) {
                $this->migration->up();
            }
        }
    }

    public function test_rollback_restores_original_delivery_purpose_constraint(): void
    {
        $this->migration->down();

        try {
            self::assertSame(1, $this->constraintCount());
            $clause = $this->constraintClause();
            self::assertStringContainsString("'initial'", $clause);
            self::assertStringContainsString("'resend'", $clause);
            self::assertStringNotContainsString("'notification'", $clause);
            self::assertSame(0, $this->triggerCount('service_delivery_notification_attempt_capability_guard'));
            self::assertSame(0, $this->triggerCount('service_delivery_notification_effect_insert_capability_guard'));
            self::assertSame(0, $this->triggerCount('service_delivery_notification_effect_update_capability_guard'));
        } finally {
            $this->migration->up();
        }
    }

    public function test_empty_interrupted_scan_cursor_table_is_rebuilt_with_pristine_singleton(): void
    {
        /** @var Migration $cursorMigration */
        $cursorMigration = require database_path('migrations/2026_08_23_000210_enable_service_notification_scan_cursor.php');
        $cursorMigration->down();
        $this->createEmptyScanCursorTable();
        self::assertSame(0, DB::table('service_notification_scan_cursor')->count());

        try {
            $cursorMigration->up();

            $cursor = DB::table('service_notification_scan_cursor')->first([
                'id',
                'last_service_subscription_id',
            ]);
            self::assertNotNull($cursor);
            self::assertSame(1, (int) $cursor->id);
            self::assertNull($cursor->last_service_subscription_id);
            self::assertSame(1, DB::table('service_notification_scan_cursor')->count());
            self::assertSame(1, $this->triggerCount('service_notification_cursor_update_guard'));
        } finally {
            if (! Schema::hasTable('service_notification_scan_cursor')) {
                $cursorMigration->up();
            }
        }
    }

    public function test_non_pristine_scan_cursor_state_still_fails_closed(): void
    {
        /** @var Migration $cursorMigration */
        $cursorMigration = require database_path('migrations/2026_08_23_000210_enable_service_notification_scan_cursor.php');
        $cursorMigration->down();
        $this->createEmptyScanCursorTable();
        DB::table('service_notification_scan_cursor')->insert([
            'id' => 1,
            'last_service_subscription_id' => 42,
            'updated_at' => now('UTC'),
        ]);

        try {
            $cursorMigration->up();
            self::fail('A durable notification scan cursor must not be discarded during interrupted-install recovery.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Service notification scan cursor migration cannot repair a non-pristine interrupted install.',
                $exception->getMessage(),
            );
            self::assertSame(42, (int) DB::table('service_notification_scan_cursor')->value('last_service_subscription_id'));
        } finally {
            Schema::dropIfExists('service_notification_scan_cursor');
            $cursorMigration->up();
        }
    }

    private function createEmptyScanCursorTable(): void
    {
        Schema::create('service_notification_scan_cursor', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_service_subscription_id')->nullable();
            $table->dateTime('updated_at', 6);
        });
    }

    private function constraintCount(): int
    {
        $row = DB::selectOne(
            <<<'SQL'
SELECT COUNT(*) AS aggregate
FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND TABLE_NAME = 'service_delivery_attempts'
  AND CONSTRAINT_NAME = 'service_delivery_attempts_purpose_chk'
  AND CONSTRAINT_TYPE = 'CHECK'
SQL,
        );

        return $row === null ? 0 : (int) $row->aggregate;
    }

    private function constraintClause(): string
    {
        $row = DB::selectOne(
            <<<'SQL'
SELECT CHECK_CLAUSE AS check_clause
FROM information_schema.CHECK_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND TABLE_NAME = 'service_delivery_attempts'
  AND CONSTRAINT_NAME = 'service_delivery_attempts_purpose_chk'
LIMIT 1
SQL,
        );

        self::assertNotNull($row);

        return (string) $row->check_clause;
    }

    private function triggerCount(string $trigger): int
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$trigger],
        );

        return $row === null ? 0 : (int) $row->aggregate;
    }
}
