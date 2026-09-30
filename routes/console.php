<?php

declare(strict_types=1);

use App\Modules\Operations\Application\SchedulerHeartbeatRecorder;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function (): void {
    app(SchedulerHeartbeatRecorder::class)->record();
})
    ->name('operations.scheduler-heartbeat')
    ->everyMinute()
    // A heartbeat is a small DB upsert. Five minutes tolerates transient slowness while
    // preventing a crashed scheduler process from suppressing liveness evidence for a day.
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('operations:check-worker-heartbeats', [
    '--max-age' => max(1, (int) config('operations.worker_heartbeat.stale_after_seconds', 480)),
    '--json' => true,
])
    ->name('operations.check-worker-heartbeats')
    ->everyMinute()
    // Heartbeat inspection and alert recording are bounded database work; stale locks should
    // recover quickly enough that worker failures remain observable.
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('operations:dispatch-outbox', [
    '--limit' => 100,
    '--json' => true,
])
    ->name('operations.dispatch-outbox')
    ->everyMinute()
    // The command examines at most 100 due messages and individual effects have their own
    // leases/idempotency. Ten minutes bounds crash suppression without weakening those fences.
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('wallet:maintenance', [
    '--hold-limit' => 100,
    '--wallet-limit' => 200,
    '--json' => true,
])
    ->name('wallet.maintenance')
    ->everyFiveMinutes()
    // The run is bounded to 100 holds and 200 wallet accounts. A 15-minute stale lock gives
    // normal DB reconciliation headroom while avoiding Laravel's 24-hour crash suppression.
    ->withoutOverlapping(15)
    ->onOneServer();

Schedule::command('referrals:process-rewards', [
    '--limit' => 250,
    '--json' => true,
])
    ->name('referrals.process-rewards')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('customers:recalculate-tiers', [
    '--batch' => 500,
    '--json' => true,
])
    ->name('customers.recalculate-tiers')
    ->dailyAt('00:15')
    ->withoutOverlapping(60)
    ->onOneServer();

Schedule::command('payments:purchase-maintenance', [
    '--limit' => 100,
    '--json' => true,
])
    ->name('payments.purchase-maintenance')
    ->everyFiveMinutes()
    // Purchase maintenance operates on bounded idempotent expiration/release batches. Recover
    // its scheduler lock after 15 minutes instead of suppressing financial cleanup for a day.
    ->withoutOverlapping(15)
    ->onOneServer();

Schedule::command('payments:alternative-maintenance', [
    '--limit' => 50,
    '--json' => true,
])
    ->name('payments.alternative-maintenance')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    // Keep mutation in the foreground so the production Scheduler flock remains
    // held until this database-mutating maintenance command has completely drained.
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

Schedule::command('telegram:process-broadcasts', [
    '--activation-limit' => 10,
    '--recipient-limit' => 10,
    '--lifecycle-limit' => 10,
    '--json' => true,
])
    ->name('telegram.process-broadcasts')
    ->everyMinute()
    // Keep broadcast provider work small and isolated so it cannot monopolize the scheduler
    // ahead of payment/provisioning maintenance or the common Outbox dispatcher.
    ->withoutOverlapping(10)
    // Provider/database broadcast mutation must remain inside the Scheduler flock.
    ->onOneServer();

Schedule::command('support:alerts:scan', [
    '--limit' => 100,
    '--json' => true,
])
    ->name('support.alerts.scan')
    ->everyMinute()
    // The scan is bounded and idempotent; keep the scheduler lock bounded after abnormal termination.
    ->withoutOverlapping(10)
    ->onOneServer();

$backupInterval = filter_var(
    config('operations.backup.database_interval_minutes', 10),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 60]],
);
if ($backupInterval === false || 60 % $backupInterval !== 0) {
    throw new RuntimeException('Backup database interval minutes must be a divisor of 60 between 1 and 60.');
}

$backupFrequentOverlap = filter_var(
    config('operations.backup.frequent_overlap_minutes', 30),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 1440]],
);
$backupDailyOverlap = filter_var(
    config('operations.backup.daily_overlap_minutes', 180),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 1440]],
);
$backupDailyTime = config('operations.backup.daily_time', '02:30');

if ($backupFrequentOverlap === false
    || $backupDailyOverlap === false
    || ! is_string($backupDailyTime)
    || preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $backupDailyTime) !== 1
) {
    throw new RuntimeException('Backup scheduler configuration is invalid.');
}

$backupCron = $backupInterval === 60 ? '0 * * * *' : '*/'.$backupInterval.' * * * *';
$backupEnabled = static fn (): bool => (bool) config('operations.backup.enabled', false);

Schedule::command('operations:backup', [
    '--kind' => 'frequent_database',
    '--json' => true,
])
    ->name('operations.backup.frequent-database')
    ->cron($backupCron)
    ->withoutOverlapping($backupFrequentOverlap)
    ->onOneServer()
    ->runInBackground()
    ->skip(static fn (): bool => now()->format('H:i') === $backupDailyTime)
    ->when($backupEnabled);

Schedule::command('operations:backup', [
    '--kind' => 'daily_full',
    '--json' => true,
])
    ->name('operations.backup.daily-full')
    ->dailyAt($backupDailyTime)
    ->withoutOverlapping($backupDailyOverlap)
    ->onOneServer()
    ->runInBackground()
    ->when($backupEnabled);

Schedule::command('reporting:run-schedules', [
    '--limit' => 5,
    '--json' => true,
])
    ->name('reporting.run-schedules')
    ->everyMinute()
    // Per-schedule leases and stable Telegram request keys provide durable retry/idempotency.
    // This bounded Scheduler lock prevents concurrent scans while recovering promptly after crashes.
    ->withoutOverlapping(10)
    ->onOneServer();
