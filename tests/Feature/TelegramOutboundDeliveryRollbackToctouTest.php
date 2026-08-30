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
use Throwable;

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
        $this->configureDdlAttackerConnection();
        $this->configureRollbackNoLockConnection();

        $this->migration()->up();
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
                    } catch (Throwable) {
                        // Best-effort isolation cleanup must not hide the original result.
                    }
                }

                $this->database->purge('telegram_fk_builder');
                $this->database->purge('telegram_ddl_attacker');
                $this->database->purge('telegram_rollback_no_lock');
                $this->database->purge('telegram_metadata');
            }

            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    public function test_operation_reference_fence_excludes_incoming_fk_and_parent_index_ddl_until_drop(): void
    {
        $runtime = DB::connection();
        $builder = $this->database->connection('telegram_fk_builder');
        $attacker = $this->database->connection('telegram_ddl_attacker');
        $databaseName = $this->safeDatabaseName($runtime);

        $this->assertDistinctDatabaseSessions($runtime, $builder, $attacker);

        $this->invokeRollback(afterFinalPreflight: function () use ($runtime, $builder, $attacker, $databaseName): void {
            $this->assertNoReferenceIndexes($runtime, 'telegram_delivery_operations');
            $this->assertIncomingForeignKeyRejected(
                $builder,
                $databaseName,
                'telegram_delivery_operations',
                'public_id',
                'CHAR(26) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin',
                'telegram_delivery_rollback_operation_fk_probe',
                'operation_public_id',
            );
            $this->assertParentIndexDdlExcluded(
                $attacker,
                $databaseName,
                'telegram_delivery_operations',
                'public_id',
                'rollback_operation_attacker_idx',
            );
        });

        self::assertFalse(Schema::hasTable('telegram_delivery_operations'));
        self::assertFalse(Schema::hasTable('telegram_delivery_authority_capability'));
        self::assertSame([], (new TelegramDeliveryDatabaseAuthoritySurfaceV1)->presentRequiredTriggers($runtime));

        $this->migration()->up();
        self::assertTrue((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->semanticsMatchExpected($runtime));
    }

    public function test_capability_reference_fence_excludes_incoming_fk_and_parent_index_ddl_until_drop(): void
    {
        $runtime = DB::connection();
        $builder = $this->database->connection('telegram_fk_builder');
        $attacker = $this->database->connection('telegram_ddl_attacker');
        $databaseName = $this->safeDatabaseName($runtime);

        $this->assertDistinctDatabaseSessions($runtime, $builder, $attacker);

        $this->invokeRollback(afterCapabilityPreflight: function () use ($runtime, $builder, $attacker, $databaseName): void {
            self::assertFalse(Schema::hasTable('telegram_delivery_operations'));
            $this->assertNoReferenceIndexes($runtime, 'telegram_delivery_authority_capability');
            $this->assertIncomingForeignKeyRejected(
                $builder,
                $databaseName,
                'telegram_delivery_authority_capability',
                'id',
                'TINYINT UNSIGNED',
                'telegram_delivery_rollback_capability_fk_probe',
                'capability_id',
            );
            $this->assertParentIndexDdlExcluded(
                $attacker,
                $databaseName,
                'telegram_delivery_authority_capability',
                'id',
                'rollback_capability_attacker_idx',
            );
        });

        self::assertFalse(Schema::hasTable('telegram_delivery_operations'));
        self::assertFalse(Schema::hasTable('telegram_delivery_authority_capability'));
        self::assertSame([], (new TelegramDeliveryDatabaseAuthoritySurfaceV1)->presentRequiredTriggers($runtime));

        $this->migration()->up();
        self::assertTrue((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->semanticsMatchExpected($runtime));
    }

    public function test_reference_fence_refuses_before_mutation_when_innodb_table_locks_is_disabled(): void
    {
        $runtime = DB::connection();
        $locking = $runtime->selectOne('SELECT @@SESSION.innodb_table_locks AS innodb_table_locks', [], false);
        self::assertNotNull($locking);
        self::assertSame(1, (int) ($locking->innodb_table_locks ?? -1));

        $runtime->statement('SET SESSION innodb_table_locks = 0');
        try {
            try {
                $this->invokeRollback();
                self::fail('Rollback must reject a session whose InnoDB table-lock prerequisite is disabled.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'Telegram delivery rollback reference fence requires @@SESSION.innodb_table_locks = 1.',
                    $exception->getMessage(),
                );
            }

            self::assertTrue(Schema::hasTable('telegram_delivery_operations'));
            self::assertTrue(Schema::hasTable('telegram_delivery_authority_capability'));
            $this->assertReferenceIndexesPresent($runtime, 'telegram_delivery_operations');
            $this->assertReferenceIndexesPresent($runtime, 'telegram_delivery_authority_capability');
            $this->assertExactRequiredTriggers();

            $capability = DB::table('telegram_delivery_authority_capability')->where('id', 1)->first();
            self::assertNotNull($capability);
            self::assertSame(1, (int) $capability->schema_version);
            self::assertNotNull($capability->activated_at);
        } finally {
            $runtime->statement('SET SESSION innodb_table_locks = 1');
        }

        $this->migration()->down();
        self::assertFalse(Schema::hasTable('telegram_delivery_operations'));
        self::assertFalse(Schema::hasTable('telegram_delivery_authority_capability'));

        $this->migration()->up();
        self::assertTrue((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->semanticsMatchExpected($runtime));
    }

    public function test_reference_fence_refuses_before_deactivation_when_lock_tables_privilege_is_missing(): void
    {
        $runtime = DB::connection();
        $noLock = $this->database->connection('telegram_rollback_no_lock');
        $this->assertDistinctDatabaseSessions($runtime, $noLock);

        $grantRows = $noLock->select('SHOW GRANTS FOR CURRENT_USER', [], false);
        $grantText = implode("\n", array_map(
            static fn (object $row): string => implode(' ', array_map('strval', array_values((array) $row))),
            $grantRows,
        ));
        self::assertStringNotContainsString('LOCK TABLES', strtoupper($grantText));

        try {
            $this->invokeRollback(connection: $noLock);
            self::fail('Rollback must reject an exact DDL session that cannot acquire the required WRITE locks.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Telegram delivery rollback reference fence could not prove effective LOCK TABLES and SELECT authority before lifecycle deactivation.',
                $exception->getMessage(),
            );
            self::assertInstanceOf(QueryException::class, $exception->getPrevious());
            self::assertContains((int) ($exception->getPrevious()->errorInfo[1] ?? 0), [1044, 1142]);
        }

        self::assertTrue(Schema::hasTable('telegram_delivery_operations'));
        self::assertTrue(Schema::hasTable('telegram_delivery_authority_capability'));
        $this->assertReferenceIndexesPresent($runtime, 'telegram_delivery_operations');
        $this->assertReferenceIndexesPresent($runtime, 'telegram_delivery_authority_capability');
        $this->assertExactRequiredTriggers();

        $capability = DB::table('telegram_delivery_authority_capability')->where('id', 1)->first();
        self::assertNotNull($capability);
        self::assertSame(1, (int) $capability->schema_version);
        self::assertNotNull($capability->activated_at);

        $autocommit = $noLock->selectOne('SELECT @@SESSION.autocommit AS autocommit', [], false);
        self::assertNotNull($autocommit);
        self::assertSame(1, (int) ($autocommit->autocommit ?? -1));

        $this->migration()->down();
        self::assertFalse(Schema::hasTable('telegram_delivery_operations'));
        self::assertFalse(Schema::hasTable('telegram_delivery_authority_capability'));

        $this->migration()->up();
        self::assertTrue((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->semanticsMatchExpected($runtime));
    }

    public function test_operation_reference_fence_interruption_is_guarded_and_down_resumes_safely(): void
    {
        $runtime = DB::connection();

        try {
            $this->invokeRollback(afterFinalPreflight: function () use ($runtime): void {
                $this->assertNoReferenceIndexes($runtime, 'telegram_delivery_operations');
                throw new RuntimeException('operation-reference-fence-interruption');
            });
            self::fail('The deterministic interruption seam must abort after the operation reference fence.');
        } catch (RuntimeException $exception) {
            self::assertSame('operation-reference-fence-interruption', $exception->getMessage());
        }

        self::assertTrue(Schema::hasTable('telegram_delivery_operations'));
        self::assertTrue(Schema::hasTable('telegram_delivery_authority_capability'));
        $this->assertNoReferenceIndexes($runtime, 'telegram_delivery_operations');
        $this->assertExactRequiredTriggers();
        $this->assertInactiveCapability();

        $this->migration()->down();

        self::assertFalse(Schema::hasTable('telegram_delivery_operations'));
        self::assertFalse(Schema::hasTable('telegram_delivery_authority_capability'));
        self::assertSame([], (new TelegramDeliveryDatabaseAuthoritySurfaceV1)->presentRequiredTriggers($runtime));

        $this->migration()->up();
        self::assertTrue((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->semanticsMatchExpected($runtime));
    }

    public function test_capability_reference_fence_interruption_is_guarded_and_up_reenters_safely(): void
    {
        $runtime = DB::connection();

        try {
            $this->invokeRollback(afterCapabilityPreflight: function () use ($runtime): void {
                self::assertFalse(Schema::hasTable('telegram_delivery_operations'));
                $this->assertNoReferenceIndexes($runtime, 'telegram_delivery_authority_capability');
                throw new RuntimeException('capability-reference-fence-interruption');
            });
            self::fail('The deterministic interruption seam must abort after the capability reference fence.');
        } catch (RuntimeException $exception) {
            self::assertSame('capability-reference-fence-interruption', $exception->getMessage());
        }

        self::assertFalse(Schema::hasTable('telegram_delivery_operations'));
        self::assertTrue(Schema::hasTable('telegram_delivery_authority_capability'));
        $this->assertNoReferenceIndexes($runtime, 'telegram_delivery_authority_capability');
        $this->assertInactiveCapability();

        $this->migration()->up();

        self::assertTrue(Schema::hasTable('telegram_delivery_operations'));
        self::assertTrue(Schema::hasTable('telegram_delivery_authority_capability'));
        self::assertTrue((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->semanticsMatchExpected($runtime));
        $reactivated = DB::table('telegram_delivery_authority_capability')->where('id', 1)->first();
        self::assertNotNull($reactivated);
        self::assertSame(1, (int) $reactivated->schema_version);
        self::assertNotNull($reactivated->activated_at);
    }

    /**
     * @param  null|\Closure():void  $afterFinalPreflight
     * @param  null|\Closure():void  $afterCapabilityPreflight
     */
    private function invokeRollback(
        ?\Closure $afterFinalPreflight = null,
        ?\Closure $afterCapabilityPreflight = null,
        ?Connection $connection = null,
    ): void {
        $migration = $this->migration();
        $rollback = new ReflectionMethod($migration, 'rollbackMysql');
        $rollback->setAccessible(true);
        $withInstallationLock = new ReflectionMethod($migration, 'withInstallationLock');
        $withInstallationLock->setAccessible(true);
        $connection ??= DB::connection();
        $lifecycleConnection = $this->database->connection('telegram_lifecycle');

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
    }

    private function assertIncomingForeignKeyRejected(
        Connection $builder,
        string $databaseName,
        string $parentTable,
        string $parentColumn,
        string $childColumnType,
        string $childTable,
        string $childColumn,
    ): void {
        $builder->statement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $sql = sprintf(<<<'SQL'
CREATE TABLE `%s` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `%s` %s NOT NULL,
    PRIMARY KEY (`id`),
    KEY `rollback_fk_idx` (`%s`),
    CONSTRAINT `rollback_fk_probe`
        FOREIGN KEY (`%s`)
        REFERENCES `%s`.`%s` (`%s`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL,
                $childTable,
                $childColumn,
                $childColumnType,
                $childColumn,
                $childColumn,
                $databaseName,
                $parentTable,
                $parentColumn,
            );

            try {
                $builder->statement($sql);
                self::fail('The reference-fenced parent must reject a new incoming foreign key.');
            } catch (QueryException $exception) {
                self::assertContains((int) ($exception->errorInfo[1] ?? 0), [1005, 1215, 1822]);
            }
        } finally {
            $builder->statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function assertParentIndexDdlExcluded(
        Connection $attacker,
        string $databaseName,
        string $table,
        string $column,
        string $index,
    ): void {
        $attacker->statement('SET SESSION lock_wait_timeout = 1');
        try {
            $attacker->statement(sprintf(
                'ALTER TABLE `%s`.`%s` ADD INDEX `%s` (`%s`)',
                $databaseName,
                $table,
                $index,
                $column,
            ));
            self::fail('The parent WRITE lock must exclude competing parent index DDL until destructive DROP.');
        } catch (QueryException $exception) {
            self::assertSame(1205, (int) ($exception->errorInfo[1] ?? 0));
        }
    }

    private function assertNoReferenceIndexes(Connection $connection, string $table): void
    {
        $rows = $connection->select('SHOW INDEX FROM `'.$table.'`', [], false);
        self::assertSame([], $rows, 'The rollback reference fence must remove every parent reference index.');
    }

    private function assertReferenceIndexesPresent(Connection $connection, string $table): void
    {
        $rows = $connection->select('SHOW INDEX FROM `'.$table.'`', [], false);
        $actual = [];
        foreach ($rows as $row) {
            $name = (string) ($row->Key_name ?? '');
            if ($name !== '') {
                $actual[$name] = true;
            }
        }

        $actual = array_keys($actual);
        sort($actual, SORT_STRING);
        $expected = $table === 'telegram_delivery_operations'
            ? [
                'PRIMARY',
                'telegram_delivery_operations_outbox_unique',
                'telegram_delivery_operations_public_unique',
                'telegram_delivery_operations_request_unique',
                'telegram_delivery_operations_state_idx',
            ]
            : ['PRIMARY'];
        sort($expected, SORT_STRING);

        self::assertSame($expected, $actual, 'Unsupported rollback must not strip parent reference indexes.');
    }

    private function assertInactiveCapability(): void
    {
        $capability = DB::table('telegram_delivery_authority_capability')->where('id', 1)->first();
        self::assertNotNull($capability);
        self::assertSame(0, (int) $capability->schema_version);
        self::assertNull($capability->activated_at);
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

    private function configureDdlAttackerConnection(): void
    {
        $default = config('database.default');
        self::assertIsString($default);
        $config = config('database.connections.'.$default);
        self::assertIsArray($config);
        $config['url'] = null;

        config(['database.connections.telegram_ddl_attacker' => $config]);
        $this->database->purge('telegram_ddl_attacker');
    }

    private function configureRollbackNoLockConnection(): void
    {
        $default = config('database.default');
        self::assertIsString($default);
        $config = config('database.connections.'.$default);
        self::assertIsArray($config);

        $config['username'] = 'freedom_ci_rollback_no_lock';
        $config['password'] = 'ci-only-rollback-no-lock-password';
        $config['url'] = null;

        config(['database.connections.telegram_rollback_no_lock' => $config]);
        $this->database->purge('telegram_rollback_no_lock');
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php');
    }
}
