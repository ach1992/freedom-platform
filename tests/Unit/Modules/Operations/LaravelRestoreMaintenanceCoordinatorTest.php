<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Application\RuntimeDeploymentInvariants;
use App\Modules\Operations\Infrastructure\LaravelRestoreMaintenanceCoordinator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use RuntimeException;
use Tests\TestCase;

final class LaravelRestoreMaintenanceCoordinatorTest extends TestCase
{
    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function test_enter_activates_owned_maintenance_and_requests_worker_restart(): void
    {
        $runId = '20260929T040000Z-1111111111111111';
        $maintenance = $this->createMock(MaintenanceMode::class);
        $console = $this->createMock(Kernel::class);

        $maintenance->expects(self::exactly(2))
            ->method('active')
            ->willReturnOnConsecutiveCalls(false, true);
        $maintenance->expects(self::once())
            ->method('activate')
            ->with(self::callback(
                static fn (array $data): bool => ($data['status'] ?? null) === 503
                    && ($data['restore_run_id'] ?? null) === $runId
                    && ($data['except'] ?? null) === [],
            ));
        $maintenance->expects(self::once())
            ->method('data')
            ->willReturn(['restore_run_id' => $runId]);
        $maintenance->expects(self::never())->method('deactivate');
        $console->expects(self::once())
            ->method('call')
            ->with('queue:restart', ['--no-interaction' => true])
            ->willReturn(0);

        $this->coordinator($maintenance, $console)->enter($runId);
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function test_worker_quiescence_failure_removes_owned_pre_mutation_maintenance(): void
    {
        $runId = '20260929T040001Z-2222222222222222';
        $maintenance = $this->createMock(MaintenanceMode::class);
        $console = $this->createMock(Kernel::class);

        $maintenance->expects(self::exactly(2))
            ->method('active')
            ->willReturnOnConsecutiveCalls(false, true);
        $maintenance->expects(self::once())->method('activate');
        $maintenance->expects(self::once())
            ->method('data')
            ->willReturn(['restore_run_id' => $runId]);
        $maintenance->expects(self::once())->method('deactivate');
        $console->expects(self::once())
            ->method('call')
            ->willReturn(1);

        try {
            $this->coordinator($maintenance, $console)->enter($runId);
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

        $maintenance->expects(self::once())->method('active')->willReturn(false);
        $maintenance->expects(self::never())->method('activate');
        $console->expects(self::never())->method('call');

        try {
            (new LaravelRestoreMaintenanceCoordinator(
                $maintenance,
                $console,
                new RuntimeDeploymentInvariants,
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
    public function test_leave_requires_restore_ownership_before_reopening_processing(): void
    {
        $runId = '20260929T040002Z-3333333333333333';
        $maintenance = $this->createMock(MaintenanceMode::class);
        $console = $this->createMock(Kernel::class);

        $maintenance->expects(self::once())->method('active')->willReturn(true);
        $maintenance->expects(self::once())
            ->method('data')
            ->willReturn(['restore_run_id' => '20260929T040003Z-4444444444444444']);
        $maintenance->expects(self::never())->method('deactivate');
        $console->expects(self::never())->method('call');

        try {
            $this->coordinator($maintenance, $console)->leave($runId);
            self::fail('Restore must not release maintenance it no longer owns.');
        } catch (RuntimeException $exception) {
            self::assertSame('Restore maintenance ownership changed unexpectedly.', $exception->getMessage());
        }
    }

    private function coordinator(
        MaintenanceMode $maintenance,
        Kernel $console,
    ): LaravelRestoreMaintenanceCoordinator {
        return new LaravelRestoreMaintenanceCoordinator(
            $maintenance,
            $console,
            new RuntimeDeploymentInvariants,
            base_path('deploy/supervisor/freedom-platform.conf'),
            360,
            static function (int $_seconds): void {},
        );
    }
}
