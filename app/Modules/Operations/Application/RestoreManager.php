<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Operations\Application\Contracts\BackupArtifactCipherFactory;
use App\Modules\Operations\Application\Contracts\BackupBundleReader;
use App\Modules\Operations\Application\Contracts\BackupRepository;
use App\Modules\Operations\Application\Contracts\ReleaseActivator;
use App\Modules\Operations\Application\Contracts\RestoreDatabaseRestorer;
use App\Modules\Operations\Application\Contracts\RestoreMaintenanceCoordinator;
use App\Modules\Operations\Application\Contracts\RestorePayloadRestorer;
use App\Modules\Operations\Application\Contracts\RestorePostRestoreVerifier;
use App\Modules\Operations\Application\Contracts\RestoreWorkspace;
use App\Modules\Operations\Application\Contracts\UpdateRecoveryRestore;
use App\Modules\Operations\Application\Contracts\UpdateReleaseExecutor;
use App\Modules\Operations\Application\Contracts\UpdateSafetyInspector;
use App\Modules\Operations\Application\Contracts\UpdateWorkspace;
use App\Shared\Application\Clock;
use App\Shared\Application\RandomGenerator;
use DateTimeZone;
use RuntimeException;
use Throwable;

final readonly class RestoreManager implements UpdateRecoveryRestore
{
    public function __construct(
        private RestoreRuntimeConfiguration $configuration,
        private BackupRuntimeConfiguration $backupConfiguration,
        private BackupRepository $backups,
        private BackupManager $backupManager,
        private BackupCompatibilityIdentity $compatibility,
        private BackupArtifactCipherFactory $cipherFactory,
        private BackupBundleReader $bundleReader,
        private RestoreDatabaseRestorer $databaseRestorer,
        private RestoreMaintenanceCoordinator $maintenance,
        private RestorePayloadRestorer $payloadRestorer,
        private RestorePostRestoreVerifier $postRestoreVerifier,
        private RestoreWorkspace $workspace,
        private Clock $clock,
        private RandomGenerator $random,
        private UpdateWorkspace $updateWorkspace,
        private ReleaseActivator $releases,
        private UpdateSafetyInspector $updateSafety,
        private UpdateReleaseExecutor $updateExecutor,
    ) {}

    /** @requirement BAK-002 SEC-001 OPS-003 QUA-001 */
    public function run(string $backupId, bool $apply = false): RestoreRunResult
    {
        return $this->execute($backupId, $apply, null);
    }

    /** @requirement UPD-001 BAK-002 RUN-002 OPS-003 SEC-001 QUA-001 */
    public function recoverUpdate(string $updateRunId, bool $apply = false): RestoreRunResult
    {
        if (preg_match('/\\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}\\z/', $updateRunId) !== 1) {
            throw new RuntimeException('The source update run identifier is invalid.');
        }

        $updateReport = $this->updateWorkspace->loadReport($updateRunId);
        $recovery = $this->recoveryContext($updateReport, $updateRunId);

        return $this->execute($recovery['backup_id'], $apply, $recovery);
    }

    /**
     * @param array{update_run_id:string,failed_release:string,previous_release:string,previous_application_version:string,schema_before_sha256:string,backup_id:string,backup_completed_at:string,adopt_containment:bool}|null $recovery
     */
    private function execute(string $backupId, bool $apply, ?array $recovery): RestoreRunResult
    {
        if ($apply && ! $this->configuration->enabled) {
            throw new RuntimeException('Restore execution is disabled by configuration.');
        }

        $restoreRunId = $this->restoreRunId();
        $workspacePath = $this->workspace->create($restoreRunId);
        $phase = 'resolve';
        $maintenanceEntered = false;
        $mutationStarted = false;
        $resumeCompleted = false;
        $safetyBackupId = null;
        $report = [
            'version' => 1,
            'restore_run_id' => $restoreRunId,
            'mode' => $apply ? 'apply' : 'dry_run',
            'status' => 'running',
            'report_finalized' => false,
            'source_backup_id' => $backupId,
            'source_artifact_sha256' => null,
            'source_completed_at' => null,
            'safety_backup_id' => null,
            'safety_artifact_sha256' => null,
            'mutation_started' => false,
            'maintenance_retained' => false,
            'scheduler_mutation_fence_retained' => false,
            'worker_quiescence_retained' => false,
            'containment_retained' => false,
            'phases' => [],
            'verification' => null,
            'failure_code' => null,
            'started_at' => $this->timestamp(),
            'completed_at' => null,
        ];

        try {
            $source = $this->backups->completedBackup($backupId);
            if (! $source->includesPrivateFiles || ! $source->kind->includesPrivateFiles()) {
                throw new RuntimeException('Restore requires a complete full backup.');
            }

            $report['source_artifact_sha256'] = $source->artifactSha256;
            $report['source_completed_at'] = $source->completedAt;
            $this->markPhase($report, 'resolved');

            $phase = 'compatibility';
            $this->compatibility->assertCompatible($source);

            $cipher = $this->cipherFactory->create($this->backupConfiguration->encryptionKey());
            if (! hash_equals($cipher->algorithm(), $source->encryptionAlgorithm)
                || ! hash_equals($cipher->keyId(), $source->encryptionKeyId)
            ) {
                throw new RuntimeException('The backup encryption identity is incompatible.');
            }
            $this->markPhase($report, 'compatibility_verified');

            $phase = 'decrypt';
            $bundlePath = $workspacePath.'/payload.bundle';
            $cipher->decryptFile($source->artifactPath, $bundlePath);
            $this->markPhase($report, 'authenticated_decryption_verified');

            $phase = 'bundle';
            $entries = $this->bundleReader->extract($bundlePath, $workspacePath.'/extracted');
            if (! unlink($bundlePath)) {
                throw new RuntimeException('The decrypted restore bundle could not be removed.');
            }

            if (count($entries) !== $source->entryCount) {
                throw new RuntimeException('The restore bundle entry count does not match its manifest.');
            }

            $this->payloadRestorer->preflight($entries);
            $this->markPhase($report, 'bundle_integrity_verified');
            $this->workspace->storeReport($restoreRunId, $report);

            if (! $apply) {
                $phase = 'cleanup';
                $this->workspace->discard($workspacePath);
                $report['status'] = 'dry_run_completed';
                $report['report_finalized'] = true;
                $report['completed_at'] = $this->timestamp();
                $this->markPhase($report, 'plaintext_cleanup_completed');
                $this->workspace->storeReport($restoreRunId, $report);

                return new RestoreRunResult(
                    $restoreRunId,
                    'dry_run_completed',
                    $source->backupId,
                    null,
                );
            }

            $phase = 'maintenance';
            $this->maintenance->enter($restoreRunId);
            $maintenanceEntered = true;
            $report['maintenance_retained'] = true;
            $report['scheduler_mutation_fence_retained'] = true;
            $report['worker_quiescence_retained'] = true;
            $report['containment_retained'] = true;
            $this->markPhase($report, 'maintenance_entered');
            $this->workspace->storeReport($restoreRunId, $report);

            $phase = 'safety_backup';
            $safety = $this->backupManager->create(BackupKind::DailyFull);
            $verifiedSafety = $this->backups->completedBackup($safety->backupId);
            if ($verifiedSafety->kind !== BackupKind::DailyFull || ! $verifiedSafety->includesPrivateFiles) {
                throw new RuntimeException('The current-state safety backup is incomplete.');
            }

            $this->compatibility->assertCompatible($verifiedSafety);
            $safetyCipher = $this->cipherFactory->create($this->backupConfiguration->encryptionKey());
            if (! hash_equals($safetyCipher->algorithm(), $verifiedSafety->encryptionAlgorithm)
                || ! hash_equals($safetyCipher->keyId(), $verifiedSafety->encryptionKeyId)
            ) {
                throw new RuntimeException('The current-state safety backup encryption identity is incompatible.');
            }

            $safetyBundlePath = $workspacePath.'/safety.bundle';
            $safetyCipher->decryptFile($verifiedSafety->artifactPath, $safetyBundlePath);
            $safetyEntries = $this->bundleReader->extract(
                $safetyBundlePath,
                $workspacePath.'/safety-extracted',
            );
            if (! unlink($safetyBundlePath)) {
                throw new RuntimeException('The decrypted safety backup bundle could not be removed.');
            }
            if (count($safetyEntries) !== $verifiedSafety->entryCount) {
                throw new RuntimeException('The safety backup bundle entry count does not match its manifest.');
            }
            $this->payloadRestorer->preflight($safetyEntries);

            $safetyBackupId = $verifiedSafety->backupId;
            $report['safety_backup_id'] = $safetyBackupId;
            $report['safety_artifact_sha256'] = $verifiedSafety->artifactSha256;
            $this->markPhase($report, 'safety_backup_verified');
            $this->workspace->storeReport($restoreRunId, $report);

            $phase = 'database_restore';
            $this->backups->synchronized(
                function () use (
                    &$phase,
                    &$mutationStarted,
                    &$report,
                    $restoreRunId,
                    $entries,
                ): void {
                    $mutationStarted = true;
                    $report['mutation_started'] = true;
                    $report['status'] = 'mutating';
                    $this->markPhase($report, 'database_restore_started');
                    $this->workspace->storeReport($restoreRunId, $report);
                    $this->databaseRestorer->restore($entries['database/database.sql']);
                    $this->markPhase($report, 'database_restore_completed');

                    $phase = 'payload_restore';
                    $this->workspace->storeReport($restoreRunId, $report);
                    $this->payloadRestorer->restore($entries, $restoreRunId);
                    $this->markPhase($report, 'private_config_restore_completed');

                    $phase = 'runtime_refresh';
                    $this->workspace->storeReport($restoreRunId, $report);
                    $this->maintenance->refreshRuntime($restoreRunId);
                    $this->markPhase($report, 'restored_runtime_refreshed');

                    $phase = 'post_restore_verification';
                    $this->workspace->storeReport($restoreRunId, $report);
                    $report['verification'] = $this->postRestoreVerifier->verify();
                    $this->markPhase($report, 'post_restore_verification_completed');
                },
                true,
                $this->backupConfiguration->priorityLockWaitSeconds * 1000,
            );

            $phase = 'cleanup';
            $this->workspace->discard($workspacePath);
            $this->markPhase($report, 'plaintext_cleanup_completed');

            $phase = 'pre_resume_report';
            $report['status'] = 'resume_pending';
            $report['report_finalized'] = false;
            $this->workspace->storeReport($restoreRunId, $report);

            $phase = 'resume';
            $this->maintenance->leave($restoreRunId);
            $resumeCompleted = true;
            $maintenanceEntered = false;
            $report['maintenance_retained'] = false;
            $report['scheduler_mutation_fence_retained'] = false;
            $report['worker_quiescence_retained'] = false;
            $report['containment_retained'] = false;
            $this->markPhase($report, 'maintenance_released');

            $phase = 'final_report';
            $report['status'] = 'completed';
            $report['report_finalized'] = true;
            $report['completed_at'] = $this->timestamp();

            try {
                $this->workspace->storeReport($restoreRunId, $report);
            } catch (Throwable) {
                return new RestoreRunResult(
                    $restoreRunId,
                    'completed_reporting_failed',
                    $source->backupId,
                    $safetyBackupId,
                );
            }

            return new RestoreRunResult(
                $restoreRunId,
                'completed',
                $source->backupId,
                $safetyBackupId,
            );
        } catch (Throwable $throwable) {
            if ($resumeCompleted) {
                $maintenanceEntered = false;
                $report['scheduler_mutation_fence_retained'] = false;
                $report['worker_quiescence_retained'] = false;
                $report['containment_retained'] = false;
            } elseif ($mutationStarted) {
                $containment = $this->retainContainment($restoreRunId);
                $maintenanceEntered = $containment['maintenance_owned'];
                $report['scheduler_mutation_fence_retained'] = $containment['scheduler_fence_held'];
                $report['worker_quiescence_retained'] = $containment['workers_quiesced'];
                $report['containment_retained'] = $containment['maintenance_owned']
                    && $containment['scheduler_fence_held']
                    && $containment['workers_quiesced'];
            } elseif ($maintenanceEntered) {
                try {
                    $this->maintenance->leave($restoreRunId);
                    $maintenanceEntered = false;
                    $report['scheduler_mutation_fence_retained'] = false;
                    $report['worker_quiescence_retained'] = false;
                    $report['containment_retained'] = false;
                } catch (Throwable) {
                    $containment = $this->retainContainment($restoreRunId);
                    $maintenanceEntered = $containment['maintenance_owned'];
                    $report['scheduler_mutation_fence_retained'] = $containment['scheduler_fence_held'];
                    $report['worker_quiescence_retained'] = $containment['workers_quiesced'];
                    $report['containment_retained'] = $containment['maintenance_owned']
                        && $containment['scheduler_fence_held']
                        && $containment['workers_quiesced'];
                }
            }

            try {
                if (file_exists($workspacePath) || is_link($workspacePath)) {
                    $this->workspace->discard($workspacePath);
                }
            } catch (Throwable) {
                $phase = 'plaintext_cleanup';
            }

            $report['status'] = $resumeCompleted ? 'failed_after_resume' : 'failed';
            $report['report_finalized'] = true;
            $report['failure_code'] = $resumeCompleted
                ? $phase.'_failed_after_resume'
                : $phase.'_failed';
            $report['mutation_started'] = $mutationStarted;
            $report['maintenance_retained'] = $maintenanceEntered;
            $report['completed_at'] = $this->timestamp();

            try {
                $this->workspace->storeReport($restoreRunId, $report);
            } catch (Throwable) {
                // The command still fails closed if protected reporting itself is unavailable.
            }

            throw new RuntimeException(
                $resumeCompleted
                    ? 'Controlled restore encountered a failure after normal processing resumed.'
                    : 'Controlled restore failed.',
                0,
                $throwable,
            );
        }
    }

    /** @return array{maintenance_owned:bool,scheduler_fence_held:bool,workers_quiesced:bool} */
    private function retainContainment(string $restoreRunId): array
    {
        try {
            $state = $this->maintenance->retain($restoreRunId);
        } catch (Throwable) {
            return [
                'maintenance_owned' => false,
                'scheduler_fence_held' => false,
                'workers_quiesced' => false,
            ];
        }

        return [
            'maintenance_owned' => ($state['maintenance_owned'] ?? false) === true,
            'scheduler_fence_held' => ($state['scheduler_fence_held'] ?? false) === true,
            'workers_quiesced' => ($state['workers_quiesced'] ?? false) === true,
        ];
    }

    /** @param array<string, mixed> $report */
    private function markPhase(array &$report, string $name): void
    {
        if (! is_array($report['phases'] ?? null)) {
            throw new RuntimeException('Restore report phase state is invalid.');
        }

        $report['phases'][] = [
            'name' => $name,
            'completed_at' => $this->timestamp(),
        ];
    }

    private function restoreRunId(): string
    {
        $timestamp = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Ymd\THis\Z');

        return $timestamp.'-'.bin2hex($this->random->bytes(8));
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format(DATE_ATOM);
    }
}
