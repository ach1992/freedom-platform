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
     * @return array{status: 'already_locked'|'completed', completed_steps: list<string>, resumed: bool}
     */
    public function run(array $environment, array $downstreamSteps): array
    {
        if (array_key_exists('environment', $downstreamSteps)) {
            throw new RuntimeException('The environment bootstrap step is reserved.');
        }

        $steps = [
            'environment' => fn (): array => $this->environmentWriter->write($environment),
            ...$downstreamSteps,
        ];

        try {
            $result = $this->orchestrator->run($steps);
        } catch (Throwable $exception) {
            $this->environmentWriter->rollback();
            $this->journal->record('environment', 'failed');

            throw $exception;
        }

        $this->environmentWriter->commit();

        return $result;
    }
}
