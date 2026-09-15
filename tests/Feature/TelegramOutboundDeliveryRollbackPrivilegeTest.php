<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\TelegramDeliveryDatabaseAuthoritySurfaceV1;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/** @requirement ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 OPS-003 QUA-004 QUA-007 QUA-010 */
final class TelegramOutboundDeliveryRollbackPrivilegeTest extends TestCase
{
    use DatabaseTruncation;

    private DatabaseManager $database;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram outbound rollback privilege regression requires MariaDB/MySQL.');
        }

        $this->database = app(DatabaseManager::class);
        $this->configureRollbackNoLockConnection();
        $this->migration()->up();
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->purge('telegram_rollback_no_lock');
            $this->database->purge('telegram_metadata');
        }

        parent::tearDown();
    }

    public function test_missing_lock_tables_privilege_refuses_before_lifecycle_deactivation(): void
    {
        $runtime = DB::connection();
        $noLock = $this->database->connection('telegram_rollback_no_lock');
        $this->assertDistinctDatabaseSessions($runtime, $noLock);

        $grantRows = $noLock->select('SHOW GRANTS FOR CURRENT_USER', [], false);
        $grantText = implode("\n", array_map(
            static fn (object $row): string => implode(' ', array_map('strval', array_values((array) $row))),
            $grantRows,
        ));
        self::assertStringContainsString('SELECT', strtoupper($grantText));
        self::assertStringNotContainsString('LOCK TABLES', strtoupper($grantText));

        try {
            $this->invokeRollback($noLock);
            self::fail('Rollback must reject the exact DDL principal before lifecycle deactivation when LOCK TABLES is absent.');
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
