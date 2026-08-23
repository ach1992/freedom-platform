<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Provisioning\Domain\ServiceSyncAnomalyType;
use App\Modules\Provisioning\Domain\ServiceSyncResolutionAction;
use App\Modules\Provisioning\Domain\ServiceSyncScope;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type SyncServiceRow object{id:int|string,public_id:string,service_target_id:int|string,remote_service_id:string,lifecycle_state:string,lifecycle_version:int|string,remote_identity_generation:int|string,mutation_generation:int|string}
 * @phpstan-type SyncObservation array{disposition:string,remote:?RemoteServiceSnapshot}
 * @phpstan-type SyncPreviousRow object{local_lifecycle_version:int|string,local_remote_identity_generation:int|string,local_mutation_generation:int|string,remote_status:string|null,remote_data_limit_bytes:int|string|null,remote_expires_at:string|null}
 * @phpstan-type SyncCandidateResult array{processed:bool,anomalies:int,provider_failure:bool}
 * @phpstan-type SyncAnomalyRow object{id:int|string,public_id:string,service_subscription_id:int|string,classification:string,severity:string,state:string,occurrence_count:int|string,resolution_action:?string,resolution_actor_administrator_id:int|string|null,resolution_request_hash:?string,resolution_reason_code:?string,resolution_reason:?string,resolution_correlation_id:?string,service_public_id:string}
 */
/** @requirement SVC-001 SVC-010 SVC-013 PRV-003 ARCH-003 ARCH-004 DAT-003 DAT-004 SEC-002 QUA-004 */
final readonly class ServiceSynchronizationService
{
    private const RESOLUTION_PERMISSION = 'services.repair';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private ProvisioningPanelAdapterResolver $adapters,
        private ServiceSyncAnomalyDetector $detector,
        private AdministratorPermissionAuthorizer $authorizer,
    ) {}

    public function syncOne(string $servicePublicId): ServiceSyncRunReceipt
    {
        if (! Str::isUlid($servicePublicId)) {
            throw new DomainException('Service sync public ID is invalid.');
        }

        $service = $this->eligibleServiceByPublicId($servicePublicId);
        if ($service === null) {
            throw new DomainException('Service sync requires a live fully bound Service.');
        }

        return $this->process(ServiceSyncScope::Service, [$service], (int) $service->id);
    }

    public function processBatch(int $limit): ServiceSyncRunReceipt
    {
        if ($limit < 1 || $limit > 500) {
            throw new DomainException('Service sync batch limit must be between 1 and 500.');
        }

        return $this->process(ServiceSyncScope::Batch, $this->candidateServices($limit), null);
    }

    public function processFull(): ServiceSyncRunReceipt
    {
        return $this->process(ServiceSyncScope::Full, $this->candidateServices(null), null);
    }

    public function resolve(
        string $anomalyPublicId,
        ServiceSyncResolutionAction $action,
        ServiceOperationalContext $context,
    ): ServiceSyncResolutionReceipt {
        if (! Str::isUlid($anomalyPublicId)) {
            throw new DomainException('Service sync anomaly public ID is invalid.');
        }
        $this->authorizer->authorize($context->actorAdministratorId, self::RESOLUTION_PERMISSION);

        $row = $this->anomalyByPublicId($anomalyPublicId);
        $requestHash = $context->requestHash();
        if ($row->resolution_request_hash !== null) {
            return $this->resolutionReplay($row, $action, $context, $requestHash);
        }
        if ($row->state !== 'open') {
            throw new DomainException('Service sync anomaly is not open for resolution.');
        }
        $this->assertResolutionAction($row->classification, $action);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $row,
            $anomalyPublicId,
            $action,
            $context,
            $requestHash,
        ): ServiceSyncResolutionReceipt {
            /** @var SyncAnomalyRow|null $locked */
            $locked = $connection->table('service_sync_anomalies as anomaly')
                ->join('service_subscriptions as service', 'service.id', '=', 'anomaly.service_subscription_id')
                ->where('anomaly.id', (int) $row->id)
                ->lockForUpdate()
                ->first([
                    'anomaly.id', 'anomaly.public_id', 'anomaly.service_subscription_id', 'anomaly.classification',
                    'anomaly.severity', 'anomaly.state', 'anomaly.occurrence_count', 'anomaly.resolution_action',
                    'anomaly.resolution_actor_administrator_id', 'anomaly.resolution_request_hash',
                    'anomaly.resolution_reason_code', 'anomaly.resolution_reason', 'anomaly.resolution_correlation_id',
                    'service.public_id as service_public_id',
                ]);
            if ($locked === null) {
                throw new RuntimeException('Service sync anomaly disappeared during resolution.');
            }
            if ($locked->resolution_request_hash !== null) {
                return $this->resolutionReplay($locked, $action, $context, $requestHash);
            }
            if ($locked->state !== 'open') {
                throw new DomainException('Service sync anomaly is not open for resolution.');
            }
            $this->assertResolutionAction($locked->classification, $action);

            $nextState = match ($action) {
                ServiceSyncResolutionAction::AdoptRemoteState, ServiceSyncResolutionAction::Ignore => 'resolved',
                ServiceSyncResolutionAction::FlagManualReview => 'manual_review',
                ServiceSyncResolutionAction::Reprovision => 'action_requested',
            };
            $eventType = match ($nextState) {
                'resolved' => 'resolved',
                'manual_review' => 'manual_review',
                'action_requested' => 'action_requested',
                default => throw new RuntimeException('Service sync resolution state is invalid.'),
            };
            $timestamp = $this->timestamp();

            ServiceSyncDatabaseAuthority::resolution(
                $connection,
                (int) $locked->service_subscription_id,
                $context->actorAdministratorId,
                $requestHash,
                $context->correlationId,
            );
            try {
                $updated = $connection->table('service_sync_anomalies')
                    ->where('id', (int) $locked->id)
                    ->where('state', 'open')
                    ->whereNull('resolution_request_hash')
                    ->update([
                        'state' => $nextState,
                        'resolution_action' => $action->value,
                        'resolution_actor_administrator_id' => $context->actorAdministratorId,
                        'resolution_request_hash' => $requestHash,
                        'resolution_reason_code' => $context->reasonCode,
                        'resolution_reason' => $context->reason,
                        'resolution_correlation_id' => $context->correlationId,
                        'resolved_at' => $timestamp,
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Service sync anomaly lost its open resolution state.');
                }
                $connection->table('service_sync_anomaly_events')->insert([
                    'service_sync_anomaly_id' => (int) $locked->id,
                    'service_sync_snapshot_id' => null,
                    'event_type' => $eventType,
                    'from_state' => 'open',
                    'to_state' => $nextState,
                    'occurrence_count' => (int) $locked->occurrence_count,
                    'actor_administrator_id' => $context->actorAdministratorId,
                    'reason_code' => $context->reasonCode,
                    'correlation_id' => $context->correlationId,
                    'created_at' => $timestamp,
                ]);
            } finally {
                ServiceSyncDatabaseAuthority::clear($connection);
            }

            return new ServiceSyncResolutionReceipt(
                $anomalyPublicId,
                $locked->service_public_id,
                $action,
                $nextState,
                false,
            );
        }, 3);
    }

    /** @param list<SyncServiceRow> $services */
    private function process(ServiceSyncScope $scope, array $services, ?int $serviceId): ServiceSyncRunReceipt
    {
        [$runId, $runPublicId, $correlationId] = $this->createRun($scope, $serviceId);
        $processed = 0;
        $anomalies = 0;
        $failures = 0;
        $skipped = 0;

        try {
            foreach ($services as $service) {
                $result = $this->syncCandidate($runId, $service, $correlationId);
                if (! $result['processed']) {
                    $skipped++;
                    continue;
                }
                $processed++;
                $anomalies += $result['anomalies'];
                if ($result['provider_failure']) {
                    $failures++;
                }
            }
        } catch (Throwable $exception) {
            $this->finalizeRun($runId, $correlationId, count($services), $processed, $anomalies, $failures + 1, $skipped, 'failed');
            throw $exception;
        }

        $state = ($anomalies > 0 || $failures > 0) ? 'completed_with_anomalies' : 'completed';
        $this->finalizeRun($runId, $correlationId, count($services), $processed, $anomalies, $failures, $skipped, $state);

        return new ServiceSyncRunReceipt(
            $runId,
            $runPublicId,
            $scope,
            count($services),
            $processed,
            $anomalies,
            $failures,
            $skipped,
        );
    }

    /** @param SyncServiceRow $service @return SyncCandidateResult */
    private function syncCandidate(int $runId, object $service, string $correlationId): array
    {
        $leaseToken = $this->acquireLease((int) $service->id);
        if ($leaseToken === null) {
            return ['processed' => false, 'anomalies' => 0, 'provider_failure' => false];
        }

        try {
            $observation = $this->observeRemote($service);
            $anomalyCount = $this->persistSnapshotAndAnomalies($runId, $service, $observation, $correlationId);

            return [
                'processed' => true,
                'anomalies' => $anomalyCount,
                'provider_failure' => $observation['disposition'] === 'unavailable',
            ];
        } finally {
            $this->releaseLease((int) $service->id, $leaseToken);
        }
    }

    /** @param SyncServiceRow $service @return SyncObservation */
    private function observeRemote(object $service): array
    {
        $connection = $this->database->connection();
        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('Service sync provider read must run outside a database transaction.');
        }

        try {
            $remote = $this->adapters->resolve((int) $service->service_target_id)
                ->findByRemoteId($service->remote_service_id);
        } catch (Throwable) {
            return ['disposition' => 'unavailable', 'remote' => null];
        }
        if ($remote === null) {
            return ['disposition' => 'missing', 'remote' => null];
        }

        return [
            'disposition' => hash_equals($service->remote_service_id, $remote->remoteId) ? 'present' : 'identity_mismatch',
            'remote' => $remote,
        ];
    }

    /** @param SyncServiceRow $service @param SyncObservation $observation */
    private function persistSnapshotAndAnomalies(int $runId, object $service, array $observation, string $correlationId): int
    {
        return $this->database->connection()->transaction(function (Connection $connection) use (
            $runId,
            $service,
            $observation,
            $correlationId,
        ): int {
            $locked = $this->eligibleServiceByIdOn($connection, (int) $service->id, true);
            if ($locked === null || ! $this->sameServiceFacts($service, $locked)) {
                throw new DomainException('Service changed while synchronization evidence was being collected.');
            }

            /** @var SyncPreviousRow|null $previous */
            $previous = $connection->table('service_sync_snapshots')
                ->where('service_subscription_id', (int) $locked->id)
                ->where('remote_disposition', 'present')
                ->where('local_lifecycle_version', (int) $locked->lifecycle_version)
                ->where('local_remote_identity_generation', (int) $locked->remote_identity_generation)
                ->where('local_mutation_generation', (int) $locked->mutation_generation)
                ->orderByDesc('observed_at')
                ->orderByDesc('id')
                ->first([
                    'local_lifecycle_version', 'local_remote_identity_generation', 'local_mutation_generation',
                    'remote_status', 'remote_data_limit_bytes', 'remote_expires_at',
                ]);

            $remote = $observation['remote'];
            $timestamp = $this->timestamp();
            ServiceSyncDatabaseAuthority::snapshot($connection, $runId, (int) $locked->id, $correlationId);
            try {
                $snapshotId = (int) $connection->table('service_sync_snapshots')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'service_sync_run_id' => $runId,
                    'service_subscription_id' => (int) $locked->id,
                    'service_target_id' => (int) $locked->service_target_id,
                    'local_lifecycle_state' => $locked->lifecycle_state,
                    'local_lifecycle_version' => (int) $locked->lifecycle_version,
                    'local_remote_identity_generation' => (int) $locked->remote_identity_generation,
                    'local_mutation_generation' => (int) $locked->mutation_generation,
                    'expected_remote_id_hash' => hash('sha256', $locked->remote_service_id),
                    'remote_disposition' => $observation['disposition'],
                    'remote_id_hash' => $remote === null ? null : hash('sha256', $remote->remoteId),
                    'remote_status' => $remote?->status->value,
                    'remote_data_limit_bytes' => $remote?->dataLimitBytes,
                    'remote_used_bytes' => $remote?->usedBytes,
                    'remote_expires_at' => $remote?->expiresAt === null ? null : $this->databaseDateTime($remote->expiresAt),
                    'remote_canonical_hash' => $remote?->canonicalHash,
                    'observed_at' => $timestamp,
                ]);
            } finally {
                ServiceSyncDatabaseAuthority::clear($connection);
            }

            $findings = $this->detector->detect(
                [
                    'lifecycle_state' => $locked->lifecycle_state,
                    'lifecycle_version' => (int) $locked->lifecycle_version,
                    'remote_identity_generation' => (int) $locked->remote_identity_generation,
                    'mutation_generation' => (int) $locked->mutation_generation,
                ],
                [
                    'disposition' => $observation['disposition'],
                    'status' => $remote?->status->value,
                    'data_limit_bytes' => $remote?->dataLimitBytes,
                    'expires_at' => $remote?->expiresAt,
                ],
                $previous === null ? null : (array) $previous,
            );

            foreach ($findings as $finding) {
                $this->recordAnomaly(
                    $connection,
                    $runId,
                    $snapshotId,
                    $locked,
                    $finding['type'],
                    $finding['severity']->value,
                    $timestamp,
                    $correlationId,
                );
            }

            return count($findings);
        }, 3);
    }

    /** @param SyncServiceRow $service */
    private function recordAnomaly(
        Connection $connection,
        int $runId,
        int $snapshotId,
        object $service,
        ServiceSyncAnomalyType $type,
        string $severity,
        string $timestamp,
        string $correlationId,
    ): void {
        $key = hash('sha256', implode('|', [
            'service-sync-anomaly-v1',
            (string) $service->id,
            $type->value,
            (string) $service->remote_identity_generation,
            (string) $service->mutation_generation,
            (string) $service->lifecycle_version,
        ]));

        ServiceSyncDatabaseAuthority::anomaly($connection, $runId, (int) $service->id, $correlationId);
        try {
            /** @var object{id:int|string,state:string,occurrence_count:int|string}|null $existing */
            $existing = $connection->table('service_sync_anomalies')
                ->where('anomaly_key', $key)
                ->lockForUpdate()
                ->first(['id', 'state', 'occurrence_count']);
            if ($existing === null) {
                $anomalyId = (int) $connection->table('service_sync_anomalies')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'service_subscription_id' => (int) $service->id,
                    'anomaly_key' => $key,
                    'classification' => $type->value,
                    'severity' => $severity,
                    'first_snapshot_id' => $snapshotId,
                    'latest_snapshot_id' => $snapshotId,
                    'state' => 'open',
                    'occurrence_count' => 1,
                    'resolution_action' => null,
                    'resolution_actor_administrator_id' => null,
                    'resolution_request_hash' => null,
                    'resolution_reason_code' => null,
                    'resolution_reason' => null,
                    'resolution_correlation_id' => null,
                    'first_detected_at' => $timestamp,
                    'last_detected_at' => $timestamp,
                    'resolved_at' => null,
                ]);
                $fromState = null;
                $toState = 'open';
                $occurrenceCount = 1;
                $eventType = 'detected';
            } else {
                $anomalyId = (int) $existing->id;
                $occurrenceCount = (int) $existing->occurrence_count + 1;
                $updated = $connection->table('service_sync_anomalies')
                    ->where('id', $anomalyId)
                    ->where('occurrence_count', (int) $existing->occurrence_count)
                    ->update([
                        'latest_snapshot_id' => $snapshotId,
                        'occurrence_count' => $occurrenceCount,
                        'last_detected_at' => $timestamp,
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Service sync anomaly recurrence lost its evidence state.');
                }
                $fromState = $existing->state;
                $toState = $existing->state;
                $eventType = 'seen';
            }

            $connection->table('service_sync_anomaly_events')->insert([
                'service_sync_anomaly_id' => $anomalyId,
                'service_sync_snapshot_id' => $snapshotId,
                'event_type' => $eventType,
                'from_state' => $fromState,
                'to_state' => $toState,
                'occurrence_count' => $occurrenceCount,
                'actor_administrator_id' => null,
                'reason_code' => null,
                'correlation_id' => $correlationId,
                'created_at' => $timestamp,
            ]);
        } finally {
            ServiceSyncDatabaseAuthority::clear($connection);
        }
    }

    /** @return array{int,string,string} */
    private function createRun(ServiceSyncScope $scope, ?int $serviceId): array
    {
        $publicId = (string) Str::ulid();
        $correlationId = 'service-sync:'.$publicId;
        $connection = $this->database->connection();
        ServiceSyncDatabaseAuthority::runCreate($connection, $correlationId);
        try {
            $runId = (int) $connection->table('service_sync_runs')->insertGetId([
                'public_id' => $publicId,
                'run_key_hash' => hash('sha256', 'service-sync-run|'.$publicId),
                'scope' => $scope->value,
                'service_subscription_id' => $serviceId,
                'state' => 'running',
                'candidate_count' => 0,
                'processed_count' => 0,
                'anomaly_count' => 0,
                'failure_count' => 0,
                'skipped_count' => 0,
                'correlation_id' => $correlationId,
                'started_at' => $this->timestamp(),
                'completed_at' => null,
            ]);
        } finally {
            ServiceSyncDatabaseAuthority::clear($connection);
        }

        return [$runId, $publicId, $correlationId];
    }

    private function finalizeRun(
        int $runId,
        string $correlationId,
        int $candidates,
        int $processed,
        int $anomalies,
        int $failures,
        int $skipped,
        string $state,
    ): void {
        $connection = $this->database->connection();
        ServiceSyncDatabaseAuthority::runFinalize($connection, $runId, $correlationId);
        try {
            $updated = $connection->table('service_sync_runs')
                ->where('id', $runId)
                ->where('state', 'running')
                ->update([
                    'state' => $state,
                    'candidate_count' => $candidates,
                    'processed_count' => $processed,
                    'anomaly_count' => $anomalies,
                    'failure_count' => $failures,
                    'skipped_count' => $skipped,
                    'completed_at' => $this->timestamp(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Service sync run lost its running state before finalization.');
            }
        } finally {
            ServiceSyncDatabaseAuthority::clear($connection);
        }
    }

    private function acquireLease(int $serviceId): ?string
    {
        $leaseSeconds = $this->leaseSeconds();
        $token = bin2hex(random_bytes(24));

        return $this->database->connection()->transaction(function (Connection $connection) use ($serviceId, $leaseSeconds, $token): ?string {
            /** @var object{lease_token_hash:string,expires_at:string}|null $row */
            $row = $connection->table('service_sync_leases')
                ->where('service_subscription_id', $serviceId)
                ->lockForUpdate()
                ->first(['lease_token_hash', 'expires_at']);
            $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
            if ($row !== null && new DateTimeImmutable($row->expires_at) > $now) {
                return null;
            }

            ServiceSyncDatabaseAuthority::lease($connection, $serviceId, $token);
            try {
                $values = [
                    'lease_token_hash' => hash('sha256', $token),
                    'claimed_at' => $this->databaseDateTime($now),
                    'expires_at' => $this->databaseDateTime($now->modify('+'.$leaseSeconds.' seconds')),
                ];
                if ($row === null) {
                    $connection->table('service_sync_leases')->insert(['service_subscription_id' => $serviceId] + $values);
                } else {
                    $updated = $connection->table('service_sync_leases')
                        ->where('service_subscription_id', $serviceId)
                        ->where('lease_token_hash', $row->lease_token_hash)
                        ->update($values);
                    if ($updated !== 1) {
                        throw new RuntimeException('Service sync lease takeover lost its expired lease.');
                    }
                }
            } finally {
                ServiceSyncDatabaseAuthority::clear($connection);
            }

            return $token;
        }, 3);
    }

    private function releaseLease(int $serviceId, string $token): void
    {
        $connection = $this->database->connection();
        ServiceSyncDatabaseAuthority::lease($connection, $serviceId, $token);
        try {
            $connection->table('service_sync_leases')
                ->where('service_subscription_id', $serviceId)
                ->where('lease_token_hash', hash('sha256', $token))
                ->delete();
        } finally {
            ServiceSyncDatabaseAuthority::clear($connection);
        }
    }

    /** @return list<SyncServiceRow> */
    private function candidateServices(?int $limit): array
    {
        $connection = $this->database->connection();
        $latest = $connection->table('service_sync_snapshots')
            ->selectRaw('service_subscription_id, MAX(observed_at) AS last_observed_at')
            ->groupBy('service_subscription_id');
        $query = $connection->table('service_subscriptions as service')
            ->leftJoinSub($latest, 'latest_sync', 'latest_sync.service_subscription_id', '=', 'service.id')
            ->whereNotNull('service.provisioned_at')
            ->whereNotNull('service.service_target_id')
            ->whereNotNull('service.remote_service_id')
            ->whereNull('service.remote_deleted_at')
            ->whereIn('service.lifecycle_state', ['active', 'suspended'])
            ->orderByRaw('latest_sync.last_observed_at IS NOT NULL')
            ->orderBy('latest_sync.last_observed_at')
            ->orderBy('service.id');
        if ($limit !== null) {
            $query->limit($limit);
        }

        /** @var list<SyncServiceRow> $rows */
        $rows = $query->get($this->serviceColumns('service'))->all();

        return $rows;
    }

    /** @return SyncServiceRow|null */
    private function eligibleServiceByPublicId(string $publicId): ?object
    {
        return $this->eligibleServiceQuery($this->database->connection(), 'service')
            ->where('service.public_id', $publicId)
            ->first($this->serviceColumns('service'));
    }

    /** @return SyncServiceRow|null */
    private function eligibleServiceByIdOn(Connection $connection, int $id, bool $lock): ?object
    {
        $query = $this->eligibleServiceQuery($connection, 'service')->where('service.id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first($this->serviceColumns('service'));
    }

    private function eligibleServiceQuery(Connection $connection, string $alias)
    {
        return $connection->table('service_subscriptions as '.$alias)
            ->whereNotNull($alias.'.provisioned_at')
            ->whereNotNull($alias.'.service_target_id')
            ->whereNotNull($alias.'.remote_service_id')
            ->whereNull($alias.'.remote_deleted_at')
            ->whereIn($alias.'.lifecycle_state', ['active', 'suspended']);
    }

    /** @return list<string> */
    private function serviceColumns(string $alias): array
    {
        return [
            $alias.'.id', $alias.'.public_id', $alias.'.service_target_id', $alias.'.remote_service_id',
            $alias.'.lifecycle_state', $alias.'.lifecycle_version', $alias.'.remote_identity_generation',
            $alias.'.mutation_generation',
        ];
    }

    /** @param SyncServiceRow $left @param SyncServiceRow $right */
    private function sameServiceFacts(object $left, object $right): bool
    {
        return (int) $left->id === (int) $right->id
            && (int) $left->service_target_id === (int) $right->service_target_id
            && hash_equals($left->remote_service_id, $right->remote_service_id)
            && $left->lifecycle_state === $right->lifecycle_state
            && (int) $left->lifecycle_version === (int) $right->lifecycle_version
            && (int) $left->remote_identity_generation === (int) $right->remote_identity_generation
            && (int) $left->mutation_generation === (int) $right->mutation_generation;
    }

    /** @return SyncAnomalyRow */
    private function anomalyByPublicId(string $publicId): object
    {
        /** @var SyncAnomalyRow|null $row */
        $row = $this->database->connection()->table('service_sync_anomalies as anomaly')
            ->join('service_subscriptions as service', 'service.id', '=', 'anomaly.service_subscription_id')
            ->where('anomaly.public_id', $publicId)
            ->first([
                'anomaly.id', 'anomaly.public_id', 'anomaly.service_subscription_id', 'anomaly.classification',
                'anomaly.severity', 'anomaly.state', 'anomaly.occurrence_count', 'anomaly.resolution_action',
                'anomaly.resolution_actor_administrator_id', 'anomaly.resolution_request_hash',
                'anomaly.resolution_reason_code', 'anomaly.resolution_reason', 'anomaly.resolution_correlation_id',
                'service.public_id as service_public_id',
            ]);
        if ($row === null) {
            throw new DomainException('Service sync anomaly does not exist.');
        }

        return $row;
    }

    /** @param SyncAnomalyRow $row */
    private function resolutionReplay(
        object $row,
        ServiceSyncResolutionAction $action,
        ServiceOperationalContext $context,
        string $requestHash,
    ): ServiceSyncResolutionReceipt {
        if (! is_string($row->resolution_request_hash)
            || ! hash_equals($row->resolution_request_hash, $requestHash)
            || $row->resolution_action !== $action->value
            || (int) $row->resolution_actor_administrator_id !== $context->actorAdministratorId
            || $row->resolution_reason_code !== $context->reasonCode
            || $row->resolution_reason !== $context->reason
            || $row->resolution_correlation_id !== $context->correlationId) {
            throw new DomainException('Service sync anomaly resolution request conflicts with existing evidence.');
        }

        return new ServiceSyncResolutionReceipt(
            $row->public_id,
            $row->service_public_id,
            $action,
            $row->state,
            true,
        );
    }

    private function assertResolutionAction(string $classification, ServiceSyncResolutionAction $action): void
    {
        $type = ServiceSyncAnomalyType::tryFrom($classification)
            ?? throw new RuntimeException('Stored Service sync anomaly classification is invalid.');
        if ($action === ServiceSyncResolutionAction::AdoptRemoteState
            && ! in_array($type, [ServiceSyncAnomalyType::UnexpectedEntitlement, ServiceSyncAnomalyType::ExpiredLocalActiveRemote], true)) {
            throw new DomainException('This Service sync anomaly cannot adopt remote state safely.');
        }
        if ($action === ServiceSyncResolutionAction::Reprovision
            && $type === ServiceSyncAnomalyType::RemoteIdentityMismatch) {
            throw new DomainException('Remote identity mismatch requires explicit repair or manual review before reprovisioning.');
        }
    }

    private function leaseSeconds(): int
    {
        $value = filter_var(config('service_sync.lease_seconds', 120), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 30, 'max_range' => 900],
        ]);
        if ($value === false) {
            throw new DomainException('Service sync lease seconds must be between 30 and 900.');
        }

        return (int) $value;
    }

    private function timestamp(): string
    {
        return $this->databaseDateTime($this->clock->now());
    }

    private function databaseDateTime(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
