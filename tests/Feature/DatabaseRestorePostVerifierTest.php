<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Operations\Application\Contracts\RestorePostRestoreVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** @requirement BAK-002 OPS-001 SEC-001 QUA-001 */
final class DatabaseRestorePostVerifierTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private refreshMariaDbDatabase;
    }

    public function refreshDatabase(): void
    {
        $connection = config('database.default');
        $driver = is_string($connection) ? config('database.connections.'.$connection.'.driver') : null;

        if ($driver !== 'mysql') {
            self::markTestSkipped('Restore post-verification requires the MariaDB integration runtime.');
        }

        $this->refreshMariaDbDatabase();
    }

    public function test_current_empty_mariadb_schema_passes_restore_integrity_and_reconciliation_checks(): void
    {
        $result = app(RestorePostRestoreVerifier::class)->verify();

        self::assertGreaterThan(0, $result['schema_migrations']);
        self::assertSame(0, $result['ledger_violations']);
        self::assertSame(0, $result['order_payment_violations']);
        self::assertSame(0, $result['service_violations']);
        self::assertTrue($result['runtime_health']);
    }
}
