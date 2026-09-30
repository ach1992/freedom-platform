<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Operations\Application\Contracts\OperationalAlertDeliveryGateway;
use App\Modules\Operations\Application\OperationalAlertDeliveryRunner;
use App\Shared\Application\OperationalAlertRecorder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\OperationsAccessFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement OPS-001 OPS-003 DAT-003 SEC-002 QUA-004 */
final class OperationalAlertDeliveryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(OperationsAccessFoundationSeeder::class);
    }

    public function test_critical_alert_queues_each_persisted_audience_once(): void
    {
        $gateway = new FakeOperationalAlertDeliveryGateway;
        $this->app->instance(OperationalAlertDeliveryGateway::class, $gateway);

        $recorder = $this->app->make(OperationalAlertRecorder::class);
        $recorder->raise(
            'critical',
            'operations.delivery_test',
            hash('sha256', 'operations-delivery-test'),
            'operations.delivery.test',
            ['state' => 'degraded'],
        );

        self::assertSame(2, DB::table('operational_alert_deliveries')->where('state', 'pending')->count());

        $summary = $this->app->make(OperationalAlertDeliveryRunner::class)->runDue(10);
        self::assertSame(2, $summary->examined);
        self::assertSame(2, $summary->queued);
        self::assertSame(0, $summary->retryScheduled);
        self::assertSame(0, $summary->failed);
        self::assertSame(2, $gateway->calls);
        self::assertEqualsCanonicalizing(['owner', 'report_channel'], $gateway->audiences);
        self::assertSame(['critical', 'critical'], $gateway->severities);
        self::assertSame(['operations.delivery_test', 'operations.delivery_test'], $gateway->events);
        self::assertSame([false, false], $gateway->resolved);
        self::assertSame([3, 3], $gateway->occurrenceCounts);
        foreach ($gateway->correlationIds as $correlationId) {
            self::assertNotSame('', $correlationId);
        }
        foreach ($gateway->trackingCodes as $trackingCode) {
            self::assertMatchesRegularExpression('/\\A[a-f0-9]{12}\\z/', $trackingCode);
        }
        foreach ($gateway->requestKeys as $requestKey) {
            self::assertStringStartsWith('operations-alert:', $requestKey);
        }
        self::assertSame(2, DB::table('operational_alert_deliveries')->where('state', 'queued')->count());
        self::assertSame(2, DB::table('operational_alert_deliveries')->whereNotNull('telegram_operation_public_id')->count());

        $replay = $this->app->make(OperationalAlertDeliveryRunner::class)->runDue(10);
        self::assertSame(0, $replay->examined);
        self::assertSame(2, $gateway->calls);
    }

    public function test_delivery_command_records_durable_scheduled_run_evidence(): void
    {
        $gateway = new FakeOperationalAlertDeliveryGateway;
        $this->app->instance(OperationalAlertDeliveryGateway::class, $gateway);
        $this->app->make(OperationalAlertRecorder::class)->raise(
            'critical',
            'operations.delivery_command_test',
            hash('sha256', 'operations-delivery-command-test'),
            'operations.delivery.command',
            ['state' => 'degraded'],
        );

        self::assertSame(0, Artisan::call('operations:deliver-alerts', [
            '--limit' => 10,
            '--json' => true,
        ]));

        $run = DB::table('scheduled_task_runs')
            ->where('task_name', 'operations.deliver-alerts')
            ->first(['state', 'metrics', 'error_class', 'error_code']);
        self::assertNotNull($run);
        self::assertSame('succeeded', $run->state);
        self::assertNull($run->error_class);
        self::assertNull($run->error_code);

        $metrics = json_decode((string) $run->metrics, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(2, $metrics['examined'] ?? null);
        self::assertSame(2, $metrics['queued'] ?? null);
        self::assertSame(0, $metrics['failed'] ?? null);
    }

    public function test_transient_queue_failure_retries_then_becomes_manual_review_without_payload_leakage(): void
    {
        $gateway = new FakeOperationalAlertDeliveryGateway(shouldFail: true);
        $this->app->instance(OperationalAlertDeliveryGateway::class, $gateway);

        $this->app->make(OperationalAlertRecorder::class)->raise(
            'warning',
            'operations.delivery_failure_test',
            hash('sha256', 'operations-delivery-failure-test'),
            'operations.delivery.failure',
            ['safe_state' => 'sensitive-value-not-for-delivery-state'],
        );

        $runner = $this->app->make(OperationalAlertDeliveryRunner::class);
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            DB::table('operational_alert_deliveries')->update([
                'available_at' => now('UTC')->subSecond(),
            ]);
            $summary = $runner->runDue(1);
            self::assertSame(1, $summary->examined);
        }

        $row = DB::table('operational_alert_deliveries')->first([
            'state',
            'attempts',
            'last_error_code',
            'telegram_operation_public_id',
            'lease_token_hash',
            'leased_until',
        ]);
        self::assertNotNull($row);
        self::assertSame('failed', $row->state);
        self::assertSame(4, (int) $row->attempts);
        self::assertSame('telegram_queue_failed', $row->last_error_code);
        self::assertNull($row->telegram_operation_public_id);
        self::assertNull($row->lease_token_hash);
        self::assertNull($row->leased_until);
        self::assertSame(4, $gateway->calls);

        $deliveryJson = json_encode((array) $row, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('sensitive-value-not-for-delivery-state', $deliveryJson);
    }
}

final class FakeOperationalAlertDeliveryGateway implements OperationalAlertDeliveryGateway
{
    public int $calls = 0;

    /** @var list<string> */
    public array $audiences = [];

    /** @var list<string> */
    public array $severities = [];

    /** @var list<string> */
    public array $events = [];

    /** @var list<bool> */
    public array $resolved = [];

    /** @var list<string> */
    public array $trackingCodes = [];

    /** @var list<string> */
    public array $requestKeys = [];

    /** @var list<int> */
    public array $occurrenceCounts = [];

    /** @var list<string> */
    public array $correlationIds = [];

    public function __construct(private readonly bool $shouldFail = false) {}

    public function queue(
        string $audience,
        string $severity,
        string $eventName,
        int $occurrenceCount,
        bool $resolved,
        string $trackingCode,
        string $requestKey,
        string $correlationId,
    ): string {
        $this->calls++;
        $this->audiences[] = $audience;
        $this->severities[] = $severity;
        $this->events[] = $eventName;
        $this->resolved[] = $resolved;
        $this->trackingCodes[] = $trackingCode;
        $this->requestKeys[] = $requestKey;
        $this->occurrenceCounts[] = $occurrenceCount;
        $this->correlationIds[] = $correlationId;

        if ($this->shouldFail) {
            throw new RuntimeException('simulated provider queue failure with secret detail');
        }

        return (string) Str::ulid();
    }
}
