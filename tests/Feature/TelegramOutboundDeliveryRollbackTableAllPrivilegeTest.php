<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\TelegramDeliveryDatabaseAuthoritySurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryLifecycleDatabaseAuthority;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/** @requirement ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 OPS-003 QUA-004 QUA-007 QUA-010 */
final class TelegramOutboundDeliveryRollbackTableAllPrivilegeTest extends TestCase
{
    use DatabaseTruncation;

    private DatabaseManager $database;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram outbound rollback privilege-level regression requires MariaDB/MySQL.');
        }

        $this->database = app(DatabaseManager::class);
        $this->configureTestConnection(
            'telegram_rollback_table_all',
            'freedom_ci_rollback_table_all',
            'ci-only-rollback-table-all-password',
        );
        $this->configureTestConnection(
            'telegram_test_grant_admin',
            'freedom_ci_test_grant_admin',
            'ci-only-test-grant-admin-password',
        );
        $this->migration()->up();
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->purge('telegram_rollback_table_all');
            $this->database->purge('telegram_test_grant_admin');
            $this->database->purge('telegram_metadata');
        }

        parent::tearDown();
    }

    public function test_table_level_all_privileges_never_fabricates_lock_tables_before_deactivation(): void
    {
        $runtime = DB::connection();
        $grantAdmin = $this->database->connection('telegram_test_grant_admin');
        $tableAll = $this->database->connection('telegram_rollback_table_all');
        $this->assertDistinctDatabaseSessions($runtime, $grantAdmin, $tableAll);

        $grantAdmin->statement(
            "GRANT ALL PRIVILEGES ON `freedom_platform_ci`.`telegram_delivery_operations` TO 'freedom_ci_rollback_table_all'@'%'",
        );
        $grantAdmin->statement(
            "GRANT ALL PRIVILEGES ON `freedom_platform_ci`.`telegram_delivery_authority_capability` TO 'freedom_ci_rollback_table_all'@'%'",
        );

        $grants = $this->grantStrings($tableAll);
        $grantText = strtoupper(implode("\n", $grants));
        self::assertStringContainsString('ALL PRIVILEGES ON', $grantText);
        self::assertStringContainsString('TELEGRAM_DELIVERY_OPERATIONS', $grantText);
        self::assertStringContainsString('TELEGRAM_DELIVERY_AUTHORITY_CAPABILITY', $grantText);
        self::assertStringNotContainsString('LOCK TABLES', $grantText);
        self::assertFalse((new TelegramDeliveryLifecycleDatabaseAuthority)->grantSetCanUseReferenceFenceLocks(
            $grants,
            'freedom_platform_ci',
        ));

        try {
            $this->invokeRollback($tableAll);
            self::fail('Rollback must reject table-level ALL PRIVILEGES before lifecycle deactivation when database-level LOCK TABLES is absent.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Telegram delivery rollback reference fence requires effective SELECT and LOCK TABLES authority on both authority tables before lifecycle deactivation.',
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

        $this->migration()->down();
        self::assertFalse(Schema::hasTable('telegram_delivery_operations'));
        self::assertFalse(Schema::hasTable('telegram_delivery_authority_capability'));

        $this->migration()->up();
        self::assertTrue((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->semanticsMatchExpected($runtime));
    }

    /** @return list<string> */
    private function grantStrings(Connection $connection): array
    {
        $rows = $connection->select('SHOW GRANTS FOR CURRENT_USER', [], false);
        $grants = [];
        foreach ($rows as $row) {
            $values = array_values((array) $row);
            self::assertCount(1, $values);
            self::assertIsString($values[0]);
            $grants[] = $values[0];
        }

        return $grants;
    }

    private function invokeRollback(Connection $connection): void
    {
        $migration = $this->migration();
        $rollback = new ReflectionMethod($migration, 'rollbackMysql');
        $rollback->setAccessible(true);
        $withInstallationLock = new ReflectionMethod($migration, 'withInstallationLock');
        $withInstallationLock->setAccessible(true);
        $lifecycleConnection = $this->database->connection('telegram_lifecycle');

        $withInstallationLock->invoke($migration, $lifecycleConnection, function () use (
            $rollback,
            $migration,
            $connection,
        ): void {
            $rollback->invoke($migration, $connection);
        });
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

    private function assertExactRequiredTriggers(): void
    {
        $actual = (new TelegramDeliveryDatabaseAuthoritySurfaceV1)
            ->presentRequiredTriggers(DB::connection());
        sort($actual, SORT_STRING);
        $expected = TelegramDeliveryDatabaseAuthoritySurfaceV1::REQUIRED_TRIGGERS;
        sort($expected, SORT_STRING);

        self::assertSame($expected, $actual);
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

        self::assertCount(count($ids), array_unique($ids), 'Rollback privilege actors must use independent MariaDB sessions.');
    }

    private function configureTestConnection(string $name, string $username, string $password): void
    {
        $default = config('database.default');
        self::assertIsString($default);
        $config = config('database.connections.'.$default);
        self::assertIsArray($config);

        $config['username'] = $username;
        $config['password'] = $password;
        $config['url'] = null;

        config(['database.connections.'.$name => $config]);
        $this->database->purge($name);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php');
    }
}
