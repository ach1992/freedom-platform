<?php

declare(strict_types=1);

namespace App\Modules\Operations\Presentation\Console;

use App\Modules\Operations\Application\WorkerHeartbeatService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * @requirement OPS-001 OPS-003
 */
final class CheckWorkerHeartbeatsCommand extends Command
{
    protected $signature = 'operations:check-worker-heartbeats
        {--max-age=120 : Maximum allowed heartbeat age in seconds}
        {--json : Emit JSON only}';

    protected $description = 'Detect stale workers and persist deduplicated critical alerts';

    public function handle(WorkerHeartbeatService $heartbeats): int
    {
        try {
            $staleWorkerIds = $heartbeats->detectAndAlertStale((int) $this->option('max-age'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $result = [
            'status' => $staleWorkerIds === [] ? 'healthy' : 'unhealthy',
            'stale_worker_ids' => $staleWorkerIds,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } elseif ($staleWorkerIds === []) {
            $this->info('All recorded worker heartbeats are fresh.');
        } else {
            $this->error(sprintf('Stale workers: %s', implode(', ', $staleWorkerIds)));
        }

        return $staleWorkerIds === [] ? self::SUCCESS : self::FAILURE;
    }
}
