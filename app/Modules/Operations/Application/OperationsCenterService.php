<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Operations\Application\Contracts\BackupRepository;
use App\Modules\Operations\Application\Contracts\PanelOperationsSnapshotSource;
use App\Modules\Operations\Application\Contracts\PaymentOperationsSnapshotSource;
use App\Modules\Operations\Application\Contracts\ProvisioningOperationsSnapshotSource;
use App\Modules\Operations\Application\Contracts\RestoreWorkspace;
use App\Modules\Operations\Application\Contracts\TelegramOperationsSnapshotSource;
use App\Modules\Operations\Application\Contracts\UpdateWorkspace;
use App\Shared\Application\Clock;
use App\Shared\Application\OutboxOperationalSnapshotSource;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\QueueManager;
use Throwable;

final readonly class OperationsCenterService
{
    private const UTC = 'UTC';

    public function __construct(
        private DatabaseManager $database,
        private QueueManager $queues,
        private RuntimeHealthProbe $runtimeHealth,
        private AdministratorUserPermissionAuthorizer $administrators,
        private Clock $clock,
        private TelegramOperationsSnapshotSource $telegram,
        private PanelOperationsSnapshotSource $panels,
        private PaymentOperationsSnapshotSource $payments,
        private ProvisioningOperationsSnapshotSource $provisioning,
        private OutboxOperationalSnapshotSource $outbox,
        private BackupRepository $backups,
        private UpdateWorkspace $updates,
        private RestoreWorkspace $restores,
    ) {}

    /** @requirement OPS-001 OPS-002 OPS-003 ACL-001 ACL-002 DAT-003 SEC-002 */
    public function snapshot(int $actorUserId): OperationsCenterSnapshot
    {
        $this->administrators->authorizeUser($actorUserId, OperationsPermissions::VIEW);

        $now = $this->clock->now()->setTimezone(new DateTimeZone(self::UTC));
        $facts = [
            ...$this->runtimeFacts(),
            ...$this->queueFacts(),
            ...$this->failedJobFacts(),
            ...$this->outboxFacts($now),
            ...$this->heartbeatFacts($now),
            ...$this->scheduledRunFacts($now),
            ...$this->telegram->facts($now),
            ...$this->panels->facts($now),
            ...$this->payments->facts($now),
            ...$this->provisioning->facts($now),
            ...$this->alertFacts(),
            ...$this->backupAndReleaseFacts(),
            new OperationsCenterFact('providers', 'providers.sms_health', 'unknown', 0, 'no_authoritative_health_observation'),
        ];

        usort(
            $facts,
            static fn (OperationsCenterFact $left, OperationsCenterFact $right): int => $left->code <=> $right->code,
        );

        return new OperationsCenterSnapshot($now, $facts);
    }

    /** @return list<OperationsCenterFact> */
    private function runtimeFacts(): array
    {
        $checks = $this->runtimeHealth->checks();
        ksort($checks);

        $facts = [];
        foreach ($checks as $code => $check) {
            $detail = ($check['error_code'] ?? '') === '' ? null : 'error_code='.(string) $check['error_code'];
            $facts[] = new OperationsCenterFact(
                'runtime',
                'runtime.'.$code,
                $check['passed'] ? 'healthy' : 'degraded',
                $check['passed'] ? 1 : 0,
                $detail,
            );
        }

        return $facts;
    }

    /** @return list<OperationsCenterFact> */
    private function queueFacts(): array
    {
        $queueNames = [];
        $inspectionReasons = [];
        $queueGroups = [];

        $configured = config('operations.worker_heartbeat.queue_group');
        if (is_string($configured) && trim($configured) !== '') {
            $queueGroups[] = $configured;
        }

        $defaultQueue = config('queue.connections.redis.queue');
        if (is_string($defaultQueue) && trim($defaultQueue) !== '') {
            $queueGroups[] = $defaultQueue;
        }

        // Queue identity is byte/case-sensitive in the application contract, while this
        // column inherits the repository's case-insensitive MariaDB collation. Apply
        // DISTINCT, ordering, and the overflow sentinel to the binary value so the database
        // cannot discard legitimate raw identities before PHP performs bounded inspection.
        /** @var list<mixed> $observedQueueGroups */
        $observedQueueGroups = $this->database->connection()->table('worker_heartbeats')
            ->where('worker_id', '<>', 'scheduler')
            ->selectRaw('CAST(queue AS BINARY) AS queue_identity')
            ->distinct()
            ->orderBy('queue_identity')
            ->limit(21)
            ->pluck('queue_identity')
            ->values()
            ->all();

        if (count($observedQueueGroups) > 20) {
            $inspectionReasons['observed_groups_truncated'] = true;
            $observedQueueGroups = array_slice($observedQueueGroups, 0, 20);
        }

        foreach ($observedQueueGroups as $queueGroup) {
            if (! is_string($queueGroup) || trim($queueGroup) === '') {
                $inspectionReasons['unsupported_queue_identity'] = true;

                continue;
            }

            $queueGroups[] = $queueGroup;
        }

        foreach ($queueGroups as $queueGroup) {
            $parsed = $this->queueGroupIdentities($queueGroup);
            if (! $parsed['complete']) {
                $inspectionReasons['unsupported_queue_identity'] = true;
            }
            array_push($queueNames, ...$parsed['identities']);
        }

        $queueNames = array_values(array_unique($queueNames, SORT_STRING));
        if (count($queueNames) > 20) {
            $inspectionReasons['queue_identities_truncated'] = true;
            $queueNames = array_slice($queueNames, 0, 20);
        }

        $facts = [];
        if ($inspectionReasons !== []) {
            ksort($inspectionReasons);
            $facts[] = new OperationsCenterFact(
                'queue',
                'queue.inspection',
                'unknown',
                count($queueNames),
                implode(
                    ';',
                    array_map(
                        static fn (string $reason): string => $reason.'=1',
                        array_keys($inspectionReasons),
                    ),
                ),
            );
        }

        if ($queueNames === []) {
            $facts[] = new OperationsCenterFact('queue', 'queue.backlog', 'unknown', 0, 'no_queue_identity');

            return $facts;
        }

        foreach ($queueNames as $queueName) {
            $factCode = $this->queueFactCode($queueName);

            try {
                $size = $this->queues->connection()->size($queueName);
                $facts[] = new OperationsCenterFact(
                    'queue',
                    $factCode,
                    $size === 0 ? 'empty' : 'observed',
                    max(0, $size),
                    'queue='.$queueName,
                );
            } catch (Throwable) {
                $facts[] = new OperationsCenterFact(
                    'queue',
                    $factCode,
                    'unknown',
                    0,
                    'queue='.$queueName,
                );
            }
        }

        return $facts;
    }

    /**
     * @return array{identities:list<string>,complete:bool}
     */
    private function queueGroupIdentities(string $queueGroup): array
    {
        $identities = [];
        $complete = true;

        foreach (explode(',', $queueGroup) as $queue) {
            $queue = trim($queue);
            if ($queue === '' || preg_match('/\A[a-zA-Z0-9_.:-]{1,64}\z/', $queue) !== 1) {
                $complete = false;

                continue;
            }

            $identities[] = $queue;
        }

        return ['identities' => $identities, 'complete' => $complete];
    }

    private function queueFactCode(string $queueName): string
    {
        // Keep the established readable codes for canonical lowercase identities. Reserve q-
        // for a reversible encoding whenever normalization could otherwise collapse identity.
        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $queueName) === 1
            && ! str_starts_with($queueName, 'q-')
        ) {
            return 'queue.backlog.'.$queueName;
        }

        return 'queue.backlog.q-'.$this->queueIdentityBase32($queueName);
    }

    private function queueIdentityBase32(string $value): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
        $encoded = '';
        $buffer = 0;
        $bits = 0;

        $bytes = unpack('C*', $value);
        if ($bytes === false) {
            throw new \RuntimeException('Queue identity encoding failed.');
        }

        foreach ($bytes as $byte) {
            $buffer = ($buffer << 8) | $byte;
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= $alphabet[($buffer >> $bits) & 31];
            }

            $buffer &= (1 << $bits) - 1;
        }

        if ($bits > 0) {
            $encoded .= $alphabet[($buffer << (5 - $bits)) & 31];
        }

        return $encoded;
    }

    /** @return list<OperationsCenterFact> */
    private function failedJobFacts(): array
    {
        $count = (int) $this->database->connection()->table('failed_jobs')->count();
        $latest = $this->database->connection()->table('failed_jobs')->max('failed_at');

        return [new OperationsCenterFact(
            'queue',
            'queue.failed_jobs',
            $count === 0 ? 'empty' : 'manual_review',
            $count,
            null,
            $this->date($latest),
        )];
    }

    /** @return list<OperationsCenterFact> */
    private function outboxFacts(DateTimeImmutable $now): array
    {
        $snapshot = $this->outbox->snapshot($now);

        return [
            new OperationsCenterFact(
                'outbox',
                'outbox.due_backlog',
                $snapshot->dueBacklog === 0 ? 'empty' : 'observed',
                $snapshot->dueBacklog,
                null,
                $snapshot->oldestDueAtUtc,
            ),
            new OperationsCenterFact(
                'outbox',
                'outbox.review_required',
                $snapshot->reviewRequired === 0 ? 'empty' : 'manual_review',
                $snapshot->reviewRequired,
            ),
        ];
    }

    /** @return list<OperationsCenterFact> */
    private function heartbeatFacts(DateTimeImmutable $now): array
    {
        $maxAge = (int) config('operations.worker_heartbeat.stale_after_seconds', 480);
        if ($maxAge < 1 || $maxAge > 86400) {
            $maxAge = 480;
        }
        $cutoff = $now->sub(new DateInterval('PT'.$maxAge.'S'));
        $cutoffString = $cutoff->format('Y-m-d H:i:s.u');
        $connection = $this->database->connection();

        /** @var object{last_seen_at:string}|null $scheduler */
        $scheduler = $connection->table('worker_heartbeats')->where('worker_id', 'scheduler')->first(['last_seen_at']);
        $schedulerObserved = $scheduler === null ? null : $this->date($scheduler->last_seen_at);
        $schedulerState = $schedulerObserved === null
            ? 'unknown'
            : ($schedulerObserved < $cutoff ? 'degraded' : 'healthy');

        $total = (int) $connection->table('worker_heartbeats')->where('worker_id', '<>', 'scheduler')->count();
        $fresh = (int) $connection->table('worker_heartbeats')
            ->where('worker_id', '<>', 'scheduler')
            ->where('last_seen_at', '>=', $cutoffString)
            ->count();
        $stale = max(0, $total - $fresh);
        $latest = $connection->table('worker_heartbeats')->where('worker_id', '<>', 'scheduler')->max('last_seen_at');
        $latestObserved = $this->date($latest);
        $workerState = $total === 0 ? 'unknown' : ($stale > 0 ? 'degraded' : 'healthy');

        return [
            new OperationsCenterFact(
                'scheduler',
                'scheduler.heartbeat',
                $schedulerState,
                $schedulerState === 'healthy' ? 1 : 0,
                'max_age_seconds='.$maxAge,
                $schedulerObserved,
            ),
            new OperationsCenterFact(
                'workers',
                'workers.heartbeat',
                $workerState,
                $total,
                'fresh='.$fresh.';stale='.$stale.';max_age_seconds='.$maxAge,
                $latestObserved,
            ),
        ];
    }

    /** @return list<OperationsCenterFact> */
    private function scheduledRunFacts(DateTimeImmutable $now): array
    {
        $connection = $this->database->connection();
        $failedCutoff = $now->sub(new DateInterval('P1D'))->format('Y-m-d H:i:s.u');
        $staleCutoff = $now->sub(new DateInterval('PT1H'))->format('Y-m-d H:i:s.u');
        $historyCount = (int) $connection->table('scheduled_task_runs')->count();
        $failed = (int) $connection->table('scheduled_task_runs')
            ->where('started_at', '>=', $failedCutoff)
            ->where('state', 'failed')
            ->count();
        $running = (int) $connection->table('scheduled_task_runs')->where('state', 'running')->count();
        $staleRunning = (int) $connection->table('scheduled_task_runs')
            ->where('state', 'running')
            ->where('started_at', '<', $staleCutoff)
            ->count();
        $latest = $connection->table('scheduled_task_runs')->max('started_at');
        $latestObserved = $this->date($latest);

        return [
            new OperationsCenterFact(
                'scheduler',
                'scheduler.run_history',
                $historyCount === 0 ? 'unknown' : 'observed',
                $historyCount,
                $historyCount === 0 ? 'no_durable_run_evidence' : null,
                $latestObserved,
            ),
            new OperationsCenterFact('scheduler', 'scheduler.failed_runs_24h', $failed === 0 ? 'empty' : 'degraded', $failed, null, $latestObserved),
            new OperationsCenterFact('scheduler', 'scheduler.running_runs', $running === 0 ? 'empty' : 'observed', $running, null, $latestObserved),
            new OperationsCenterFact(
                'scheduler',
                'scheduler.stale_running_runs',
                $staleRunning === 0 ? 'empty' : 'degraded',
                $staleRunning,
                'stale_after_seconds=3600',
                $latestObserved,
            ),
        ];
    }

    /** @return list<OperationsCenterFact> */
    private function alertFacts(): array
    {
        $connection = $this->database->connection();
        $warning = (int) $connection->table('alerts')->whereNull('resolved_at')->where('severity', 'warning')->count();
        $critical = (int) $connection->table('alerts')
            ->whereNull('resolved_at')
            ->whereIn('severity', ['critical', 'security'])
            ->count();
        $deliveryFailed = (int) $connection->table('operational_alert_deliveries')->where('state', 'failed')->count();

        return [
            new OperationsCenterFact('alerts', 'alerts.unresolved_warning', $warning === 0 ? 'empty' : 'observed', $warning),
            new OperationsCenterFact(
                'alerts',
                'alerts.unresolved_critical_security',
                $critical === 0 ? 'empty' : 'degraded',
                $critical,
            ),
            new OperationsCenterFact(
                'alerts',
                'alerts.delivery_failed',
                $deliveryFailed === 0 ? 'empty' : 'manual_review',
                $deliveryFailed,
            ),
        ];
    }

    /** @return list<OperationsCenterFact> */
    private function backupAndReleaseFacts(): array
    {
        $facts = [];
        $backupEnabled = config('operations.backup.enabled') === true;

        if (! $backupEnabled) {
            $facts[] = new OperationsCenterFact('backup', 'backup.runtime', 'empty', 0, 'disabled');
            $facts[] = new OperationsCenterFact('backup', 'backup.completed', 'empty', 0, 'disabled');
        } else {
            try {
                $backup = $this->backups->operationalStatus();
                $inspectionComplete = $backup['inspection_complete'];
                $completedCount = $backup['completed_count'];
                $inspectedEntries = $backup['inspected_entries'];

                $runtimeDetail = $inspectionComplete
                    ? ($completedCount > 0 ? 'completed_evidence='.$completedCount : 'enabled_without_completed_backup')
                    : 'observed_completed_evidence='.$completedCount
                        .';inspected_entries='.$inspectedEntries
                        .';inspection_truncated=1';

                $completedDetail = [];
                if ($backup['latest_bytes'] !== null) {
                    $completedDetail[] = 'latest_observed_bytes='.$backup['latest_bytes'];
                }
                if (! $inspectionComplete) {
                    $completedDetail[] = 'inspected_entries='.$inspectedEntries;
                    $completedDetail[] = 'inspection_truncated=1';
                }

                $facts[] = new OperationsCenterFact(
                    'backup',
                    'backup.runtime',
                    $inspectionComplete
                        ? ($completedCount > 0 ? 'observed' : 'unknown')
                        : 'unknown',
                    1,
                    mb_substr($runtimeDetail, 0, 191),
                    $this->date($backup['latest_completed_at']),
                );
                $facts[] = new OperationsCenterFact(
                    'backup',
                    'backup.completed',
                    $inspectionComplete
                        ? ($completedCount === 0 ? 'empty' : 'observed')
                        : 'unknown',
                    $completedCount,
                    $completedDetail === [] ? null : mb_substr(implode(';', $completedDetail), 0, 191),
                    $this->date($backup['latest_completed_at']),
                );
            } catch (Throwable) {
                $facts[] = new OperationsCenterFact('backup', 'backup.runtime', 'degraded', 0, 'status_unavailable');
                $facts[] = new OperationsCenterFact('backup', 'backup.completed', 'unknown', 0, 'status_unavailable');
            }
        }

        try {
            $update = $this->updates->operationalStatus();
            $release = $update['current_release_id'];
            $releaseDetail = $release;
            if ($release !== null && $update['application_version'] !== null) {
                $releaseDetail .= ';app='.$update['application_version'];
            }
            $facts[] = new OperationsCenterFact(
                'release',
                'release.version',
                $release === null ? 'unknown' : 'observed',
                $release === null ? 0 : 1,
                $releaseDetail === null ? null : mb_substr($releaseDetail, 0, 191),
            );

            $updateInventoryComplete = $update['report_inventory_complete'];
            $updateDetail = $this->operationDetail($update['latest_status'], $update['latest_failure_code']);
            if (! $updateInventoryComplete) {
                $updateDetail = mb_substr(
                    $updateDetail
                        .';inspected_entries='.$update['inspected_entries']
                        .';inspection_truncated=1',
                    0,
                    191,
                );
            }
            $facts[] = new OperationsCenterFact(
                'update',
                'update.latest',
                $updateInventoryComplete
                    ? $this->operationState($update['latest_status'], $update['latest_failure_code'])
                    : 'unknown',
                $update['latest_status'] === null ? 0 : 1,
                $updateDetail,
                $this->date($update['latest_completed_at']),
            );
        } catch (Throwable) {
            $facts[] = new OperationsCenterFact('release', 'release.version', 'unknown', 0, 'status_unavailable');
            $facts[] = new OperationsCenterFact('update', 'update.latest', 'degraded', 0, 'status_unavailable');
        }

        try {
            $restore = $this->restores->operationalStatus();
            $restoreInventoryComplete = $restore['report_inventory_complete'];
            $restoreDetail = $this->operationDetail($restore['latest_status'], $restore['latest_failure_code']);
            if (! $restoreInventoryComplete) {
                $restoreDetail = mb_substr(
                    $restoreDetail
                        .';inspected_entries='.$restore['inspected_entries']
                        .';inspection_truncated=1',
                    0,
                    191,
                );
            }
            $facts[] = new OperationsCenterFact(
                'restore',
                'restore.latest',
                $restoreInventoryComplete
                    ? $this->operationState($restore['latest_status'], $restore['latest_failure_code'])
                    : 'unknown',
                $restore['latest_status'] === null ? 0 : 1,
                $restoreDetail,
                $this->date($restore['latest_completed_at']),
            );
        } catch (Throwable) {
            $facts[] = new OperationsCenterFact('restore', 'restore.latest', 'degraded', 0, 'status_unavailable');
        }

        return $facts;
    }

    private function operationState(?string $status, ?string $failureCode): string
    {
        if ($status === null) {
            return 'unknown';
        }
        if ($failureCode !== null || str_contains($status, 'failed')) {
            return 'degraded';
        }
        if (str_contains($status, 'required')
            || str_contains($status, 'review')
            || str_contains($status, 'pending')
            || str_contains($status, 'resume')
        ) {
            return 'manual_review';
        }

        return 'observed';
    }

    private function operationDetail(?string $status, ?string $failureCode): string
    {
        if ($status === null) {
            return 'no_durable_report';
        }

        $detail = 'status='.$status;
        if ($failureCode !== null) {
            $detail .= ';failure_code='.$failureCode;
        }

        return mb_substr($detail, 0, 191);
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone(self::UTC)))
                ->setTimezone(new DateTimeZone(self::UTC));
        } catch (Throwable) {
            return null;
        }
    }
}
