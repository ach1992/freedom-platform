<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\TelegramRateRetryRetentionLifecycleFence;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

final class TelegramRateRetryRetentionMigrationSafetyTest extends TestCase
{
    use DatabaseTruncation;

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram rate/retry/retention migration safety requires MariaDB/MySQL.');
        }

        (require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php'))->up();
        $this->migration = require database_path(
            'migrations/2026_09_27_000100_add_telegram_rate_retry_retention_foundation.php',
        );
        $this->migration->up();
    }

    protected function tearDown(): void
    {
        try {
            if (DB::connection()->getDriverName() === 'mysql') {
                $this->migration->up();
                $this->truncateTablesForAllConnections();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_rollback_cut_waits_for_entered_runtime_writer_and_blocks_later_writers(): void
    {
        $database = app(DatabaseManager::class);
        $default = (string) config('database.default');
        $connectionConfig = config('database.connections.'.$default);
        self::assertIsArray($connectionConfig);
        $contenderName = 'telegram_rate_retry_rollback_contender';
        config(['database.connections.'.$contenderName => $connectionConfig]);
        $primary = $database->connection($default);
        $contender = $database->connection($contenderName);
        $contender->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $contender->statement('SET SESSION lock_wait_timeout = 1');

        $primary->beginTransaction();
        try {
            app(TelegramRateRetryRetentionLifecycleFence::class)
                ->acquireRuntimeWriteFence($primary);

            config(['database.default' => $contenderName]);
            try {
                try {
                    $this->establishRollbackCut();
                    self::fail('Rollback must wait behind a runtime writer that entered before the cut.');
                } catch (QueryException $exception) {
                    self::assertStringContainsString('Lock wait timeout', $exception->getMessage());
                }
            } finally {
                config(['database.default' => $default]);
            }
        } finally {
            $primary->commit();
        }

        config(['database.default' => $contenderName]);
        try {
            $this->establishRollbackCut();
        } finally {
            config(['database.default' => $default]);
        }

        $primary->beginTransaction();
        try {
            try {
                app(TelegramRateRetryRetentionLifecycleFence::class)
                    ->acquireRuntimeWriteFence($primary);
                self::fail('A runtime writer starting after the rollback cut must fail closed.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('not accepting runtime evidence writes', $exception->getMessage());
            }
        } finally {
            $primary->rollBack();
            DB::purge($contenderName);
        }
    }

    public function test_interrupted_rollback_reentry_repairs_surface_before_releasing_runtime_writers(): void
    {
        $this->establishRollbackCut();

        DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_retry_directives_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_retry_directives_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_retry_directives_insert_guard');
        Schema::dropIfExists('telegram_delivery_retry_directives');

        DB::connection()->transaction(function ($connection): void {
            try {
                app(TelegramRateRetryRetentionLifecycleFence::class)
                    ->acquireRuntimeWriteFence($connection);
                self::fail('Interrupted rollback must keep runtime evidence writers fenced.');
            } catch (RuntimeException) {
                // Expected.
            }
        });

        $this->migration->up();

        self::assertTrue(Schema::hasTable('telegram_delivery_retry_directives'));
        self::assertTrue(Schema::hasColumn(
            'processed_telegram_updates',
            'interaction_rate_authorized_at',
        ));
        self::assertTrue(Schema::hasColumn(
            'telegram_rate_retry_retention_lifecycle',
            'runtime_write_gate',
        ));
        self::assertFalse(Schema::hasColumn(
            'telegram_rate_retry_retention_lifecycle',
            'rollback_fence',
        ));

        DB::connection()->transaction(function ($connection): void {
            app(TelegramRateRetryRetentionLifecycleFence::class)
                ->acquireRuntimeWriteFence($connection);
            self::assertTrue(true);
        });
    }

    public function test_semantic_refusal_releases_cut_without_destroying_interaction_authorization_evidence(): void
    {
        $now = now('UTC');
        DB::table('processed_telegram_updates')->insert([
            'bot_id' => '123456789',
            'update_id' => 998001,
            'payload_hash' => hash('sha256', 'rollback-evidence'),
            'state' => 'processing',
            'correlation_id' => 'rollback-evidence-998001',
            'interaction_rate_authorized_at' => $now,
            'processed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            $this->migration->down();
            self::fail('Rollback must refuse while reusable interaction-rate evidence exists.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Telegram interaction rate authorization evidence exists; rollback is refused.',
                $exception->getMessage(),
            );
        }

        self::assertTrue(Schema::hasTable('telegram_delivery_retry_directives'));
        self::assertTrue(Schema::hasColumn(
            'processed_telegram_updates',
            'interaction_rate_authorized_at',
        ));
        self::assertNotNull(DB::table('processed_telegram_updates')
            ->where('update_id', 998001)
            ->value('interaction_rate_authorized_at'));
        self::assertTrue(Schema::hasColumn(
            'telegram_rate_retry_retention_lifecycle',
            'runtime_write_gate',
        ));
        self::assertFalse(Schema::hasColumn(
            'telegram_rate_retry_retention_lifecycle',
            'rollback_fence',
        ));
    }

    private function establishRollbackCut(): void
    {
        (new ReflectionClass($this->migration))
            ->getMethod('establishLifecycleFence')
            ->invoke($this->migration);
    }
}
