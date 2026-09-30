<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Shared\Application\Clock;
use DateTimeZone;
use DomainException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class ReportScheduleService
{
    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $authorizer,
        private ReportScheduleNextRunCalculator $nextRuns,
        private ReportingAudit $audit,
        private ConfigRepository $config,
        private Clock $clock,
    ) {}

    /** @requirement REP-003 ACL-001 ACL-002 DAT-002 DAT-003 SEC-002 OPS-003 */
    public function createChannelSchedule(
        int $actorUserId,
        string $period,
        string $frequency,
        string $runTimeLocal,
        ?int $weekdayIso,
        ?int $dayOfMonth,
        string $correlationId,
        string $requestKey,
    ): string {
        if (! in_array($period, ReportPeriod::presets(), true)) {
            throw new DomainException('Scheduled reports require a supported preset period.');
        }
        if (! in_array($frequency, ReportScheduleFrequency::values(), true)) {
            throw new DomainException('Scheduled report frequency is unsupported.');
        }

        $administratorId = $this->sameAdministratorForPermissions(
            $actorUserId,
            [ReportingPermissions::SCHEDULE, ReportingPermissions::VIEW, ReportingPermissions::DELIVER],
        );
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $this->assertReportChannelConfigured();
        $nextRun = $this->nextRuns->next($frequency, $runTimeLocal, $weekdayIso, $dayOfMonth, $now);
        $publicId = strtoupper((string) Str::ulid());
        $timestamp = $now->format('Y-m-d H:i:s.u');

        $this->database->connection()->transaction(function (Connection $connection) use (
            $publicId,
            $administratorId,
            $actorUserId,
            $period,
            $frequency,
            $runTimeLocal,
            $weekdayIso,
            $dayOfMonth,
            $nextRun,
            $timestamp,
            $correlationId,
            $requestKey,
        ): void {
            $connection->table('report_schedules')->insert([
                'public_id' => $publicId,
                'created_by_administrator_id' => $administratorId,
                'actor_user_id' => $actorUserId,
                'period' => $period,
                'frequency' => $frequency,
                'run_time_local' => $runTimeLocal,
                'weekday_iso' => $weekdayIso,
                'day_of_month' => $dayOfMonth,
                'enabled' => true,
                'next_run_at' => $nextRun->format('Y-m-d H:i:s.u'),
                'lease_token_hash' => null,
                'lease_expires_at' => null,
                'retry_count' => 0,
                'retry_not_before_at' => null,
                'last_due_at' => null,
                'last_queued_at' => null,
                'last_delivery_operation_public_id' => null,
                'last_error_code' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $this->audit->recordSchedule(
                $administratorId,
                $publicId,
                [
                    'action' => 'created',
                    'period' => $period,
                    'frequency' => $frequency,
                    'run_time_local' => $runTimeLocal,
                    'weekday_iso' => $weekdayIso,
                    'day_of_month' => $dayOfMonth,
                    'next_run_at_utc' => $nextRun->format('Y-m-d H:i:s.u'),
                ],
                $correlationId,
                $requestKey,
            );
        }, 3);

        return $publicId;
    }

    /** @requirement REP-003 ACL-001 ACL-002 SEC-002 */
    public function disableOwnSchedule(
        int $actorUserId,
        string $schedulePublicId,
        string $correlationId,
        string $requestKey,
    ): void {
        $administratorId = $this->sameAdministratorForPermissions(
            $actorUserId,
            [ReportingPermissions::SCHEDULE],
        );
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $schedulePublicId) !== 1) {
            throw new DomainException('Report schedule identity is invalid.');
        }

        $timestamp = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $this->database->connection()->transaction(function (Connection $connection) use (
            $schedulePublicId,
            $administratorId,
            $timestamp,
            $correlationId,
            $requestKey,
        ): void {
            $updated = $connection->table('report_schedules')
                ->where('public_id', $schedulePublicId)
                ->where('created_by_administrator_id', $administratorId)
                ->where('enabled', true)
                ->update([
                    'enabled' => false,
                    'lease_token_hash' => null,
                    'lease_expires_at' => null,
                    'retry_count' => 0,
                    'retry_not_before_at' => null,
                    'updated_at' => $timestamp,
                ]);
            if ($updated !== 1) {
                throw new DomainException('Active report schedule was not found for this administrator.');
            }

            $this->audit->recordSchedule(
                $administratorId,
                $schedulePublicId,
                ['action' => 'disabled'],
                $correlationId,
                $requestKey,
            );
        }, 3);

    }

    /**
     * @return list<array{public_id:string,period:string,frequency:string,run_time_local:string,weekday_iso:int|null,day_of_month:int|null,enabled:bool,next_run_at:string,last_error_code:string|null}>
     */
    public function listOwnSchedules(int $actorUserId): array
    {
        $administratorId = $this->sameAdministratorForPermissions(
            $actorUserId,
            [ReportingPermissions::SCHEDULE],
        );

        /** @var list<object{public_id:string,period:string,frequency:string,run_time_local:string,weekday_iso:int|string|null,day_of_month:int|string|null,enabled:int|bool|string,next_run_at:string,last_error_code:string|null}> $rows */
        $rows = $this->database->connection()->table('report_schedules')
            ->where('created_by_administrator_id', $administratorId)
            ->orderByDesc('enabled')
            ->orderBy('next_run_at')
            ->limit(50)
            ->get([
                'public_id', 'period', 'frequency', 'run_time_local', 'weekday_iso', 'day_of_month',
                'enabled', 'next_run_at', 'last_error_code',
            ])
            ->all();

        return array_map(static fn (object $row): array => [
            'public_id' => $row->public_id,
            'period' => $row->period,
            'frequency' => $row->frequency,
            'run_time_local' => $row->run_time_local,
            'weekday_iso' => $row->weekday_iso === null ? null : (int) $row->weekday_iso,
            'day_of_month' => $row->day_of_month === null ? null : (int) $row->day_of_month,
            'enabled' => (bool) $row->enabled,
            'next_run_at' => $row->next_run_at,
            'last_error_code' => $row->last_error_code,
        ], $rows);
    }

    /** @param list<string> $permissions */
    private function sameAdministratorForPermissions(int $actorUserId, array $permissions): int
    {
        $administratorId = null;
        foreach ($permissions as $permission) {
            $authorizedId = $this->authorizer->authorizeUser($actorUserId, $permission);
            if ($administratorId !== null && $administratorId !== $authorizedId) {
                throw new RuntimeException('Reporting administrator identity changed during authorization.');
            }
            $administratorId = $authorizedId;
        }

        if ($administratorId === null) {
            throw new RuntimeException('Reporting permission set cannot be empty.');
        }

        return $administratorId;
    }

    private function assertReportChannelConfigured(): void
    {
        $channel = $this->config->get('reporting.telegram.report_channel_chat_id');
        if (! is_int($channel) || $channel === 0) {
            throw new RuntimeException('Reporting Telegram channel is not configured.');
        }
    }
}
