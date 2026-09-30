<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Reporting\Application\Contracts\ReportingScheduledChannelDelivery;
use App\Modules\Reporting\Application\Contracts\ReportingScheduledTaskRunRecorder;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type ScheduleClaim array{
 *   id:int,
 *   public_id:string,
 *   created_by_administrator_id:int,
 *   actor_user_id:int,
 *   period:string,
 *   frequency:string,
 *   run_time_local:string,
 *   weekday_iso:int|null,
 *   day_of_month:int|null,
 *   next_run_at:string,
 *   retry_count:int,
 *   lease_token:string
 * }
 */
final readonly class ReportScheduleRunner
{
    private const LEASE_SECONDS = 300;

    private const MAX_BATCH = 20;

    private const MAX_RETRIES = 3;

    /** @var array<int, int> */
    private const RETRY_DELAY_SECONDS = [
        1 => 60,
        2 => 300,
        3 => 900,
    ];

    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $authorizer,
        private ReportDateRangeResolver $ranges,
        private ReportingScheduledChannelDelivery $delivery,
        private ReportScheduleNextRunCalculator $nextRuns,
        private ReportingAudit $audit,
        private ReportingScheduledTaskRunRecorder $taskRuns,
        private Clock $clock,
    ) {}

    /** @requirement REP-003 ACL-001 ACL-002 DAT-002 DAT-003 SEC-002 OPS-003 RUN-003 */
    public function runDue(int $limit): ReportScheduleRunSummary
    {
        if ($limit < 1 || $limit > self::MAX_BATCH) {
            throw new RuntimeException('Reporting schedule batch limit must be between 1 and '.self::MAX_BATCH.'.');
        }

        $started = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $startedMonotonic = hrtime(true);
        $runId = $this->taskRuns->start('reporting.run-schedules', $started);

        $examined = 0;
        $queued = 0;
        $retryScheduled = 0;
        $disabled = 0;

        try {
            while ($examined < $limit) {
                $claim = $this->claimOne();
                if ($claim === null) {
                    break;
                }
                $examined++;

                $outcome = $this->executeClaim($claim);
                match ($outcome) {
                    'queued' => $queued++,
                    'retry' => $retryScheduled++,
                    'disabled' => $disabled++,
                    default => throw new RuntimeException('Unexpected reporting schedule outcome.'),
                };
            }

            $summary = new ReportScheduleRunSummary($examined, $queued, $retryScheduled, $disabled);
            $this->recordTaskRunFinish($runId, 'succeeded', $startedMonotonic, $summary, null, null);

            return $summary;
        } catch (Throwable $exception) {
            $summary = new ReportScheduleRunSummary($examined, $queued, $retryScheduled, $disabled);
            $this->recordTaskRunFinish(
                $runId,
                'failed',
                $startedMonotonic,
                $summary,
                $exception::class,
                'report_schedule_runner_failed',
            );
            throw $exception;
        }
    }

    /** @return ScheduleClaim|null */
    private function claimOne(): ?array
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $timestamp = $now->format('Y-m-d H:i:s.u');
        $leaseExpires = $now->modify('+'.self::LEASE_SECONDS.' seconds')->format('Y-m-d H:i:s.u');

        return $this->database->connection()->transaction(function (Connection $connection) use ($timestamp, $leaseExpires): ?array {
            /** @var object{id:int|string,public_id:string,created_by_administrator_id:int|string,actor_user_id:int|string,period:string,frequency:string,run_time_local:string,weekday_iso:int|string|null,day_of_month:int|string|null,next_run_at:string,retry_count:int|string}|null $row */
            $row = $connection->table('report_schedules')
                ->where('enabled', true)
                ->where('next_run_at', '<=', $timestamp)
                ->where(static function ($query) use ($timestamp): void {
                    $query->whereNull('retry_not_before_at')->orWhere('retry_not_before_at', '<=', $timestamp);
                })
                ->where(static function ($query) use ($timestamp): void {
                    $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', $timestamp);
                })
                ->orderBy('next_run_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first([
                    'id', 'public_id', 'created_by_administrator_id', 'actor_user_id', 'period', 'frequency',
                    'run_time_local', 'weekday_iso', 'day_of_month', 'next_run_at', 'retry_count',
                ]);
            if ($row === null) {
                return null;
            }

            $token = bin2hex(random_bytes(32));
            $updated = $connection->table('report_schedules')
                ->where('id', $row->id)
                ->update([
                    'lease_token_hash' => hash('sha256', $token),
                    'lease_expires_at' => $leaseExpires,
                    'updated_at' => $timestamp,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Reporting schedule lease could not be acquired.');
            }

            return [
                'id' => (int) $row->id,
                'public_id' => $row->public_id,
                'created_by_administrator_id' => (int) $row->created_by_administrator_id,
                'actor_user_id' => (int) $row->actor_user_id,
                'period' => $row->period,
                'frequency' => $row->frequency,
                'run_time_local' => $row->run_time_local,
                'weekday_iso' => $row->weekday_iso === null ? null : (int) $row->weekday_iso,
                'day_of_month' => $row->day_of_month === null ? null : (int) $row->day_of_month,
                'next_run_at' => $row->next_run_at,
                'retry_count' => (int) $row->retry_count,
                'lease_token' => $token,
            ];
        }, 3);
    }

    /** @param ScheduleClaim $claim @return 'queued'|'retry'|'disabled' */
    private function executeClaim(array $claim): string
    {
        $dueAt = new DateTimeImmutable($claim['next_run_at'], new DateTimeZone('UTC'));
        $requestKey = 'report-schedule:'.$claim['public_id'].':'.$dueAt->format('YmdHis.u');
        $correlationId = 'report-schedule:'.substr(hash('sha256', $requestKey), 0, 48);

        try {
            $administratorId = $this->authorizer->authorizeUser(
                $claim['actor_user_id'],
                ReportingPermissions::SCHEDULE,
            );
            if ($administratorId !== $claim['created_by_administrator_id']) {
                throw new AuthorizationException('Reporting schedule administrator identity is no longer valid.');
            }

            $range = $this->ranges->resolveAt($claim['period'], $dueAt);
            $this->database->connection()->transaction(function () use ($claim, $dueAt, $range, $correlationId, $requestKey): void {
                $operationId = $this->delivery->deliverToConfiguredChannel(
                    $claim['actor_user_id'],
                    $range,
                    $correlationId,
                    $requestKey,
                );
                $this->audit->recordScheduleExecution(
                    $claim['created_by_administrator_id'],
                    $claim['public_id'],
                    [
                        'outcome' => 'queued',
                        'due_at_utc' => $dueAt->format('Y-m-d H:i:s.u'),
                        'delivery_operation_public_id' => $operationId,
                    ],
                    $correlationId,
                    $this->scheduleAuditRequestKey($requestKey, $claim['retry_count']),
                );
                $this->finishSuccess($claim, $dueAt, $operationId);
            }, 3);

            return 'queued';
        } catch (AuthorizationException) {
            $this->database->connection()->transaction(function () use ($claim, $dueAt, $correlationId, $requestKey): void {
                $this->audit->recordScheduleExecution(
                    $claim['created_by_administrator_id'],
                    $claim['public_id'],
                    [
                        'outcome' => 'disabled',
                        'due_at_utc' => $dueAt->format('Y-m-d H:i:s.u'),
                        'error_code' => 'permission_revoked',
                    ],
                    $correlationId,
                    $this->scheduleAuditRequestKey($requestKey, $claim['retry_count']),
                );
                $this->finishDisabled($claim, $dueAt, 'permission_revoked');
            }, 3);

            return 'disabled';
        } catch (Throwable) {
            $nextRetry = $claim['retry_count'] + 1;
            if ($nextRetry > self::MAX_RETRIES) {
                $this->database->connection()->transaction(function () use ($claim, $dueAt, $correlationId, $requestKey): void {
                    $this->audit->recordScheduleExecution(
                        $claim['created_by_administrator_id'],
                        $claim['public_id'],
                        [
                            'outcome' => 'disabled',
                            'due_at_utc' => $dueAt->format('Y-m-d H:i:s.u'),
                            'error_code' => 'retry_exhausted',
                        ],
                        $correlationId,
                        $this->scheduleAuditRequestKey($requestKey, $claim['retry_count']),
                    );
                    $this->finishDisabled($claim, $dueAt, 'retry_exhausted');
                }, 3);

                return 'disabled';
            }

            $this->database->connection()->transaction(function () use ($claim, $dueAt, $nextRetry, $correlationId, $requestKey): void {
                $this->audit->recordScheduleExecution(
                    $claim['created_by_administrator_id'],
                    $claim['public_id'],
                    [
                        'outcome' => 'retry_scheduled',
                        'due_at_utc' => $dueAt->format('Y-m-d H:i:s.u'),
                        'retry_count' => $nextRetry,
                        'error_code' => 'execution_failed',
                    ],
                    $correlationId,
                    $this->scheduleAuditRequestKey($requestKey, $claim['retry_count']),
                );
                $this->finishRetry($claim, $dueAt, $nextRetry);
            }, 3);

            return 'retry';
        }
    }

    private function scheduleAuditRequestKey(string $requestKey, int $retryCount): string
    {
        return $requestKey.':attempt:'.$retryCount;
    }

    /** @param ScheduleClaim $claim */
    private function finishSuccess(array $claim, DateTimeImmutable $dueAt, string $operationId): void
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $nextRun = $this->nextRuns->next(
            $claim['frequency'],
            $claim['run_time_local'],
            $claim['weekday_iso'],
            $claim['day_of_month'],
            $now,
        );
        $updated = $this->leasedRow($claim)->update([
            'next_run_at' => $nextRun->format('Y-m-d H:i:s.u'),
            'lease_token_hash' => null,
            'lease_expires_at' => null,
            'retry_count' => 0,
            'retry_not_before_at' => null,
            'last_due_at' => $dueAt->format('Y-m-d H:i:s.u'),
            'last_queued_at' => $now->format('Y-m-d H:i:s.u'),
            'last_delivery_operation_public_id' => $operationId,
            'last_error_code' => null,
            'updated_at' => $now->format('Y-m-d H:i:s.u'),
        ]);
        if ($updated !== 1) {
            throw new RuntimeException('Reporting schedule success could not be finalized under its lease.');
        }
    }

    /** @param ScheduleClaim $claim */
    private function finishRetry(array $claim, DateTimeImmutable $dueAt, int $retryCount): void
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $retryAt = $now->modify('+'.self::RETRY_DELAY_SECONDS[$retryCount].' seconds');
        $updated = $this->leasedRow($claim)->update([
            'lease_token_hash' => null,
            'lease_expires_at' => null,
            'retry_count' => $retryCount,
            'retry_not_before_at' => $retryAt->format('Y-m-d H:i:s.u'),
            'last_due_at' => $dueAt->format('Y-m-d H:i:s.u'),
            'last_error_code' => 'execution_failed',
            'updated_at' => $now->format('Y-m-d H:i:s.u'),
        ]);
        if ($updated !== 1) {
            throw new RuntimeException('Reporting schedule retry could not be finalized under its lease.');
        }
    }

    /** @param ScheduleClaim $claim */
    private function finishDisabled(array $claim, DateTimeImmutable $dueAt, string $errorCode): void
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $updated = $this->leasedRow($claim)->update([
            'enabled' => false,
            'lease_token_hash' => null,
            'lease_expires_at' => null,
            'retry_count' => 0,
            'retry_not_before_at' => null,
            'last_due_at' => $dueAt->format('Y-m-d H:i:s.u'),
            'last_error_code' => $errorCode,
            'updated_at' => $now->format('Y-m-d H:i:s.u'),
        ]);
        if ($updated !== 1) {
            throw new RuntimeException('Reporting schedule disable could not be finalized under its lease.');
        }
    }

    /** @param ScheduleClaim $claim */
    private function leasedRow(array $claim): Builder
    {
        return $this->database->connection()->table('report_schedules')
            ->where('id', $claim['id'])
            ->where('lease_token_hash', hash('sha256', $claim['lease_token']));
    }

    private function recordTaskRunFinish(
        string $runId,
        string $state,
        int $startedMonotonic,
        ReportScheduleRunSummary $summary,
        ?string $errorClass,
        ?string $errorCode,
    ): void {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $durationMs = max(0, (int) round((hrtime(true) - $startedMonotonic) / 1_000_000));
        $this->taskRuns->finish(
            $runId,
            $state,
            $now,
            $durationMs,
            $summary->toArray(),
            $errorClass,
            $errorCode,
        );
    }
}
