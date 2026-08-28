<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\TelegramDeliveryDatabaseAuthoritySurfaceV1;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/** @requirement ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 OPS-003 QUA-004 QUA-007 QUA-010 */
final class TelegramOutboundDeliveryRollbackToctouTest extends TestCase
{
    use DatabaseTruncation;

    private DatabaseManager $database;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram outbound rollback TOCTOU regression requires MariaDB/MySQL.');
        }

        $this->database = app(DatabaseManager::class);
        $this->configureForeignKeyBuilderConnection();

        $migration = $this->migration();
        $migration->up();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->database)) {
                foreach ([
                    'telegram_delivery_rollback_operation_fk_probe',
                    'telegram_delivery_rollback_capability_fk_probe',
                ] as $table) {
                    try {
                        $this->database->connection('telegram_fk_builder')
                            ->statement('DROP TABLE IF EXISTS '.$table);
                    } catch (\Throwable) {
                        // Best-effort isolation cleanup must not hide the original result.
                    }
                }

                $this->database->purge('telegram_fk_builder');
                $this->database->purge('telegram_metadata');
            }

            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    public function test_incoming_fk_created_after_final_preflight_blocks_before_any_guard_loss(): void
    {
        $runtime = DB::connection();
        $builder = $this->database->connection('telegram_fk_builder');
        $databaseName = $this->safeDatabaseName($runtime);
        $migration = $this->migration();

        $this->assertDistinctDatabaseSessions($runtime, $builder);

        $this->assertRollbackDropFails(afterFinalPreflight: function () use ($builder, $databaseName): void {
            $builder->statement(sprintf(<<<'SQL'
CREATE TABLE telegram_delivery_rollback_operation_fk_probe (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    operation_public_id CHAR(26) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    PRIMARY KEY (id),
    KEY rollback_operation_fk_idx (operation_public_id),
    CONSTRAINT rollback_operation_fk
        FOREIGN KEY (operation_public_id)
        REFERENCES `%s`.`telegram_delivery_operations` (`public_id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
SQL, $databaseName));
        });

        self::assertTrue(Schema::hasTable('telegram_delivery_operations'));
        self::assertTrue(Schema::hasTable('telegram_delivery_authority_capability'));
        $this->assertExactRequiredTriggers();
        $capability = DB::table('telegram_delivery_authority_capability')->where('id', 1)->first();
        self::assertNotNull($capability);
        self::assertSame(0, (int) $capability->schema_version);
        self::assertNull($capability->activated_at);

        try {
            $migration->up();
            self::fail('Re-entry must not remove guards while the rollback-blocking operation FK still exists.');
        } catch (QueryException $exception) {
            self::assertContains((int) ($exception->errorInfo[1] ?? 0), [1217, 1451]);
        }
        self::assertTrue(Schema::hasTable('telegram_delivery_operations'));
        self::assertTrue(Schema::hasTable('telegram_delivery_authority_capability'));
        $this->assertExactRequiredTriggers();

        $builder->statement('DROP TABLE telegram_delivery_rollback_operation_fk_probe');
        $migration->up();
        self::assertTrue((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->semanticsMatchExpected($runtime));
        $reactivated = DB::table('telegram_delivery_authority_capability')->where('id', 1)->first();
        self::assertNotNull($reactivated);
        self::assertSame(1, (int) $reactivated->schema_version);
        self::assertNotNull($reactivated->activated_at);

        $migration->down();

        self::assertFalse(Schema::hasTable('telegram_delivery_operations'));
        self::assertFalse(Schema::hasTable('telegram_delivery_authority_capability'));
        self::assertSame([], (new TelegramDeliveryDatabaseAuthoritySurfaceV1)->presentRequiredTriggers($runtime));

        $migration->up();
        self::assertTrue((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->semanticsMatchExpected($runtime));
    }

    public function test_capability_fk_race_leaves_a_guarded_resumable_partial_rollback(): void
    {
        $runtime = DB::connection();
        $builder = $this->database->connection('telegram_fk_builder');
        $databaseName = $this->safeDatabaseName($runtime);
        $migration = $this->migration();

        $this->assertDistinctDatabaseSessions($runtime, $builder);

        $this->assertRollbackDropFails(afterCapabilityPreflight: function () use ($builder, $databaseName): void {
            $builder->statement(sprintf(<<<'SQL'
CREATE TABLE telegram_delivery_rollback_capability_fk_probe (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    capability_id TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY rollback_capability_fk_idx (capability_id),
    CONSTRAINT rollback_capability_fk
        FOREIGN KEY (capability_id)
        REFERENCES `%s`.`telegram_delivery_authority_capability` (`id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL, $databaseName));
        });

        self::assertFalse(Schema::hasTable('telegram_delivery_operations'));
        self::assertTrue(Schema::hasTable('telegram_delivery_authority_capability'));

        $present = (new TelegramDeliveryDatabaseAuthoritySurfaceV1)->presentRequiredTriggers($runtime);
        sort($present, SORT_STRING);
        $expected = [
            'outbox_telegram_delivery_envelope_delete_guard',
            'outbox_telegram_delivery_envelope_insert_guard',
            'outbox_telegram_delivery_envelope_update_guard',
            'telegram_delivery_capability_delete_guard',
            'telegram_delivery_capability_insert_guard',
            'telegram_delivery_capability_update_guard',
        ];
        sort($expected, SORT_STRING);
        self::assertSame($expected, $present);
        $capability = DB::table('telegram_delivery_authority_capability')->where('id', 1)->first();
        self::assertNotNull($capability);
        self::assertSame(0, (int) $capability->schema_version);
        self::assertNull($capability->activated_at);

        try {
            $migration->up();
            self::fail('Capability-only re-entry must not remove guards while its rollback-blocking FK still exists.');
        } catch (QueryException $exception) {
            self::assertContains((int) ($exception->errorInfo[1] ?? 0), [1217, 1451]);
        }
        self::assertFalse(Schema::hasTable('telegram_delivery_operations'));
        self::assertTrue(Schema::hasTable('telegram_delivery_authority_capability'));
        $presentAfterFailedUp = (new TelegramDeliveryDatabaseAuthoritySurfaceV1)->presentRequiredTriggers($runtime);
        sort($presentAfterFailedUp, SORT_STRING);
        self::assertSame($expected, $presentAfterFailedUp);

        $builder->statement('DROP TABLE telegram_delivery_rollback_capability_fk_probe');
        $migration->up();

        self::assertTrue(Schema::hasTable('telegram_delivery_authority_capability'));
        self::assertTrue(Schema::hasTable('telegram_delivery_operations'));
        self::assertTrue((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->semanticsMatchExpected($runtime));
    }

    /**
     * @param  null|\Closure():void  $afterFinalPreflight
     * @param  null|\Closure():void  $afterCapabilityPreflight
     */
    private function assertRollbackDropFails(?\Closure $afterFinalPreflight = null, ?\Closure $afterCapabilityPreflight = null): void
    {
        $migration = $this->migration();
        $rollback = new ReflectionMethod($migration, 'rollbackMysql');
        $rollback->setAccessible(true);
        $withInstallationLock = new ReflectionMethod($migration, 'withInstallationLock');
        $withInstallationLock->setAccessible(true);
        $connection = DB::connection();
        $lifecycleConnection = $this->database->connection('telegram_lifecycle');

        try {
            $withInstallationLock->invoke($migration, $lifecycleConnection, function () use (
                $rollback,
                $migration,
                $connection,
                $afterFinalPreflight,
                $afterCapabilityPreflight,
            ): void {
                $rollback->invoke(
                    $migration,
                    $connection,
                    $afterFinalPreflight,
                    $afterCapabilityPreflight,
                );
            });
            self::fail('A racing incoming foreign key must prevent dependency-sensitive rollback progress.');
        } catch (QueryException $exception) {
            self::assertContains((int) ($exception->errorInfo[1] ?? 0), [1217, 1451]);
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'Cannot resume Telegram outbound delivery rollback from an unattested partial rollback surface.',
                $exception->getMessage(),
            );
        }
    }

    private function assertDistinctDatabaseSessions(Connection $runtime, Connection $builder): void
    {
        $runtimeSession = $runtime->selectOne('SELECT CONNECTION_ID() AS connection_id', [], false);
        $builderSession = $builder->selectOne('SELECT CONNECTION_ID() AS connection_id', [], false);

        self::assertNotNull($runtimeSession);
        self::assertNotNull($builderSession);
        self::assertGreaterThan(0, (int) ($runtimeSession->connection_id ?? 0));
        self::assertGreaterThan(0, (int) ($builderSession->connection_id ?? 0));
        self::assertNotSame(
            (int) $runtimeSession->connection_id,
            (int) $builderSession->connection_id,
            'The rollback race actor must use an independent MariaDB session.',
        );
    }

    private function assertExactRequiredTriggers(): void
    {
        $actual = (new TelegramDeliveryDatabaseAuthoritySurfaceV1)
            ->presentRequiredTriggers(DB::connection());
        sort($actual, SORT_STRING);
        $expected = TelegramDeliveryDatabaseAuthoritySurfaceV1::REQUIRED_TRIGGERS;
        sort($expected, SORT_STRING);

        self::assertSame($expected, $actual);
    }

    private function safeDatabaseName(Connection $connection): string
    {
        $databaseName = $connection->getDatabaseName();
        if (preg_match('/\A[A-Za-z0-9_]+\z/', $databaseName) !== 1) {
            throw new RuntimeException('CI database identifier is not safe for rollback concurrency regression SQL.');
        }

        return $databaseName;
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

        config(['database.connections.telegram_fk_builder' => $config]);
        $this->database->purge('telegram_fk_builder');
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php');
    }
}
