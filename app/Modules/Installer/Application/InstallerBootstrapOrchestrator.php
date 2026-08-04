<?php

declare(strict_types=1);

namespace App\Modules\Installer\Application;

use Closure;
use RuntimeException;
use Throwable;

final readonly class InstallerBootstrapOrchestrator
{
    /** @requirement INS-001 SEC-007 QUA-011 */
    public function __construct(
        private InstallerBootstrapJournal $journal,
        private InstallerLock $lock,
    ) {
    }

    /**
     * Execute idempotent bootstrap steps in order and resume from the last journaled step.
     *
     * Step callbacks must be safe to retry when a process terminates after the side effect
     * but before the completion record is persisted.
     *
     * @param  array<string, Closure(): void>  $steps
     * @return array{status: 'already_locked'|'completed', completed_steps: list<string>, resumed: bool}
     */
    public function run(array $steps): array
    {
        $stepNames = $this->validateSteps($steps);

        if ($this->lock->exists()) {
            return [
                'status' => 'already_locked',
                'completed_steps' => [],
                'resumed' => false,
            ];
        }

        $current = $this->journal->current();

        if ($current !== null && $current['step'] === 'bootstrap' && $current['status'] === 'completed') {
            $this->lock->activate();

            return [
                'status' => 'completed',
                'completed_steps' => $stepNames,
                'resumed' => true,
            ];
        }

        $startIndex = $this->resumeIndex($current, $stepNames);
        $resumed = $current !== null;

        $this->journal->record('bootstrap', 'started');

        for ($index = $startIndex, $count = count($stepNames); $index < $count; $index++) {
            $stepName = $stepNames[$index];
            $this->journal->record($stepName, 'started');

            try {
                $steps[$stepName]();
            } catch (Throwable $exception) {
                $this->journal->record($stepName, 'failed');

                throw $exception;
            }

            $this->journal->record($stepName, 'completed');
        }

        $this->journal->record('bootstrap', 'completed');
        $this->lock->activate();

        return [
            'status' => 'completed',
            'completed_steps' => $stepNames,
            'resumed' => $resumed,
        ];
    }

    /**
     * @param  array<string, Closure(): void>  $steps
     * @return list<string>
     */
    private function validateSteps(array $steps): array
    {
        if ($steps === []) {
            throw new RuntimeException('Bootstrap requires at least one step.');
        }

        $stepNames = [];

        foreach ($steps as $stepName => $step) {
            if (! is_string($stepName) || $stepName === '' || $stepName === 'bootstrap') {
                throw new RuntimeException('Bootstrap step names must be non-empty strings and cannot use the reserved bootstrap name.');
            }

            if (! $step instanceof Closure) {
                throw new RuntimeException('Bootstrap steps must be closures.');
            }

            $stepNames[] = $stepName;
        }

        return $stepNames;
    }

    /**
     * @param  array{step: string, status: string, updated_at: string}|null  $current
     * @param  list<string>  $stepNames
     */
    private function resumeIndex(?array $current, array $stepNames): int
    {
        if ($current === null || $current['step'] === 'bootstrap') {
            return 0;
        }

        $index = array_search($current['step'], $stepNames, true);

        if ($index === false) {
            throw new RuntimeException('Bootstrap journal references an unknown step.');
        }

        return $current['status'] === 'completed' ? $index + 1 : $index;
    }
}
