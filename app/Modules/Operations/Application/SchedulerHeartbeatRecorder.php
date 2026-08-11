<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Shared\Application\Clock;
use Illuminate\Database\DatabaseManager;

final readonly class SchedulerHeartbeatRecorder
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    /** @requirement RUN-003 OPS-003 */
    public function record(): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');

        $this->database->connection()->table('worker_heartbeats')->updateOrInsert(
            ['worker_id' => 'scheduler'],
            [
                'queue' => 'scheduler',
                'host_hash' => hash('sha256', gethostname() ?: 'unknown'),
                'release_version' => config('app.version'),
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }
}
