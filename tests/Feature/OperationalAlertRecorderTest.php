<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Application\OperationalAlertRecorder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** @requirement OPS-003 QUA-004 */
final class OperationalAlertRecorderTest extends TestCase
{
    use DatabaseTruncation;

    public function test_raise_preserves_occurrence_count_and_reopens_resolved_alert(): void
    {
        $alerts = $this->app->make(OperationalAlertRecorder::class);
        $event = 'operations.boundary_occurrence_test';
        $deduplicationKey = hash('sha256', 'operations-boundary-occurrence-test');

        $alerts->raise('warning', $event, $deduplicationKey, 'operations.test.1', ['state' => 'first']);
        $alerts->raise('warning', $event, $deduplicationKey, 'operations.test.1', ['state' => 'second']);

        $row = DB::table('alerts')
            ->where('event_name', $event)
            ->where('deduplication_key', $deduplicationKey)
            ->first(['occurrence_count', 'resolved_at']);
        self::assertNotNull($row);
        self::assertSame(2, (int) $row->occurrence_count);
        self::assertNull($row->resolved_at);

        $alerts->resolve($event, $deduplicationKey);
        self::assertNotNull(DB::table('alerts')
            ->where('event_name', $event)
            ->where('deduplication_key', $deduplicationKey)
            ->value('resolved_at'));

        $alerts->raise('warning', $event, $deduplicationKey, 'operations.test.1', ['state' => 'third']);

        $reopened = DB::table('alerts')
            ->where('event_name', $event)
            ->where('deduplication_key', $deduplicationKey)
            ->first(['occurrence_count', 'resolved_at']);
        self::assertNotNull($reopened);
        self::assertSame(3, (int) $reopened->occurrence_count);
        self::assertNull($reopened->resolved_at);
    }

    public function test_raise_once_does_not_multiply_or_reopen_one_durable_condition(): void
    {
        $alerts = $this->app->make(OperationalAlertRecorder::class);
        $event = 'operations.boundary_once_test';
        $deduplicationKey = hash('sha256', 'operations-boundary-once-test');

        $alerts->raiseOnce('warning', $event, $deduplicationKey, 'operations.test.2', ['state' => 'terminal']);
        $alerts->raiseOnce('warning', $event, $deduplicationKey, 'operations.test.2', ['state' => 'terminal']);

        self::assertSame(1, (int) DB::table('alerts')
            ->where('event_name', $event)
            ->where('deduplication_key', $deduplicationKey)
            ->value('occurrence_count'));

        $alerts->resolve($event, $deduplicationKey);
        $resolvedAt = DB::table('alerts')
            ->where('event_name', $event)
            ->where('deduplication_key', $deduplicationKey)
            ->value('resolved_at');
        self::assertNotNull($resolvedAt);

        $alerts->raiseOnce('warning', $event, $deduplicationKey, 'operations.test.2', ['state' => 'terminal']);

        $row = DB::table('alerts')
            ->where('event_name', $event)
            ->where('deduplication_key', $deduplicationKey)
            ->first(['occurrence_count', 'resolved_at']);
        self::assertNotNull($row);
        self::assertSame(1, (int) $row->occurrence_count);
        self::assertSame((string) $resolvedAt, (string) $row->resolved_at);
    }
}
