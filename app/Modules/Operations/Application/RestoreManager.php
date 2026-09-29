<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Operations\Application\Contracts\BackupArtifactCipherFactory;
use App\Modules\Operations\Application\Contracts\BackupBundleReader;
use App\Modules\Operations\Application\Contracts\BackupRepository;
use App\Modules\Operations\Application\Contracts\RestoreDatabaseRestorer;
use App\Modules\Operations\Application\Contracts\RestoreMaintenanceCoordinator;
use App\Modules\Operations\Application\Contracts\RestorePayloadRestorer;
use App\Modules\Operations\Application\Contracts\RestorePostRestoreVerifier;
use App\Modules\Operations\Application\Contracts\RestoreWorkspace;
use App\Shared\Application\Clock;
use App\Shared\Application\RandomGenerator;
use DateTimeZone;
use RuntimeException;
use Throwable;

final readonly class RestoreManager
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
    ) {}

    /** @requirement BAK-002 SEC-001 OPS-003 QUA-001 */
    public function run(string $backupId, bool $apply = false): RestoreRunResult
    {
        if ($apply && ! $this->configuration->enabled) {
            throw new RuntimeException('Restore execution is disabled by configuration.');
        }

        $restoreRunId = $this->restoreRunId();
        $workspacePath = $this->workspace->create($restoreRunId);
        $phase = 'resolve';
        $maintenanceEntered = false;
        $mutationStarted = false;
        $safetyBackupId = null;
        $report = [
            'version' => 1,
            'restore_run_id' => $restoreRunId,
            'mode' => $apply ? 'apply' : 'dry_run',
            'status' => 'running',
            'source_backup_id' => $backupId,
            'source_artifact_sha256' => null,
            'source_completed_at' => null,
            'safety_backup_id' => null,
            'safety_artifact_sha256' => null,
            'mutation_started' => false,
            'maintenance_retained' => false,
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
            $report['status'] = 'verified';
            $this->workspace->storeReport($restoreRunId, $report);

            $phase = 'resume';
            $this->maintenance->leave($restoreRunId);
            $maintenanceEntered = false;
            $report['maintenance_retained'] = false;
            $this->markPhase($report, 'maintenance_released');

            $phase = 'final_report';
            $report['status'] = 'completed';
            $report['completed_at'] = $this->timestamp();
            $this->workspace->storeReport($restoreRunId, $report);

            return new RestoreRunResult(
                $restoreRunId,
                'completed',
                $source->backupId,
                $safetyBackupId,
            );
        } catch (Throwable $throwable) {
            if ($maintenanceEntered && ! $mutationStarted) {
                try {
                    $this->maintenance->leave($restoreRunId);
                    $maintenanceEntered = false;
                } catch (Throwable) {
                    // A failed pre-mutation release remains fail-closed.
                }
            } elseif (! $maintenanceEntered && $mutationStarted) {
                try {
                    $this->maintenance->enter($restoreRunId);
                    $maintenanceEntered = true;
                } catch (Throwable) {
                    // Report the uncertain containment state without concealing the failure.
                }
            }

            try {
                if (file_exists($workspacePath) || is_link($workspacePath)) {
                    $this->workspace->discard($workspacePath);
                }
            } catch (Throwable) {
                $phase = 'plaintext_cleanup';
            }

            $report['status'] = 'failed';
            $report['failure_code'] = $phase.'_failed';
            $report['mutation_started'] = $mutationStarted;
            $report['maintenance_retained'] = $maintenanceEntered;
            $report['completed_at'] = $this->timestamp();

            try {
                $this->workspace->storeReport($restoreRunId, $report);
            } catch (Throwable) {
                // The command still fails closed if protected reporting itself is unavailable.
            }

            throw new RuntimeException('Controlled restore failed.', 0, $throwable);
        }
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
