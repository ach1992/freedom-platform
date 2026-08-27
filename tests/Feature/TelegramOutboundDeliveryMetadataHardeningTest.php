<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\TelegramDeliveryDatabaseAuthoritySurfaceV1;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** @requirement ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 OPS-003 QUA-004 QUA-007 QUA-010 */
final class TelegramOutboundDeliveryMetadataHardeningTest extends TestCase
{
    use DatabaseTruncation;

    private DatabaseManager $database;

    /** @var array<string,mixed> */
    private array $originalMetadataConfig;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram outbound metadata hardening requires MariaDB/MySQL.');
        }

        $this->database = app(DatabaseManager::class);
        $metadata = config('database.connections.telegram_metadata');
        self::assertIsArray($metadata);
        $this->originalMetadataConfig = $metadata;

        $migration = require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php');
        $migration->up();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->database)) {
                config(['database.connections.telegram_metadata' => $this->originalMetadataConfig]);
                $this->database->purge('telegram_metadata');
                $this->database->purge('telegram_metadata_shadow');
            }

            if (Schema::hasTable('telegram_metadata_definer_probe_effect')) {
                Schema::drop('telegram_metadata_definer_probe_effect');
            }

            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    public function test_wrong_server_is_rejected_even_when_legacy_identity_tuple_collides(): void
    {
        $runtime = DB::connection();
        $shadow = $this->configureShadowMetadataConnection();

        $runtimeIdentity = $this->legacyAndUniqueIdentity($runtime);
        $shadowIdentity = $this->legacyAndUniqueIdentity($shadow);

        self::assertSame($runtimeIdentity['hostname'], $shadowIdentity['hostname']);
        self::assertSame($runtimeIdentity['port'], $shadowIdentity['port']);
        self::assertSame($runtimeIdentity['server_id'], $shadowIdentity['server_id']);
        self::assertSame($runtimeIdentity['version'], $shadowIdentity['version']);
        self::assertNotSame('', $runtimeIdentity['server_uid']);
        self::assertNotSame('', $shadowIdentity['server_uid']);
        self::assertNotSame($runtimeIdentity['server_uid'], $shadowIdentity['server_uid']);

        config(['database.connections.telegram_metadata' => config('database.connections.telegram_metadata_shadow')]);
        $this->database->purge('telegram_metadata');

        self::assertFalse((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->semanticsMatchExpected($runtime));
    }

    public function test_process_plus_definer_routine_execute_is_rejected_as_effective_application_authority(): void
    {
        $runtime = DB::connection();
        $routine = $this->originalMetadataConfig;
        $routine['username'] = 'freedom_ci_metadata_routine';
        $routine['password'] = 'ci-only-metadata-routine-password';
        $routine['url'] = null;
        config(['database.connections.telegram_metadata' => $routine]);
        $this->database->purge('telegram_metadata');

        self::assertFalse((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->semanticsMatchExpected($runtime));

        $metadata = $this->database->connection('telegram_metadata');
        $metadata->statement('CALL `freedom_platform_ci`.`telegram_metadata_definer_probe`()');
        self::assertTrue(Schema::hasTable('telegram_metadata_definer_probe_effect'));

        Schema::drop('telegram_metadata_definer_probe_effect');
        self::assertFalse(Schema::hasTable('telegram_metadata_definer_probe_effect'));
    }

    private function configureShadowMetadataConnection(): Connection
    {
        $port = getenv('MARIADB_SHADOW_PORT');
        self::assertIsString($port);
        self::assertMatchesRegularExpression('/^[0-9]{2,5}$/', $port);

        $config = $this->originalMetadataConfig;
        $config['host'] = '127.0.0.1';
        $config['port'] = $port;
        $config['database'] = 'information_schema';
        $config['username'] = 'freedom_ci_metadata';
        $config['password'] = 'ci-only-metadata-password';
        $config['url'] = null;

        config(['database.connections.telegram_metadata_shadow' => $config]);
        $this->database->purge('telegram_metadata_shadow');

        return $this->database->connection('telegram_metadata_shadow');
    }

    /** @return array{hostname:string,port:int,server_id:int,version:string,server_uid:string} */
    private function legacyAndUniqueIdentity(Connection $connection): array
    {
        $row = $connection->selectOne(<<<'SQL'
SELECT
    @@hostname AS hostname,
    @@port AS port,
    @@server_id AS server_id,
    VERSION() AS version,
    @@server_uid AS server_uid
SQL, [], false);
        self::assertNotNull($row);

        return [
            'hostname' => (string) ($row->hostname ?? ''),
            'port' => (int) ($row->port ?? 0),
            'server_id' => (int) ($row->server_id ?? 0),
            'version' => (string) ($row->version ?? ''),
            'server_uid' => trim((string) ($row->server_uid ?? '')),
        ];
    }
}
