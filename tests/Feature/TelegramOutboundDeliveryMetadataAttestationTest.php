<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\TelegramDeliveryDatabaseAuthoritySurfaceV1;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** @requirement ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 OPS-003 QUA-004 QUA-007 QUA-010 */
final class TelegramOutboundDeliveryMetadataAttestationTest extends TestCase
{
    use DatabaseTruncation;

    private DatabaseManager $database;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram outbound metadata attestation requires MariaDB/MySQL.');
        }

        $this->database = app(DatabaseManager::class);
        $this->configureForeignKeyBuilderConnection();

        $migration = require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php');
        $migration->up();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->database)) {
                try {
                    $this->database->connection('telegram_fk_builder')
                        ->statement('DROP TABLE IF EXISTS telegram_delivery_hidden_fk_probe');
                } catch (\Throwable) {
                    // Best-effort isolation cleanup must not hide the original test result.
                }

                $this->database->purge('telegram_fk_builder');
                $this->database->purge('telegram_metadata');
            }

            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    public function test_hidden_cross_schema_incoming_foreign_key_is_detected_by_global_inventory(): void
    {
        $runtime = DB::connection();
        $surface = new TelegramDeliveryDatabaseAuthoritySurfaceV1;
        self::assertTrue($surface->semanticsMatchExpected($runtime));

        $databaseName = $runtime->getDatabaseName();
        if (preg_match('/\A[A-Za-z0-9_]+\z/', $databaseName) !== 1) {
            throw new RuntimeException('CI database identifier is not safe for cross-schema attestation regression SQL.');
        }

        $builder = $this->database->connection('telegram_fk_builder');
        $builder->statement('DROP TABLE IF EXISTS telegram_delivery_hidden_fk_probe');
        $builder->statement(sprintf(<<<'SQL'
CREATE TABLE telegram_delivery_hidden_fk_probe (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    operation_public_id CHAR(26) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    PRIMARY KEY (id),
    KEY telegram_delivery_hidden_fk_operation_idx (operation_public_id),
    CONSTRAINT telegram_delivery_hidden_fk_operation_fk
        FOREIGN KEY (operation_public_id)
        REFERENCES `%s`.`telegram_delivery_operations` (`public_id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
SQL, $databaseName));

        self::assertFalse($runtime->table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', 'freedom_platform_hidden_fk')
            ->where('TABLE_NAME', 'telegram_delivery_hidden_fk_probe')
            ->where('REFERENCED_TABLE_SCHEMA', $databaseName)
            ->where('REFERENCED_TABLE_NAME', 'telegram_delivery_operations')
            ->exists());

        self::assertFalse($surface->semanticsMatchExpected($runtime));

        $builder->statement('DROP TABLE telegram_delivery_hidden_fk_probe');
        self::assertTrue($surface->semanticsMatchExpected($runtime));
    }

    public function test_metadata_attestation_fails_closed_when_dedicated_principal_lacks_process(): void
    {
        $surface = new TelegramDeliveryDatabaseAuthoritySurfaceV1;
        $runtime = DB::connection();
        self::assertTrue($surface->semanticsMatchExpected($runtime));

        $original = config('database.connections.telegram_metadata');
        self::assertIsArray($original);
        $underPrivileged = $original;
        $underPrivileged['username'] = 'freedom_ci_metadata_unprivileged';
        $underPrivileged['password'] = 'ci-only-metadata-unprivileged-password';
        config(['database.connections.telegram_metadata' => $underPrivileged]);
        $this->database->purge('telegram_metadata');

        try {
            self::assertFalse($surface->semanticsMatchExpected($runtime));
        } finally {
            config(['database.connections.telegram_metadata' => $original]);
            $this->database->purge('telegram_metadata');
        }

        self::assertTrue($surface->semanticsMatchExpected($runtime));
    }

    public function test_metadata_attestation_rejects_process_principal_with_extra_application_privileges(): void
    {
        $surface = new TelegramDeliveryDatabaseAuthoritySurfaceV1;
        $runtime = DB::connection();
        self::assertTrue($surface->semanticsMatchExpected($runtime));

        $original = config('database.connections.telegram_metadata');
        self::assertIsArray($original);
        $broad = $original;
        $broad['username'] = 'freedom_ci_metadata_broad';
        $broad['password'] = 'ci-only-metadata-broad-password';
        config(['database.connections.telegram_metadata' => $broad]);
        $this->database->purge('telegram_metadata');

        try {
            self::assertFalse($surface->semanticsMatchExpected($runtime));
        } finally {
            config(['database.connections.telegram_metadata' => $original]);
            $this->database->purge('telegram_metadata');
        }

        self::assertTrue($surface->semanticsMatchExpected($runtime));
    }

    public function test_metadata_principal_is_distinct_process_only_and_cannot_read_application_tables(): void
    {
        $runtime = DB::connection();
        $metadata = $this->database->connection('telegram_metadata');

        $runtimePrincipal = $runtime->selectOne('SELECT CURRENT_USER() AS principal', [], false);
        $metadataPrincipal = $metadata->selectOne('SELECT CURRENT_USER() AS principal', [], false);
        self::assertNotNull($runtimePrincipal);
        self::assertNotNull($metadataPrincipal);
        self::assertNotSame((string) $runtimePrincipal->principal, (string) $metadataPrincipal->principal);

        try {
            $runtime->selectOne('SELECT ID FROM information_schema.INNODB_SYS_FOREIGN LIMIT 1', [], false);
            self::fail('The ordinary runtime database principal must not hold PROCESS.');
        } catch (QueryException $exception) {
            self::assertSame(1227, (int) ($exception->errorInfo[1] ?? 0));
        }

        self::assertNotNull($metadata->selectOne(
            'SELECT COUNT(*) AS foreign_key_count FROM information_schema.INNODB_SYS_FOREIGN',
            [],
            false,
        ));

        $databaseName = $runtime->getDatabaseName();
        if (preg_match('/\A[A-Za-z0-9_]+\z/', $databaseName) !== 1) {
            throw new RuntimeException('CI database identifier is not safe for metadata least-privilege regression SQL.');
        }

        try {
            $metadata->selectOne(sprintf(
                'SELECT COUNT(*) AS row_count FROM `%s`.`telegram_delivery_operations`',
                $databaseName,
            ), [], false);
            self::fail('The metadata-attestation principal must not read application tables.');
        } catch (QueryException $exception) {
            self::assertSame(1142, (int) ($exception->errorInfo[1] ?? 0));
        }
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
}
