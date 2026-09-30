<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\BackupCompatibilityIdentity;
use App\Modules\Operations\Application\BackupManager;
use App\Modules\Operations\Application\BackupRuntimeConfiguration;
use App\Modules\Operations\Application\BackupTelegramExportService;
use App\Modules\Operations\Application\Contracts\BackupArtifactCipherFactory;
use App\Modules\Operations\Application\Contracts\BackupBundleReader as BackupBundleReaderContract;
use App\Modules\Operations\Application\Contracts\BackupBundleWriter as BackupBundleWriterContract;
use App\Modules\Operations\Application\Contracts\BackupDatabaseDumper;
use App\Modules\Operations\Application\Contracts\BackupPayloadCollector as BackupPayloadCollectorContract;
use App\Modules\Operations\Application\Contracts\BackupRepository;
use App\Modules\Operations\Application\Contracts\ReleaseActivator;
use App\Modules\Operations\Application\Contracts\ReleaseHealthVerifier;
use App\Modules\Operations\Application\Contracts\RestoreCriticalAuthorityIdentity;
use App\Modules\Operations\Application\Contracts\RestoreDatabaseRestorer;
use App\Modules\Operations\Application\Contracts\RestoreMaintenanceCoordinator;
use App\Modules\Operations\Application\Contracts\RestorePayloadFilesystem;
use App\Modules\Operations\Application\Contracts\RestorePayloadRestorer;
use App\Modules\Operations\Application\Contracts\RestorePostRestoreVerifier;
use App\Modules\Operations\Application\Contracts\RestoreRuntimeHealthVerifier;
use App\Modules\Operations\Application\Contracts\RestoreSchedulerMutationLock;
use App\Modules\Operations\Application\Contracts\RestoreWorkspace;
use App\Modules\Operations\Application\Contracts\UpdateMutationFence;
use App\Modules\Operations\Application\Contracts\UpdatePackageVerifier;
use App\Modules\Operations\Application\Contracts\UpdateReleaseExecutor;
use App\Modules\Operations\Application\Contracts\UpdateSafetyInspector;
use App\Modules\Operations\Application\Contracts\UpdateWorkspace;
use App\Modules\Operations\Application\Contracts\VerifiedPreUpdateBackupProvider;
use App\Modules\Operations\Application\QueueWorkerHeartbeatReporter;
use App\Modules\Operations\Application\RestoreManager;
use App\Modules\Operations\Application\RestoreRuntimeConfiguration;
use App\Modules\Operations\Application\RuntimeDeploymentInvariants;
use App\Modules\Operations\Application\UpdateManager;
use App\Modules\Operations\Application\UpdateRuntimeConfiguration;
use App\Modules\Operations\Application\WorkerHeartbeatService;
use App\Modules\Payments\Application\PurchaseProviderMutationBarrier;
use App\Shared\Application\Clock;
use App\Shared\Application\RandomGenerator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class OperationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {

        $this->app->singleton(
            WorkerRuntimeConfiguration::class,
            static fn (): WorkerRuntimeConfiguration => WorkerRuntimeConfiguration::resolve([
                'enabled' => config('operations.worker_heartbeat.enabled', false),
                'worker_id' => config('operations.worker_heartbeat.worker_id'),
                'queue_group' => config('operations.worker_heartbeat.queue_group', 'default'),
                'release_version' => config('operations.worker_heartbeat.release_version'),
                'boot_id' => config('operations.worker_heartbeat.boot_id'),
                'interval_seconds' => config('operations.worker_heartbeat.interval_seconds', 30),
            ]),
        );

        $this->app->singleton(
            QueueWorkerHeartbeatReporter::class,
            function (Application $application): QueueWorkerHeartbeatReporter {
                $runtime = $application->make(WorkerRuntimeConfiguration::class);

                return new QueueWorkerHeartbeatReporter(
                    $application->make(WorkerHeartbeatService::class),
                    $application->make(Clock::class),
                    $application->make(LoggerInterface::class),
                    $runtime->enabled,
                    $runtime->workerId,
                    $runtime->queueGroup,
                    $runtime->releaseVersion,
                    $runtime->bootId,
                    $runtime->intervalSeconds,
                );
            },
        );

        $this->app->singleton(
            BackupRuntimeConfiguration::class,
            static function (): BackupRuntimeConfiguration {
                $configuration = config('operations.backup');
                if (! is_array($configuration)) {
                    throw new RuntimeException('Backup configuration is unavailable.');
                }

                return BackupRuntimeConfiguration::fromArray($configuration);
            },
        );

        $this->app->singleton(
            RestoreRuntimeConfiguration::class,
            static function (): RestoreRuntimeConfiguration {
                $configuration = config('operations.restore');
                if (! is_array($configuration)) {
                    throw new RuntimeException('Restore configuration is unavailable.');
                }

                return RestoreRuntimeConfiguration::fromArray($configuration);
            },
        );

        $this->app->singleton(
            UpdateRuntimeConfiguration::class,
            static function (): UpdateRuntimeConfiguration {
                $configuration = config('operations.update');
                if (! is_array($configuration)) {
                    throw new RuntimeException('Update configuration is unavailable.');
                }

                return UpdateRuntimeConfiguration::fromArray($configuration);
            },
        );

        $this->app->singleton(
            BackupRepository::class,
            fn (Application $application): BackupRepository => new FilesystemBackupRepository(
                $application->make(BackupRuntimeConfiguration::class)->root,
            ),
        );

        $this->app->singleton(
            BackupPayloadCollectorContract::class,
            function (Application $application): BackupPayloadCollectorContract {
                $configuration = $application->make(BackupRuntimeConfiguration::class);

                return new BackupPayloadCollector(
                    $configuration->configFiles,
                    $configuration->privateDirectories,
                );
            },
        );

        $this->app->singleton(BackupBundleWriterContract::class, BackupBundleWriter::class);
        $this->app->singleton(BackupArtifactCipherFactory::class, SodiumBackupCipherFactory::class);
        $this->app->singleton(BackupTelegramExportService::class);

        $this->app->singleton(
            BackupDatabaseDumper::class,
            function (): BackupDatabaseDumper {
                $connectionName = config('database.default');
                if (! is_string($connectionName) || $connectionName === '') {
                    throw new RuntimeException('Backup database connection configuration is invalid.');
                }

                $database = config('database.connections.'.$connectionName);
                if (! is_array($database) || ($database['driver'] ?? null) !== 'mysql') {
                    throw new RuntimeException('Backup requires the supported MariaDB/MySQL connection.');
                }

                return new MariaDbBackupDumper(
                    self::requiredString(config('operations.backup.dump_binary'), 'Backup dump binary'),
                    self::requiredString($database['host'] ?? null, 'Backup database host'),
                    self::boundedInteger($database['port'] ?? null, 1, 65535, 'Backup database port'),
                    self::requiredString($database['database'] ?? null, 'Backup database name'),
                    self::requiredString($database['username'] ?? null, 'Backup database username'),
                    self::stringValue($database['password'] ?? null, 'Backup database credential'),
                    self::boundedInteger(
                        config('operations.backup.process_timeout_seconds'),
                        1,
                        86_400,
                        'Backup process timeout',
                    ),
                );
            },
        );

        $this->app->singleton(
            BackupCompatibilityIdentity::class,
            static function (): BackupCompatibilityIdentity {
                $connectionName = config('database.default');
                $database = is_string($connectionName)
                    ? config('database.connections.'.$connectionName)
                    : null;
                $driver = is_array($database) ? ($database['driver'] ?? null) : null;
                if (! is_string($driver) || $driver === '') {
                    throw new RuntimeException('Backup compatibility database driver configuration is invalid.');
                }

                $version = config('app.version');
                $applicationVersion = is_string($version) && $version !== '' ? $version : 'unversioned';

                return new BackupCompatibilityIdentity(
                    $applicationVersion,
                    base_path('composer.lock'),
                    database_path('migrations'),
                    $driver,
                );
            },
        );

        $this->app->singleton(BackupBundleReaderContract::class, BackupBundleReader::class);

        $this->app->singleton(RestorePayloadFilesystem::class, NativeRestorePayloadFilesystem::class);

        $this->app->singleton(
            RestoreSchedulerMutationLock::class,
            static fn (): RestoreSchedulerMutationLock => new FilesystemRestoreSchedulerMutationLock(
                storage_path('framework/operations-scheduler-mutation.lock'),
            ),
        );

        $this->app->singleton(
            RestoreCriticalAuthorityIdentity::class,
            fn (Application $application): RestoreCriticalAuthorityIdentity => new LaravelRestoreCriticalAuthorityIdentity(
                $application->make(DatabaseManager::class),
            ),
        );

        $this->app->singleton(
            RestoreWorkspace::class,
            fn (Application $application): RestoreWorkspace => new FilesystemRestoreWorkspace(
                $application->make(BackupRuntimeConfiguration::class)->root,
            ),
        );

        $this->app->singleton(
            RestorePayloadRestorer::class,
            function (Application $application): RestorePayloadRestorer {
                $backup = $application->make(BackupRuntimeConfiguration::class);

                return new FilesystemRestorePayloadRestorer(
                    $backup->configFiles,
                    $backup->privateDirectories,
                    $application->make(RestorePayloadFilesystem::class),
                );
            },
        );

        $this->app->singleton(
            RestoreMaintenanceCoordinator::class,
            function (Application $application): RestoreMaintenanceCoordinator {
                $restore = $application->make(RestoreRuntimeConfiguration::class);

                return new LaravelRestoreMaintenanceCoordinator(
                    $application->make(MaintenanceMode::class),
                    $application->make(Kernel::class),
                    $application->make(RuntimeDeploymentInvariants::class),
                    $application->make(RestoreSchedulerMutationLock::class),
                    base_path('deploy/supervisor/freedom-platform.conf'),
                    $restore->quiesceSeconds,
                );
            },
        );

        $this->app->singleton(
            RestoreDatabaseRestorer::class,
            function (Application $application): RestoreDatabaseRestorer {
                $connectionName = config('database.default');
                if (! is_string($connectionName) || $connectionName === '') {
                    throw new RuntimeException('Restore database connection configuration is invalid.');
                }

                $database = config('database.connections.'.$connectionName);
                if (! is_array($database) || ($database['driver'] ?? null) !== 'mysql') {
                    throw new RuntimeException('Restore requires the supported MariaDB/MySQL connection.');
                }

                $restore = $application->make(RestoreRuntimeConfiguration::class);

                return new MariaDbRestoreExecutor(
                    $restore->mariaDbBinary,
                    self::requiredString($database['host'] ?? null, 'Restore database host'),
                    self::boundedInteger($database['port'] ?? null, 1, 65535, 'Restore database port'),
                    self::requiredString($database['database'] ?? null, 'Restore database name'),
                    self::requiredString($database['username'] ?? null, 'Restore database username'),
                    self::stringValue($database['password'] ?? null, 'Restore database credential'),
                    $restore->processTimeoutSeconds,
                );
            },
        );

        $this->app->singleton(
            RestoreRuntimeHealthVerifier::class,
            function (Application $application): RestoreRuntimeHealthVerifier {
                $restore = $application->make(RestoreRuntimeConfiguration::class);

                return new ArtisanRestoreRuntimeHealthVerifier(
                    PHP_BINARY,
                    base_path('artisan'),
                    base_path(),
                    $restore->processTimeoutSeconds,
                );
            },
        );

        $this->app->singleton(
            RestorePostRestoreVerifier::class,
            fn (Application $application): RestorePostRestoreVerifier => new DatabaseRestorePostVerifier(
                $application->make(DatabaseManager::class),
                $application->make(RestoreCriticalAuthorityIdentity::class),
                $application->make(RestoreRuntimeHealthVerifier::class),
                database_path('migrations'),
            ),
        );

        $this->app->singleton(RestoreManager::class);

        $this->app->singleton(
            BackupManager::class,
            function (Application $application): BackupManager {
                $connectionName = config('database.default');
                $database = is_string($connectionName)
                    ? config('database.connections.'.$connectionName)
                    : null;
                $driver = is_array($database) ? ($database['driver'] ?? null) : null;
                if (! is_string($driver) || $driver === '') {
                    throw new RuntimeException('Backup database driver configuration is invalid.');
                }

                $version = config('app.version');
                $applicationVersion = is_string($version) && $version !== '' ? $version : 'unversioned';

                return new BackupManager(
                    $application->make(BackupRuntimeConfiguration::class),
                    static fn (): BackupDatabaseDumper => $application->make(BackupDatabaseDumper::class),
                    $application->make(BackupPayloadCollectorContract::class),
                    $application->make(BackupBundleWriterContract::class),
                    $application->make(BackupArtifactCipherFactory::class),
                    $application->make(BackupRepository::class),
                    $application->make(Clock::class),
                    $application->make(RandomGenerator::class),
                    $applicationVersion,
                    base_path('composer.lock'),
                    database_path('migrations'),
                    $driver,
                );
            },
        );

        $this->app->singleton(
            UpdatePackageVerifier::class,
            fn (Application $application): UpdatePackageVerifier => new PharUpdatePackageVerifier(
                $application->make(UpdateRuntimeConfiguration::class)->packageRoot,
            ),
        );

        $this->app->singleton(
            UpdateWorkspace::class,
            fn (Application $application): UpdateWorkspace => new FilesystemUpdateWorkspace(
                $application->make(UpdateRuntimeConfiguration::class)->deploymentRoot,
            ),
        );

        $this->app->singleton(
            UpdateSafetyInspector::class,
            fn (Application $application): UpdateSafetyInspector => new DatabaseUpdateSafetyInspector(
                $application->make(DatabaseManager::class),
                base_path('database/migrations'),
                base_path('deploy/supervisor/freedom-platform.conf'),
            ),
        );

        $this->app->singleton(
            VerifiedPreUpdateBackupProvider::class,
            fn (Application $application): VerifiedPreUpdateBackupProvider => new RestoreVerifiedPreUpdateBackupProvider(
                $application->make(BackupManager::class),
                $application->make(RestoreManager::class),
            ),
        );

        $this->app->singleton(
            UpdateReleaseExecutor::class,
            fn (Application $application): UpdateReleaseExecutor => new ArtisanUpdateReleaseExecutor(
                $application->make(UpdateRuntimeConfiguration::class),
            ),
        );

        $this->app->singleton(
            UpdateMutationFence::class,
            fn (Application $application): UpdateMutationFence => new PurchaseProviderUpdateMutationFence(
                $application->make(PurchaseProviderMutationBarrier::class),
            ),
        );

        $this->app->singleton(
            ReleaseHealthVerifier::class,
            fn (Application $application): ReleaseHealthVerifier => new ArtisanReleaseHealthVerifier(
                $application->make(UpdateRuntimeConfiguration::class)->phpBinary,
                min($application->make(UpdateRuntimeConfiguration::class)->processTimeoutSeconds, 600),
            ),
        );

        $this->app->singleton(
            ReleaseActivator::class,
            fn (Application $application): ReleaseActivator => new FilesystemReleaseActivator(
                $application->make(ReleaseHealthVerifier::class),
                $application->make(UpdateRuntimeConfiguration::class)->deploymentRoot,
                $application->make(UpdateRuntimeConfiguration::class)->deploymentRoot.'/shared/release-journal.json',
            ),
        );

        $this->app->singleton(UpdateManager::class);
    }

    public function boot(): void
    {
        $runtime = $this->app->make(WorkerRuntimeConfiguration::class);

        if (! $runtime->enabled) {
            return;
        }

        $reporter = $this->app->make(QueueWorkerHeartbeatReporter::class);

        Queue::looping(static function () use ($reporter): void {
            $reporter->reportSafely();
        });
        Queue::before(static function (JobProcessing $event) use ($reporter): void {
            $reporter->reportSafely($event->job->getQueue());
        });
        Queue::after(static function (JobProcessed $event) use ($reporter): void {
            $reporter->reportSafely($event->job->getQueue());
        });
        Queue::exceptionOccurred(static function (JobExceptionOccurred $event) use ($reporter): void {
            $reporter->reportSafely($event->job->getQueue());
        });
    }

    private static function requiredString(mixed $value, string $label): string
    {
        if (! is_string($value) || $value === '') {
            throw new RuntimeException($label.' configuration is invalid.');
        }

        return $value;
    }

    private static function stringValue(mixed $value, string $label): string
    {
        if (! is_string($value)) {
            throw new RuntimeException($label.' configuration is invalid.');
        }

        return $value;
    }

    private static function boundedInteger(mixed $value, int $minimum, int $maximum, string $label): int
    {
        $validated = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => $minimum, 'max_range' => $maximum]],
        );

        if ($validated === false) {
            throw new RuntimeException($label.' configuration is invalid.');
        }

        return $validated;
    }
}
