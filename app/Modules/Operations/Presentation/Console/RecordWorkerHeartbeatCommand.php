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
                $this->stringArgument('worker-id'),
                $this->stringOption('queue'),
                $this->releaseVersion(),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $this->info('Worker heartbeat recorded.');

        return self::SUCCESS;
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('Argument %s must be a string.', $name));
        }

        return $value;
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('Option %s must be a string.', $name));
        }

        return $value;
    }

    private function releaseVersion(): string
    {
        $override = $this->option('release');

        if ($override !== null) {
            if (! is_string($override)) {
                throw new InvalidArgumentException('Release option must be a string.');
            }

            return $override;
        }

        $configured = config('app.version', 'unversioned');

        return is_string($configured) ? $configured : 'unversioned';
    }
}
