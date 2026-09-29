<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Application\BackupCompatibilityIdentity;
use App\Modules\Operations\Application\BackupKind;
use App\Modules\Operations\Application\BackupManager;
use App\Modules\Operations\Application\BackupRuntimeConfiguration;
use App\Modules\Operations\Application\Contracts\BackupDatabaseDumper;
use App\Modules\Operations\Application\Contracts\RestoreDatabaseRestorer;
use App\Modules\Operations\Application\Contracts\RestoreMaintenanceCoordinator;
use App\Modules\Operations\Application\Contracts\RestorePayloadRestorer;
use App\Modules\Operations\Application\Contracts\RestorePostRestoreVerifier;
use App\Modules\Operations\Application\RestoreManager;
use App\Modules\Operations\Application\RestoreRuntimeConfiguration;
use App\Modules\Operations\Infrastructure\BackupBundleReader;
use App\Modules\Operations\Infrastructure\BackupBundleWriter;
use App\Modules\Operations\Infrastructure\BackupPayloadCollector;
use App\Modules\Operations\Infrastructure\FilesystemBackupRepository;
use App\Modules\Operations\Infrastructure\FilesystemRestoreWorkspace;
use App\Modules\Operations\Infrastructure\SodiumBackupCipherFactory;
use App\Shared\Application\Clock;
use App\Shared\Application\RandomGenerator;
use DateTimeImmutable;
use RuntimeException;
use Tests\TestCase;

final class RestoreManagerTest extends TestCase
{
    /** @requirement BAK-002 SEC-001 QUA-001 */
    public function test_dry_run_is_non_destructive_and_available_while_restore_execution_is_disabled(): void
    {
        $fixture = $this->fixture('dry-run');

        try {
            $source = $this->sourceBackup($fixture);
            $events = new RestoreEventLog;
            $manager = $this->manager(
                $fixture,
                $events,
                enabled: false,
            );

            $result = $manager->run($source, false);

            self::assertSame('dry_run_completed', $result->status);
            self::assertSame($source, $result->sourceBackupId);
            self::assertNull($result->safetyBackupId);
            self::assertSame(['payload_preflight'], $events->events);

            $report = $this->singleReport($fixture['root']);
            self::assertSame('dry_run_completed', $report['status']);
            self::assertFalse($report['mutation_started']);
            self::assertFalse($report['maintenance_retained']);
            self::assertNull($report['safety_backup_id']);
            self::assertSame('dry_run', $report['mode']);
        } finally {
            $this->removeTree($fixture['base']);
        }
    }

    /** @requirement BAK-002 OPS-003 SEC-001 QUA-001 */
    public function test_apply_verifies_full_safety_backup_before_destructive_restore_and_reopens_last(): void
    {
        $fixture = $this->fixture('success');

        try {
            $source = $this->sourceBackup($fixture);
            $events = new RestoreEventLog;
            $manager = $this->manager($fixture, $events);

            $result = $manager->run($source, true);

            self::assertSame('completed', $result->status);
            self::assertNotNull($result->safetyBackupId);
            self::assertSame([
                'payload_preflight',
                'maintenance_enter',
                'safety_backup_capture',
                'payload_preflight',
                'database_restore',
                'payload_restore',
                'runtime_refresh',
                'post_restore_verify',
                'maintenance_leave',
            ], $events->events);

            $report = $this->singleReport($fixture['root']);
            self::assertSame('completed', $report['status']);
            self::assertSame($result->safetyBackupId, $report['safety_backup_id']);
            self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) $report['safety_artifact_sha256']);
            self::assertTrue($report['mutation_started']);
            self::assertFalse($report['maintenance_retained']);
            self::assertSame(0, $report['verification']['ledger_violations']);
            self::assertSame(0, $report['verification']['order_payment_violations']);
            self::assertSame(0, $report['verification']['service_violations']);
        } finally {
            $this->removeTree($fixture['base']);
        }
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function test_safety_backup_failure_releases_maintenance_without_crossing_destructive_boundary(): void
    {
        $fixture = $this->fixture('safety-failure');

        try {
            $source = $this->sourceBackup($fixture);
            $events = new RestoreEventLog;
            $manager = $this->manager(
                $fixture,
                $events,
                safetyBackupFailure: true,
            );

            $this->expectRestoreFailure(fn () => $manager->run($source, true));

            self::assertSame([
                'payload_preflight',
                'maintenance_enter',
                'safety_backup_capture',
                'maintenance_leave',
            ], $events->events);

            $report = $this->singleReport($fixture['root']);
            self::assertSame('failed', $report['status']);
            self::assertSame('safety_backup_failed', $report['failure_code']);
            self::assertFalse($report['mutation_started']);
            self::assertFalse($report['maintenance_retained']);
        } finally {
            $this->removeTree($fixture['base']);
        }
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function test_database_restore_failure_retains_maintenance_and_skips_later_restore_phases(): void
    {
        $fixture = $this->fixture('database-failure');

        try {
            $source = $this->sourceBackup($fixture);
            $events = new RestoreEventLog;
            $manager = $this->manager(
                $fixture,
                $events,
                databaseFailure: true,
            );

            $this->expectRestoreFailure(fn () => $manager->run($source, true));

            self::assertSame([
                'payload_preflight',
                'maintenance_enter',
                'safety_backup_capture',
                'payload_preflight',
                'database_restore',
            ], $events->events);

            $report = $this->singleReport($fixture['root']);
            self::assertSame('database_restore_failed', $report['failure_code']);
            self::assertTrue($report['mutation_started']);
            self::assertTrue($report['maintenance_retained']);
        } finally {
            $this->removeTree($fixture['base']);
        }
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function test_private_config_restore_failure_retains_maintenance_and_skips_post_restore_verification(): void
    {
        $fixture = $this->fixture('payload-failure');

        try {
            $source = $this->sourceBackup($fixture);
            $events = new RestoreEventLog;
            $manager = $this->manager(
                $fixture,
                $events,
                payloadFailure: true,
            );

            $this->expectRestoreFailure(fn () => $manager->run($source, true));

            self::assertSame([
                'payload_preflight',
                'maintenance_enter',
                'safety_backup_capture',
                'payload_preflight',
                'database_restore',
                'payload_restore',
            ], $events->events);

            $report = $this->singleReport($fixture['root']);
            self::assertSame('payload_restore_failed', $report['failure_code']);
            self::assertTrue($report['mutation_started']);
            self::assertTrue($report['maintenance_retained']);
        } finally {
            $this->removeTree($fixture['base']);
        }
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function test_runtime_refresh_failure_retains_maintenance_and_skips_post_restore_verification(): void
    {
        $fixture = $this->fixture('runtime-refresh-failure');

        try {
            $source = $this->sourceBackup($fixture);
            $events = new RestoreEventLog;
            $manager = $this->manager(
                $fixture,
                $events,
                runtimeRefreshFailure: true,
            );

            $this->expectRestoreFailure(fn () => $manager->run($source, true));

            self::assertSame([
                'payload_preflight',
                'maintenance_enter',
                'safety_backup_capture',
                'payload_preflight',
                'database_restore',
                'payload_restore',
                'runtime_refresh',
            ], $events->events);

            $report = $this->singleReport($fixture['root']);
            self::assertSame('runtime_refresh_failed', $report['failure_code']);
            self::assertTrue($report['mutation_started']);
            self::assertTrue($report['maintenance_retained']);
        } finally {
            $this->removeTree($fixture['base']);
        }
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function test_post_restore_verification_failure_retains_maintenance_and_never_reports_success(): void
    {
        $fixture = $this->fixture('post-failure');

        try {
            $source = $this->sourceBackup($fixture);
            $events = new RestoreEventLog;
            $manager = $this->manager(
                $fixture,
                $events,
                postRestoreFailure: true,
            );

            $this->expectRestoreFailure(fn () => $manager->run($source, true));

            self::assertSame([
                'payload_preflight',
                'maintenance_enter',
                'safety_backup_capture',
                'payload_preflight',
                'database_restore',
                'payload_restore',
                'runtime_refresh',
                'post_restore_verify',
            ], $events->events);

            $report = $this->singleReport($fixture['root']);
            self::assertSame('post_restore_verification_failed', $report['failure_code']);
            self::assertTrue($report['mutation_started']);
            self::assertTrue($report['maintenance_retained']);
        } finally {
            $this->removeTree($fixture['base']);
        }
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function test_resume_failure_reestablishes_and_reports_proven_containment(): void
    {
        $fixture = $this->fixture('resume-failure');

        try {
            $source = $this->sourceBackup($fixture);
            $events = new RestoreEventLog;
            $manager = $this->manager(
                $fixture,
                $events,
                resumeFailure: true,
            );

            $this->expectRestoreFailure(fn () => $manager->run($source, true));

            self::assertSame([
                'payload_preflight',
                'maintenance_enter',
                'safety_backup_capture',
                'payload_preflight',
                'database_restore',
                'payload_restore',
                'runtime_refresh',
                'post_restore_verify',
                'maintenance_leave',
            ], $events->events);

            $report = $this->singleReport($fixture['root']);
            self::assertSame('resume_failed', $report['failure_code']);
            self::assertTrue($report['mutation_started']);
            self::assertTrue($report['maintenance_retained']);
            self::assertTrue($report['scheduler_mutation_fence_retained']);
            self::assertTrue($report['containment_retained']);
        } finally {
            $this->removeTree($fixture['base']);
        }
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function test_maintenance_entry_failure_stops_before_safety_backup_or_mutation(): void
    {
        $fixture = $this->fixture('maintenance-failure');

        try {
            $source = $this->sourceBackup($fixture);
            $events = new RestoreEventLog;
            $manager = $this->manager(
                $fixture,
                $events,
                maintenanceFailure: true,
            );

            $this->expectRestoreFailure(fn () => $manager->run($source, true));

            self::assertSame([
                'payload_preflight',
                'maintenance_enter',
            ], $events->events);

            $report = $this->singleReport($fixture['root']);
            self::assertSame('maintenance_failed', $report['failure_code']);
            self::assertFalse($report['mutation_started']);
            self::assertFalse($report['maintenance_retained']);
        } finally {
            $this->removeTree($fixture['base']);
        }
    }

    /** @requirement BAK-002 QUA-001 */
    public function test_incompatible_or_database_only_source_fails_before_maintenance(): void
    {
        $fixture = $this->fixture('incompatible');

        try {
            $configuration = $this->backupConfiguration($fixture);
            $repository = new FilesystemBackupRepository($configuration->root);

            $oldManager = $this->backupManager(
                $configuration,
                new RestoreTestDatabaseDumper('old-source'),
                new RestoreFixedClock('2026-09-29T01:00:00+00:00'),
                new RestoreFixedRandom("\x01"),
                'old-release',
            );
            $incompatible = $oldManager->create(BackupKind::DailyFull)->backupId;

            $databaseOnly = $this->backupManager(
                $configuration,
                new RestoreTestDatabaseDumper('db-only'),
                new RestoreFixedClock('2026-09-29T01:10:00+00:00'),
                new RestoreFixedRandom("\x02"),
                'test-release',
            )->create(BackupKind::FrequentDatabase)->backupId;

            foreach ([$incompatible, $databaseOnly] as $source) {
                $events = new RestoreEventLog;
                $manager = $this->manager($fixture, $events);

                $this->expectRestoreFailure(fn () => $manager->run($source, false));
                self::assertSame([], $events->events);
            }

            self::assertInstanceOf(FilesystemBackupRepository::class, $repository);
        } finally {
            $this->removeTree($fixture['base']);
        }
    }

    /**
     * @param  array{base:string,root:string,environment:string,private:string}  $fixture
     */
    private function manager(
        array $fixture,
        RestoreEventLog $events,
        bool $enabled = true,
        bool $safetyBackupFailure = false,
        bool $databaseFailure = false,
        bool $payloadFailure = false,
        bool $runtimeRefreshFailure = false,
        bool $postRestoreFailure = false,
        bool $maintenanceFailure = false,
        bool $resumeFailure = false,
    ): RestoreManager {
        $backup = $this->backupConfiguration($fixture);
        $repository = new FilesystemBackupRepository($backup->root);
        $safetyBackup = $this->backupManager(
            $backup,
            new RestoreTestDatabaseDumper(
                'current-state-safety',
                $events,
                $safetyBackupFailure,
            ),
            new RestoreFixedClock('2026-09-29T02:00:00+00:00'),
            new RestoreFixedRandom("\x22"),
            'test-release',
        );

        return new RestoreManager(
            new RestoreRuntimeConfiguration($enabled, '/usr/bin/mariadb', 30, 360),
            $backup,
            $repository,
            $safetyBackup,
            new BackupCompatibilityIdentity(
                'test-release',
                base_path('composer.lock'),
                database_path('migrations'),
                'mysql',
            ),
            new SodiumBackupCipherFactory,
            new BackupBundleReader,
            new RestoreTestDatabaseRestorer($events, $databaseFailure),
            new RestoreTestMaintenance(
                $events,
                $maintenanceFailure,
                $runtimeRefreshFailure,
                $resumeFailure,
            ),
            new RestoreTestPayloadRestorer($events, $payloadFailure),
            new RestoreTestPostVerifier($events, $postRestoreFailure),
            new FilesystemRestoreWorkspace($backup->root),
            new RestoreFixedClock('2026-09-29T03:00:00+00:00'),
            new RestoreFixedRandom("\x33"),
        );
    }

    /**
     * @param  array{base:string,root:string,environment:string,private:string}  $fixture
     */
    private function sourceBackup(array $fixture): string
    {
        $configuration = $this->backupConfiguration($fixture);

        return $this->backupManager(
            $configuration,
            new RestoreTestDatabaseDumper('source-database'),
            new RestoreFixedClock('2026-09-29T01:00:00+00:00'),
            new RestoreFixedRandom("\x11"),
            'test-release',
        )->create(BackupKind::DailyFull)->backupId;
    }

    private function backupManager(
        BackupRuntimeConfiguration $configuration,
        BackupDatabaseDumper $database,
        Clock $clock,
        RandomGenerator $random,
        string $applicationVersion,
    ): BackupManager {
        return new BackupManager(
            $configuration,
            static fn (): BackupDatabaseDumper => $database,
            new BackupPayloadCollector(
                $configuration->configFiles,
                $configuration->privateDirectories,
            ),
            new BackupBundleWriter,
            new SodiumBackupCipherFactory,
            new FilesystemBackupRepository($configuration->root),
            $clock,
            $random,
            $applicationVersion,
            base_path('composer.lock'),
            database_path('migrations'),
            'mysql',
        );
    }

    /**
     * @param  array{base:string,root:string,environment:string,private:string}  $fixture
     */
    private function backupConfiguration(array $fixture): BackupRuntimeConfiguration
    {
        return new BackupRuntimeConfiguration(
            true,
            $fixture['root'],
            str_repeat("\x07", SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES),
            30,
            10,
            '02:30',
            30,
            180,
            2,
            false,
            19_000_000,
            ['environment' => $fixture['environment']],
            ['application' => $fixture['private']],
        );
    }

    /**
     * @return array{base:string,root:string,environment:string,private:string}
     */
    private function fixture(string $case): array
    {
        $base = storage_path('framework/testing/restore-manager-'.$case.'-'.bin2hex(random_bytes(4)));
        $private = $base.'/private';
        mkdir($private, 0700, true);
        file_put_contents($base.'/.env', "APP_ENV=testing\n");
        file_put_contents($private.'/payload.txt', 'private-test-payload');

        return [
            'base' => $base,
            'root' => $base.'/backups',
            'environment' => $base.'/.env',
            'private' => $private,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function singleReport(string $backupRoot): array
    {
        $reports = glob($backupRoot.'/restore/reports/restore-*.json');
        self::assertIsArray($reports);
        self::assertCount(1, $reports);

        $contents = file_get_contents($reports[0]);
        self::assertIsString($contents);

        $report = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($report);

        return $report;
    }

    private function expectRestoreFailure(callable $operation): void
    {
        try {
            $operation();
            self::fail('The controlled restore was expected to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('Controlled restore failed.', $exception->getMessage());
        }
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

        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->removeTree($path.'/'.$name);
            }
        }

        @rmdir($path);
    }
}

final class RestoreEventLog
{
    /** @var list<string> */
    public array $events = [];
}

final readonly class RestoreTestDatabaseDumper implements BackupDatabaseDumper
{
    public function __construct(
        private string $contents,
        private ?RestoreEventLog $events = null,
        private bool $fail = false,
    ) {}

    public function capture(string $destinationPath): void
    {
        if ($this->events !== null) {
            $this->events->events[] = 'safety_backup_capture';
        }

        if ($this->fail) {
            throw new RuntimeException('test-only safety backup failure');
        }

        file_put_contents($destinationPath, $this->contents);
        chmod($destinationPath, 0600);
    }
}

final readonly class RestoreTestDatabaseRestorer implements RestoreDatabaseRestorer
{
    public function __construct(
        private RestoreEventLog $events,
        private bool $fail = false,
    ) {}

    public function restore(string $sqlPath): void
    {
        $this->events->events[] = 'database_restore';

        if ($this->fail) {
            throw new RuntimeException('test-only database restore failure');
        }

        if (! is_file($sqlPath)) {
            throw new RuntimeException('test-only missing SQL payload');
        }
    }
}

final class RestoreTestMaintenance implements RestoreMaintenanceCoordinator
{
    public bool $active = false;

    public function __construct(
        private readonly RestoreEventLog $events,
        private readonly bool $failEnter = false,
        private readonly bool $failRefresh = false,
        private readonly bool $failLeaveAfterDeactivate = false,
    ) {}

    public function enter(string $restoreRunId): void
    {
        $this->events->events[] = 'maintenance_enter';

        if ($this->failEnter) {
            throw new RuntimeException('test-only maintenance failure');
        }

        $this->active = true;
    }

    public function refreshRuntime(string $restoreRunId): void
    {
        $this->events->events[] = 'runtime_refresh';

        if ($this->failRefresh) {
            throw new RuntimeException('test-only runtime refresh failure');
        }
    }

    public function leave(string $restoreRunId): void
    {
        $this->events->events[] = 'maintenance_leave';
        $this->active = false;

        if ($this->failLeaveAfterDeactivate) {
            throw new RuntimeException('test-only resume failure after maintenance deactivation');
        }
    }

    public function retain(string $restoreRunId): array
    {
        $this->active = true;

        return [
            'maintenance_owned' => true,
            'scheduler_fence_held' => true,
        ];
    }
}

final readonly class RestoreTestPayloadRestorer implements RestorePayloadRestorer
{
    public function __construct(
        private RestoreEventLog $events,
        private bool $failRestore = false,
    ) {}

    public function preflight(array $entries): void
    {
        $this->events->events[] = 'payload_preflight';

        if (! isset($entries['database/database.sql'], $entries['config/environment'])) {
            throw new RuntimeException('test-only incomplete restore payload');
        }
    }

    public function restore(array $entries, string $restoreRunId): void
    {
        $this->events->events[] = 'payload_restore';

        if ($this->failRestore) {
            throw new RuntimeException('test-only payload restore failure');
        }
    }
}

final readonly class RestoreTestPostVerifier implements RestorePostRestoreVerifier
{
    public function __construct(
        private RestoreEventLog $events,
        private bool $fail = false,
    ) {}

    public function verify(): array
    {
        $this->events->events[] = 'post_restore_verify';

        if ($this->fail) {
            throw new RuntimeException('test-only post restore failure');
        }

        return [
            'schema_migrations' => 1,
            'ledger_violations' => 0,
            'order_payment_violations' => 0,
            'service_violations' => 0,
            'runtime_health' => true,
        ];
    }
}

final readonly class RestoreFixedClock implements Clock
{
    public function __construct(private string $now) {}

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->now);
    }
}

final readonly class RestoreFixedRandom implements RandomGenerator
{
    public function __construct(private string $byte) {}

    public function bytes(int $length): string
    {
        return str_repeat($this->byte, $length);
    }

    public function integer(int $minimum, int $maximum): int
    {
        return $minimum;
    }
}
