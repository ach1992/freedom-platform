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

    public function test_concurrent_raise_once_converges_on_one_alert(): void
    {
        $event = 'operations.boundary_concurrent_once_test';
        $deduplicationKey = hash('sha256', 'operations-boundary-concurrent-once-test');
        $prefix = sys_get_temp_dir().'/operational-alert-'.bin2hex(random_bytes(8));
        $barrier = $prefix.'-go';
        $results = [$prefix.'-1', $prefix.'-2'];

        DB::disconnect();
        $children = [];
        foreach ([0, 1] as $index) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                $outcome = ['ok' => false];
                try {
                    while (! file_exists($barrier)) {
                        usleep(1000);
                    }
                    DB::reconnect();
                    $this->app->make(OperationalAlertRecorder::class)->raiseOnce(
                        'warning',
                        $event,
                        $deduplicationKey,
                        'operations.test.concurrent',
                        ['state' => 'terminal'],
                    );
                    $outcome = ['ok' => true];
                } catch (\Throwable $throwable) {
                    $outcome = ['error' => $throwable::class.':'.$throwable->getMessage()];
                }
                file_put_contents($results[$index], json_encode($outcome, JSON_THROW_ON_ERROR));
                exit(0);
            }
            $children[] = $pid;
        }

        touch($barrier);
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        DB::reconnect();

        foreach ($results as $file) {
            $outcome = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($outcome);
            self::assertArrayNotHasKey('error', $outcome, (string) ($outcome['error'] ?? ''));
            self::assertTrue((bool) ($outcome['ok'] ?? false));
        }

        $row = DB::table('alerts')
            ->where('event_name', $event)
            ->where('deduplication_key', $deduplicationKey)
            ->first(['occurrence_count', 'resolved_at']);
        self::assertNotNull($row);
        self::assertSame(1, (int) $row->occurrence_count);
        self::assertNull($row->resolved_at);

        @unlink($barrier);
        foreach ($results as $file) {
            @unlink($file);
        }
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
