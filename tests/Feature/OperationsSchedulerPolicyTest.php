<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** @requirement OPS-003 QUA-004 */
final class OperationsSchedulerPolicyTest extends TestCase
{
    public function test_critical_scheduler_overlap_locks_have_explicit_bounded_ttls(): void
    {
        $expected = [
            'operations.scheduler-heartbeat' => 5,
            'operations.check-worker-heartbeats' => 5,
            'operations.dispatch-outbox' => 10,
            'wallet.maintenance' => 15,
            'payments.purchase-maintenance' => 15,
            'referrals.process-rewards' => 10,
            'customers.recalculate-tiers' => 60,
            'payments.alternative-maintenance' => 10,
            'services.auto-renew' => 30,
            'services.sync' => 30,
            'services.notifications' => 30,
            'telegram.process-broadcasts' => 10,
            'support.alerts.scan' => 10,
            'operations.backup.frequent-database' => 30,
            'operations.backup.daily-full' => 180,
        ];

        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(static fn (Event $event): bool => is_string($event->description))
            ->keyBy(static fn (Event $event): string => $event->description);

        foreach ($expected as $name => $expiresAt) {
            self::assertTrue($events->has($name), 'Missing scheduled task '.$name);
            /** @var Event $event */
            $event = $events->get($name);
            self::assertTrue($event->withoutOverlapping, $name.' must prevent overlapping runs.');
            self::assertSame($expiresAt, $event->expiresAt, $name.' must use the expected bounded stale-lock TTL.');
        }
    }

    /** @requirement BAK-001 OPS-003 QUA-004 */
    public function test_daily_slot_makes_the_due_frequent_backup_yield_to_daily_full(): void
    {
        config()->set('operations.backup.enabled', true);
        $timezone = (string) config('app.timezone', 'UTC');
        Carbon::setTestNow(Carbon::parse('2026-09-29 02:30:00', $timezone));

        try {
            $events = collect($this->app->make(Schedule::class)->events())
                ->filter(static fn (Event $event): bool => is_string($event->description))
                ->keyBy(static fn (Event $event): string => $event->description);

            /** @var Event $frequent */
            $frequent = $events->get('operations.backup.frequent-database');
            /** @var Event $daily */
            $daily = $events->get('operations.backup.daily-full');

            self::assertTrue($frequent->isDue($this->app), 'The default frequent cron is due at 02:30.');
            self::assertTrue($daily->isDue($this->app), 'The daily full backup is due at 02:30.');
            self::assertFalse(
                $frequent->filtersPass($this->app),
                'The frequent peer must yield when the daily-full slot is due.',
            );
            self::assertTrue(
                $daily->filtersPass($this->app),
                'The required daily-full backup must remain eligible at its configured slot.',
            );
        } finally {
            Carbon::setTestNow();
        }
    }
}
