<?php

declare(strict_types=1);

namespace App\Modules\Installer\Application;

final readonly class InstallerBootstrapOrchestrator
{
    /** @requirement INS-001 SEC-007 QUA-011 */
    public function __construct(
        private InstallerBootstrapJournal $journal,
        private InstallerLock $lock,
    ) {
    }

    public function start(): void
    {
        if ($this->lock->isLocked()) {
            return;
        }

        $this->journal->record('bootstrap', 'started');
    }

    public function complete(): void
    {
        $this->journal->record('bootstrap', 'completed');
        $this->lock->activate();
    }
}
