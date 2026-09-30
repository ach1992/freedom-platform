<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Shared\Application\Clock;
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
            ...$this->telegramIngressFacts(),
            ...$this->panelFacts(),
            ...$this->paymentHealthFacts($now),
            ...$this->cardToCardFacts(),
            ...$this->giftCardFacts(),
            ...$this->provisioningFacts(),
            ...$this->serviceSyncFacts(),
            ...$this->alertFacts(),
            ...$this->backupAndReleaseFacts(),
            new OperationsCenterFact(
                'providers',
                'providers.sms_health',
                'unknown',
                0,
                'no_authoritative_health_observation',
            ),
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
            $detail = null;
            if (($check['error_code'] ?? '') !== '') {
                $detail = 'error_code='.(string) $check['error_code'];
            }
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

        $configured = config('operations.worker_heartbeat.queue_group');
        if (is_string($configured)) {
            foreach (explode(',', $configured) as $queue) {
                $queue = trim($queue);
                if ($queue !== '' && preg_match('/\A[a-zA-Z0-9_.:-]{1,64}\z/', $queue) === 1) {
                    $queueNames[] = $queue;
                }
            }
        }

        $defaultQueue = config('queue.connections.redis.queue');
        if (is_string($defaultQueue)
            && $defaultQueue !== ''
            && preg_match('/\A[a-zA-Z0-9_.:-]{1,64}\z/', $defaultQueue) === 1
        ) {
            $queueNames[] = $defaultQueue;
        }

        /** @var list<string> $observedQueues */
        $observedQueues = $this->database->connection()->table('worker_heartbeats')
            ->where('worker_id', '<>', 'scheduler')
            ->distinct()
            ->orderBy('queue')
            ->limit(20)
            ->pluck('queue')
            ->filter(static fn (mixed $queue): bool => is_string($queue) && $queue !== '')
            ->values()
            ->all();
        array_push($queueNames, ...$observedQueues);
        $queueNames = array_slice(array_values(array_unique($queueNames)), 0, 20);

        if ($queueNames === []) {
            return [new OperationsCenterFact('queue', 'queue.backlog', 'unknown', 0, 'no_queue_identity')];
        }

        $facts = [];
        foreach ($queueNames as $queueName) {
            try {
                $size = $this->queues->connection()->size($queueName);
                $facts[] = new OperationsCenterFact(
                    'queue',
                    'queue.backlog.'.strtolower(str_replace(':', '-', $queueName)),
                    $size === 0 ? 'empty' : 'observed',
                    max(0, $size),
                    'queue='.$queueName,
                );
            } catch (Throwable) {
                $facts[] = new OperationsCenterFact(
                    'queue',
                    'queue.backlog.'.strtolower(str_replace(':', '-', $queueName)),
                    'unknown',
                    0,
                    'queue='.$queueName,
                );
            }
        }

        return $facts;
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
        $connection = $this->database->connection();
        $nowString = $now->format('Y-m-d H:i:s.u');
        $due = (int) $connection->table('outbox_messages')
            ->whereNull('processed_at')
            ->whereIn('dispatch_state', ['pending', 'retry'])
            ->where('available_at', '<=', $nowString)
            ->count();
        $review = (int) $connection->table('outbox_messages')
            ->whereNull('processed_at')
            ->where('dispatch_state', 'review_required')
            ->count();

        return [
            new OperationsCenterFact(
                'outbox',
                'outbox.due_backlog',
                $due === 0 ? 'empty' : 'observed',
                $due,
            ),
            new OperationsCenterFact(
                'outbox',
                'outbox.review_required',
                $review === 0 ? 'empty' : 'manual_review',
                $review,
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

        /** @var object{last_seen_at:string}|null $scheduler */
        $scheduler = $this->database->connection()->table('worker_heartbeats')
            ->where('worker_id', 'scheduler')
            ->first(['last_seen_at']);
        $schedulerObserved = $scheduler === null ? null : $this->date($scheduler->last_seen_at);
        $schedulerState = $schedulerObserved === null
            ? 'unknown'
            : ($schedulerObserved < $cutoff ? 'degraded' : 'healthy');

        /** @var list<object{last_seen_at:string}> $workers */
        $workers = $this->database->connection()->table('worker_heartbeats')
            ->where('worker_id', '<>', 'scheduler')
            ->get(['last_seen_at'])
            ->all();
        $fresh = 0;
        $stale = 0;
        $latest = null;
        foreach ($workers as $worker) {
            $observed = $this->date($worker->last_seen_at);
            if ($observed === null) {
                $stale++;

                continue;
            }
            $latest = $latest === null || $observed > $latest ? $observed : $latest;
            if ($observed < $cutoff) {
                $stale++;
            } else {
                $fresh++;
            }
        }

        $workerState = $workers === []
            ? 'unknown'
            : ($stale > 0 ? 'degraded' : 'healthy');

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
                count($workers),
                'fresh='.$fresh.';stale='.$stale.';max_age_seconds='.$maxAge,
                $latest,
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
        $running = (int) $connection->table('scheduled_task_runs')
            ->where('state', 'running')
            ->count();
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
            new OperationsCenterFact(
                'scheduler',
                'scheduler.failed_runs_24h',
                $failed === 0 ? 'empty' : 'degraded',
                $failed,
                null,
                $latestObserved,
            ),
            new OperationsCenterFact(
                'scheduler',
                'scheduler.running_runs',
                $running === 0 ? 'empty' : 'observed',
                $running,
                null,
                $latestObserved,
            ),
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
    private function telegramIngressFacts(): array
    {
        $failed = (int) $this->database->connection()->table('processed_telegram_updates')
            ->whereIn('state', ['failed', 'failed_terminal'])
            ->count();
        $latest = $this->database->connection()->table('processed_telegram_updates')->max('received_at');

        return [
            new OperationsCenterFact(
                'webhook',
                'webhook.telegram_last_observed',
                $latest === null ? 'unknown' : 'observed',
                $latest === null ? 0 : 1,
                null,
                $this->date($latest),
            ),
            new OperationsCenterFact(
                'webhook',
                'webhook.telegram_failed_updates',
                $failed === 0 ? 'empty' : 'manual_review',
                $failed,
                null,
                $this->date($latest),
            ),
        ];
    }

    /** @return list<OperationsCenterFact> */
    private function panelFacts(): array
    {
        /** @var list<object{provider_type:string,state:string,last_test_status:?string,last_panel_version:?string,last_tested_at:?string}> $rows */
        $rows = $this->database->connection()->table('panel_connections')
            ->orderBy('provider_type')
            ->orderBy('id')
            ->limit(100)
            ->get([
                'provider_type',
                'state',
                'last_test_status',
                'last_panel_version',
                'last_tested_at',
            ])
            ->all();

        if ($rows === []) {
            return [new OperationsCenterFact('panels', 'panels.inventory', 'empty', 0)];
        }

        $groups = [];
        foreach ($rows as $row) {
            $provider = preg_match('/\A[a-zA-Z0-9_.:-]{1,64}\z/', $row->provider_type) === 1
                ? strtolower($row->provider_type)
                : 'other';
            $groups[$provider] ??= [
                'count' => 0,
                'failed' => 0,
                'tested' => 0,
                'versions' => [],
                'latest' => null,
            ];
            $groups[$provider]['count']++;
            if ($row->last_test_status === 'failed' || $row->last_test_status === 'failure') {
                $groups[$provider]['failed']++;
            }
            if ($row->last_test_status !== null) {
                $groups[$provider]['tested']++;
            }
            if ($row->last_panel_version !== null && mb_strlen($row->last_panel_version) <= 32) {
                $groups[$provider]['versions'][$row->last_panel_version] = true;
            }
            $observed = $this->date($row->last_tested_at);
            if ($observed !== null
                && ($groups[$provider]['latest'] === null || $observed > $groups[$provider]['latest'])
            ) {
                $groups[$provider]['latest'] = $observed;
            }
        }

        $facts = [];
        foreach (array_slice($groups, 0, 20, true) as $provider => $group) {
            $state = $group['failed'] > 0
                ? 'degraded'
                : ($group['tested'] > 0 ? 'observed' : 'unknown');
            $versions = array_slice(array_keys($group['versions']), 0, 3);
            $detail = 'provider='.$provider.';tested='.$group['tested'].';failed='.$group['failed'];
            if ($versions !== []) {
                $detail .= ';versions='.implode(',', $versions);
            }
            $facts[] = new OperationsCenterFact(
                'panels',
                'panels.provider.'.$provider,
                $state,
                (int) $group['count'],
                mb_substr($detail, 0, 191),
                $group['latest'],
            );
        }

        return $facts;
    }

    /** @return list<OperationsCenterFact> */
    private function paymentHealthFacts(DateTimeImmutable $now): array
    {
        /** @var list<object{method_code:string,healthy:int|bool,observed_at:string,expires_at:string}> $rows */
        $rows = $this->database->connection()->table('payment_method_health_observations')
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['method_code', 'healthy', 'observed_at', 'expires_at'])
            ->all();

        $seen = [];
        $facts = [];
        foreach ($rows as $row) {
            if (isset($seen[$row->method_code]) || count($facts) >= 20) {
                continue;
            }
            $seen[$row->method_code] = true;
            $code = preg_match('/\A[a-z][a-z0-9_.-]{1,63}\z/', $row->method_code) === 1
                ? $row->method_code
                : 'unknown';
            $observed = $this->date($row->observed_at);
            $expires = $this->date($row->expires_at);
            $expired = $expires === null || $expires <= $now;
            $healthy = (bool) $row->healthy;

            $facts[] = new OperationsCenterFact(
                'payments',
                'payments.health.'.$code,
                $expired ? 'unknown' : ($healthy ? 'healthy' : 'degraded'),
                $healthy && ! $expired ? 1 : 0,
                $expired ? 'observation_expired' : null,
                $observed,
            );
        }

        if ($facts === []) {
            $facts[] = new OperationsCenterFact(
                'payments',
                'payments.health',
                'unknown',
                0,
                'no_authoritative_health_observation',
            );
        }

        return $facts;
    }

    /** @return list<OperationsCenterFact> */
    private function cardToCardFacts(): array
    {
        /** @var list<object{provider_code:string,last_success_at:?string,last_failure_at:?string,last_failure_code:?string}> $rows */
        $rows = $this->database->connection()->table('c2c_provider_cursors')
            ->orderBy('provider_code')
            ->limit(20)
            ->get(['provider_code', 'last_success_at', 'last_failure_at', 'last_failure_code'])
            ->all();

        $facts = [];
        foreach ($rows as $row) {
            $provider = preg_match('/\A[a-z][a-z0-9_.-]{1,63}\z/', $row->provider_code) === 1
                ? $row->provider_code
                : 'unknown';
            $success = $this->date($row->last_success_at);
            $failure = $this->date($row->last_failure_at);
            $state = $success === null && $failure === null
                ? 'unknown'
                : ($failure !== null && ($success === null || $failure > $success) ? 'degraded' : 'observed');
            $observed = $success === null || ($failure !== null && $failure > $success) ? $failure : $success;
            $detail = $row->last_failure_code === null
                ? null
                : 'last_failure_code='.mb_substr($row->last_failure_code, 0, 64);

            $facts[] = new OperationsCenterFact(
                'payments',
                'payments.c2c_provider.'.$provider,
                $state,
                $state === 'degraded' ? 0 : 1,
                $detail,
                $observed,
            );
        }

        $unmatched = (int) $this->database->connection()->table('c2c_bank_transactions as bank')
            ->leftJoin('c2c_transaction_matches as matches', 'matches.c2c_bank_transaction_id', '=', 'bank.id')
            ->leftJoin('c2c_match_reviews as reviews', 'reviews.c2c_bank_transaction_id', '=', 'bank.id')
            ->where('bank.status', 'settled')
            ->whereNull('matches.id')
            ->whereNull('reviews.id')
            ->count('bank.id');
        $facts[] = new OperationsCenterFact(
            'payments',
            'payments.c2c_unmatched_settled',
            $unmatched === 0 ? 'empty' : 'manual_review',
            $unmatched,
        );

        if ($rows === []) {
            $facts[] = new OperationsCenterFact(
                'payments',
                'payments.c2c_provider_health',
                'unknown',
                0,
                'no_cursor_observation',
            );
        }

        return $facts;
    }

    /** @return list<OperationsCenterFact> */
    private function giftCardFacts(): array
    {
        $pendingCapture = (int) $this->database->connection()->table('gift_card_submissions')
            ->whereIn('state', ['valid_unreserved', 'reserved', 'redeeming'])
            ->count();
        $manualReview = (int) $this->database->connection()->table('gift_card_submissions')
            ->whereIn('state', ['pending_manual_review', 'provider_unavailable'])
            ->count();
        $reconciliation = (int) $this->database->connection()->table('gift_card_reconciliation_findings')
            ->count();

        return [
            new OperationsCenterFact(
                'payments',
                'payments.gift_card_pending_capture',
                $pendingCapture === 0 ? 'empty' : 'observed',
                $pendingCapture,
            ),
            new OperationsCenterFact(
                'payments',
                'payments.gift_card_manual_review',
                $manualReview === 0 ? 'empty' : 'manual_review',
                $manualReview,
            ),
            new OperationsCenterFact(
                'payments',
                'payments.gift_card_reconciliation_findings',
                $reconciliation === 0 ? 'empty' : 'manual_review',
                $reconciliation,
            ),
        ];
    }

    /** @return list<OperationsCenterFact> */
    private function provisioningFacts(): array
    {
        $review = (int) $this->database->connection()->table('provisioning_operations')
            ->whereIn('state', ['uncertain_remote_result', 'failed_final', 'needs_review'])
            ->count();

        return [new OperationsCenterFact(
            'provisioning',
            'provisioning.manual_review',
            $review === 0 ? 'empty' : 'manual_review',
            $review,
        )];
    }

    /** @return list<OperationsCenterFact> */
    private function serviceSyncFacts(): array
    {
        $unresolved = (int) $this->database->connection()->table('service_sync_anomalies')
            ->whereIn('state', ['open', 'manual_review', 'action_requested'])
            ->count();

        return [new OperationsCenterFact(
            'provisioning',
            'provisioning.sync_anomalies',
            $unresolved === 0 ? 'empty' : 'manual_review',
            $unresolved,
        )];
    }

    /** @return list<OperationsCenterFact> */
    private function alertFacts(): array
    {
        $connection = $this->database->connection();
        $warning = (int) $connection->table('alerts')
            ->whereNull('resolved_at')
            ->where('severity', 'warning')
            ->count();
        $critical = (int) $connection->table('alerts')
            ->whereNull('resolved_at')
            ->whereIn('severity', ['critical', 'security'])
            ->count();
        $deliveryFailed = (int) $connection->table('operational_alert_deliveries')
            ->where('state', 'failed')
            ->count();

        return [
            new OperationsCenterFact(
                'alerts',
                'alerts.unresolved_warning',
                $warning === 0 ? 'empty' : 'observed',
                $warning,
            ),
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
        $backupEnabled = config('operations.backup.enabled') === true;
        $releaseVersion = config('app.version');
        $release = is_string($releaseVersion) && $releaseVersion !== ''
            ? mb_substr($releaseVersion, 0, 64)
            : null;

        return [
            new OperationsCenterFact(
                'backup',
                'backup.runtime',
                'unknown',
                $backupEnabled ? 1 : 0,
                $backupEnabled ? 'enabled_without_live_status' : 'disabled',
            ),
            new OperationsCenterFact(
                'release',
                'release.version',
                $release === null ? 'unknown' : 'observed',
                $release === null ? 0 : 1,
                $release,
            ),
        ];
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
