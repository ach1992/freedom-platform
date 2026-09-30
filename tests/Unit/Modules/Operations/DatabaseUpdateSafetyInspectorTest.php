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

    /** @requirement UPD-001 RUN-002 RUN-006 QUA-001 */
    public function test_installed_schema_can_be_proven_against_candidate_before_current_switches(): void
    {
        $schema = $this->createStub(SchemaBuilder::class);
        $schema->method('hasTable')->willReturn(true);
        $query = $this->createStub(QueryBuilder::class);
        $query->method('pluck')->willReturn(new Collection([
            '2026_01_01_000000_first',
            '2026_01_02_000000_second',
        ]));
        $connection = $this->createStub(Connection::class);
        $connection->method('getSchemaBuilder')->willReturn($schema);
        $connection->method('table')->willReturn($query);
        $database = $this->createStub(DatabaseManager::class);
        $database->method('connection')->willReturn($connection);

        $base = storage_path('framework/testing/update-installed-schema-'.bin2hex(random_bytes(4)));
        $current = $base.'/current/database/migrations';
        $candidate = $base.'/releases/1.1.0/database/migrations';
        mkdir($current, 0700, true);
        mkdir($candidate, 0700, true);

        try {
            file_put_contents($current.'/2026_01_01_000000_first.php', "<?php\n");
            file_put_contents($candidate.'/2026_01_01_000000_first.php', "<?php\n");
            file_put_contents($candidate.'/2026_01_02_000000_second.php', "<?php\n");

            $inspector = new DatabaseUpdateSafetyInspector(
                $database,
                $current,
                base_path('deploy/supervisor/freedom-platform.conf'),
            );

            try {
                $inspector->currentSchemaSha256();
                self::fail('The active predecessor release must not claim the candidate schema.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'The current schema migration authority does not match the active release files.',
                    $exception->getMessage(),
                );
            }

            self::assertSame(
                UpdateMigrationIdentity::fromDirectory($candidate),
                $inspector->installedSchemaSha256ForRelease($base.'/releases/1.1.0'),
            );
        } finally {
            $this->removeTree($base);
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
    public function test_worker_boot_verification_is_release_and_generation_bound(): void
    {
        $workers = [
            'freedom-platform-critical_00',
            'freedom-platform-critical_01',
            'freedom-platform-provisioning_00',
            'freedom-platform-provisioning_01',
            'freedom-platform-bulk_00',
        ];

        $baselineInspector = new DatabaseUpdateSafetyInspector(
            $this->databaseForWorkers($this->workerRows(
                $workers,
                '1.0.0',
                str_repeat('a', 32),
                '2026-09-30 00:59:59.000000',
            )),
            base_path('database/migrations'),
            base_path('deploy/supervisor/freedom-platform.conf'),
        );
        $baseline = $baselineInspector->workerBootIds();
        self::assertCount(count($workers), $baseline);

        $missingBaselineRows = $this->workerRows(
            $workers,
            '1.0.0',
            str_repeat('a', 32),
            '2026-09-30 00:59:59.000000',
        );
        array_pop($missingBaselineRows);
        $missingBaselineInspector = new DatabaseUpdateSafetyInspector(
            $this->databaseForWorkers($missingBaselineRows),
            base_path('database/migrations'),
            base_path('deploy/supervisor/freedom-platform.conf'),
        );
        try {
            $missingBaselineInspector->workerBootIds();
            self::fail('The pre-restart worker generation baseline must cover every reviewed process.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'The reviewed worker boot-generation baseline is incomplete.',
                $exception->getMessage(),
            );
        }

        $freshInspector = new DatabaseUpdateSafetyInspector(
            $this->databaseForWorkers($this->workerRows(
                $workers,
                '1.1.0',
                str_repeat('b', 32),
                '2026-09-30 01:00:01.000000',
            )),
            base_path('database/migrations'),
            base_path('deploy/supervisor/freedom-platform.conf'),
        );
        $freshInspector->assertWorkersRestartedAfter(
            '2026-09-30 01:00:00.000000',
            '1.1.0',
            $baseline,
        );
        self::addToAssertionCount(1);

        $partialBaseline = $baseline;
        array_pop($partialBaseline);
        try {
            $freshInspector->assertWorkersRestartedAfter(
                '2026-09-30 01:00:00.000000',
                '1.1.0',
                $partialBaseline,
            );
            self::fail('Worker restart proof must reject an incomplete previous-generation baseline.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'The previous worker boot-generation baseline is incomplete.',
                $exception->getMessage(),
            );
        }

        foreach ([
            'wrong release' => $this->workerRows(
                $workers,
                '1.0.0',
                str_repeat('c', 32),
                '2026-09-30 01:00:01.000000',
            ),
            'stale generation' => $this->workerRows(
                $workers,
                '1.1.0',
                str_repeat('a', 32),
                '2026-09-30 01:00:01.000000',
            ),
            'stale timestamp' => $this->workerRows(
                $workers,
                '1.1.0',
                str_repeat('c', 32),
                '2026-09-30 00:59:59.000000',
            ),
        ] as $case => $rows) {
            $inspector = new DatabaseUpdateSafetyInspector(
                $this->databaseForWorkers($rows),
                base_path('database/migrations'),
                base_path('deploy/supervisor/freedom-platform.conf'),
            );

            try {
                $inspector->assertWorkersRestartedAfter(
                    '2026-09-30 01:00:00.000000',
                    '1.1.0',
                    $baseline,
                );
                self::fail('Worker verification must reject '.$case.'.');
            } catch (RuntimeException $exception) {
                self::assertSame('The activated release worker boot verification failed.', $exception->getMessage());
            }
        }

        $missingRows = $this->workerRows(
            $workers,
            '1.1.0',
            str_repeat('d', 32),
            '2026-09-30 01:00:01.000000',
        );
        array_pop($missingRows);
        $missingInspector = new DatabaseUpdateSafetyInspector(
            $this->databaseForWorkers($missingRows),
            base_path('database/migrations'),
            base_path('deploy/supervisor/freedom-platform.conf'),
        );
        try {
            $missingInspector->assertWorkersRestartedAfter(
                '2026-09-30 01:00:00.000000',
                '1.1.0',
                $baseline,
            );
            self::fail('Every reviewed Supervisor worker must provide fresh boot evidence.');
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

    /** @param list<array{worker_id:string,release_version:string,boot_id:string,last_seen_at:string}> $rows */
    private function databaseForWorkers(array $rows): DatabaseManager
    {
        $query = $this->createStub(QueryBuilder::class);
        $query->method('whereIn')->willReturnSelf();
        $query->method('get')->willReturn(new Collection(array_map(
            static fn (array $row): object => (object) $row,
            $rows,
        )));

        $connection = $this->createStub(Connection::class);
        $connection->method('table')->willReturn($query);

        $database = $this->createStub(DatabaseManager::class);
        $database->method('connection')->willReturn($connection);

        return $database;
    }

    /**
     * @param  list<string>  $workers
     * @return list<array{worker_id:string,release_version:string,boot_id:string,last_seen_at:string}>
     */
    private function workerRows(
        array $workers,
        string $release,
        string $bootId,
        string $lastSeenAt,
    ): array {
        return array_map(
            static fn (string $workerId): array => [
                'worker_id' => $workerId,
                'release_version' => $release,
                'boot_id' => $bootId,
                'last_seen_at' => $lastSeenAt,
            ],
            $workers,
        );
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
