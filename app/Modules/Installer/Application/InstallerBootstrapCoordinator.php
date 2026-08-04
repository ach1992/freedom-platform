<?php

declare(strict_types=1);

namespace App\Modules\Installer\Application;

final readonly class InstallerBootstrapCoordinator
{
    /** @requirement INS-001 SEC-007 QUA-011 */
    public function __construct(
        private InstallerBootstrapJournal $journal,
        private InstallerLock $lock,
    ) {
    }

    /** @return array{step: string, status: string} */
    public function begin(): array
    {
        $this->journal->record('bootstrap', 'started');

        return ['step' => 'bootstrap', 'status' => 'started'];
    }

    public function complete(): void
    {
        $this->journal->record('bootstrap', 'completed');
        $this->lock->activate();
    }
}
