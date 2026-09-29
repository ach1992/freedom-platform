<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use PHPUnit\Framework\TestCase;

final class RestoreSchedulerDeploymentContractTest extends TestCase
{
    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function test_production_scheduler_is_flocked_and_mutating_background_children_do_not_escape_the_fence(): void
    {
        $cron = file_get_contents(base_path('deploy/cron/freedom-platform.cron'));
        $schedule = file_get_contents(base_path('routes/console.php'));

        self::assertIsString($cron);
        self::assertIsString($schedule);

        self::assertStringContainsString(
            '/usr/bin/flock -n storage/framework/operations-scheduler-mutation.lock',
            $cron,
        );
        self::assertStringContainsString('artisan schedule:run', $cron);

        foreach ([
            'payments:alternative-maintenance',
            'telegram:process-broadcasts',
        ] as $command) {
            $position = strpos($schedule, "Schedule::command('".$command."'");
            self::assertNotFalse($position);

            $next = strpos($schedule, 'Schedule::', $position + 1);
            $definition = substr(
                $schedule,
                $position,
                $next === false ? null : $next - $position,
            );

            self::assertStringNotContainsString(
                '->runInBackground()',
                $definition,
                $command.' must remain inside the production Scheduler flock.',
            );
        }
    }
}
