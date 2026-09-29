<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Application\UpdateMigrationIdentity;
use App\Modules\Operations\Infrastructure\DatabaseUpdateSafetyInspector;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Collection;
use RuntimeException;
use Tests\TestCase;

final class DatabaseUpdateSafetyInspectorTest extends TestCase
{
    /** @requirement UPD-001 PAY-003 PRV-003 QUA-001 */
    public function test_concrete_inspector_refuses_each_unresolved_financial_provider_authority(): void
    {
        foreach ([
            'purchase_provider_mutation_attempts' => 'Unsafe purchase-provider mutation work is unresolved.',
            'provisioning_operations' => 'Unsafe provisioning/provider work is active or unresolved.',
            'service_sync_runs' => 'Unsafe provider synchronization work is active.',
        ] as $unsafeTable => $message) {
            $inspector = new DatabaseUpdateSafetyInspector(
                $this->databaseForUnsafeTable($unsafeTable),
                base_path('database/migrations'),
                base_path('deploy/supervisor/freedom-platform.conf'),
            );

            try {
                $inspector->assertNoUnsafeWork();
                self::fail('An unresolved financial/provider authority must fail update preflight.');
            } catch (RuntimeException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    /** @requirement UPD-001 PAY-003 PRV-003 QUA-001 */
    public function test_concrete_inspector_allows_absent_or_resolved_financial_provider_work(): void
    {
        $inspector = new DatabaseUpdateSafetyInspector(
            $this->databaseForUnsafeTable(null),
            base_path('database/migrations'),
            base_path('deploy/supervisor/freedom-platform.conf'),
        );

        $inspector->assertNoUnsafeWork();
        self::addToAssertionCount(1);
    }

    /** @requirement UPD-001 RUN-006 QUA-001 */
    public function test_current_schema_identity_uses_exact_migration_authority(): void
    {
        $schema = $this->createStub(SchemaBuilder::class);
        $schema->method('hasTable')->willReturn(true);
        $query = $this->createStub(QueryBuilder::class);
        $query->method('pluck')->willReturn(new Collection([
            '2026_01_02_000000_second',
            '2026_01_01_000000_first',
        ]));
        $connection = $this->createStub(Connection::class);
        $connection->method('getSchemaBuilder')->willReturn($schema);
        $connection->method('table')->willReturn($query);
        $database = $this->createStub(DatabaseManager::class);
        $database->method('connection')->willReturn($connection);

        $migrations = storage_path('framework/testing/update-current-schema-'.bin2hex(random_bytes(4)));
        mkdir($migrations, 0700, true);
        try {
            file_put_contents($migrations.'/2026_01_01_000000_first.php', "<?php\n");
            file_put_contents($migrations.'/2026_01_02_000000_second.php', "<?php\n");
            $inspector = new DatabaseUpdateSafetyInspector(
                $database,
                $migrations,
                base_path('deploy/supervisor/freedom-platform.conf'),
            );

            self::assertSame(
                UpdateMigrationIdentity::fromDirectory($migrations),
                $inspector->currentSchemaSha256(),
            );
        } finally {
            $this->removeTree($migrations);
        }
    }

    /** @requirement UPD-001 RUN-006 SEC-008 QUA-001 */
    public function test_release_schema_identity_rejects_symlinked_migration_and_hashes_regular_migrations(): void
    {
        $database = $this->createStub(DatabaseManager::class);
        $inspector = new DatabaseUpdateSafetyInspector(
            $database,
            base_path('database/migrations'),
            base_path('deploy/supervisor/freedom-platform.conf'),
        );
        $release = storage_path('framework/testing/update-schema-'.bin2hex(random_bytes(4)));
        mkdir($release.'/database/migrations', 0700, true);

        try {
            file_put_contents($release.'/database/migrations/2026_01_01_000000_first.php', "<?php\n");
            file_put_contents($release.'/database/migrations/2026_01_02_000000_second.php', "<?php\n");
            self::assertSame(
                UpdateMigrationIdentity::fromDirectory($release.'/database/migrations'),
                $inspector->releaseSchemaSha256($release),
            );

            unlink($release.'/database/migrations/2026_01_02_000000_second.php');
            symlink(
                $release.'/database/migrations/2026_01_01_000000_first.php',
                $release.'/database/migrations/2026_01_02_000000_second.php',
            );

            try {
                $inspector->releaseSchemaSha256($release);
                self::fail('A symlinked migration must fail closed.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('unavailable or unsafe', $exception->getMessage());
            }
        } finally {
            $this->removeTree($release);
        }
    }

    /** @requirement UPD-001 RUN-003 OPS-003 QUA-001 */
    public function test_worker_boot_verification_requires_every_reviewed_supervisor_process_after_activation(): void
    {
        $workers = [
            'freedom-platform-critical_00',
            'freedom-platform-critical_01',
            'freedom-platform-provisioning_00',
            'freedom-platform-provisioning_01',
            'freedom-platform-bulk_00',
        ];
        $inspector = new DatabaseUpdateSafetyInspector(
            $this->databaseForWorkers($workers),
            base_path('database/migrations'),
            base_path('deploy/supervisor/freedom-platform.conf'),
        );
        $inspector->assertWorkersRestartedAfter('2026-09-30 01:00:00.000000');
        self::addToAssertionCount(1);

        array_pop($workers);
        $inspector = new DatabaseUpdateSafetyInspector(
            $this->databaseForWorkers($workers),
            base_path('database/migrations'),
            base_path('deploy/supervisor/freedom-platform.conf'),
        );
        try {
            $inspector->assertWorkersRestartedAfter('2026-09-30 01:00:00.000000');
            self::fail('Every reviewed Supervisor worker must boot after activation.');
        } catch (RuntimeException $exception) {
            self::assertSame('The activated release worker boot verification failed.', $exception->getMessage());
        }
    }

    private function databaseForUnsafeTable(?string $unsafeTable): DatabaseManager
    {
        $schema = $this->createStub(SchemaBuilder::class);
        $schema->method('hasTable')->willReturn(true);

        $purchase = $this->existsQuery($unsafeTable === 'purchase_provider_mutation_attempts');
        $provisioning = $this->existsQuery($unsafeTable === 'provisioning_operations');
        $sync = $this->existsQuery($unsafeTable === 'service_sync_runs', where: true);

        $connection = $this->createStub(Connection::class);
        $connection->method('getSchemaBuilder')->willReturn($schema);
        $connection->method('table')->willReturnCallback(static fn (string $table): QueryBuilder => match ($table) {
            'purchase_provider_mutation_attempts' => $purchase,
            'provisioning_operations' => $provisioning,
            'service_sync_runs' => $sync,
            default => throw new RuntimeException('Unexpected update safety table: '.$table),
        });

        $database = $this->createStub(DatabaseManager::class);
        $database->method('connection')->willReturn($connection);

        return $database;
    }

    private function existsQuery(bool $exists, bool $where = false): QueryBuilder
    {
        $query = $this->createStub(QueryBuilder::class);
        $query->method('whereIn')->willReturnSelf();
        $query->method('where')->willReturnSelf();
        $query->method('exists')->willReturn($exists);

        return $query;
    }

    /** @param list<string> $workers */
    private function databaseForWorkers(array $workers): DatabaseManager
    {
        $query = $this->createStub(QueryBuilder::class);
        $query->method('whereIn')->willReturnSelf();
        $query->method('where')->willReturnSelf();
        $query->method('pluck')->willReturn(new Collection($workers));

        $connection = $this->createStub(Connection::class);
        $connection->method('table')->willReturn($query);

        $database = $this->createStub(DatabaseManager::class);
        $database->method('connection')->willReturn($connection);

        return $database;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path.'/'.$entry);
        }
        @rmdir($path);
    }
}
