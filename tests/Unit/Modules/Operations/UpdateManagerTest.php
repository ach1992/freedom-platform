<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Application\Contracts\ReleaseActivator;
use App\Modules\Operations\Application\Contracts\RestoreMaintenanceCoordinator;
use App\Modules\Operations\Application\Contracts\UpdateMutationFence;
use App\Modules\Operations\Application\Contracts\UpdatePackageVerifier;
use App\Modules\Operations\Application\Contracts\UpdateReleaseExecutor;
use App\Modules\Operations\Application\Contracts\UpdateSafetyInspector;
use App\Modules\Operations\Application\Contracts\UpdateWorkspace;
use App\Modules\Operations\Application\Contracts\VerifiedPreUpdateBackupProvider;
use App\Modules\Operations\Application\UpdateManager;
use App\Modules\Operations\Application\UpdateRuntimeConfiguration;
use App\Modules\Operations\Application\VerifiedPreUpdateBackup;
use App\Modules\Operations\Application\VerifiedUpdatePackage;
use App\Shared\Application\Clock;
use App\Shared\Application\RandomGenerator;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Tests\TestCase;

final class UpdateManagerTest extends TestCase
{
    /** @requirement UPD-001 BAK-001 RUN-002 OPS-003 QUA-001 */
    public function test_success_flow_orders_backup_staging_migration_activation_workers_and_resume(): void
    {
        $fixture = $this->fixture();
        $manager = $this->manager($fixture);

        $result = $manager->run('/controlled/release.tar', str_repeat('c', 64), true, '1.1.0');

        self::assertSame('completed', $result->status);
        self::assertSame('1.1.0', $fixture->workspace->current);
        self::assertSame($fixture->package->toSchemaSha256, $fixture->safety->schema);
        self::assertSame('backup-001', $result->preUpdateBackupId);
        self::assertSame([
            'recover',
            'package.verify',
            'executor.prerequisites',
            'safety.no_unsafe_work',
            'backup.create_verified',
            'workspace.stage',
            'package.extract',
            'executor.prepare',
            'workspace.publish',
            'release.prepare',
            'fence.enter',
            'safety.no_unsafe_work',
            'maintenance.enter',
            'safety.no_unsafe_work',
            'executor.migrate',
            'executor.verify',
            'release.activate',
            'executor.verify',
            'maintenance.refresh',
            'safety.workers',
            'executor.verify',
            'workspace.identity',
            'workspace.prune',
            'maintenance.leave',
            'fence.leave',
        ], $fixture->events->events);
        self::assertSame('1.1.0', $fixture->workspace->identity['release_id'] ?? null);
        self::assertFalse($fixture->maintenance->retained);
    }

    /** @requirement UPD-001 BAK-001 QUA-001 */
    public function test_pre_update_backup_failure_never_crosses_staging_or_mutation_boundary(): void
    {
        $fixture = $this->fixture();
        $fixture->backup->fail = true;
        $manager = $this->manager($fixture);

        $result = $manager->run('/controlled/release.tar', str_repeat('c', 64), true, '1.1.0');

        self::assertSame('failed_pre_mutation', $result->status);
        self::assertSame('1.0.0', $fixture->workspace->current);
        self::assertSame($fixture->package->fromSchemaSha256, $fixture->safety->schema);
        self::assertNotContains('workspace.stage', $fixture->events->events);
        self::assertNotContains('maintenance.enter', $fixture->events->events);
        self::assertNotContains('executor.migrate', $fixture->events->events);
    }

    /** @requirement UPD-001 PAY-003 PRV-003 QUA-001 */
    public function test_unsafe_active_work_refusal_occurs_before_backup_or_mutation(): void
    {
        $fixture = $this->fixture();
        $fixture->safety->unsafe = true;
        $manager = $this->manager($fixture);

        try {
            $manager->run('/controlled/release.tar', str_repeat('c', 64), true, '1.1.0');
            self::fail('Unsafe financial/provider work must refuse update execution.');
        } catch (RuntimeException $exception) {
            self::assertSame('test-only-unsafe-work-secret', $exception->getMessage());
        }

        self::assertNotContains('backup.create_verified', $fixture->events->events);
        self::assertNotContains('maintenance.enter', $fixture->events->events);
    }

    /** @requirement UPD-001 BAK-002 OPS-003 QUA-001 */
    public function test_migration_failure_is_contained_and_requires_restore_even_when_schema_appears_unchanged(): void
    {
        $fixture = $this->fixture();
        $fixture->executor->failMigrate = true;
        $manager = $this->manager($fixture);

        $result = $manager->run('/controlled/release.tar', str_repeat('c', 64), true, '1.1.0');

        self::assertSame('restore_required', $result->status);
        self::assertTrue($result->restoreRequired);
        self::assertSame('backup-001', $result->preUpdateBackupId);
        self::assertSame('1.0.0', $fixture->workspace->current);
        self::assertSame($fixture->package->fromSchemaSha256, $fixture->safety->schema);
        self::assertTrue($fixture->maintenance->retained);
        self::assertNotContains('maintenance.leave', $fixture->events->events);
        $report = $fixture->workspace->lastReport();
        self::assertSame('restore_required', $report['status'] ?? null);
        self::assertSame('migration_failed', $report['failure_code'] ?? null);
        self::assertTrue($report['containment_retained'] ?? false);
    }

    /** @requirement UPD-001 RUN-002 OPS-003 QUA-001 */
    public function test_compatible_post_activation_failure_uses_code_only_rollback_and_never_database_rollback(): void
    {
        $fixture = $this->fixture(rollbackCompatible: true);
        $fixture->executor->failVerifyAt = 2;
        $manager = $this->manager($fixture);

        $result = $manager->run('/controlled/release.tar', str_repeat('c', 64), true, '1.1.0');

        self::assertSame('failed_safely_rolled_back', $result->status);
        self::assertFalse($result->restoreRequired);
        self::assertSame('1.0.0', $fixture->workspace->current);
        self::assertSame($fixture->package->toSchemaSha256, $fixture->safety->schema);
        self::assertContains('release.rollback', $fixture->events->events);
        self::assertNotContains('database.rollback', $fixture->events->events);
        self::assertContains('maintenance.leave', $fixture->events->events);
    }

    /** @requirement UPD-001 BAK-002 OPS-003 QUA-001 */
    public function test_incompatible_post_activation_failure_withholds_old_code_and_requires_controlled_restore(): void
    {
        $fixture = $this->fixture(rollbackCompatible: false);
        $fixture->executor->failVerifyAt = 2;
        $manager = $this->manager($fixture);

        $result = $manager->run('/controlled/release.tar', str_repeat('c', 64), true, '1.1.0');

        self::assertSame('restore_required', $result->status);
        self::assertTrue($result->restoreRequired);
        self::assertSame('1.1.0', $fixture->workspace->current);
        self::assertTrue($fixture->maintenance->retained);
        self::assertNotContains('release.rollback', $fixture->events->events);
        $report = $fixture->workspace->lastReport();
        self::assertSame('2026-09-30T00:59:00+00:00', $report['potential_data_loss_window_started_at'] ?? null);
        self::assertArrayHasKey('potential_data_loss_window_observed_at', $report);
    }

    /** @requirement UPD-001 OPS-003 QUA-001 */
    public function test_worker_boot_verification_failure_keeps_failure_contained_until_runtime_is_proven(): void
    {
        $fixture = $this->fixture(rollbackCompatible: true);
        $fixture->safety->workerFailure = true;
        $manager = $this->manager($fixture);

        $result = $manager->run('/controlled/release.tar', str_repeat('c', 64), true, '1.1.0');

        self::assertSame('restore_required', $result->status);
        self::assertTrue($result->restoreRequired);
        self::assertContains('maintenance.refresh', $fixture->events->events);
        self::assertContains('safety.workers', $fixture->events->events);
        self::assertContains('release.rollback', $fixture->events->events);
        self::assertTrue($fixture->maintenance->retained);
    }

    /** @requirement UPD-001 BAK-002 QUA-001 */
    public function test_compatible_explicit_rollback_switches_code_only_and_preserves_schema(): void
    {
        $fixture = $this->fixture(rollbackCompatible: true);
        $fixture->workspace->current = '1.1.0';
        $fixture->safety->schema = $fixture->package->toSchemaSha256;
        $fixture->workspace->identity = $this->installedIdentity($fixture, [$fixture->package->toSchemaSha256]);
        $manager = $this->manager($fixture);

        $result = $manager->rollback(true, '1.1.0');

        self::assertSame('rollback_completed', $result->status);
        self::assertSame('1.0.0', $fixture->workspace->current);
        self::assertSame($fixture->package->toSchemaSha256, $fixture->safety->schema);
        self::assertContains('release.rollback', $fixture->events->events);
        self::assertNotContains('executor.migrate', $fixture->events->events);
        self::assertNotContains('database.rollback', $fixture->events->events);
    }

    /** @requirement UPD-001 BAK-002 QUA-001 */
    public function test_incompatible_explicit_rollback_refuses_mutation_and_reports_restore_backup_window(): void
    {
        $fixture = $this->fixture(rollbackCompatible: false);
        $fixture->workspace->current = '1.1.0';
        $fixture->safety->schema = $fixture->package->toSchemaSha256;
        $fixture->workspace->identity = $this->installedIdentity($fixture, [str_repeat('d', 64)]);
        $manager = $this->manager($fixture);

        $result = $manager->rollback(true, '1.1.0');

        self::assertSame('rollback_incompatible_restore_required', $result->status);
        self::assertTrue($result->restoreRequired);
        self::assertSame('backup-001', $result->preUpdateBackupId);
        self::assertSame('1.1.0', $fixture->workspace->current);
        self::assertNotContains('maintenance.enter', $fixture->events->events);
        self::assertNotContains('release.rollback', $fixture->events->events);
        $report = $fixture->workspace->lastReport();
        self::assertArrayHasKey('potential_data_loss_window_started_at', $report);
        self::assertArrayHasKey('potential_data_loss_window_observed_at', $report);
    }

    /** @requirement UPD-001 SEC-001 QUA-001 */
    public function test_failure_reports_do_not_persist_exception_secrets(): void
    {
        $fixture = $this->fixture();
        $fixture->executor->failMigrate = true;
        $manager = $this->manager($fixture);

        $manager->run('/controlled/release.tar', str_repeat('c', 64), true, '1.1.0');

        $json = json_encode($fixture->workspace->reports, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('test-only-migration-secret', $json);
        self::assertStringNotContainsString('test-only-unsafe-work-secret', $json);
    }

    private function manager(UpdateManagerFixture $fixture): UpdateManager
    {
        return new UpdateManager(
            new UpdateRuntimeConfiguration(
                enabled: true,
                deploymentRoot: '/tmp/fake-deployment',
                packageRoot: '/controlled',
                phpBinary: '/usr/bin/php',
                composerBinary: '/usr/bin/composer',
                runUser: 'www',
                processTimeoutSeconds: 60,
                releaseRetention: 3,
            ),
            $fixture->packages,
            $fixture->workspace,
            $fixture->safety,
            $fixture->backup,
            $fixture->executor,
            $fixture->releases,
            $fixture->maintenance,
            $fixture->fence,
            new FixedUpdateClock,
            new FixedUpdateRandom,
        );
    }

    private function fixture(bool $rollbackCompatible = true): UpdateManagerFixture
    {
        $events = new UpdateEventLog;
        $fromSchema = str_repeat('a', 64);
        $toSchema = str_repeat('b', 64);
        $package = new VerifiedUpdatePackage(
            packagePath: '/controlled/release.tar',
            packageSha256: str_repeat('c', 64),
            manifestSha256: str_repeat('e', 64),
            releaseId: '1.1.0',
            applicationVersion: '1.1.0',
            fromRelease: '1.0.0',
            fromApplicationVersion: '1.0.0',
            fromSchemaSha256: $fromSchema,
            toSchemaSha256: $toSchema,
            rollbackCompatibleSchemaSha256: $rollbackCompatible ? [$toSchema] : [],
            composerLockSha256: str_repeat('f', 64),
            laravelMajor: 13,
            phpMinimum: '8.4.0',
            phpMaximumExclusive: '9.0.0',
            requiredFreeBytes: 1,
            payloadBytes: 100,
            checksumsSha256: str_repeat('1', 64),
            payloadChecksums: ['artisan' => str_repeat('2', 64)],
        );
        $workspace = new FakeUpdateWorkspace($events);
        $safety = new FakeUpdateSafetyInspector($events, $fromSchema, $toSchema);
        $executor = new FakeUpdateReleaseExecutor($events, $safety);
        $packages = new FakeUpdatePackageVerifier($events, $package);
        $backup = new FakeVerifiedPreUpdateBackupProvider($events);
        $releases = new FakeReleaseActivator($events, $workspace);
        $maintenance = new FakeUpdateMaintenanceCoordinator($events);
        $fence = new FakeUpdateMutationFence($events);

        return new UpdateManagerFixture(
            $events,
            $package,
            $packages,
            $workspace,
            $safety,
            $backup,
            $executor,
            $releases,
            $maintenance,
            $fence,
        );
    }

    /** @param list<string> $compatibleSchemas */
    private function installedIdentity(UpdateManagerFixture $fixture, array $compatibleSchemas): array
    {
        return [
            'version' => 1,
            'release_id' => '1.1.0',
            'application_version' => '1.1.0',
            'previous_release' => '1.0.0',
            'previous_application_version' => '1.0.0',
            'schema_sha256' => $fixture->package->toSchemaSha256,
            'rollback_compatible_schema_sha256' => $compatibleSchemas,
            'pre_update_backup_id' => 'backup-001',
            'pre_update_backup_completed_at' => '2026-09-30T00:59:00+00:00',
        ];
    }
}

final readonly class UpdateManagerFixture
{
    public function __construct(
        public UpdateEventLog $events,
        public VerifiedUpdatePackage $package,
        public FakeUpdatePackageVerifier $packages,
        public FakeUpdateWorkspace $workspace,
        public FakeUpdateSafetyInspector $safety,
        public FakeVerifiedPreUpdateBackupProvider $backup,
        public FakeUpdateReleaseExecutor $executor,
        public FakeReleaseActivator $releases,
        public FakeUpdateMaintenanceCoordinator $maintenance,
        public FakeUpdateMutationFence $fence,
    ) {}
}

final class UpdateEventLog
{
    /** @var list<string> */
    public array $events = [];

    public function add(string $event): void
    {
        $this->events[] = $event;
    }
}

final class FakeUpdatePackageVerifier implements UpdatePackageVerifier
{
    public bool $extractFailure = false;

    public function __construct(
        private readonly UpdateEventLog $events,
        private readonly VerifiedUpdatePackage $package,
    ) {}

    public function verify(string $packagePath, string $trustedPackageSha256): VerifiedUpdatePackage
    {
        $this->events->add('package.verify');
        if ($packagePath !== $this->package->packagePath || $trustedPackageSha256 !== $this->package->packageSha256) {
            throw new RuntimeException('package mismatch');
        }

        return $this->package;
    }

    public function extract(VerifiedUpdatePackage $package, string $destination): void
    {
        $this->events->add('package.extract');
        if ($this->extractFailure) {
            throw new RuntimeException('test-only-extract-secret');
        }
    }
}

final class FakeUpdateWorkspace implements UpdateWorkspace
{
    public string $current = '1.0.0';

    /** @var array<string,mixed>|null */
    public ?array $identity = null;

    /** @var list<array<string,mixed>> */
    public array $reports = [];

    public function __construct(private readonly UpdateEventLog $events) {}

    public function recoverInterruptedPreMutationRuns(): void
    {
        $this->events->add('recover');
    }

    public function storeReport(string $updateRunId, array $report): void
    {
        $this->reports[] = $report;
    }

    public function createStaging(string $updateRunId, string $releaseId): string
    {
        $this->events->add('workspace.stage');

        return '/tmp/fake-deployment/releases/.update-'.$updateRunId.'-'.$releaseId;
    }

    public function publishRelease(string $stagingPath, string $releaseId): string
    {
        $this->events->add('workspace.publish');

        return '/tmp/fake-deployment/releases/'.$releaseId;
    }

    public function discardStaging(string $stagingPath): void
    {
        $this->events->add('workspace.discard_staging');
    }

    public function discardInactiveRelease(string $releaseId): void
    {
        $this->events->add('workspace.discard_release');
    }

    public function currentReleaseId(): ?string
    {
        return $this->current;
    }

    public function installedIdentity(): ?array
    {
        return $this->identity;
    }

    public function storeInstalledIdentity(array $identity): void
    {
        $this->events->add('workspace.identity');
        $this->identity = $identity;
    }

    public function pruneReleases(array $protectedReleaseIds, int $retention): void
    {
        $this->events->add('workspace.prune');
    }

    /** @return array<string,mixed> */
    public function lastReport(): array
    {
        $report = end($this->reports);
        if (! is_array($report)) {
            throw new RuntimeException('No update report was recorded.');
        }

        return $report;
    }
}

final class FakeUpdateSafetyInspector implements UpdateSafetyInspector
{
    public bool $unsafe = false;

    public bool $workerFailure = false;

    public function __construct(
        private readonly UpdateEventLog $events,
        public string $schema,
        private readonly string $targetSchema,
    ) {}

    public function currentSchemaSha256(): string
    {
        return $this->schema;
    }

    public function releaseSchemaSha256(string $releasePath): string
    {
        return $this->targetSchema;
    }

    public function assertNoUnsafeWork(): void
    {
        $this->events->add('safety.no_unsafe_work');
        if ($this->unsafe) {
            throw new RuntimeException('test-only-unsafe-work-secret');
        }
    }

    public function assertWorkersRestartedAfter(string $activatedAfter): void
    {
        $this->events->add('safety.workers');
        if ($this->workerFailure) {
            throw new RuntimeException('test-only-worker-secret');
        }
    }
}

final class FakeVerifiedPreUpdateBackupProvider implements VerifiedPreUpdateBackupProvider
{
    public bool $fail = false;

    public function __construct(private readonly UpdateEventLog $events) {}

    public function createVerified(): VerifiedPreUpdateBackup
    {
        $this->events->add('backup.create_verified');
        if ($this->fail) {
            throw new RuntimeException('test-only-backup-secret');
        }

        return new VerifiedPreUpdateBackup('backup-001', '2026-09-30T00:59:00+00:00');
    }
}

final class FakeUpdateReleaseExecutor implements UpdateReleaseExecutor
{
    public bool $failMigrate = false;

    public ?int $failVerifyAt = null;

    private int $verifyCount = 0;

    public function __construct(
        private readonly UpdateEventLog $events,
        private readonly FakeUpdateSafetyInspector $safety,
    ) {}

    public function assertPrerequisites(VerifiedUpdatePackage $package): void
    {
        $this->events->add('executor.prerequisites');
    }

    public function prepare(string $releasePath, VerifiedUpdatePackage $package): void
    {
        $this->events->add('executor.prepare');
    }

    public function migrate(string $releasePath): void
    {
        $this->events->add('executor.migrate');
        if ($this->failMigrate) {
            throw new RuntimeException('test-only-migration-secret');
        }
        $this->safety->schema = str_repeat('b', 64);
    }

    public function verifyRelease(string $releasePath, ?VerifiedUpdatePackage $package = null): void
    {
        $this->events->add('executor.verify');
        $this->verifyCount++;
        if ($this->failVerifyAt === $this->verifyCount) {
            throw new RuntimeException('test-only-health-secret');
        }
    }
}

final class FakeReleaseActivator implements ReleaseActivator
{
    public function __construct(
        private readonly UpdateEventLog $events,
        private readonly FakeUpdateWorkspace $workspace,
    ) {}

    public function prepare(string $releaseId): string
    {
        $this->events->add('release.prepare');

        return '/tmp/fake-deployment/releases/'.$releaseId;
    }

    public function activate(string $releaseId, bool $automaticRollbackSafe = true): array
    {
        $this->events->add('release.activate');
        $previous = $this->workspace->current;
        $this->workspace->current = $releaseId;

        return ['status' => 'activated', 'release' => $releaseId, 'previous_release' => $previous];
    }

    public function rollbackTo(string $releaseId): array
    {
        $this->events->add('release.rollback');
        $previous = $this->workspace->current;
        $this->workspace->current = $releaseId;

        return ['status' => 'rolled_back', 'release' => $releaseId, 'previous_release' => $previous];
    }
}

final class FakeUpdateMaintenanceCoordinator implements RestoreMaintenanceCoordinator
{
    public bool $retained = false;

    public function __construct(private readonly UpdateEventLog $events) {}

    public function enter(string $restoreRunId): void
    {
        $this->events->add('maintenance.enter');
        $this->retained = true;
    }

    public function refreshRuntime(string $restoreRunId): void
    {
        $this->events->add('maintenance.refresh');
    }

    public function leave(string $restoreRunId): void
    {
        $this->events->add('maintenance.leave');
        $this->retained = false;
    }

    public function retain(string $restoreRunId): array
    {
        $this->events->add('maintenance.retain');
        $this->retained = true;

        return [
            'maintenance_owned' => true,
            'scheduler_fence_held' => true,
            'workers_quiesced' => true,
        ];
    }
}

final class FakeUpdateMutationFence implements UpdateMutationFence
{
    public function __construct(private readonly UpdateEventLog $events) {}

    public function run(Closure $operation): mixed
    {
        $this->events->add('fence.enter');
        try {
            return $operation();
        } finally {
            $this->events->add('fence.leave');
        }
    }
}

final class FixedUpdateClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-30T01:00:00+00:00', new DateTimeZone('UTC'));
    }
}

final class FixedUpdateRandom implements RandomGenerator
{
    public function bytes(int $length): string
    {
        return str_repeat("\x01", $length);
    }

    public function integer(int $minimum, int $maximum): int
    {
        return $minimum;
    }
}
