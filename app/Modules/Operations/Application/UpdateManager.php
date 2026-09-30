<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Operations\Application\Contracts\ReleaseActivator;
use App\Modules\Operations\Application\Contracts\RestoreMaintenanceCoordinator;
use App\Modules\Operations\Application\Contracts\UpdateMutationFence;
use App\Modules\Operations\Application\Contracts\UpdatePackageVerifier;
use App\Modules\Operations\Application\Contracts\UpdateReleaseExecutor;
use App\Modules\Operations\Application\Contracts\UpdateSafetyInspector;
use App\Modules\Operations\Application\Contracts\UpdateWorkspace;
use App\Modules\Operations\Application\Contracts\VerifiedPreUpdateBackupProvider;
use App\Shared\Application\Clock;
use App\Shared\Application\RandomGenerator;
use DateTimeZone;
use RuntimeException;
use Throwable;

final readonly class UpdateManager
{
    public function __construct(
        private UpdateRuntimeConfiguration $configuration,
        private UpdatePackageVerifier $packages,
        private UpdateWorkspace $workspace,
        private UpdateSafetyInspector $safety,
        private VerifiedPreUpdateBackupProvider $backups,
        private UpdateReleaseExecutor $executor,
        private ReleaseActivator $releases,
        private RestoreMaintenanceCoordinator $maintenance,
        private UpdateMutationFence $mutationFence,
        private Clock $clock,
        private RandomGenerator $random,
    ) {}

    /** @requirement UPD-001 BAK-001 BAK-002 RUN-002 RUN-006 OPS-003 SEC-001 QUA-001 */
    public function run(
        string $packagePath,
        string $trustedPackageSha256,
        bool $apply = false,
        ?string $confirmation = null,
    ): UpdateRunResult {
        $this->workspace->recoverInterruptedPreMutationRuns();

        $package = $this->packages->verify($packagePath, $trustedPackageSha256);
        $this->executor->assertPrerequisites($package);

        $previousRelease = $this->workspace->currentReleaseId();
        if ($previousRelease === null || ! hash_equals($package->fromRelease, $previousRelease)) {
            throw new RuntimeException('The update package predecessor does not match the active release.');
        }

        $schemaBefore = $this->safety->currentSchemaSha256();
        if (! hash_equals($package->fromSchemaSha256, $schemaBefore)) {
            throw new RuntimeException('The update package predecessor schema is incompatible.');
        }

        $this->safety->assertNoUnsafeWork();

        $updateRunId = $this->runId();
        $report = $this->baseReport($updateRunId, 'update', $package->releaseId, $previousRelease);
        $report['package_sha256'] = $package->packageSha256;
        $report['manifest_sha256'] = $package->manifestSha256;
        $report['schema_before_sha256'] = $schemaBefore;
        $report['schema_target_sha256'] = $package->toSchemaSha256;
        $report['rollback_code_compatible_after'] = $package->previousCodeCompatibleWith($package->toSchemaSha256);

        if (! $apply) {
            $report['status'] = 'dry_run_completed';
            $report['report_finalized'] = true;
            $report['completed_at'] = $this->timestamp();
            $this->workspace->storeReport($updateRunId, $report);

            return new UpdateRunResult(
                $updateRunId,
                'dry_run_completed',
                $package->releaseId,
                $previousRelease,
                null,
                null,
                false,
            );
        }

        if (! $this->configuration->enabled) {
            throw new RuntimeException('Controlled update execution is disabled by configuration.');
        }
        if (! is_string($confirmation) || ! hash_equals($package->releaseId, $confirmation)) {
            throw new RuntimeException('Controlled update confirmation does not match the exact release.');
        }

        $this->workspace->storeReport($updateRunId, $report);

        $backup = null;
        $stagingPath = null;
        $releasePath = null;
        $maintenanceEntered = false;
        $mutationStarted = false;
        $migrationCompleted = false;
        $activationStarted = false;
        $phase = 'backup';

        try {
            $backup = $this->backups->createVerified();
            $report['pre_update_backup_id'] = $backup->backupId;
            $report['pre_update_backup_completed_at'] = $backup->completedAt;
            $this->markPhase($report, 'pre_update_backup_verified');
            $this->workspace->storeReport($updateRunId, $report);

            $phase = 'staging';
            $stagingPath = $this->workspace->createStaging($updateRunId, $package->releaseId);
            $report['staging_path'] = $stagingPath;
            $this->workspace->storeReport($updateRunId, $report);

            $this->packages->extract($package, $stagingPath);
            $this->markPhase($report, 'package_extracted');

            $phase = 'dependencies';
            $this->executor->prepare($stagingPath, $package);
            $this->markPhase($report, 'locked_dependencies_verified');
            $this->workspace->storeReport($updateRunId, $report);

            $phase = 'release_publish';
            $releasePath = $this->workspace->publishRelease($stagingPath, $package->releaseId);
            $stagingPath = null;
            $report['staging_path'] = null;
            $report['release_published'] = true;
            $this->markPhase($report, 'release_published');

            $prepared = $this->releases->prepare($package->releaseId);
            if (! hash_equals($releasePath, $prepared)) {
                throw new RuntimeException('The release-switch authority resolved a different candidate path.');
            }

            $releaseSchema = $this->safety->releaseSchemaSha256($releasePath);
            if (! hash_equals($package->toSchemaSha256, $releaseSchema)) {
                throw new RuntimeException('The published release schema identity is inconsistent.');
            }
            $this->markPhase($report, 'shared_resources_linked');

            $phase = 'runtime_bootstrap';
            $this->executor->prepareRuntime($releasePath);
            $this->markPhase($report, 'runtime_bootstrap_completed');

            $this->packages->verifyExtracted($package, $releasePath);
            $this->markPhase($report, 'reviewed_payload_reverified');

            $this->workspace->sealPublishedRelease($package->releaseId);
            $this->markPhase($report, 'immutable_release_sealed');
            $this->workspace->storeReport($updateRunId, $report);

            return $this->mutationFence->run(function () use (
                $package,
                $previousRelease,
                $schemaBefore,
                $updateRunId,
                $releasePath,
                $backup,
                &$report,
                &$maintenanceEntered,
                &$mutationStarted,
                &$migrationCompleted,
                &$activationStarted,
                &$phase,
            ): UpdateRunResult {
                try {
                    $phase = 'unsafe_work_preflight';
                    $this->safety->assertNoUnsafeWork();

                    $phase = 'maintenance';
                    $this->maintenance->enter($updateRunId);
                    $maintenanceEntered = true;
                    $report['maintenance_retained'] = true;
                    $report['scheduler_mutation_fence_retained'] = true;
                    $report['worker_quiescence_retained'] = true;
                    $report['containment_retained'] = true;
                    $this->markPhase($report, 'maintenance_and_workers_quiesced');
                    $this->workspace->storeReport($updateRunId, $report);

                    $this->safety->assertNoUnsafeWork();
                    if ($this->workspace->currentReleaseId() !== $previousRelease
                        || ! $this->schemaMatches($schemaBefore, $this->safety->currentSchemaSha256())
                    ) {
                        throw new RuntimeException('The active release or schema changed before migration.');
                    }

                    $phase = 'migration';
                    $mutationStarted = true;
                    $report['mutation_started'] = true;
                    $report['status'] = 'mutating';
                    $this->markPhase($report, 'migration_started');
                    $this->workspace->storeReport($updateRunId, $report);

                    $this->executor->migrate($releasePath);
                    $schemaAfter = $this->safety->installedSchemaSha256ForRelease($releasePath);
                    if (! hash_equals($package->toSchemaSha256, $schemaAfter)) {
                        throw new RuntimeException('The post-migration schema identity is inconsistent.');
                    }
                    $migrationCompleted = true;
                    $report['schema_after_sha256'] = $schemaAfter;
                    $this->markPhase($report, 'migration_completed');
                    $this->workspace->storeReport($updateRunId, $report);

                    $phase = 'pre_activation_health';
                    $this->executor->verifyRelease($releasePath, $package);
                    $this->markPhase($report, 'pre_activation_health_verified');
                    $this->workspace->storeReport($updateRunId, $report);

                    $phase = 'activation';
                    $activationStarted = true;
                    $activatedAfter = $this->databaseTimestamp();
                    $report['activation_started'] = true;
                    $report['activation_started_at'] = $activatedAfter;
                    $this->workspace->storeReport($updateRunId, $report);

                    $activation = $this->releases->activate(
                        $package->releaseId,
                        $package->previousCodeCompatibleWith($schemaAfter),
                    );
                    if (! in_array($activation['status'], ['activated', 'already_active'], true)
                        || $this->workspace->currentReleaseId() !== $package->releaseId
                    ) {
                        throw new RuntimeException('The atomic release activation could not be proven.');
                    }
                    $this->markPhase($report, 'atomic_activation_completed');
                    $this->workspace->storeReport($updateRunId, $report);

                    $phase = 'post_activation_health';
                    $this->executor->verifyRelease($releasePath, $package);

                    $phase = 'worker_reload';
                    $workerBootIds = $this->safety->workerBootIds();
                    $workerRestartAfter = $this->databaseTimestamp();
                    $this->maintenance->refreshRuntime($updateRunId);
                    $this->safety->assertWorkersRestartedAfter(
                        $workerRestartAfter,
                        $package->releaseId,
                        $workerBootIds,
                    );

                    if ($this->workspace->currentReleaseId() !== $package->releaseId
                        || ! $this->schemaMatches($package->toSchemaSha256, $this->safety->currentSchemaSha256())
                    ) {
                        throw new RuntimeException('The activated release identity changed during postflight.');
                    }

                    $this->executor->verifyRelease($releasePath, $package);
                    $this->markPhase($report, 'post_activation_runtime_verified');

                    $phase = 'installed_identity';
                    $this->workspace->storeInstalledIdentity([
                        'version' => 1,
                        'release_id' => $package->releaseId,
                        'application_version' => $package->applicationVersion,
                        'previous_release' => $previousRelease,
                        'previous_application_version' => $package->fromApplicationVersion,
                        'schema_sha256' => $package->toSchemaSha256,
                        'package_sha256' => $package->packageSha256,
                        'manifest_sha256' => $package->manifestSha256,
                        'composer_lock_sha256' => $package->composerLockSha256,
                        'rollback_compatible_schema_sha256' => $package->rollbackCompatibleSchemaSha256,
                        'pre_update_backup_id' => $backup->backupId,
                        'pre_update_backup_completed_at' => $backup->completedAt,
                        'activated_at' => $this->timestamp(),
                    ]);
                    $this->workspace->pruneReleases(
                        [$package->releaseId, $previousRelease],
                        $this->configuration->releaseRetention,
                    );
                    $this->markPhase($report, 'installed_identity_and_retention_verified');

                    $phase = 'resume';
                    $report['status'] = 'resume_pending';
                    $report['report_finalized'] = false;
                    $this->workspace->storeReport($updateRunId, $report);

                    $this->maintenance->leave($updateRunId);

                    if ($this->workspace->currentReleaseId() !== $package->releaseId
                        || ! $this->schemaMatches($package->toSchemaSha256, $this->safety->currentSchemaSha256())
                    ) {
                        throw new RuntimeException('The resumed release identity is inconsistent.');
                    }

                    $maintenanceEntered = false;
                    $report['maintenance_retained'] = false;
                    $report['scheduler_mutation_fence_retained'] = false;
                    $report['worker_quiescence_retained'] = false;
                    $report['containment_retained'] = false;
                    $report['status'] = 'completed';
                    $report['report_finalized'] = true;
                    $report['completed_at'] = $this->timestamp();
                    $this->markPhase($report, 'runtime_resumed');

                    try {
                        $this->workspace->storeReport($updateRunId, $report);
                    } catch (Throwable) {
                        return new UpdateRunResult(
                            $updateRunId,
                            'completed_reporting_failed',
                            $package->releaseId,
                            $previousRelease,
                            $backup->backupId,
                            $backup->completedAt,
                            false,
                        );
                    }

                    return new UpdateRunResult(
                        $updateRunId,
                        'completed',
                        $package->releaseId,
                        $previousRelease,
                        $backup->backupId,
                        $backup->completedAt,
                        false,
                    );
                } catch (Throwable) {
                    return $this->handleMutationFailure(
                        $package,
                        $previousRelease,
                        $updateRunId,
                        $backup,
                        $maintenanceEntered,
                        $mutationStarted,
                        $migrationCompleted,
                        $activationStarted,
                        $phase,
                        $report,
                    );
                }
            });
        } catch (Throwable) {
            if ($mutationStarted) {
                return $this->handleMutationFailure(
                    $package,
                    $previousRelease,
                    $updateRunId,
                    $backup,
                    $maintenanceEntered,
                    $mutationStarted,
                    $migrationCompleted,
                    $activationStarted,
                    $phase,
                    $report,
                );
            }

            if ($stagingPath !== null) {
                try {
                    $this->workspace->discardStaging($stagingPath);
                } catch (Throwable) {
                    $report['staging_cleanup_failed'] = true;
                }
            }
            if ($releasePath !== null && ($report['release_published'] ?? false) === true) {
                try {
                    $this->workspace->discardInactiveRelease($package->releaseId);
                    $report['release_published'] = false;
                } catch (Throwable) {
                    $report['release_cleanup_failed'] = true;
                }
            }

            if ($maintenanceEntered) {
                try {
                    if ($this->workspace->currentReleaseId() !== $previousRelease
                        || ! $this->schemaMatches($schemaBefore, $this->safety->currentSchemaSha256())
                    ) {
                        throw new RuntimeException('Pre-mutation release state changed unexpectedly.');
                    }
                    $this->maintenance->leave($updateRunId);
                    $maintenanceEntered = false;
                } catch (Throwable) {
                    $containment = $this->maintenance->retain($updateRunId);
                    $this->recordContainment($report, $containment);
                }
            }

            $report['status'] = $maintenanceEntered ? 'failed_pre_mutation_contained' : 'failed_pre_mutation';
            $report['failure_code'] = $phase.'_failed';
            $report['report_finalized'] = true;
            $report['completed_at'] = $this->timestamp();
            $this->workspace->storeReport($updateRunId, $report);

            return new UpdateRunResult(
                $updateRunId,
                $report['status'],
                $package->releaseId,
                $previousRelease,
                $backup?->backupId,
                $backup?->completedAt,
                false,
            );
        }
    }

    /** @requirement UPD-001 BAK-002 RUN-002 OPS-003 QUA-001 */
    public function rollback(bool $apply = false, ?string $confirmation = null): UpdateRunResult
    {
        $this->workspace->recoverInterruptedPreMutationRuns();
        $installed = $this->workspace->installedIdentity();
        if ($installed === null) {
            throw new RuntimeException('No controlled installed-release identity is available for rollback.');
        }

        $releaseId = $this->stringField($installed, 'release_id');
        $previousRelease = $this->stringField($installed, 'previous_release');
        $previousApplicationVersion = $this->stringField($installed, 'previous_application_version');
        $backupId = $this->stringField($installed, 'pre_update_backup_id');
        $backupCompletedAt = $this->stringField($installed, 'pre_update_backup_completed_at');
        $compatibleSchemas = $installed['rollback_compatible_schema_sha256'] ?? null;
        if (! is_array($compatibleSchemas)) {
            throw new RuntimeException('The installed release has no controlled rollback compatibility contract.');
        }

        if ($this->workspace->currentReleaseId() !== $releaseId) {
            throw new RuntimeException('The installed release identity does not match the active release.');
        }

        $schema = $this->safety->currentSchemaSha256();
        $compatible = false;
        foreach ($compatibleSchemas as $candidate) {
            if (is_string($candidate)
                && preg_match('/\A[0-9a-f]{64}\z/', $candidate) === 1
                && hash_equals($candidate, $schema)
            ) {
                $compatible = true;
            }
        }

        $updateRunId = $this->runId();
        $report = $this->baseReport($updateRunId, 'rollback', $releaseId, $previousRelease);
        $report['schema_before_sha256'] = $schema;
        $report['pre_update_backup_id'] = $backupId;
        $report['pre_update_backup_completed_at'] = $backupCompletedAt;
        $report['potential_data_loss_window_started_at'] = $backupCompletedAt;

        if (! $compatible) {
            $report['status'] = 'rollback_incompatible_restore_required';
            $report['restore_required'] = true;
            $report['report_finalized'] = true;
            $report['completed_at'] = $this->timestamp();
            $report['potential_data_loss_window_observed_at'] = $report['completed_at'];
            $this->workspace->storeReport($updateRunId, $report);

            return new UpdateRunResult(
                $updateRunId,
                'rollback_incompatible_restore_required',
                $releaseId,
                $previousRelease,
                $backupId,
                $backupCompletedAt,
                true,
            );
        }

        if (! $apply) {
            $report['status'] = 'rollback_dry_run_completed';
            $report['report_finalized'] = true;
            $report['completed_at'] = $this->timestamp();
            $this->workspace->storeReport($updateRunId, $report);

            return new UpdateRunResult(
                $updateRunId,
                'rollback_dry_run_completed',
                $releaseId,
                $previousRelease,
                $backupId,
                $backupCompletedAt,
                false,
            );
        }

        if (! $this->configuration->enabled) {
            throw new RuntimeException('Controlled update execution is disabled by configuration.');
        }
        if (! is_string($confirmation) || ! hash_equals($releaseId, $confirmation)) {
            throw new RuntimeException('Controlled rollback confirmation does not match the active release.');
        }

        $this->workspace->storeReport($updateRunId, $report);

        return $this->mutationFence->run(function () use (
            $updateRunId,
            $releaseId,
            $previousRelease,
            $previousApplicationVersion,
            $backupId,
            $backupCompletedAt,
            $schema,
            &$report,
        ): UpdateRunResult {
            $maintenanceEntered = false;
            $sourceReleasePath = $this->configuration->deploymentRoot.'/releases/'.$releaseId;

            try {
                $this->safety->assertNoUnsafeWork();
                $this->maintenance->enter($updateRunId);
                $maintenanceEntered = true;
                $report['maintenance_retained'] = true;
                $report['scheduler_mutation_fence_retained'] = true;
                $report['worker_quiescence_retained'] = true;
                $report['containment_retained'] = true;
                $this->safety->assertNoUnsafeWork();

                if ($this->workspace->currentReleaseId() !== $releaseId
                    || ! $this->schemaMatches($schema, $this->safety->currentSchemaSha256())
                ) {
                    throw new RuntimeException('The rollback source release or schema changed unexpectedly.');
                }

                $report['mutation_started'] = true;
                $report['activation_started'] = true;
                $rollbackStartedAt = $this->databaseTimestamp();
                $report['activation_started_at'] = $rollbackStartedAt;
                $report['status'] = 'rolling_back';
                $this->workspace->storeReport($updateRunId, $report);

                $switch = $this->releases->rollbackTo($previousRelease);
                if (! in_array($switch['status'], ['rolled_back', 'already_active'], true)
                    || $this->workspace->currentReleaseId() !== $previousRelease
                    || ! $this->schemaMatches(
                        $schema,
                        $this->safety->installedSchemaSha256ForRelease($sourceReleasePath),
                    )
                ) {
                    throw new RuntimeException('The controlled code rollback could not be proven.');
                }

                $previousPath = $this->configuration->deploymentRoot.'/releases/'.$previousRelease;
                $this->executor->verifyRelease($previousPath);
                $workerBootIds = $this->safety->workerBootIds();
                $workerRestartAfter = $this->databaseTimestamp();
                $this->maintenance->refreshRuntime($updateRunId);
                $this->safety->assertWorkersRestartedAfter(
                    $workerRestartAfter,
                    $previousRelease,
                    $workerBootIds,
                );
                $this->executor->verifyRelease($previousPath);

                $this->workspace->storeInstalledIdentity([
                    'version' => 1,
                    'release_id' => $previousRelease,
                    'application_version' => $previousApplicationVersion,
                    'schema_sha256' => $schema,
                    'rolled_back_from' => $releaseId,
                    'pre_update_backup_id' => $backupId,
                    'pre_update_backup_completed_at' => $backupCompletedAt,
                    'activated_at' => $this->timestamp(),
                ]);

                $report['status'] = 'resume_pending';
                $this->workspace->storeReport($updateRunId, $report);
                $this->maintenance->leave($updateRunId);

                if ($this->workspace->currentReleaseId() !== $previousRelease
                    || ! $this->schemaMatches(
                        $schema,
                        $this->safety->installedSchemaSha256ForRelease($sourceReleasePath),
                    )
                ) {
                    throw new RuntimeException('The resumed rollback release identity is inconsistent.');
                }

                $maintenanceEntered = false;
                $report['maintenance_retained'] = false;
                $report['scheduler_mutation_fence_retained'] = false;
                $report['worker_quiescence_retained'] = false;
                $report['containment_retained'] = false;
                $report['status'] = 'rollback_completed';
                $report['report_finalized'] = true;
                $report['completed_at'] = $this->timestamp();
                $this->markPhase($report, 'compatible_code_rollback_completed');

                try {
                    $this->workspace->storeReport($updateRunId, $report);
                } catch (Throwable) {
                    return new UpdateRunResult(
                        $updateRunId,
                        'rollback_completed_reporting_failed',
                        $previousRelease,
                        $releaseId,
                        $backupId,
                        $backupCompletedAt,
                        false,
                    );
                }

                return new UpdateRunResult(
                    $updateRunId,
                    'rollback_completed',
                    $previousRelease,
                    $releaseId,
                    $backupId,
                    $backupCompletedAt,
                    false,
                );
            } catch (Throwable) {
                if ($maintenanceEntered) {
                    $containment = $this->maintenance->retain($updateRunId);
                    $this->recordContainment($report, $containment);
                }
                $report['status'] = 'rollback_failed_contained';
                $report['failure_code'] = 'rollback_failed';
                $report['report_finalized'] = true;
                $report['completed_at'] = $this->timestamp();
                $this->workspace->storeReport($updateRunId, $report);

                return new UpdateRunResult(
                    $updateRunId,
                    'rollback_failed_contained',
                    $releaseId,
                    $previousRelease,
                    $backupId,
                    $backupCompletedAt,
                    false,
                );
            }
        });
    }

    /** @param array<string, mixed> $report */
    private function handleMutationFailure(
        VerifiedUpdatePackage $package,
        string $previousRelease,
        string $updateRunId,
        ?VerifiedPreUpdateBackup $backup,
        bool $maintenanceEntered,
        bool $mutationStarted,
        bool $migrationCompleted,
        bool $activationStarted,
        string $phase,
        array &$report,
    ): UpdateRunResult {
        $report['failure_code'] = $phase.'_failed';
        $report['mutation_started'] = $mutationStarted;
        $report['activation_started'] = $activationStarted;

        if ($maintenanceEntered) {
            $containment = $this->maintenance->retain($updateRunId);
            $this->recordContainment($report, $containment);
            if (! ($containment['maintenance_owned']
                && $containment['scheduler_fence_held']
                && $containment['workers_quiesced'])
            ) {
                $report['status'] = 'failure_containment_unproven';
                $report['report_finalized'] = true;
                $report['completed_at'] = $this->timestamp();
                $this->workspace->storeReport($updateRunId, $report);

                return new UpdateRunResult(
                    $updateRunId,
                    'failure_containment_unproven',
                    $package->releaseId,
                    $previousRelease,
                    $backup?->backupId,
                    $backup?->completedAt,
                    true,
                );
            }
        }

        $currentRelease = null;
        $currentSchema = null;
        try {
            $currentRelease = $this->workspace->currentReleaseId();
            $currentSchema = $mutationStarted
                ? $this->safety->installedSchemaSha256ForRelease(
                    $this->configuration->deploymentRoot.'/releases/'.$package->releaseId,
                )
                : $this->safety->currentSchemaSha256();
        } catch (Throwable) {
            // Uncertain current state must remain contained.
        }
        $report['observed_current_release'] = $currentRelease;
        $report['observed_schema_sha256'] = $currentSchema;

        if ($maintenanceEntered
            && $migrationCompleted
            && is_string($currentSchema)
            && hash_equals($package->toSchemaSha256, $currentSchema)
            && $package->previousCodeCompatibleWith($currentSchema)
        ) {
            try {
                if ($currentRelease === $package->releaseId) {
                    $this->releases->rollbackTo($previousRelease);
                    $currentRelease = $this->workspace->currentReleaseId();
                    $this->markPhase($report, 'failure_code_rollback_completed');
                }

                if ($currentRelease === $previousRelease) {
                    $previousPath = $this->configuration->deploymentRoot.'/releases/'.$previousRelease;
                    $this->executor->verifyRelease($previousPath);
                    $workerBootIds = $this->safety->workerBootIds();
                    $workerRestartAfter = $this->databaseTimestamp();
                    $this->maintenance->refreshRuntime($updateRunId);
                    $this->safety->assertWorkersRestartedAfter(
                        $workerRestartAfter,
                        $previousRelease,
                        $workerBootIds,
                    );
                    $this->executor->verifyRelease($previousPath);

                    if (! $this->schemaMatches(
                        $currentSchema,
                        $this->safety->installedSchemaSha256ForRelease(
                            $this->configuration->deploymentRoot.'/releases/'.$package->releaseId,
                        ),
                    )) {
                        throw new RuntimeException('The compatible rollback schema identity changed unexpectedly.');
                    }

                    // A failed update may already have persisted the candidate installed identity
                    // before the final resume transition. Reconcile that durable authority to the
                    // proven active previous release before reopening processing.
                    $this->workspace->storeInstalledIdentity([
                        'version' => 1,
                        'release_id' => $previousRelease,
                        'application_version' => $package->fromApplicationVersion,
                        'schema_sha256' => $currentSchema,
                        'recovered_from_release_attempt' => $package->releaseId,
                        'pre_update_backup_id' => $backup?->backupId,
                        'pre_update_backup_completed_at' => $backup?->completedAt,
                        'activated_at' => $this->timestamp(),
                    ]);
                    $this->markPhase($report, 'failure_installed_identity_reconciled');

                    $this->maintenance->leave($updateRunId);

                    $report['maintenance_retained'] = false;
                    $report['scheduler_mutation_fence_retained'] = false;
                    $report['worker_quiescence_retained'] = false;
                    $report['containment_retained'] = false;
                    $report['status'] = 'failed_safely_rolled_back';
                    $report['report_finalized'] = true;
                    $report['completed_at'] = $this->timestamp();
                    $this->workspace->storeReport($updateRunId, $report);

                    return new UpdateRunResult(
                        $updateRunId,
                        'failed_safely_rolled_back',
                        $package->releaseId,
                        $previousRelease,
                        $backup?->backupId,
                        $backup?->completedAt,
                        false,
                    );
                }
            } catch (Throwable) {
                $containment = $this->maintenance->retain($updateRunId);
                $this->recordContainment($report, $containment);
            }
        }

        $report['status'] = 'restore_required';
        $report['restore_required'] = true;
        $report['report_finalized'] = true;
        $report['completed_at'] = $this->timestamp();
        if ($backup !== null) {
            $report['pre_update_backup_id'] = $backup->backupId;
            $report['pre_update_backup_completed_at'] = $backup->completedAt;
            $report['potential_data_loss_window_started_at'] = $backup->completedAt;
            $report['potential_data_loss_window_observed_at'] = $report['completed_at'];
        }
        $this->workspace->storeReport($updateRunId, $report);

        return new UpdateRunResult(
            $updateRunId,
            'restore_required',
            $package->releaseId,
            $previousRelease,
            $backup?->backupId,
            $backup?->completedAt,
            true,
        );
    }

    /** @return array<string, mixed> */
    private function baseReport(
        string $updateRunId,
        string $operation,
        string $releaseId,
        ?string $previousRelease,
    ): array {
        return [
            'version' => 1,
            'update_run_id' => $updateRunId,
            'operation' => $operation,
            'status' => 'running',
            'report_finalized' => false,
            'release_id' => $releaseId,
            'previous_release' => $previousRelease,
            'package_sha256' => null,
            'manifest_sha256' => null,
            'schema_before_sha256' => null,
            'schema_target_sha256' => null,
            'schema_after_sha256' => null,
            'pre_update_backup_id' => null,
            'pre_update_backup_completed_at' => null,
            'staging_path' => null,
            'release_published' => false,
            'mutation_started' => false,
            'activation_started' => false,
            'activation_started_at' => null,
            'maintenance_retained' => false,
            'scheduler_mutation_fence_retained' => false,
            'worker_quiescence_retained' => false,
            'containment_retained' => false,
            'restore_required' => false,
            'rollback_code_compatible_after' => false,
            'failure_code' => null,
            'phases' => [],
            'started_at' => $this->timestamp(),
            'completed_at' => null,
        ];
    }

    /** @param array<string, mixed> $report */
    private function markPhase(array &$report, string $phase): void
    {
        $phases = $report['phases'] ?? [];
        if (! is_array($phases)) {
            $phases = [];
        }
        $phases[] = [
            'name' => $phase,
            'at' => $this->timestamp(),
        ];
        $report['phases'] = $phases;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array{maintenance_owned:bool,scheduler_fence_held:bool,workers_quiesced:bool}  $containment
     */
    private function recordContainment(array &$report, array $containment): void
    {
        $report['maintenance_retained'] = $containment['maintenance_owned'];
        $report['scheduler_mutation_fence_retained'] = $containment['scheduler_fence_held'];
        $report['worker_quiescence_retained'] = $containment['workers_quiesced'];
        $report['containment_retained'] = $containment['maintenance_owned']
            && $containment['scheduler_fence_held']
            && $containment['workers_quiesced'];
    }

    /** @param array<string, mixed> $identity */
    private function stringField(array $identity, string $field): string
    {
        $value = $identity[$field] ?? null;
        if (! is_string($value) || $value === '') {
            throw new RuntimeException('The installed release rollback identity is incomplete.');
        }

        return $value;
    }

    /** @phpstan-impure */
    private function schemaMatches(string $expected, string $actual): bool
    {
        return hash_equals($expected, $actual);
    }

    private function runId(): string
    {
        return $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Ymd\THis\Z').'-'.bin2hex($this->random->bytes(8));
    }

    private function timestamp(): string
    {
        return $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DATE_ATOM);
    }

    private function databaseTimestamp(): string
    {
        return $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }
}
