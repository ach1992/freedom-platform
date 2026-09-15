<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Operations\Application\WorkerHeartbeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class WorkerHeartbeatQueueCapacityTest extends TestCase
{
    use RefreshDatabase;

    /** @requirement OPS-001 OPS-003 DAT-003 RUN-003 QUA-011 */
    public function test_real_supervisor_queue_group_is_persisted_without_truncation(): void
    {
        $queue = 'provisioning,telegram-ingress,telegram-delivery,synchronization,default';

        $this->app->make(WorkerHeartbeatService::class)->record(
            'freedom-platform-provisioning_00',
            $queue,
            '0.3.0-test',
        );

        self::assertSame(
            $queue,
            DB::table('worker_heartbeats')
                ->where('worker_id', 'freedom-platform-provisioning_00')
                ->value('queue'),
        );
    }

    /** @requirement OPS-003 SEC-009 QUA-013 */
    public function test_queue_group_over_schema_limit_is_rejected_before_database_write(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Queue name must contain between 1 and 191 characters.');

        $this->app->make(WorkerHeartbeatService::class)->record(
            'oversized-worker',
            str_repeat('q', 192),
        );
    }
}
