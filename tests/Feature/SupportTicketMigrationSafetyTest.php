<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Support\Application\SupportTicketService;
use Database\Seeders\SupportTicketCategorySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/** @requirement SUP-001 DAT-003 QUA-004 */
final class SupportTicketMigrationSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_reenters_partial_surface_and_restores_readiness(): void
    {
        self::assertTrue(class_exists(SupportTicketService::class));
        $migration = $this->migration();
        DB::unprepared('DROP TRIGGER IF EXISTS support_tickets_update_guard');
        DB::statement('ALTER TABLE support_tickets DROP CONSTRAINT support_ticket_priority_chk');
        Schema::drop('support_ticket_state_histories');
        Schema::drop('support_ticket_messages');

        $migration->up();

        self::assertTrue(Schema::hasTable('support_ticket_messages'));
        self::assertTrue(Schema::hasTable('support_ticket_state_histories'));
        self::assertSame(1, $this->constraintCount('support_tickets', 'support_ticket_priority_chk'));
        self::assertSame(1, $this->triggerCount('support_tickets_update_guard'));
        self::assertSame(1, $this->triggerCount('support_ticket_state_histories_insert_guard'));
    }

    public function test_migration_fails_closed_for_unrecognized_partial_surface(): void
    {
        $migration = $this->migration();
        $migration->down();
        Schema::create('support_ticket_categories', function (Blueprint $table): void {
            $table->bigIncrements('id');
        });

        try {
            $migration->up();
            self::fail('An unrecognized partial Support surface must fail closed.');
        } catch (RuntimeException) {
            self::assertTrue(Schema::hasTable('support_ticket_categories'));
            self::assertFalse(Schema::hasColumn('support_ticket_categories', 'code'));
        } finally {
            Schema::dropIfExists('support_ticket_categories');
            $migration->up();
        }
    }

    public function test_rollback_refuses_category_rows_and_preserves_data(): void
    {
        $this->seed(SupportTicketCategorySeeder::class);
        $migration = $this->migration();

        try {
            $migration->down();
            self::fail('Seeded/operator-editable category data must block destructive rollback.');
        } catch (RuntimeException) {
            self::assertTrue(Schema::hasTable('support_ticket_categories'));
            self::assertSame(6, DB::table('support_ticket_categories')->count());
        }
    }

    public function test_rollback_refuses_unexpected_incoming_foreign_key_and_empty_surface_reinstalls_cleanly(): void
    {
        $migration = $this->migration();
        Schema::create('support_ticket_external_reference_probe', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('support_ticket_id');
            $table->foreign('support_ticket_id', 'support_ticket_external_probe_fk')
                ->references('id')->on('support_tickets')->restrictOnDelete();
        });

        try {
            $migration->down();
            self::fail('Unexpected incoming foreign keys must block Support authority removal.');
        } catch (RuntimeException) {
            self::assertTrue(Schema::hasTable('support_tickets'));
        } finally {
            Schema::dropIfExists('support_ticket_external_reference_probe');
        }

        $migration->down();
        foreach (['support_ticket_state_histories', 'support_ticket_messages', 'support_tickets', 'support_ticket_categories'] as $table) {
            self::assertFalse(Schema::hasTable($table));
        }

        $migration->up();
        foreach (['support_ticket_categories', 'support_tickets', 'support_ticket_messages', 'support_ticket_state_histories'] as $table) {
            self::assertTrue(Schema::hasTable($table));
        }
        self::assertSame(1, $this->triggerCount('support_tickets_insert_guard'));
        self::assertSame(1, $this->triggerCount('support_tickets_transition_history'));
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_09_16_000100_create_support_ticket_foundation.php');

        return $migration;
    }

    private function constraintCount(string $table, string $constraint): int
    {
        return (int) DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->count();
    }

    private function triggerCount(string $trigger): int
    {
        return (int) DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TRIGGER_NAME', $trigger)
            ->count();
    }
}
