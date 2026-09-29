<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Operations\Application\Contracts\RestoreCriticalAuthorityIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** @requirement BAK-002 OPS-001 OPS-003 SEC-001 QUA-001 */
final class RestoreRuntimeAuthorityContinuityTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private refreshMariaDbDatabase;
    }

    public function refreshDatabase(): void
    {
        $connection = config('database.default');
        $driver = is_string($connection) ? config('database.connections.'.$connection.'.driver') : null;

        if ($driver !== 'mysql') {
            self::markTestSkipped('Restore authority continuity requires the MariaDB integration runtime.');
        }

        $this->refreshMariaDbDatabase();
    }

    public function test_fresh_runtime_attestation_accepts_the_exact_current_critical_authority(): void
    {
        $fingerprint = app(RestoreCriticalAuthorityIdentity::class)->fingerprint();

        $this->artisan('operations:restore-runtime-attest', [
            '--expected-authority-fingerprint' => $fingerprint,
            '--json' => true,
        ])->assertSuccessful();
    }

    public function test_runtime_attestation_rejects_redis_or_maintenance_authority_drift(): void
    {
        $identity = app(RestoreCriticalAuthorityIdentity::class);
        $expected = $identity->fingerprint();

        config([
            'database.redis.default.database' => '9',
            'app.maintenance.driver' => 'cache',
            'app.maintenance.store' => 'redis',
        ]);

        $this->artisan('operations:restore-runtime-attest', [
            '--expected-authority-fingerprint' => $expected,
            '--json' => true,
        ])->assertFailed();
    }
}
