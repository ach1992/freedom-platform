<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Reporting\Application\Contracts\ReportingExportDeliveryGateway;
use App\Modules\Reporting\Application\Contracts\ReportingTextDeliveryGateway;
use App\Modules\Reporting\Application\ReportDateRange;
use App\Modules\Reporting\Application\ReportingDeliveryService;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\ReportingAccessFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement REP-001 REP-002 REP-003 ACL-001 ACL-002 DAT-002 DAT-003 SEC-002 OPS-003 */
final class ReportingDeliveryIntegrationTest extends TestCase
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

    public function test_owner_channel_and_export_delivery_use_permission_aware_gateways_and_safe_audit(): void
    {
        $administratorId = $this->ownerAdministrator();
        $userId = (int) DB::table('administrators')->where('id', $administratorId)->value('user_id');
        $textGateway = new FakeReportingTextDeliveryGateway;
        $exportGateway = new FakeReportingExportDeliveryGateway;
        $this->app->instance(ReportingTextDeliveryGateway::class, $textGateway);
        $this->app->instance(ReportingExportDeliveryGateway::class, $exportGateway);
        $delivery = $this->app->make(ReportingDeliveryService::class);
        $range = new ReportDateRange(
            'delivery_test',
            now('UTC')->subHour()->toDateTimeImmutable(),
            now('UTC')->toDateTimeImmutable(),
        );

        $channelOperation = $delivery->deliverToConfiguredChannel(
            $userId,
            $range,
            'report-delivery-channel-test',
            'report-delivery-channel-test-request',
        );
        self::assertSame($textGateway->operationId, $channelOperation);
        self::assertSame(-1001234567890, $textGateway->recipientChatId);
        self::assertNotNull($textGateway->text);
        self::assertStringContainsString('Report — delivery_test', $textGateway->text);

        $replayedChannelOperation = $delivery->deliverToConfiguredChannel(
            $userId,
            $range,
            'report-delivery-channel-test',
            'report-delivery-channel-test-request',
        );
        self::assertSame($channelOperation, $replayedChannelOperation);
        self::assertSame(1, $textGateway->sendCalls);
        self::assertSame(2, $textGateway->findCalls);

        $exportOperation = $delivery->queueExportForAdministratorChat(
            $userId,
            123456789,
            $range,
            'xlsx',
            'fa',
            'report-delivery-export-test',
            'report-delivery-export-test-request',
        );
        self::assertSame($exportGateway->operationId, $exportOperation);
        self::assertSame(123456789, $exportGateway->recipientChatId);
        self::assertSame('xlsx', $exportGateway->format);
        self::assertSame('fa', $exportGateway->locale);

        $replayedExportOperation = $delivery->queueExportForAdministratorChat(
            $userId,
            123456789,
            $range,
            'xlsx',
            'fa',
            'report-delivery-export-test',
            'report-delivery-export-test-request',
        );
        self::assertSame($exportOperation, $replayedExportOperation);
        self::assertSame(2, $exportGateway->queueCalls);

        self::assertSame(1, DB::table('audit_logs')->where('action', 'report.view')->count());
        self::assertSame(2, DB::table('audit_logs')->where('action', 'report.deliver')->count());
        foreach (DB::table('audit_logs')->where('action', 'report.deliver')->pluck('after_safe_data') as $safeJson) {
            $safe = (string) $safeJson;
            self::assertStringNotContainsString('123456789', $safe);
            self::assertStringNotContainsString('-1001234567890', $safe);
            self::assertStringContainsString('destination_sha256', $safe);
        }
    }

    public function test_view_permission_does_not_implicitly_allow_delivery_or_export(): void
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
        $viewPermissionId = (int) DB::table('permissions')->where('code', 'reports.view')->value('id');
        DB::table('administrator_permission_overrides')->insert([
            'administrator_id' => $administratorId,
            'permission_id' => $viewPermissionId,
            'effect' => 'allow',
            'changed_by_administrator_id' => null,
            'reason_code' => 'reporting_delivery_test',
            'reason' => 'Grant view only for reporting delivery separation test.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->app->instance(ReportingTextDeliveryGateway::class, new FakeReportingTextDeliveryGateway);
        $this->app->instance(ReportingExportDeliveryGateway::class, new FakeReportingExportDeliveryGateway);
        $delivery = $this->app->make(ReportingDeliveryService::class);
        $range = new ReportDateRange('delivery_test', now('UTC')->subHour()->toDateTimeImmutable(), now('UTC')->toDateTimeImmutable());

        try {
            $delivery->deliverToConfiguredChannel($userId, $range, 'report-delivery-denied', 'report-delivery-denied-request');
            self::fail('View-only reporting permission must not allow channel delivery.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        try {
            $delivery->queueExportForAdministratorChat(
                $userId,
                123456789,
                $range,
                'csv',
                'en',
                'report-export-denied',
                'report-export-denied-request',
            );
            self::fail('View-only reporting permission must not allow export.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(0, DB::table('audit_logs')->whereIn('action', ['report.deliver', 'report.export'])->count());
    }
}

final class FakeReportingTextDeliveryGateway implements ReportingTextDeliveryGateway
{
    public string $operationId;

    public ?int $recipientChatId = null;

    public ?string $text = null;

    public ?string $existingOperationId = null;

    public int $sendCalls = 0;

    public int $findCalls = 0;

    public function __construct()
    {
        $this->operationId = strtoupper((string) Str::ulid());
    }

    public function findExisting(int $recipientChatId, string $requestKey, string $correlationId): ?string
    {
        $this->findCalls++;

        return $this->existingOperationId;
    }

    public function send(int $recipientChatId, string $text, string $requestKey, string $correlationId): string
    {
        $this->sendCalls++;
        $this->recipientChatId = $recipientChatId;
        $this->text = $text;
        $this->existingOperationId = $this->operationId;

        return $this->operationId;
    }
}

final class FakeReportingExportDeliveryGateway implements ReportingExportDeliveryGateway
{
    public string $operationId;

    public ?int $recipientChatId = null;

    public ?string $format = null;

    public ?string $locale = null;

    public int $queueCalls = 0;

    public function __construct()
    {
        $this->operationId = strtoupper((string) Str::ulid());
    }

    public function queue(
        int $recipientChatId,
        ReportDateRange $range,
        string $format,
        string $locale,
        string $requestKey,
        string $correlationId,
    ): string {
        $this->queueCalls++;
        $this->recipientChatId = $recipientChatId;
        $this->format = $format;
        $this->locale = $locale;

        return $this->operationId;
    }
}
