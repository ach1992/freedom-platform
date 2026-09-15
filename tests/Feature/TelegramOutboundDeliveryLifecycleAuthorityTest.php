<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\TelegramDeliveryLifecycleDatabaseAuthority;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** @requirement ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 OPS-003 QUA-004 QUA-007 QUA-010 */
final class TelegramOutboundDeliveryLifecycleAuthorityTest extends TestCase
{
    use DatabaseTruncation;

    private DatabaseManager $database;

    /** @var array<string,mixed> */
    private array $originalLifecycleConfig;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram lifecycle authority requires MariaDB/MySQL.');
        }

        $this->database = app(DatabaseManager::class);
        $original = config('database.connections.telegram_lifecycle');
        self::assertIsArray($original);
        $this->originalLifecycleConfig = $original;
        $this->migration()->up();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->database, $this->originalLifecycleConfig)) {
                config(['database.connections.telegram_lifecycle' => $this->originalLifecycleConfig]);
                $this->database->purge('telegram_lifecycle');
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_lifecycle_principal_is_select_update_only_and_distinct_from_runtime_and_metadata(): void
    {
        $runtime = DB::connection();
        $lifecycle = $this->database->connection('telegram_lifecycle');
        $authority = new TelegramDeliveryLifecycleDatabaseAuthority($this->database);

        self::assertTrue($authority->connectionBoundaryMatchesExpected($runtime));
        self::assertSame('telegram_lifecycle', $authority->principalUsername($lifecycle));

        $capability = $lifecycle->selectOne('SELECT id FROM telegram_delivery_authority_capability LIMIT 1', [], false);
        self::assertNotNull($capability);
        self::assertSame(1, (int) ($capability->id ?? 0));

        foreach ([
            "INSERT INTO telegram_delivery_authority_capability (id, capability_hash, schema_version, activated_at, created_at) VALUES (2, REPEAT('a', 64), 0, NULL, UTC_TIMESTAMP(6))",
            'DELETE FROM telegram_delivery_authority_capability WHERE id = 1',
            'ALTER TABLE telegram_delivery_authority_capability ADD COLUMN lifecycle_forbidden_probe INT NULL',
        ] as $sql) {
            try {
                $lifecycle->statement($sql);
                self::fail('Lifecycle credentials exceeded the accepted SELECT/UPDATE-only boundary.');
            } catch (QueryException $exception) {
                self::assertContains((int) ($exception->errorInfo[1] ?? 0), [1142, 1143]);
            }
        }
    }

    public function test_lifecycle_boundary_rejects_runtime_underprivileged_overprivileged_and_wrong_server_connections(): void
    {
        $runtime = DB::connection();
        $authority = new TelegramDeliveryLifecycleDatabaseAuthority($this->database);

        $cases = [
            'runtime' => config('database.connections.'.config('database.default')),
            'unprivileged' => array_replace($this->originalLifecycleConfig, [
                'username' => 'freedom_ci_lifecycle_unprivileged',
                'password' => 'ci-only-lifecycle-unprivileged-password',
            ]),
            'broad' => array_replace($this->originalLifecycleConfig, [
                'username' => 'freedom_ci_lifecycle_broad',
                'password' => 'ci-only-lifecycle-broad-password',
            ]),
            'shadow' => array_replace($this->originalLifecycleConfig, [
                'port' => (int) (getenv('MARIADB_SHADOW_PORT') ?: 33068),
            ]),
        ];

        foreach ($cases as $name => $config) {
            self::assertIsArray($config);
            config(['database.connections.telegram_lifecycle' => $config]);
            $this->database->purge('telegram_lifecycle');
            self::assertFalse(
                $authority->connectionBoundaryMatchesExpected($runtime),
                'Lifecycle boundary unexpectedly accepted case: '.$name,
            );
        }

        config(['database.connections.telegram_lifecycle' => $this->originalLifecycleConfig]);
        $this->database->purge('telegram_lifecycle');
        self::assertTrue($authority->connectionBoundaryMatchesExpected($runtime));
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php');
    }
}
