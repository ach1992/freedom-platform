<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        } finally {
            $this->migration->up();
        }
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
}
