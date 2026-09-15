<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Presentation\Console;

use App\Modules\Provisioning\Application\ServiceSynchronizationService;
use DomainException;
use Illuminate\Console\Command;
use RuntimeException;

/** @requirement SVC-010 SVC-013 RUN-004 QUA-004 */
final class ProcessServiceSynchronizationsCommand extends Command
{
    protected $signature = 'services:sync {--service= : Synchronize exactly one Service public ID} {--full : Synchronize all eligible Services} {--limit= : Maximum Services for batch scope} {--json : Emit machine-readable output}';

    protected $description = 'Synchronize authoritative local Service state with remote panel state and record anomalies.';

    public function handle(ServiceSynchronizationService $service): int
    {
        try {
            $servicePublicId = $this->option('service');
            $full = (bool) $this->option('full');
            $rawLimit = $this->option('limit');
            if ($full && is_string($servicePublicId) && $servicePublicId !== '') {
                throw new DomainException('Service sync --service and --full are mutually exclusive.');
            }
            if (($full || (is_string($servicePublicId) && $servicePublicId !== '')) && $rawLimit !== null && $rawLimit !== '') {
                throw new DomainException('Service sync --limit is only valid for batch scope.');
            }

            if (is_string($servicePublicId) && $servicePublicId !== '') {
                $receipt = $service->syncOne($servicePublicId);
            } elseif ($full) {
                $receipt = $service->processFull();
            } else {
                $receipt = $service->processBatch($this->limit($rawLimit));
            }
        } catch (DomainException|RuntimeException $exception) {
            return $this->failure($exception->getMessage());
        }

        $payload = [
            'status' => $receipt->requiresAttention() ? 'attention_required' : 'ok',
            'run' => $receipt->runPublicId,
            'scope' => $receipt->scope->value,
            'candidates' => $receipt->candidates,
            'processed' => $receipt->processed,
            'anomalies' => $receipt->anomalies,
            'failures' => $receipt->failures,
            'skipped' => $receipt->skipped,
        ];
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));
        } else {
            $this->line(sprintf(
                'Service sync: scope=%s candidates=%d processed=%d anomalies=%d failures=%d skipped=%d',
                $receipt->scope->value,
                $receipt->candidates,
                $receipt->processed,
                $receipt->anomalies,
                $receipt->failures,
                $receipt->skipped,
            ));
        }

        return $receipt->requiresAttention() ? self::FAILURE : self::SUCCESS;
    }

    private function limit(mixed $raw): int
    {
        $value = $raw === null || $raw === '' ? config('service_sync.batch_limit', 50) : $raw;
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($validated === false) {
            throw new DomainException('Service sync --limit must be an integer between 1 and 500.');
        }

        return (int) $validated;
    }

    private function failure(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode(['status' => 'error', 'message' => $message], JSON_THROW_ON_ERROR));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
