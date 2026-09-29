<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use Closure;
use App\Modules\Operations\Application\Contracts\RestoreSchedulerMutationLock;
use App\Modules\Operations\Application\RuntimeDeploymentInvariants;
use App\Modules\Operations\Infrastructure\LaravelRestoreMaintenanceCoordinator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use RuntimeException;
use Tests\TestCase;

final class LaravelRestoreMaintenanceCoordinatorTest extends TestCase
{
    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function test_enter_drains_scheduler_before_requesting_worker_restart(): void
    {
        $runId = '20260929T040000Z-1111111111111111';
        $maintenance = $this->createMock(MaintenanceMode::class);
        $console = $this->createMock(Kernel::class);
        $scheduler = $this->createMock(RestoreSchedulerMutationLock::class);
        $events = [];

        $maintenance->expects(self::exactly(2))
            ->method('active')
            ->willReturnOnConsecutiveCalls(false, true);
        $maintenance->expects(self::once())
            ->method('activate')
            ->with(self::callback(
                static fn (array $data): bool => ($data['status'] ?? null) === 503
                    && ($data['restore_run_id'] ?? null) === $runId
                    && ($data['except'] ?? null) === [],
            ))
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'maintenance';
            });
        $maintenance->expects(self::once())
            ->method('data')
            ->willReturn(['restore_run_id' => $runId]);
        $maintenance->expects(self::never())->method('deactivate');

        $scheduler->expects(self::once())
            ->method('acquire')
            ->with(360)
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'scheduler';
            });
        $scheduler->expects(self::never())->method('release');

        $console->expects(self::once())
            ->method('call')
            ->with('queue:restart', ['--no-interaction' => true])
            ->willReturnCallback(static function () use (&$events): int {
                $events[] = 'queue';

                return 0;
            });

        $this->coordinator($maintenance, $console, $scheduler)->enter($runId);

        self::assertSame(['maintenance', 'scheduler', 'queue'], $events);
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function test_scheduler_drain_failure_removes_owned_pre_mutation_maintenance(): void
    {
        $runId = '20260929T040013Z-8888888888888888';
        $maintenance = $this->createMock(MaintenanceMode::class);
        $console = $this->createMock(Kernel::class);
        $scheduler = $this->createMock(RestoreSchedulerMutationLock::class);

        $maintenance->expects(self::exactly(2))
            ->method('active')
            ->willReturnOnConsecutiveCalls(false, true);
        $maintenance->expects(self::once())->method('activate');
        $maintenance->expects(self::once())
            ->method('data')
            ->willReturn(['restore_run_id' => $runId]);
        $maintenance->expects(self::once())->method('deactivate');
        $scheduler->expects(self::once())
            ->method('acquire')
            ->willThrowException(new RuntimeException('test-only active Scheduler mutation'));
        $scheduler->expects(self::once())->method('release');
        $console->expects(self::never())->method('call');

        try {
            $this->coordinator($maintenance, $console, $scheduler)->enter($runId);
            self::fail('Active Scheduler mutation must abort restore maintenance entry.');
        } catch (RuntimeException $exception) {
            self::assertSame('test-only active Scheduler mutation', $exception->getMessage());
        }
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function test_worker_quiescence_failure_releases_scheduler_fence_and_owned_pre_mutation_maintenance(): void
    {
        $runId = '20260929T040001Z-2222222222222222';
        $maintenance = $this->createMock(MaintenanceMode::class);
        $console = $this->createMock(Kernel::class);
        $scheduler = $this->createMock(RestoreSchedulerMutationLock::class);

        $maintenance->expects(self::exactly(2))
            ->method('active')
            ->willReturnOnConsecutiveCalls(false, true);
        $maintenance->expects(self::once())->method('activate');
        $maintenance->expects(self::once())
            ->method('data')
            ->willReturn(['restore_run_id' => $runId]);
        $maintenance->expects(self::once())->method('deactivate');
        $scheduler->expects(self::once())->method('acquire')->with(360);
        $scheduler->expects(self::once())->method('release');
        $console->expects(self::once())
            ->method('call')
            ->willReturn(1);

        try {
            $this->coordinator($maintenance, $console, $scheduler)->enter($runId);
            self::fail('Failed queue quiescence request must abort restore maintenance entry.');
        } catch (RuntimeException $exception) {
            self::assertSame('Queue worker quiescence could not be requested.', $exception->getMessage());
        }
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function test_quiescence_shorter_than_worker_timeout_boundary_fails_before_maintenance_activation(): void
    {
        $runId = '20260929T040010Z-5555555555555555';
        $maintenance = $this->createMock(MaintenanceMode::class);
        $console = $this->createMock(Kernel::class);
        $scheduler = $this->createMock(RestoreSchedulerMutationLock::class);

        $maintenance->expects(self::once())->method('active')->willReturn(false);
        $maintenance->expects(self::never())->method('activate');
        $scheduler->expects(self::never())->method('acquire');
        $console->expects(self::never())->method('call');

        try {
            (new LaravelRestoreMaintenanceCoordinator(
                $maintenance,
                $console,
                new RuntimeDeploymentInvariants,
                $scheduler,
                base_path('deploy/supervisor/freedom-platform.conf'),
                300,
                static function (int $_seconds): void {},
            ))->enter($runId);
            self::fail('Unsafe quiescence duration must be rejected before maintenance activation.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Restore quiescence is shorter than the reviewed worker timeout boundary.',
                $exception->getMessage(),
            );
        }
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function test_refresh_runtime_clears_config_then_restarts_workers_while_scheduler_fence_is_retained(): void
    {
        $runId = '20260929T040011Z-6666666666666666';
        $maintenance = $this->createMock(MaintenanceMode::class);
        $console = $this->createMock(Kernel::class);
        $scheduler = $this->createMock(RestoreSchedulerMutationLock::class);
        $calls = [];

        $maintenance->expects(self::exactly(2))
            ->method('active')
            ->willReturn(true);
        $maintenance->expects(self::exactly(2))
            ->method('data')
            ->willReturn(['restore_run_id' => $runId]);
        $maintenance->expects(self::never())->method('deactivate');
        $scheduler->expects(self::never())->method('acquire');
        $scheduler->expects(self::never())->method('release');

        $console->expects(self::exactly(2))
            ->method('call')
            ->willReturnCallback(static function (string $command, array $arguments) use (&$calls): int {
                $calls[] = [$command, $arguments];

                return 0;
            });

        $this->coordinator($maintenance, $console, $scheduler)->refreshRuntime($runId);

        self::assertSame([
            ['config:clear', ['--no-ansi' => true, '--no-interaction' => true]],
            ['queue:restart', ['--no-interaction' => true]],
        ], $calls);
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function test_refresh_runtime_config_failure_stops_before_worker_restart_and_keeps_scheduler_fence(): void
    {
        $runId = '20260929T040012Z-7777777777777777';
        $maintenance = $this->createMock(MaintenanceMode::class);
        $console = $this->createMock(Kernel::class);
        $scheduler = $this->createMock(RestoreSchedulerMutationLock::class);

        $maintenance->expects(self::once())->method('active')->willReturn(true);
        $maintenance->expects(self::once())
            ->method('data')
            ->willReturn(['restore_run_id' => $runId]);
        $maintenance->expects(self::never())->method('deactivate');
        $scheduler->expects(self::never())->method('release');

        $console->expects(self::once())
            ->method('call')
            ->with('config:clear', ['--no-ansi' => true, '--no-interaction' => true])
            ->willReturn(1);

        try {
            $this->coordinator($maintenance, $console, $scheduler)->refreshRuntime($runId);
            self::fail('A restored config-cache refresh failure must remain fail-closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('Restored configuration cache could not be cleared.', $exception->getMessage());
        }
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function test_leave_requires_restore_ownership_before_reopening_processing(): void
    {
        $runId = '20260929T040002Z-3333333333333333';
        $maintenance = $this->createMock(MaintenanceMode::class);
        $console = $this->createMock(Kernel::class);
        $scheduler = $this->createMock(RestoreSchedulerMutationLock::class);

        $maintenance->expects(self::once())->method('active')->willReturn(true);
        $maintenance->expects(self::once())
            ->method('data')
            ->willReturn(['restore_run_id' => '20260929T040003Z-4444444444444444']);
        $maintenance->expects(self::never())->method('deactivate');
        $scheduler->expects(self::never())->method('release');
        $console->expects(self::never())->method('call');

        try {
            $this->coordinator($maintenance, $console, $scheduler)->leave($runId);
            self::fail('Restore must not release maintenance it no longer owns.');
        } catch (RuntimeException $exception) {
            self::assertSame('Restore maintenance ownership changed unexpectedly.', $exception->getMessage());
        }
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function test_leave_releases_maintenance_before_scheduler_fence(): void
    {
        $runId = '20260929T040014Z-9999999999999999';
        $maintenance = $this->createMock(MaintenanceMode::class);
        $console = $this->createStub(Kernel::class);
        $scheduler = $this->createMock(RestoreSchedulerMutationLock::class);
        $events = [];

        $maintenance->expects(self::exactly(2))
            ->method('active')
            ->willReturnOnConsecutiveCalls(true, false);
        $maintenance->expects(self::once())
            ->method('data')
            ->willReturn(['restore_run_id' => $runId]);
        $maintenance->expects(self::once())
            ->method('deactivate')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'maintenance_released';
            });
        $scheduler->expects(self::once())
            ->method('release')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'scheduler_released';
            });

        $this->coordinator($maintenance, $console, $scheduler)->leave($runId);

        self::assertSame(['maintenance_released', 'scheduler_released'], $events);
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function test_deactivation_side_effect_then_exception_keeps_scheduler_fence_for_recontainment(): void
    {
        $runId = '20260929T040015Z-aaaaaaaaaaaaaaaa';
        $maintenance = new FaultInjectingRestoreMaintenanceMode($runId);
        $maintenance->throwAfterDeactivate = true;
        $scheduler = new StatefulRestoreSchedulerMutationLock(true);
        $coordinator = $this->coordinator(
            $maintenance,
            $this->createStub(Kernel::class),
            $scheduler,
        );

        try {
            $coordinator->leave($runId);
            self::fail('A deactivation failure must not be treated as a successful reopen.');
        } catch (RuntimeException $exception) {
            self::assertSame('test-only maintenance deactivation failure', $exception->getMessage());
        }

        self::assertTrue($scheduler->held());
        self::assertFalse($maintenance->active);

        $state = $coordinator->retain($runId);

        self::assertTrue($state['maintenance_owned']);
        self::assertTrue($state['scheduler_fence_held']);
        self::assertTrue($state['workers_quiesced']);
        self::assertTrue($maintenance->active);
        self::assertTrue($scheduler->held());
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function test_post_deactivation_verification_failure_occurs_before_scheduler_release_and_is_recontained(): void
    {
        $runId = '20260929T040016Z-bbbbbbbbbbbbbbbb';
        $maintenance = new FaultInjectingRestoreMaintenanceMode($runId);
        $maintenance->throwOnActiveCall = 2;
        $scheduler = new StatefulRestoreSchedulerMutationLock(true);
        $coordinator = $this->coordinator(
            $maintenance,
            $this->createStub(Kernel::class),
            $scheduler,
        );

        try {
            $coordinator->leave($runId);
            self::fail('An uncertain maintenance-release verification must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('test-only maintenance active verification failure', $exception->getMessage());
        }

        self::assertTrue($scheduler->held());
        self::assertFalse($maintenance->active);

        $state = $coordinator->retain($runId);

        self::assertTrue($state['maintenance_owned']);
        self::assertTrue($state['scheduler_fence_held']);
        self::assertTrue($maintenance->active);
        self::assertTrue($scheduler->held());
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function test_recontainment_drains_worker_started_during_maintenance_release_gap(): void
    {
        $runId = '20260929T040017Z-cccccccccccccccc';
        $workerRunning = false;
        $restartRequested = false;
        $maintenance = new FaultInjectingRestoreMaintenanceMode($runId);
        $maintenance->throwAfterDeactivate = true;
        $maintenance->afterDeactivate = static function () use (&$workerRunning): void {
            $workerRunning = true;
        };
        $scheduler = new StatefulRestoreSchedulerMutationLock(true);
        $console = $this->createMock(Kernel::class);
        $console->expects(self::once())
            ->method('call')
            ->with('queue:restart', ['--no-interaction' => true])
            ->willReturnCallback(static function () use (&$restartRequested, &$workerRunning): int {
                self::assertTrue($workerRunning);
                $restartRequested = true;

                return 0;
            });

        $coordinator = $this->coordinator(
            $maintenance,
            $console,
            $scheduler,
            static function (int $_seconds) use (&$restartRequested, &$workerRunning): void {
                self::assertTrue($restartRequested);
                self::assertTrue($workerRunning);
                $workerRunning = false;
            },
        );

        try {
            $coordinator->leave($runId);
            self::fail('Injected resume failure must interrupt reopening.');
        } catch (RuntimeException $exception) {
            self::assertSame('test-only maintenance deactivation failure', $exception->getMessage());
        }

        self::assertTrue($workerRunning);
        self::assertTrue($scheduler->held());
        self::assertFalse($maintenance->active);

        $state = $coordinator->retain($runId);

        self::assertTrue($state['maintenance_owned']);
        self::assertTrue($state['scheduler_fence_held']);
        self::assertTrue($state['workers_quiesced']);
        self::assertFalse($workerRunning);
        self::assertTrue($maintenance->active);
        self::assertTrue($scheduler->held());
    }

    private function coordinator(
        MaintenanceMode $maintenance,
        Kernel $console,
        ?RestoreSchedulerMutationLock $scheduler = null,
        ?Closure $waiter = null,
    ): LaravelRestoreMaintenanceCoordinator {
        return new LaravelRestoreMaintenanceCoordinator(
            $maintenance,
            $console,
            new RuntimeDeploymentInvariants,
            $scheduler ?? $this->createStub(RestoreSchedulerMutationLock::class),
            base_path('deploy/supervisor/freedom-platform.conf'),
            360,
            $waiter ?? static function (int $_seconds): void {},
        );
    }
}

final class FaultInjectingRestoreMaintenanceMode implements MaintenanceMode
{
    public bool $active = true;

    public bool $throwAfterDeactivate = false;

    public ?int $throwOnActiveCall = null;

    public ?Closure $afterDeactivate = null;

    private int $activeCalls = 0;

    /** @var array<string, mixed> */
    private array $payload;

    public function __construct(string $restoreRunId)
    {
        $this->payload = ['restore_run_id' => $restoreRunId];
    }

    public function activate(array $payload): void
    {
        $this->active = true;
        $this->payload = $payload;
    }

    public function deactivate(): void
    {
        $this->active = false;
        $this->payload = [];

        if ($this->afterDeactivate !== null) {
            ($this->afterDeactivate)();
        }

        if ($this->throwAfterDeactivate) {
            $this->throwAfterDeactivate = false;

            throw new RuntimeException('test-only maintenance deactivation failure');
        }
    }

    public function active(): bool
    {
        $this->activeCalls++;

        if ($this->throwOnActiveCall === $this->activeCalls) {
            $this->throwOnActiveCall = null;

            throw new RuntimeException('test-only maintenance active verification failure');
        }

        return $this->active;
    }

    public function data(): array
    {
        return $this->payload;
    }
}

final class StatefulRestoreSchedulerMutationLock implements RestoreSchedulerMutationLock
{
    public function __construct(private bool $held) {}

    public function acquire(int $timeoutSeconds): void
    {
        $this->held = true;
    }

    public function release(): void
    {
        $this->held = false;
    }

    public function held(): bool
    {
        return $this->held;
    }
}
