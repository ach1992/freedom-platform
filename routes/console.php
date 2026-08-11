<?php

declare(strict_types=1);

use App\Modules\Operations\Application\SchedulerHeartbeatRecorder;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function (): void {
    app(SchedulerHeartbeatRecorder::class)->record();
})
    ->name('operations.scheduler-heartbeat')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('operations:check-worker-heartbeats', [
    '--max-age' => max(1, (int) config('operations.worker_heartbeat.stale_after_seconds', 480)),
    '--json' => true,
])
    ->name('operations.check-worker-heartbeats')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('wallet:maintenance', [
    '--hold-limit' => 100,
    '--wallet-limit' => 200,
    '--json' => true,
])
    ->name('wallet.maintenance')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
