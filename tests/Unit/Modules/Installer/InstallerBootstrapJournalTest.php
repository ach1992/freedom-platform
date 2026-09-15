<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Installer;

use App\Modules\Installer\Application\InstallerBootstrapJournal;
use Tests\TestCase;

final class InstallerBootstrapJournalTest extends TestCase
{
    public function test_it_records_and_reads_bootstrap_state(): void
    {
        $path = storage_path('framework/testing/bootstrap-journal.json');
        @unlink($path);

        $journal = new InstallerBootstrapJournal($path);
        $journal->record('environment', 'completed');

        $this->assertSame([
            'step' => 'environment',
            'status' => 'completed',
            'updated_at' => $journal->current()['updated_at'],
        ], $journal->current());

        @unlink($path);
    }
}
