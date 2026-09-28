<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
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
}
