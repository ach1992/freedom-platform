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

Schedule::command('operations:dispatch-outbox', [
    '--limit' => 100,
    '--json' => true,
])
    ->name('operations.dispatch-outbox')
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

Schedule::command('payments:purchase-maintenance', [
    '--limit' => 100,
    '--json' => true,
])
    ->name('payments.purchase-maintenance')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('services:auto-renew', [
    '--limit' => config('auto_renew.batch_limit', 50),
    '--json' => true,
])
    ->name('services.auto-renew')
    ->everyFiveMinutes()
    // Laravel's default overlap lock lasts 24 hours. Bound this critical scheduler so an abnormal
    // process death cannot suppress renewal processing for an entire day, while still leaving
    // ample headroom for the bounded 50-Service batch to finish normally.
    ->withoutOverlapping(30)
    ->onOneServer();

$serviceSyncInterval = filter_var(
    config('service_sync.interval_minutes', 5),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 60]],
);
if ($serviceSyncInterval === false || 60 % $serviceSyncInterval !== 0) {
    throw new RuntimeException('Service sync interval minutes must be a divisor of 60 between 1 and 60.');
}
$serviceSyncCron = $serviceSyncInterval === 60 ? '0 * * * *' : '*/'.$serviceSyncInterval.' * * * *';
Schedule::command('services:sync', [
    '--limit' => config('service_sync.batch_limit', 50),
    '--json' => true,
])
    ->name('services.sync')
    ->cron($serviceSyncCron)
    // Keep the overlap TTL bounded so a terminated sync process cannot suppress future observation
    // for a day. Per-Service leases remain the durable concurrency fence for manual/concurrent runs.
    ->withoutOverlapping(30)
    ->onOneServer();

$serviceNotificationInterval = filter_var(
    config('service_notifications.interval_minutes', 5),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 60]],
);
if ($serviceNotificationInterval === false || 60 % $serviceNotificationInterval !== 0) {
    throw new RuntimeException('Service notification interval minutes must be a divisor of 60 between 1 and 60.');
}
$serviceNotificationCron = $serviceNotificationInterval === 60
    ? '2 * * * *'
    : '*/'.$serviceNotificationInterval.' * * * *';
Schedule::command('services:notifications', [
    '--limit' => config('service_notifications.batch_limit', 50),
    '--json' => true,
])
    ->name('services.notifications')
    ->cron($serviceNotificationCron)
    // Delivery effects are separately idempotent and provider-fenced. This lock only prevents
    // duplicate threshold scans and is intentionally bounded after abnormal process termination.
    ->withoutOverlapping(30)
    ->onOneServer();
