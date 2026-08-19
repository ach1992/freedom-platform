<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type ReconciliationRow object{id:int|string,public_id:string,request_key_hash:string,service_subscription_id:int|string,actor_administrator_id:int|string,service_target_id:int|string,before_remote_service_id:string,proposed_remote_service_id:?string,remote_disposition:string,remote_canonical_hash:?string,target_remote_identity_generation:int|string,target_lifecycle_version:int|string,state:string,audit_log_id:int|string|null,correlation_id:string,applied_at:?string}
 * @phpstan-type RepairReplayServiceRow object{id:int|string,service_target_id:int|string|null,remote_service_id:?string}
 */
final readonly class ServiceRepairService
{
    private const PERMISSION = 'services.repair';

    private const EVIDENCE_AUTHORITY = 'service_operational_evidence_v1';

    private const SERVICE_AUTHORITY = 'service_repair_v1';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private AdministratorPermissionAuthorizer $authorizer,
        private ServiceOperationalAuthorityGuard $authority,
        private ServiceOperationalDatabaseCapability $databaseCapability,
        private ProvisioningPanelAdapterResolver $adapters,
        private ServiceOperationalAudit $audit,
    ) {}

    /** @requirement SVC-010 PRV-003 ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 QUA-004 */
    public function previewRemoteIdentity(
        string $servicePublicId,
        string $proposedRemoteServiceId,
        ServiceOperationalContext $context,
    ): ServiceReconciliationReceipt {
        $this->authority->assertFinalized();
        $this->authorizer->authorize($context->actorAdministratorId, self::PERMISSION);
        if (! Str::isUlid($servicePublicId) || ! $this->validRemoteId($proposedRemoteServiceId)) {
            throw new DomainException('Service reconciliation identity is invalid.');
        }

        /** @var object{id:int|string,public_id:string,service_target_id:int|string|null,remote_service_id:?string,provisioned_at:?string,lifecycle_state:string,lifecycle_version:int|string,remote_identity_generation:int|string,remote_deleted_at:?string}|null $service */
        $service = $this->database->connection()->table('service_subscriptions')->where('public_id', $servicePublicId)->first([
            'id', 'public_id', 'service_target_id', 'remote_service_id', 'provisioned_at', 'lifecycle_state',
            'lifecycle_version', 'remote_identity_generation', 'remote_deleted_at',
        ]);
        if ($service === null) {
            throw new DomainException('Service Subscription does not exist.');
        }
        if ($service->remote_deleted_at !== null || $service->lifecycle_state === 'retired'
            || $service->service_target_id === null || ! is_string($service->remote_service_id)
            || $service->remote_service_id === '' || $service->provisioned_at === null) {
            throw new DomainException('Service reconciliation requires a live fully bound Service.');
        }

        $connection = $this->database->connection();
        /** @var ReconciliationRow|null $existing */
        $existing = $connection->table('service_reconciliation_cases')->where('request_key_hash', $context->requestHash())->first();
        if ($existing !== null && $existing->state === 'applied') {
            $this->assertContext($existing, $context);
            if ((int) $existing->service_subscription_id !== (int) $service->id
                || ! is_string($existing->proposed_remote_service_id)
                || ! hash_equals($existing->proposed_remote_service_id, $proposedRemoteServiceId)
                || ! hash_equals($service->remote_service_id, $proposedRemoteServiceId)) {
                throw new DomainException('Service reconciliation request fingerprint conflicts with existing evidence.');
            }

            return $this->receipt($existing, (string) $service->public_id, true);
        }
        if (hash_equals($service->remote_service_id, $proposedRemoteServiceId)) {
            throw new DomainException('Service reconciliation remote identity is unchanged.');
        }

        [$disposition, $snapshot] = $this->remoteDisposition((int) $service->service_target_id, $proposedRemoteServiceId);
        if ($snapshot !== null && ! $this->snapshotMatchesLifecycle($snapshot, $service->lifecycle_state)) {
            $disposition = 'uncertain';
            $snapshot = null;
        }

        if ($existing !== null) {
            $this->assertReplay($existing, $service, $proposedRemoteServiceId, $context);
            if ($existing->remote_disposition !== $disposition
                || ($snapshot !== null && ! is_string($existing->remote_canonical_hash))
                || ($snapshot !== null && ! hash_equals((string) $existing->remote_canonical_hash, $snapshot->canonicalHash))) {
                throw new DomainException('Service reconciliation remote evidence changed on replay.');
            }

            return $this->receipt($existing, $servicePublicId, true);
        }

        $publicId = (string) Str::ulid();
        $this->setEvidenceAuthority($connection);
        try {
            $caseId = (int) $connection->table('service_reconciliation_cases')->insertGetId([
                'public_id' => $publicId,
                'request_key_hash' => $context->requestHash(),
                'service_subscription_id' => (int) $service->id,
                'actor_administrator_id' => $context->actorAdministratorId,
                'service_target_id' => (int) $service->service_target_id,
                'before_remote_service_id' => $service->remote_service_id,
                'proposed_remote_service_id' => $proposedRemoteServiceId,
                'remote_disposition' => $disposition,
                'remote_canonical_hash' => $snapshot?->canonicalHash,
                'target_remote_identity_generation' => $this->positiveInt($service->remote_identity_generation, 'Remote identity generation'),
                'target_lifecycle_version' => $this->nonNegativeInt($service->lifecycle_version, 'Lifecycle version'),
                'state' => 'previewed',
                'audit_log_id' => null,
                'correlation_id' => $context->correlationId,
                'created_at' => $this->timestamp(),
                'applied_at' => null,
            ]);
        } finally {
            $this->clearEvidenceAuthority($connection);
        }

        /** @var ReconciliationRow|null $row */
        $row = $connection->table('service_reconciliation_cases')->where('id', $caseId)->first();
        if ($row === null) {
            throw new RuntimeException('Service reconciliation preview disappeared.');
        }

        return $this->receipt($row, $servicePublicId, false);
    }

    /** @requirement SVC-010 PRV-003 ARCH-003 ARCH-004 DAT-003 DAT-004 SEC-002 SEC-008 QUA-004 */
    public function apply(string $casePublicId, ServiceOperationalContext $context): ServiceReconciliationReceipt
    {
        $this->authority->assertFinalized();
        $this->authorizer->authorize($context->actorAdministratorId, self::PERMISSION);
        if (! Str::isUlid($casePublicId)) {
            throw new DomainException('Service reconciliation case public ID is invalid.');
        }

        /** @var ReconciliationRow|null $preview */
        $preview = $this->database->connection()->table('service_reconciliation_cases')->where('public_id', $casePublicId)->first();
        if ($preview === null) {
            throw new DomainException('Service reconciliation case does not exist.');
        }
        $this->assertContext($preview, $context);
        $servicePublicId = $this->servicePublicId((int) $preview->service_subscription_id);
        if ($preview->state === 'applied') {
            return $this->receipt($preview, $servicePublicId, true);
        }
        if ($preview->state !== 'previewed' || $preview->remote_disposition !== 'present'
            || ! is_string($preview->proposed_remote_service_id) || ! is_string($preview->remote_canonical_hash)) {
            throw new DomainException('Only a known-present reconciliation preview can be applied.');
        }

        // Provider lookup stays outside the DB transaction. No create/mutation is performed by
        // repair: absent/uncertain evidence remains informational and cannot reach this path.
        [$disposition, $snapshot] = $this->remoteDisposition((int) $preview->service_target_id, $preview->proposed_remote_service_id);
        $currentLifecycle = $this->database->connection()->table('service_subscriptions')->where('id', (int) $preview->service_subscription_id)->value('lifecycle_state');
        if ($disposition !== 'present' || $snapshot === null || ! is_string($currentLifecycle)
            || ! $this->snapshotMatchesLifecycle($snapshot, $currentLifecycle)
            || ! hash_equals($preview->remote_canonical_hash, $snapshot->canonicalHash)) {
            throw new DomainException('Service reconciliation candidate changed after preview.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use ($preview, $context, $servicePublicId): ServiceReconciliationReceipt {
            /** @var ReconciliationRow|null $case */
            $case = $connection->table('service_reconciliation_cases')->where('id', (int) $preview->id)->lockForUpdate()->first();
            if ($case === null) {
                throw new RuntimeException('Service reconciliation case disappeared before apply.');
            }
            $this->assertContext($case, $context);
            if ($case->state === 'applied') {
                return $this->receipt($case, $servicePublicId, true);
            }
            if ($case->state !== 'previewed' || $case->remote_disposition !== 'present' || ! is_string($case->proposed_remote_service_id)) {
                throw new DomainException('Service reconciliation case is not applyable.');
            }

            /** @var object{id:int|string,public_id:string,service_target_id:int|string|null,remote_service_id:?string,lifecycle_state:string,lifecycle_version:int|string,remote_identity_generation:int|string,mutation_generation:int|string,remote_deleted_at:?string}|null $service */
            $service = $connection->table('service_subscriptions')->where('id', (int) $case->service_subscription_id)->lockForUpdate()->first([
                'id', 'public_id', 'service_target_id', 'remote_service_id', 'lifecycle_state', 'lifecycle_version',
                'remote_identity_generation', 'mutation_generation', 'remote_deleted_at',
            ]);
            if ($service === null || $service->remote_deleted_at !== null || $service->lifecycle_state === 'retired'
                || (int) $service->service_target_id !== (int) $case->service_target_id
                || ! is_string($service->remote_service_id)
                || ! hash_equals($service->remote_service_id, $case->before_remote_service_id)
                || (int) $service->remote_identity_generation !== (int) $case->target_remote_identity_generation
                || (int) $service->lifecycle_version !== (int) $case->target_lifecycle_version) {
                throw new DomainException('Service reconciliation preview is stale against current Service authority.');
            }
            if ($connection->table('provisioning_operations')
                ->where('service_subscription_id', (int) $service->id)
                ->whereNotIn('state', ['succeeded', 'failed_final', 'compensated'])
                ->exists()) {
                throw new DomainException('Service reconciliation is blocked by an unresolved mutation.');
            }
            if ($connection->table('service_delivery_attempts as attempt')
                ->leftJoin('service_delivery_effects as effect', 'effect.service_delivery_attempt_id', '=', 'attempt.id')
                ->where('attempt.service_subscription_id', (int) $service->id)
                ->where(function ($query): void {
                    $query->whereNull('effect.id')->orWhereNotIn('effect.state', ['succeeded', 'failed_final']);
                })
                ->exists()) {
                throw new DomainException('Service reconciliation is blocked by unresolved delivery authority.');
            }

            $auditId = $this->audit->record(
                $connection,
                'service.operational.repair.applied',
                'service_subscription',
                $servicePublicId,
                $context,
                [
                    'remote_service_id_hash' => hash('sha256', $case->before_remote_service_id),
                    'remote_identity_generation' => (int) $case->target_remote_identity_generation,
                    'lifecycle_version' => (int) $case->target_lifecycle_version,
                ],
                [
                    'remote_service_id_hash' => hash('sha256', (string) $case->proposed_remote_service_id),
                    'remote_canonical_hash' => $case->remote_canonical_hash,
                    'remote_identity_generation' => (int) $case->target_remote_identity_generation + 1,
                    'lifecycle_version' => (int) $case->target_lifecycle_version + 1,
                ],
            );

            $this->setEvidenceAuthority($connection);
            try {
                $moved = $connection->table('service_reconciliation_cases')->where('id', (int) $case->id)->where('state', 'previewed')->update([
                    'state' => 'applying',
                    'audit_log_id' => $auditId,
                ]);
            } finally {
                $this->clearEvidenceAuthority($connection);
            }
            if ($moved !== 1) {
                throw new RuntimeException('Service reconciliation lost its preview state.');
            }

            $this->setServiceAuthority($connection, (int) $case->id, $context);
            try {
                $updated = $connection->table('service_subscriptions')
                    ->where('id', (int) $service->id)
                    ->where('remote_service_id', $case->before_remote_service_id)
                    ->where('remote_identity_generation', (int) $case->target_remote_identity_generation)
                    ->where('lifecycle_version', (int) $case->target_lifecycle_version)
                    ->update([
                        'remote_service_id' => $case->proposed_remote_service_id,
                        'remote_identity_generation' => (int) $case->target_remote_identity_generation + 1,
                        'lifecycle_version' => (int) $case->target_lifecycle_version + 1,
                        'updated_at' => $this->timestamp(),
                    ]);
            } finally {
                $this->clearServiceAuthority($connection);
            }
            if ($updated !== 1) {
                throw new RuntimeException('Service reconciliation lost its generation/lifecycle authority.');
            }

            $this->setEvidenceAuthority($connection);
            try {
                $connection->table('service_reconciliation_changes')->insert([
                    'service_reconciliation_case_id' => (int) $case->id,
                    'service_subscription_id' => (int) $service->id,
                    'field_name' => 'remote_service_id',
                    'before_value' => $case->before_remote_service_id,
                    'after_value' => $case->proposed_remote_service_id,
                    'before_remote_identity_generation' => (int) $case->target_remote_identity_generation,
                    'after_remote_identity_generation' => (int) $case->target_remote_identity_generation + 1,
                    'before_lifecycle_version' => (int) $case->target_lifecycle_version,
                    'after_lifecycle_version' => (int) $case->target_lifecycle_version + 1,
                    'audit_log_id' => $auditId,
                    'created_at' => $this->timestamp(),
                ]);
                $finished = $connection->table('service_reconciliation_cases')->where('id', (int) $case->id)->where('state', 'applying')->update([
                    'state' => 'applied',
                    'applied_at' => $this->timestamp(),
                ]);
            } finally {
                $this->clearEvidenceAuthority($connection);
            }
            if ($finished !== 1) {
                throw new RuntimeException('Service reconciliation did not finalize.');
            }

            /** @var ReconciliationRow|null $applied */
            $applied = $connection->table('service_reconciliation_cases')->where('id', (int) $case->id)->first();
            if ($applied === null) {
                throw new RuntimeException('Service reconciliation evidence disappeared after apply.');
            }

            return $this->receipt($applied, $servicePublicId, false);
        }, 3);
    }

    /** @return array{string, RemoteServiceSnapshot|null} */
    private function remoteDisposition(int $serviceTargetId, string $remoteServiceId): array
    {
        try {
            $snapshot = $this->adapters->resolve($serviceTargetId)->findByRemoteId($remoteServiceId);
        } catch (Throwable) {
            return ['uncertain', null];
        }
        if ($snapshot === null) {
            return ['absent', null];
        }
        if (! hash_equals($remoteServiceId, $snapshot->remoteId)) {
            return ['uncertain', null];
        }

        return ['present', $snapshot];
    }

    private function snapshotMatchesLifecycle(RemoteServiceSnapshot $snapshot, string $lifecycleState): bool
    {
        return ($lifecycleState === 'active' && $snapshot->status === PanelServiceStatus::Active)
            || ($lifecycleState === 'suspended' && $snapshot->status === PanelServiceStatus::Suspended);
    }

    private function validRemoteId(string $remoteId): bool
    {
        return strlen($remoteId) >= 1 && strlen($remoteId) <= 191
            && preg_match('/\A[A-Za-z0-9._:-]+\z/', $remoteId) === 1;
    }

    /** @param ReconciliationRow $row */
    private function assertContext(object $row, ServiceOperationalContext $context): void
    {
        if (! hash_equals($row->request_key_hash, $context->requestHash())
            || ! hash_equals($row->correlation_id, $context->correlationId)
            || (int) $row->actor_administrator_id !== $context->actorAdministratorId) {
            throw new DomainException('Service reconciliation request identity conflicts with existing evidence.');
        }
    }

    /**
     * @param  ReconciliationRow  $row
     * @param  RepairReplayServiceRow  $service
     */
    private function assertReplay(object $row, object $service, string $proposedRemoteServiceId, ServiceOperationalContext $context): void
    {
        $this->assertContext($row, $context);
        if ((int) $row->service_subscription_id !== (int) $service->id
            || (int) $row->service_target_id !== (int) $service->service_target_id
            || ! hash_equals($row->before_remote_service_id, (string) $service->remote_service_id)
            || ! is_string($row->proposed_remote_service_id)
            || ! hash_equals($row->proposed_remote_service_id, $proposedRemoteServiceId)) {
            throw new DomainException('Service reconciliation request fingerprint conflicts with existing evidence.');
        }
    }

    /** @param ReconciliationRow $row */
    private function receipt(object $row, string $servicePublicId, bool $replayed): ServiceReconciliationReceipt
    {
        return new ServiceReconciliationReceipt(
            (int) $row->id,
            $row->public_id,
            $servicePublicId,
            $row->remote_disposition,
            $row->state,
            $row->before_remote_service_id,
            $row->proposed_remote_service_id,
            (int) $row->target_remote_identity_generation,
            (int) $row->target_lifecycle_version,
            $replayed,
        );
    }

    private function servicePublicId(int $serviceId): string
    {
        $value = $this->database->connection()->table('service_subscriptions')->where('id', $serviceId)->value('public_id');
        if (! is_string($value) || ! Str::isUlid($value)) {
            throw new RuntimeException('Service Subscription identity is unavailable.');
        }

        return $value;
    }

    private function setEvidenceAuthority(Connection $connection): void
    {
        $this->databaseCapability->apply($connection);
        try {
            $connection->statement('SET @app_service_operational_evidence_authority = ?', [self::EVIDENCE_AUTHORITY]);
        } catch (\Throwable $exception) {
            $this->databaseCapability->clear($connection);
            throw $exception;
        }
    }

    private function clearEvidenceAuthority(Connection $connection): void
    {
        try {
            $this->databaseCapability->clear($connection);
        } finally {
            $connection->statement('SET @app_service_operational_evidence_authority = NULL');
        }
    }

    private function setServiceAuthority(Connection $connection, int $evidenceId, ServiceOperationalContext $context): void
    {
        $this->databaseCapability->apply($connection);
        try {
            $connection->statement('SET @app_service_operational_authority = ?', [self::SERVICE_AUTHORITY]);
            $connection->statement('SET @app_service_operational_evidence_id = ?', [$evidenceId]);
            $connection->statement('SET @app_service_operational_request_hash = ?', [$context->requestHash()]);
            $connection->statement('SET @app_service_operational_correlation_id = ?', [$context->correlationId]);
        } catch (\Throwable $exception) {
            $this->databaseCapability->clear($connection);
            throw $exception;
        }
    }

    private function clearServiceAuthority(Connection $connection): void
    {
        try {
            $this->databaseCapability->clear($connection);
        } finally {
            $connection->statement(
                'SET @app_service_operational_authority = NULL, @app_service_operational_evidence_id = NULL, @app_service_operational_request_hash = NULL, @app_service_operational_correlation_id = NULL',
            );
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function positiveInt(int|string $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }

    private function nonNegativeInt(int|string $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 0) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }
}
