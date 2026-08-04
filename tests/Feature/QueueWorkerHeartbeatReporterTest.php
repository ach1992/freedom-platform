<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Operations\Application\QueueWorkerHeartbeatReporter;
use App\Modules\Operations\Application\WorkerHeartbeatService;
use App\Shared\Application\Clock;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Log\AbstractLogger;
use Stringable;
use Tests\TestCase;

/** @requirement OPS-001 OPS-003 RUN-003 RUN-004 */
final class QueueWorkerHeartbeatReporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_reporter_records_once_per_interval_and_updates_the_active_queue(): void
    {
        $clock = new MutableHeartbeatClock(new DateTimeImmutable('2026-08-04T00:00:00+00:00'));
        $logger = new RecordingHeartbeatLogger;
        $reporter = $this->reporter($clock, $logger, true, 'worker-critical-00');

        $this->assertTrue($reporter->report());
        $this->assertDatabaseHas('worker_heartbeats', [
            'worker_id' => 'worker-critical-00',
            'queue' => 'critical,default',
            'release_version' => '0.2.0-test',
        ]);

        $this->assertFalse($reporter->report('critical-payments'));
        $this->assertDatabaseHas('worker_heartbeats', [
            'worker_id' => 'worker-critical-00',
            'queue' => 'critical,default',
        ]);

        $clock->advance(31);

        $this->assertTrue($reporter->report('critical-payments'));
        $this->assertDatabaseHas('worker_heartbeats', [
            'worker_id' => 'worker-critical-00',
            'queue' => 'critical-payments',
        ]);
        $this->assertSame([], $logger->records);
    }

    public function test_disabled_reporter_is_a_no_op(): void
    {
        $clock = new MutableHeartbeatClock(new DateTimeImmutable('2026-08-04T00:00:00+00:00'));
        $logger = new RecordingHeartbeatLogger;
        $reporter = $this->reporter($clock, $logger, false, 'worker-disabled-00');

        $this->assertFalse($reporter->report());
        $this->assertDatabaseMissing('worker_heartbeats', [
            'worker_id' => 'worker-disabled-00',
        ]);
    }

    public function test_safe_reporting_logs_only_generic_failure_metadata_and_keeps_worker_alive(): void
    {
        $clock = new MutableHeartbeatClock(new DateTimeImmutable('2026-08-04T00:00:00+00:00'));
        $logger = new RecordingHeartbeatLogger;
        $reporter = $this->reporter($clock, $logger, true, '');

        $reporter->reportSafely('test-only-secret-queue');

        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertSame('Queue worker heartbeat could not be recorded.', $logger->records[0]['message']);
        $this->assertSame('operations.worker_heartbeat_record_failed', $logger->records[0]['context']['event']);
        $this->assertArrayHasKey('exception_class', $logger->records[0]['context']);
        $this->assertStringNotContainsString('test-only-secret-queue', json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    private function reporter(
        MutableHeartbeatClock $clock,
        RecordingHeartbeatLogger $logger,
        bool $enabled,
        string $workerId,
    ): QueueWorkerHeartbeatReporter {
        return new QueueWorkerHeartbeatReporter(
            new WorkerHeartbeatService(
                $this->app->make(DatabaseManager::class),
                $clock,
            ),
            $clock,
            $logger,
            $enabled,
            $workerId,
            'critical,default',
            '0.2.0-test',
            30,
        );
    }
}

final class MutableHeartbeatClock implements Clock
{
    public function __construct(private DateTimeImmutable $current) {}

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }

    public function advance(int $seconds): void
    {
        $this->current = $this->current->add(new DateInterval(sprintf('PT%dS', $seconds)));
    }
}

final class RecordingHeartbeatLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log(mixed $level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
