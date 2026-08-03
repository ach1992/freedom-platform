<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function (): void {
    $now = now('UTC');

    DB::table('worker_heartbeats')->updateOrInsert(
        ['worker_id' => 'scheduler'],
        [
            'queue' => 'scheduler',
            'host_hash' => hash('sha256', gethostname() ?: 'unknown'),
            'release_version' => config('app.version'),
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    );
})
    ->name('operations.scheduler-heartbeat')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
