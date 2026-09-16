<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Support\Application\SupportTicketService;
use Database\Seeders\SupportTicketCategorySeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/** @requirement SUP-001 DAT-003 QUA-004 */
final class SupportTicketMigrationSafetyTest extends TestCase
{
    use DatabaseTruncation;

    private DatabaseManager $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = app(DatabaseManager::class);
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->configureConnection('support_writer');
            $this->configureConnection('support_ddl_attacker');
            $this->configureForeignKeyBuilderConnection();
        }
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->database)) {
                try {
                    $this->database->connection('support_fk_builder')
                        ->statement('DROP TABLE IF EXISTS support_ticket_hidden_reference_probe');
                } catch (Throwable) {
                    // Best-effort disposable CI cleanup must not hide the test result.
                }

                foreach (['support_writer', 'support_ddl_attacker', 'support_fk_builder'] as $connection) {
                    $this->database->purge($connection);
                }
            }

            if (DB::connection()->getDriverName() === 'mysql') {
                try {
                    Schema::dropIfExists('support_ticket_external_reference_race_probe');
                } catch (Throwable) {
                    // Best-effort disposable CI cleanup must not hide the test result.
                }
            }
        } finally {
            parent::tearDown();
        }
    }

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

    public function test_inflight_ticket_and_message_commit_is_observed_before_any_destructive_drop(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            self::markTestSkipped('Support rollback in-flight commit regression requires MariaDB/MySQL.');
        }
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the Support rollback in-flight commit regression.');
        }

        $userId = $this->user();
        $barrier = sys_get_temp_dir().'/support-rollback-inflight-'.bin2hex(random_bytes(8));
        $pid = null;

        try {
            try {
                $this->invokeRollback(afterInitialPreflight: function () use ($userId, $barrier, &$pid): void {
                    $pid = pcntl_fork();
                    self::assertNotSame(-1, $pid);
                    if ($pid === 0) {
                        try {
                            $writer = $this->database->connection('support_writer');
                            $writer->beginTransaction();
                            $now = now('UTC');
                            $categoryId = (int) $writer->table('support_ticket_categories')->insertGetId([
                                'code' => 'rollback_inflight',
                                'name_fa' => 'آزمایش rollback',
                                'name_en' => 'Rollback in-flight probe',
                                'route_role_code' => null,
                                'sort_order' => 0,
                                'is_active' => true,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                            $ticketId = (int) $writer->table('support_tickets')->insertGetId([
                                'tracking_number' => 'ST'.str_repeat('7', 18),
                                'requester_user_id' => $userId,
                                'category_id' => $categoryId,
                                'state' => 'new',
                                'state_version' => 1,
                                'priority' => 'normal',
                                'assigned_user_id' => null,
                                'order_id' => null,
                                'payment_intent_id' => null,
                                'service_subscription_id' => null,
                                'title' => 'rollback in-flight durable probe',
                                'close_reason' => null,
                                'resolved_at' => null,
                                'closed_at' => null,
                                'reopen_until' => null,
                                'last_transition_actor_user_id' => $userId,
                                'last_transition_reason_code' => 'ticket_created',
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                            $writer->table('support_ticket_messages')->insert([
                                'ticket_id' => $ticketId,
                                'actor_user_id' => $userId,
                                'kind' => 'customer_reply',
                                'body' => 'durable message committed while rollback waits',
                                'idempotency_key' => 'rollback-inflight-message',
                                'customer_visible' => true,
                                'created_at' => $now,
                            ]);
                            file_put_contents($barrier, 'ready');
                            usleep(250000);
                            $writer->commit();
                            exit(0);
                        } catch (Throwable $exception) {
                            file_put_contents($barrier, 'error|'.$exception::class.'|'.$exception->getMessage());
                            exit(1);
                        }
                    }

                    $deadline = microtime(true) + 10.0;
                    while (! file_exists($barrier)) {
                        if (microtime(true) >= $deadline) {
                            throw new RuntimeException('Timed out waiting for the in-flight Support rollback transaction.');
                        }
                        usleep(1000);
                    }
                    $state = trim((string) file_get_contents($barrier));
                    if ($state !== 'ready') {
                        throw new RuntimeException('In-flight Support rollback worker failed before commit: '.$state);
                    }
                });
                self::fail('Rollback must stop after the in-flight Support transaction commits durable evidence.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('contains durable rows', $exception->getMessage());
            }

            self::assertIsInt($pid);
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
            self::assertTrue(Schema::hasTable('support_ticket_categories'));
            self::assertTrue(Schema::hasTable('support_tickets'));
            self::assertTrue(Schema::hasTable('support_ticket_messages'));
            self::assertTrue(Schema::hasTable('support_ticket_state_histories'));
            self::assertSame(1, DB::table('support_ticket_categories')->where('code', 'rollback_inflight')->count());
            self::assertSame(1, DB::table('support_tickets')->where('tracking_number', 'ST'.str_repeat('7', 18))->count());
            self::assertSame(1, DB::table('support_ticket_messages')->where('idempotency_key', 'rollback-inflight-message')->count());
            self::assertSame(1, DB::table('support_ticket_state_histories')->count());
        } finally {
            @unlink($barrier);
        }
    }

    public function test_rollback_write_fence_excludes_late_ticket_write_after_final_preflight(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            self::markTestSkipped('Support rollback write-fence regression requires MariaDB/MySQL.');
        }

        $migration = $this->migration();
        $writer = $this->database->connection('support_writer');
        $userId = $this->user();
        $this->assertDistinctDatabaseSessions(DB::connection(), $writer);
        $attempted = false;

        $this->invokeRollback(afterFinalPreflight: function (string $table) use ($writer, $userId, &$attempted): void {
            if ($table !== 'support_ticket_state_histories') {
                return;
            }

            $attempted = true;
            $writer->statement('SET SESSION lock_wait_timeout = 1');
            $now = now('UTC');
            try {
                $writer->table('support_tickets')->insert([
                    'tracking_number' => 'ST'.str_repeat('0', 18),
                    'requester_user_id' => $userId,
                    'category_id' => 1,
                    'state' => 'new',
                    'state_version' => 1,
                    'priority' => 'normal',
                    'assigned_user_id' => null,
                    'order_id' => null,
                    'payment_intent_id' => null,
                    'service_subscription_id' => null,
                    'title' => 'rollback write-fence race probe',
                    'close_reason' => null,
                    'resolved_at' => null,
                    'closed_at' => null,
                    'reopen_until' => null,
                    'last_transition_actor_user_id' => $userId,
                    'last_transition_reason_code' => 'ticket_created',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                self::fail('A ticket write starting after final rollback preflight must remain mechanically excluded.');
            } catch (QueryException $exception) {
                self::assertSame(1205, (int) ($exception->errorInfo[1] ?? 0));
            }
        });

        self::assertTrue($attempted);
        foreach (['support_ticket_state_histories', 'support_ticket_messages', 'support_tickets', 'support_ticket_categories'] as $table) {
            self::assertFalse(Schema::hasTable($table));
        }

        $migration->up();
        self::assertSame(1, $this->triggerCount('support_tickets_insert_guard'));
    }

    public function test_rollback_write_fence_excludes_concurrent_incoming_foreign_key_ddl(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            self::markTestSkipped('Support rollback DDL-race regression requires MariaDB/MySQL.');
        }

        Schema::create('support_ticket_external_reference_race_probe', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('support_ticket_id')->nullable();
            $table->index('support_ticket_id', 'support_ticket_race_probe_idx');
        });
        $attacker = $this->database->connection('support_ddl_attacker');
        $this->assertDistinctDatabaseSessions(DB::connection(), $attacker);
        $attempted = false;

        try {
            $this->invokeRollback(afterFinalPreflight: function (string $table) use ($attacker, &$attempted): void {
                if ($table !== 'support_ticket_state_histories') {
                    return;
                }

                $attempted = true;
                $attacker->statement('SET SESSION lock_wait_timeout = 1');
                try {
                    $attacker->statement(<<<'SQL'
ALTER TABLE support_ticket_external_reference_race_probe
ADD CONSTRAINT support_ticket_race_probe_fk
FOREIGN KEY (support_ticket_id) REFERENCES support_tickets(id) ON DELETE RESTRICT
SQL);
                    self::fail('Concurrent incoming-FK DDL must be excluded while the Support rollback WRITE fence is held.');
                } catch (QueryException $exception) {
                    self::assertSame(1205, (int) ($exception->errorInfo[1] ?? 0));
                }
            });

            self::assertTrue($attempted);
            self::assertSame(0, $this->constraintCount('support_ticket_external_reference_race_probe', 'support_ticket_race_probe_fk'));
        } finally {
            Schema::dropIfExists('support_ticket_external_reference_race_probe');
        }

        $this->migration()->up();
        self::assertSame(1, $this->triggerCount('support_tickets_insert_guard'));
    }

    public function test_hidden_incoming_fk_failure_keeps_surviving_surface_guarded_and_up_repairs(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            self::markTestSkipped('Support hidden-FK rollback regression requires MariaDB/MySQL.');
        }

        $databaseName = $this->safeDatabaseName(DB::connection());
        $builder = $this->database->connection('support_fk_builder');
        $builder->statement(sprintf(<<<'SQL'
CREATE TABLE support_ticket_hidden_reference_probe (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    support_ticket_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY support_ticket_hidden_reference_idx (support_ticket_id),
    CONSTRAINT support_ticket_hidden_reference_fk
        FOREIGN KEY (support_ticket_id) REFERENCES `%s`.support_tickets(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL, $databaseName));

        $migration = $this->migration();
        try {
            try {
                $migration->down();
                self::fail('A hidden incoming foreign key must prevent dropping its Support parent.');
            } catch (Throwable) {
                self::assertTrue(Schema::hasTable('support_tickets'));
                self::assertTrue(Schema::hasTable('support_ticket_categories'));
                self::assertSame(1, $this->triggerCount('support_tickets_insert_guard'));
                self::assertSame(1, $this->triggerCount('support_tickets_initial_history'));
            }
        } finally {
            $builder->statement('DROP TABLE IF EXISTS support_ticket_hidden_reference_probe');
        }

        $migration->up();
        foreach (['support_ticket_categories', 'support_tickets', 'support_ticket_messages', 'support_ticket_state_histories'] as $table) {
            self::assertTrue(Schema::hasTable($table));
        }
        self::assertSame(1, $this->triggerCount('support_ticket_state_histories_insert_guard'));
    }

    public function test_interruption_after_child_drop_retains_guards_and_down_reenters_cleanly(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            self::markTestSkipped('Support interrupted rollback regression requires MariaDB/MySQL.');
        }

        try {
            $this->invokeRollback(afterDrop: static function (string $table): void {
                if ($table === 'support_ticket_state_histories') {
                    throw new RuntimeException('support-rollback-interruption');
                }
            });
            self::fail('The deterministic rollback interruption seam must abort after the first child DROP.');
        } catch (RuntimeException $exception) {
            self::assertSame('support-rollback-interruption', $exception->getMessage());
        }

        self::assertFalse(Schema::hasTable('support_ticket_state_histories'));
        self::assertTrue(Schema::hasTable('support_ticket_messages'));
        self::assertTrue(Schema::hasTable('support_tickets'));
        self::assertTrue(Schema::hasTable('support_ticket_categories'));
        self::assertSame(1, $this->triggerCount('support_tickets_insert_guard'));
        self::assertSame(1, $this->triggerCount('support_tickets_initial_history'));
        self::assertSame(1, $this->triggerCount('support_ticket_messages_delete_guard'));

        $migration = $this->migration();
        $migration->down();
        foreach (['support_ticket_state_histories', 'support_ticket_messages', 'support_tickets', 'support_ticket_categories'] as $table) {
            self::assertFalse(Schema::hasTable($table));
        }

        $migration->up();
        self::assertSame(1, $this->triggerCount('support_tickets_transition_history'));
        self::assertSame(1, $this->triggerCount('support_ticket_state_histories_insert_guard'));
    }

    /**
     * @param  null|\Closure(string):void  $afterFinalPreflight
     * @param  null|\Closure(string):void  $afterDrop
     */
    private function invokeRollback(
        ?\Closure $afterFinalPreflight = null,
        ?\Closure $afterDrop = null,
        ?\Closure $afterInitialPreflight = null,
    ): void {
        $migration = $this->migration();
        $rollback = new ReflectionMethod($migration, 'rollbackMysql');
        $withInstallationLock = new ReflectionMethod($migration, 'withInstallationLock');
        $connection = DB::connection();

        $withInstallationLock->invoke($migration, function () use (
            $rollback,
            $migration,
            $connection,
            $afterFinalPreflight,
            $afterDrop,
            $afterInitialPreflight,
        ): void {
            $rollback->invoke(
                $migration,
                $connection,
                $afterFinalPreflight,
                $afterDrop,
                $afterInitialPreflight,
            );
        });
    }

    private function assertDistinctDatabaseSessions(Connection ...$connections): void
    {
        $ids = [];
        foreach ($connections as $connection) {
            $row = $connection->selectOne('SELECT CONNECTION_ID() AS connection_id', [], false);
            self::assertNotNull($row);
            $id = (int) ($row->connection_id ?? 0);
            self::assertGreaterThan(0, $id);
            $ids[] = $id;
        }

        self::assertCount(count($ids), array_unique($ids), 'Rollback race actors must use independent MariaDB sessions.');
    }

    private function configureConnection(string $name): void
    {
        $default = config('database.default');
        self::assertIsString($default);
        $config = config('database.connections.'.$default);
        self::assertIsArray($config);
        $config['url'] = null;
        config(['database.connections.'.$name => $config]);
        $this->database->purge($name);
    }

    private function configureForeignKeyBuilderConnection(): void
    {
        $default = config('database.default');
        self::assertIsString($default);
        $config = config('database.connections.'.$default);
        self::assertIsArray($config);
        $config['database'] = 'freedom_platform_hidden_fk';
        $config['username'] = 'freedom_ci_fk_builder';
        $config['password'] = 'ci-only-fk-builder-password';
        $config['url'] = null;
        config(['database.connections.support_fk_builder' => $config]);
        $this->database->purge('support_fk_builder');
    }

    private function safeDatabaseName(Connection $connection): string
    {
        $databaseName = $connection->getDatabaseName();
        if (preg_match('/\A[A-Za-z0-9_]+\z/', $databaseName) !== 1) {
            throw new RuntimeException('CI database identifier is not safe for Support rollback regression SQL.');
        }

        return $databaseName;
    }

    private function user(): int
    {
        $now = now('UTC');

        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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
