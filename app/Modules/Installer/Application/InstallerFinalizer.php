<?php

declare(strict_types=1);

namespace App\Modules\Installer\Application;

use App\Modules\Installer\Application\Contracts\InstallerFinalizationRunner;

final readonly class InstallerFinalizer
{
    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function __construct(
        private InstallerEnvironmentBootstrapper $bootstrapper,
        private InstallerFinalizationRunner $runner,
    ) {}

    /**
     * @param  array<string, string>  $environment
     * @return array{status: 'already_locked'|'completed', completed_steps: list<string>, resumed: bool}
     */
    public function finalize(array $environment, ?string $lifecycleDatabasePassword = null): array
    {
        return $this->bootstrapper->run($environment, [
            'config_clear' => function (): void {
                $this->runner->clearConfiguration();
            },
            'migrations' => function () use ($lifecycleDatabasePassword): void {
                $this->runner->migrate($lifecycleDatabasePassword);
            },
            'config_cache' => function (): void {
                $this->runner->cacheConfiguration();
            },
        ]);
    }
}
