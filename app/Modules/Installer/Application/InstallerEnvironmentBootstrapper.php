<?php

declare(strict_types=1);

namespace App\Modules\Installer\Application;

use Closure;
use RuntimeException;
use Throwable;

final readonly class InstallerEnvironmentBootstrapper
{
    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function __construct(
        private InstallerEnvironmentWriter $environmentWriter,
        private InstallerBootstrapOrchestrator $orchestrator,
        private InstallerBootstrapJournal $journal,
    ) {}

    /**
     * @param  array<string, string>  $environment
     * @param  array<string, Closure(): void>  $downstreamSteps
     * @param  null|Closure(): void  $afterEnvironmentRollback
     * @return array{status: 'already_locked'|'completed', completed_steps: list<string>, resumed: bool}
     */
    public function run(
        array $environment,
        array $downstreamSteps,
        ?Closure $afterEnvironmentRollback = null,
    ): array {
        if (array_key_exists('environment', $downstreamSteps)) {
            throw new RuntimeException('The environment bootstrap step is reserved.');
        }

        $environmentApplied = false;
        $steps = [
            'environment' => function () use ($environment, &$environmentApplied): void {
                $this->environmentWriter->write($environment);
                $environmentApplied = true;
            },
            ...$downstreamSteps,
        ];

        try {
            $result = $this->orchestrator->run($steps);
        } catch (Throwable $exception) {
            $this->environmentWriter->rollback();

            if ($environmentApplied && $afterEnvironmentRollback !== null) {
                $afterEnvironmentRollback();
            }

            $this->journal->record('environment', 'failed');

            throw $exception;
        }

        $this->environmentWriter->commit();

        return $result;
    }
}
