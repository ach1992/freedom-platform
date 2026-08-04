<?php

declare(strict_types=1);

namespace App\Modules\Operations\Presentation\Console;

use App\Modules\Operations\Application\WorkerHeartbeatService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * @requirement OPS-003
 */
final class RecordWorkerHeartbeatCommand extends Command
{
    protected $signature = 'operations:worker-heartbeat
        {worker-id : Stable process-manager worker identifier}
        {--queue=default : Queue name handled by this worker}
        {--release= : Explicit release version override}';

    protected $description = 'Record an authenticated application worker heartbeat';

    public function handle(WorkerHeartbeatService $heartbeats): int
    {
        try {
            $heartbeats->record(
                (string) $this->argument('worker-id'),
                (string) $this->option('queue'),
                $this->option('release') !== null
                    ? (string) $this->option('release')
                    : (string) config('app.version', 'unversioned'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $this->info('Worker heartbeat recorded.');

        return self::SUCCESS;
    }
}
