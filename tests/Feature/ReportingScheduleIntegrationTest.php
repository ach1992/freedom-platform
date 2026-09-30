<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Reporting\Application\Contracts\ReportingExportDeliveryGateway;
use App\Modules\Reporting\Application\Contracts\ReportingScheduledChannelDelivery;
use App\Modules\Reporting\Application\Contracts\ReportingTextDeliveryGateway;
use App\Modules\Reporting\Application\ReportDateRange;
use App\Modules\Reporting\Application\ReportScheduleFrequency;
use App\Modules\Reporting\Application\ReportScheduleRunner;
use App\Modules\Reporting\Application\ReportScheduleService;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\ReportingAccessFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement REP-003 ACL-001 ACL-002 DAT-002 DAT-003 SEC-002 OPS-003 RUN-003 */
final class ReportingScheduleIntegrationTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(ReportingAccessFoundationSeeder::class);
        config(['reporting.telegram.report_channel_chat_id' => -1001234567890]);
    }

    public function test_reporting_schedule_command_is_registered_in_the_single_scheduler(): void
    {
        self::assertSame(0, Artisan::call('schedule:list'));
        self::assertStringContainsString('reporting:run-schedules', Artisan::output());
    }

    public function test_owner_can_create_list_and_disable_a_channel_schedule(): void
    {
        $administratorId = $this->ownerAdministrator();
        $userId = (int) DB::table('administrators')->where('id', $administratorId)->value('user_id');
        $service = $this->app->make(ReportScheduleService::class);

        $publicId = $service->createChannelSchedule(
            $userId,
            'last_7_days',
            ReportScheduleFrequency::WEEKLY,
            '08:00',
            6,
            null,
            'report-schedule-create-test',
            'report-schedule-create-test-request',
        );

        self::assertMatchesRegularExpression('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $publicId);
        self::assertDatabaseHas('report_schedules', [
            'public_id' => $publicId,
            'created_by_administrator_id' => $administratorId,
            'actor_user_id' => $userId,
            'period' => 'last_7_days',
            'frequency' => 'weekly',
            'run_time_local' => '08:00',
            'weekday_iso' => 6,
            'enabled' => 1,
            'retry_count' => 0,
        ]);

        $schedules = $service->listOwnSchedules($userId);
        self::assertCount(1, $schedules);
        self::assertSame($publicId, $schedules[0]['public_id']);
        self::assertTrue($schedules[0]['enabled']);

        $service->disableOwnSchedule(
            $userId,
            $publicId,
            'report-schedule-disable-test',
            'report-schedule-disable-test-request',
        );
        self::assertDatabaseHas('report_schedules', ['public_id' => $publicId, 'enabled' => 0]);
        self::assertSame(2, DB::table('audit_logs')->where('action', 'report.schedule')->count());
    }

    public function test_runner_retries_the_same_occurrence_idempotently_then_advances_schedule(): void
    {
        $administratorId = $this->ownerAdministrator();
        $userId = (int) DB::table('administrators')->where('id', $administratorId)->value('user_id');
        $service = $this->app->make(ReportScheduleService::class);
        $publicId = $service->createChannelSchedule(
            $userId,
            'yesterday',
            ReportScheduleFrequency::DAILY,
            '08:00',
            null,
            null,
            'report-schedule-runner-create',
            'report-schedule-runner-create-request',
        );

        $dueAt = now('UTC')->subMinute()->format('Y-m-d H:i:s.u');
        DB::table('report_schedules')->where('public_id', $publicId)->update([
            'next_run_at' => $dueAt,
            'updated_at' => now('UTC'),
        ]);

        $fake = new FakeReportingScheduledChannelDelivery;
        $this->app->instance(ReportingScheduledChannelDelivery::class, $fake);
        $runner = $this->app->make(ReportScheduleRunner::class);

        $first = $runner->runDue(5);
        self::assertSame(1, $first->examined);
        self::assertSame(1, $first->retryScheduled);
        self::assertSame(0, $first->queued);

        $afterFailure = DB::table('report_schedules')->where('public_id', $publicId)->first();
        self::assertNotNull($afterFailure);
        self::assertSame($dueAt, (string) $afterFailure->next_run_at);
        self::assertSame(1, (int) $afterFailure->retry_count);
        self::assertSame('execution_failed', $afterFailure->last_error_code);
        self::assertNull($afterFailure->lease_token_hash);
        self::assertNotNull($afterFailure->retry_not_before_at);

        DB::table('report_schedules')->where('public_id', $publicId)->update([
            'retry_not_before_at' => now('UTC')->subSecond(),
            'updated_at' => now('UTC'),
        ]);
        $fake->fail = false;

        $second = $runner->runDue(5);
        self::assertSame(1, $second->examined);
        self::assertSame(1, $second->queued);
        self::assertSame(0, $second->retryScheduled);
        self::assertCount(2, $fake->requestKeys);
        self::assertSame($fake->requestKeys[0], $fake->requestKeys[1]);

        $afterSuccess = DB::table('report_schedules')->where('public_id', $publicId)->first();
        self::assertNotNull($afterSuccess);
        self::assertSame(0, (int) $afterSuccess->retry_count);
        self::assertNull($afterSuccess->retry_not_before_at);
        self::assertNull($afterSuccess->last_error_code);
        self::assertSame($fake->operationId, $afterSuccess->last_delivery_operation_public_id);
        self::assertGreaterThan($dueAt, (string) $afterSuccess->next_run_at);
        self::assertSame(2, DB::table('scheduled_task_runs')->where('task_name', 'reporting.run-schedules')->count());
        self::assertSame(2, DB::table('audit_logs')->where('action', 'report.schedule_execute')->count());
    }

    public function test_runner_skips_schedule_with_an_active_lease(): void
    {
        $administratorId = $this->ownerAdministrator();
        $userId = (int) DB::table('administrators')->where('id', $administratorId)->value('user_id');
        $publicId = $this->app->make(ReportScheduleService::class)->createChannelSchedule(
            $userId,
            'today',
            ReportScheduleFrequency::DAILY,
            '08:00',
            null,
            null,
            'report-schedule-lease-create',
            'report-schedule-lease-create-request',
        );
        DB::table('report_schedules')->where('public_id', $publicId)->update([
            'next_run_at' => now('UTC')->subMinute(),
            'lease_token_hash' => hash('sha256', 'active-reporting-lease'),
            'lease_expires_at' => now('UTC')->addMinutes(5),
            'updated_at' => now('UTC'),
        ]);

        $fake = new FakeReportingScheduledChannelDelivery;
        $fake->fail = false;
        $this->app->instance(ReportingScheduledChannelDelivery::class, $fake);

        $summary = $this->app->make(ReportScheduleRunner::class)->runDue(5);
        self::assertSame(0, $summary->examined);
        self::assertSame([], $fake->requestKeys);
        self::assertDatabaseHas('report_schedules', [
            'public_id' => $publicId,
            'enabled' => 1,
            'lease_token_hash' => hash('sha256', 'active-reporting-lease'),
        ]);
    }

    public function test_runner_disables_schedule_when_view_permission_is_revoked_before_delivery_replay(): void
    {
        $userId = $this->quoteUser('customer');
        $now = now('UTC');
        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => $userId,
            'status' => 'active',
            'is_owner' => false,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach (['reports.schedule', 'reports.view', 'reports.deliver'] as $permissionCode) {
            $permissionId = (int) DB::table('permissions')->where('code', $permissionCode)->value('id');
            DB::table('administrator_permission_overrides')->insert([
                'administrator_id' => $administratorId,
                'permission_id' => $permissionId,
                'effect' => 'allow',
                'changed_by_administrator_id' => null,
                'reason_code' => 'reporting_schedule_view_revoke_test',
                'reason' => 'Grant reporting permissions for execution-time view revocation test.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $publicId = $this->app->make(ReportScheduleService::class)->createChannelSchedule(
            $userId,
            'yesterday',
            ReportScheduleFrequency::DAILY,
            '08:00',
            null,
            null,
            'report-schedule-view-revoke-create',
            'report-schedule-view-revoke-create-request',
        );
        DB::table('report_schedules')->where('public_id', $publicId)->update([
            'next_run_at' => now('UTC')->subMinute(),
            'updated_at' => now('UTC'),
        ]);
        $viewPermissionId = (int) DB::table('permissions')->where('code', 'reports.view')->value('id');
        DB::table('administrator_permission_overrides')
            ->where('administrator_id', $administratorId)
            ->where('permission_id', $viewPermissionId)
            ->update(['effect' => 'deny', 'updated_at' => now('UTC')]);

        $gateway = new ScheduleReportingTextDeliveryGateway;
        $this->app->instance(ReportingTextDeliveryGateway::class, $gateway);
        $this->app->instance(ReportingExportDeliveryGateway::class, new ScheduleReportingExportDeliveryGateway);

        $summary = $this->app->make(ReportScheduleRunner::class)->runDue(5);
        self::assertSame(1, $summary->examined);
        self::assertSame(1, $summary->disabled);
        self::assertSame(0, $gateway->findCalls);
        self::assertSame(0, $gateway->sendCalls);
        self::assertDatabaseHas('report_schedules', [
            'public_id' => $publicId,
            'enabled' => 0,
            'retry_count' => 0,
            'last_error_code' => 'permission_revoked',
        ]);
    }

    public function test_runner_disables_schedule_when_schedule_permission_is_revoked(): void
    {
        $userId = $this->quoteUser('customer');
        $now = now('UTC');
        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => $userId,
            'status' => 'active',
            'is_owner' => false,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach (['reports.schedule', 'reports.view', 'reports.deliver'] as $permissionCode) {
            $permissionId = (int) DB::table('permissions')->where('code', $permissionCode)->value('id');
            DB::table('administrator_permission_overrides')->insert([
                'administrator_id' => $administratorId,
                'permission_id' => $permissionId,
                'effect' => 'allow',
                'changed_by_administrator_id' => null,
                'reason_code' => 'reporting_schedule_permission_test',
                'reason' => 'Grant reporting schedule test permission.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $publicId = $this->app->make(ReportScheduleService::class)->createChannelSchedule(
            $userId,
            'yesterday',
            ReportScheduleFrequency::DAILY,
            '08:00',
            null,
            null,
            'report-schedule-permission-create',
            'report-schedule-permission-create-request',
        );
        DB::table('report_schedules')->where('public_id', $publicId)->update([
            'next_run_at' => now('UTC')->subMinute(),
            'updated_at' => now('UTC'),
        ]);
        $schedulePermissionId = (int) DB::table('permissions')->where('code', 'reports.schedule')->value('id');
        DB::table('administrator_permission_overrides')
            ->where('administrator_id', $administratorId)
            ->where('permission_id', $schedulePermissionId)
            ->update(['effect' => 'deny', 'updated_at' => now('UTC')]);

        $fake = new FakeReportingScheduledChannelDelivery;
        $fake->fail = false;
        $this->app->instance(ReportingScheduledChannelDelivery::class, $fake);

        $summary = $this->app->make(ReportScheduleRunner::class)->runDue(5);
        self::assertSame(1, $summary->examined);
        self::assertSame(1, $summary->disabled);
        self::assertSame([], $fake->requestKeys);
        self::assertDatabaseHas('report_schedules', [
            'public_id' => $publicId,
            'enabled' => 0,
            'retry_count' => 0,
            'last_error_code' => 'permission_revoked',
        ]);
        self::assertSame(1, DB::table('audit_logs')
            ->where('action', 'report.schedule_execute')
            ->where('target_id', $publicId)
            ->count());
    }
}

final class FakeReportingScheduledChannelDelivery implements ReportingScheduledChannelDelivery
{
    public bool $fail = true;

    /** @var list<string> */
    public array $requestKeys = [];

    public string $operationId;

    public function __construct()
    {
        $this->operationId = strtoupper((string) Str::ulid());
    }

    public function deliverToConfiguredChannel(
        int $actorUserId,
        ReportDateRange $range,
        string $correlationId,
        string $requestKey,
    ): string {
        $this->requestKeys[] = $requestKey;
        if ($this->fail) {
            throw new RuntimeException('Synthetic retryable reporting delivery failure.');
        }

        return $this->operationId;
    }
}

final class ScheduleReportingTextDeliveryGateway implements ReportingTextDeliveryGateway
{
    public int $findCalls = 0;

    public int $sendCalls = 0;

    public function findExisting(int $recipientChatId, string $requestKey, string $correlationId): ?string
    {
        $this->findCalls++;

        return null;
    }

    public function send(int $recipientChatId, string $text, string $requestKey, string $correlationId): string
    {
        $this->sendCalls++;

        return strtoupper((string) Str::ulid());
    }
}

final class ScheduleReportingExportDeliveryGateway implements ReportingExportDeliveryGateway
{
    public function queue(
        int $recipientChatId,
        ReportDateRange $range,
        string $format,
        string $locale,
        string $requestKey,
        string $correlationId,
    ): string {
        return strtoupper((string) Str::ulid());
    }
}
