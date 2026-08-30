<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait PreservesOutboxTriggerSurface
{
    use RestoresDatabaseTrigger;

    /**
     * @var list<array{
     *     name: string,
     *     create_statement: string,
     *     event_manipulation: string,
     *     action_timing: string,
     *     action_order: int
     * }>
     */
    private array $outboxTriggerSurfaceSnapshot = [];

    protected function setUpPreservesOutboxTriggerSurface(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $this->outboxTriggerSurfaceSnapshot = $this->snapshotDatabaseTriggersForTable('outbox_messages');
    }

    protected function tearDownPreservesOutboxTriggerSurface(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || $this->outboxTriggerSurfaceSnapshot === []) {
            return;
        }

        $this->restoreDatabaseTriggersForTable('outbox_messages', $this->outboxTriggerSurfaceSnapshot);
        $this->outboxTriggerSurfaceSnapshot = [];
    }
}
